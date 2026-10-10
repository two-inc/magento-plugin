<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Framework\Url;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\ComposeRefund;
use Two\Gateway\Service\Order\ComposeShipment;
use Two\Gateway\Service\Order\ShopTaxRates;
use Two\Gateway\Service\Order\TaxCodeResolver;
use Two\Gateway\Test\Stubs\UnderscoreDataObject;

/**
 * TWO-24877: the tax_code each composed 0% line carries, through the real
 * composers. Product tax class 5 is the goods class, 7 the services class and
 * 9 the shipping tax class unless a case says otherwise.
 *
 * Create is composed on a placement-shaped order: neither the order nor its
 * items have an id yet, only the quote item ids conversion copied over.
 */
class TaxCodeResolverTest extends TestCase
{
    private const EXPORT = 'ES_IVA_EXPORT';
    private const INTRA = 'ES_IVA_INTRA_COMMUNITY';
    private const SERVICES = 'ES_IVA_INTRA_COMMUNITY_SERVICES';
    private const ART20 = 'ES_IVA_EXEMPT_ART20';

    /** @var bool whether the composers get a resolver; false composes as before TWO-24877 */
    private $withResolver = true;

    /** @var array<int, array> the shop's rates per product tax class, [['code' => .., 'percent' => ..]] */
    private $rates = [];

    /** @var int|null the store's shipping tax class as the repository reports it (null = None) */
    private $shippingClass = 9;

    /**
     * Create, then an edit of the order once saved, carry the resolved code.
     *
     * @dataProvider createCases
     * @param array $products [product type, tax percent] per line
     * @param array|null $shipping [delivery country, postcode], null for no delivery address
     * @param string $billing buyer (billing) country, optionally followed by the billing postcode and VAT id
     * @param array $expected tax_code per line, null for none; the shipping line last when there is one
     * @param bool $withShipping whether the order charges shipping at 0%
     */
    public function testCreateLinesCarryTheResolvedCode(
        string $merchant,
        array $map,
        array $products,
        ?array $shipping,
        string $billing,
        array $expected,
        string $description,
        bool $withShipping = false
    ): void {
        $order = $this->order($products, $shipping, $billing, $withShipping);
        $create = $this->composer(ComposeOrder::class, $merchant, $map)->execute($order, 'ref', []);
        $this->assertSame($expected, $this->codes($create['line_items']), "$description: create");

        $this->save($order);
        $edit = $this->composer(ComposeOrder::class, $merchant, $map)
            ->execute($order, 'ref', ['isEdit' => true, 'placedTerms' => null]);
        $this->assertSame($expected, $this->codes($edit['line_items']), "$description: edit");
    }

    public static function createCases(): array
    {
        $goods = [['simple', 0.0]];
        $service = [['virtual', 0.0]];
        $mixed = [['virtual', 0.0], ['simple', 0.0]];
        return [
            ['ES', [], $goods, ['US', '10001'], 'US', [null], 'nothing mapped: no code, even for an export'],
            ['ES', [], $service, null, 'DE 10115 DE123456789', [null], 'nothing mapped: no code for an EU buyer with a VAT number'],
            ['ES', [], $mixed, ['ES', '35001'], 'ES', [null, null, null], 'nothing mapped: no code for any line, shipping included', true],
            ['ES', ['5' => self::ART20], $goods, ['US', '10001'], 'US', [self::ART20], 'a mapped class'],
            ['ES', ['5' => self::ART20], $goods, ['ES', '28001'], 'ES', [self::ART20], 'a mapped class on a domestic order'],
            ['ES', ['7' => self::ART20], [['configurable', 0.0, true]], ['US', '10001'], 'US', [self::ART20], 'a configurable maps by its child\'s class'],
            ['ES', ['7' => self::ART20], $mixed, ['US', '10001'], 'US', [self::ART20, null, null], 'only the mapped class of a mixed order', true],
            ['ES', ['9' => 'ES_IVA_EXEMPT_ART22'], $goods, ['US', '10001'], 'US', [null, 'ES_IVA_EXEMPT_ART22'], 'the shipping tax class maps the shipping line', true],
            ['NO', [], $goods, ['US', '10001'], 'US', [null], 'a non-Spanish merchant with no mapping'],
            ['DE', ['5' => 'DE_ZERO'], $goods, ['DE', '10115'], 'DE', ['DE_ZERO'], 'a non-Spanish merchant with a mapping'],
            ['ES', ['5' => self::ART20], [['simple', 21.0]], ['US', '10001'], 'US', [null], 'a mapped line at 21%'],
            ['ES', ['5' => self::ART20], [['simple', 21.0], ['simple', 0.0]], ['US', '10001'], 'US', [null, self::ART20], 'only the 0% line of two'],
        ];
    }

    /**
     * TWO-26153: the case table shared by the three plugins, rows 1 to 17
     * (row 18, the upgrade's fan-out, is in FanOutTaxCodeMapTest). Merchant
     * country ES. Rows are keyed `<class>|exempt`, `<class>|rate:<rate code>`
     * and `<class>|none`; $rates is what the shop's rules match for each
     * class at the tax address (none for a buyer whose customer group the
     * shop exempts).
     *
     * @dataProvider sharedCases
     * @param array $products [product type, tax percent] per line
     * @param array $billing [country, postcode]
     * @param array|null $delivery [country, postcode], null for a virtual order
     * @param array $expected tax_code per line, the "Other charges" fee line last when $fee is set
     */
    public function testTheSharedCaseTable(
        array $products,
        array $billing,
        ?array $delivery,
        ?string $vat,
        array $rates,
        array $map,
        array $expected,
        string $description,
        float $fee = 0.0
    ): void {
        $this->rates = $rates;
        $order = $this->order($products, $delivery, $billing[0], false, $fee);
        $order->billing->setData('postcode', $billing[1]);
        $order->billing->setData('vat_id', $vat);

        $create = $this->create($order, 'ES', $map);
        $this->assertSame($expected, $this->codes($create['line_items']), $description);
    }

    public static function sharedCases(): array
    {
        $goods = [['simple', 0.0]];
        $de = ['DE', '10115'];
        $es = ['ES', '28001'];
        $exempt = ['5|exempt' => self::INTRA];
        $exportOutside = ['5|exempt' => self::INTRA, '5|none' => self::EXPORT];
        $mixed = [['simple', 0.0], ['virtual', 0.0]];
        return [
            'row 1' => [[['simple', 21.0]], $es, $es, null, [5 => [['code' => 'ES-21', 'percent' => 21.0]]], $exempt, [null], 'non-0% lines are never touched'],
            'row 2' => [$goods, $de, $de, 'DE123', [], $exempt, [self::INTRA], 'step 1 exempt buyer'],
            'row 3' => [$goods, $de, $de, 'de 123', [], $exempt, [self::INTRA], 'VAT read as entered, any non-empty value counts'],
            'row 4' => [$goods, $de, $de, '   ', [], $exempt, [null], 'whitespace-only VAT is empty; NR on (none) gives no code'],
            'row 5' => [$goods, $de, ['US', '10001'], 'DE123', [5 => [['code' => 'US-0', 'percent' => 0.0]]], $exempt + ['5|rate:US-0' => self::EXPORT], [self::EXPORT], 'export: tax address outside the EU skips step 1'],
            'row 6' => [$goods, $es, ['ES', '35001'], null, [5 => [['code' => 'ES-CANARIAS-0', 'percent' => 0.0]]], ['5|rate:ES-CANARIAS-0' => self::EXPORT], [self::EXPORT], "step 2 shop's 0% rule"],
            'row 7' => [$goods, ['US', '10001'], ['US', '10001'], null, [], ['5|none' => self::EXPORT], [self::EXPORT], 'step 3 no-rule row'],
            'row 8' => [$goods, $de, $de, 'DE123', [], ['5|none' => self::INTRA], [null], 'a matched row on (none) never falls through'],
            'row 9' => [$goods, $es, $es, 'ESB123', [], $exempt, [null], 'merchant-country buyer is never exempt'],
            'row 10' => [$goods, ['MC', '98000'], ['MC', '98000'], 'FR123', [], $exempt, [self::INTRA], 'Monaco is in the EU VAT area'],
            'row 11' => [$goods, ['GB', 'BT1 1AA'], ['GB', 'BT1 1AA'], 'XI123', [], $exempt, [self::INTRA], 'Northern Ireland (GB + BT postcode) is in the EU VAT area'],
            'row 12' => [$goods, ['GB', 'SW1A 1AA'], ['GB', 'SW1A 1AA'], 'GB123', [], $exportOutside, [self::EXPORT], 'Great Britain is outside'],
            'row 13' => [$goods, ['CH', '8001'], ['CH', '8001'], 'CHE123', [], $exportOutside, [self::EXPORT], 'Switzerland is outside'],
            'row 14' => [[['virtual', 0.0]], ['FR', '75001'], null, 'FR123', [], ['7|exempt' => self::SERVICES], [self::SERVICES], "goods vs services comes from the merchant's per-class mapping"],
            'row 15' => [$goods, $de, $de, 'DE123', [], $exempt, [self::INTRA, self::INTRA], "step 4: shared code of the order's coded 0% lines", 12.50],
            'row 16' => [$mixed, $de, $de, 'DE123', [], $exempt + ['7|exempt' => self::SERVICES], [self::INTRA, self::SERVICES, null], 'step 4: disagreeing codes give no code', 12.50],
            'row 17' => [$goods, $de, $de, null, [], $exempt, [null, null], 'step 4 with nothing to share', 12.50],
            'billing at home' => [$goods, $es, $de, 'DE123', [], $exportOutside, [self::EXPORT], "step 1 needs the billing country abroad too: a merchant-country buyer delivered abroad is not exempt"],
            'billing outside the EU' => [$goods, ['US', '10001'], $de, 'DE123', [], $exportOutside, [self::EXPORT], 'step 1 needs the billing country in the EU VAT area'],
            'two 0% rates' => [$goods, $es, ['ES', '35001'], null, [5 => [['code' => 'ES-CANARIAS-0', 'percent' => 0.0], ['code' => 'ES-OTHER-0', 'percent' => 0.0]]], ['5|rate:ES-CANARIAS-0' => self::EXPORT, '5|rate:ES-OTHER-0' => self::INTRA], [self::EXPORT], "two 0% rates match: the first in core's order wins"],
            '0% beside 21%' => [$goods, $es, ['ES', '35001'], null, [5 => [['code' => 'ES-CANARIAS-0', 'percent' => 0.0], ['code' => 'ES-21', 'percent' => 21.0]]], ['5|rate:ES-CANARIAS-0' => self::EXPORT], [null], 'a 0% rate beside one above 0% gives no code'],
            'rate above 0%' => [$goods, $es, $es, null, [5 => [['code' => 'ES-21', 'percent' => 21.0]]], ['5|none' => self::EXPORT], [null], 'a 0% line whose matched rate is not 0% gets no code'],
        ];
    }

    /**
     * TWO-26153: on a later request, step 4 shares the codes steps 1 to 3 gave
     * at placement, the same as at placement.
     *
     * @dataProvider adjustmentCases
     */
    public function testARefundAdjustmentSharesOnlyTheCodesStepsOneToThreeGave(
        array $map,
        array $products,
        string $billing,
        ?string $expected,
        string $description
    ): void {
        $order = $this->order($products, ['US', '10001'], $billing);
        $this->create($order, 'ES', $map);
        $this->save($order);

        $adjustment = array_values(array_filter(
            $this->refund($order, 'ES', $map),
            static fn (array $line) => ($line['order_item_id'] ?? null) === 'adjustment'
        ));
        $this->assertSame($expected, $adjustment[0]['tax_code'] ?? null, $description);
    }

    public static function adjustmentCases(): array
    {
        $mixed = [['simple', 0.0], ['virtual', 0.0]];
        return [
            [[], $mixed, 'US', null, 'nothing mapped: nothing to share, so no code'],
            [['5|none' => self::EXPORT], [['simple', 0.0]], 'US', self::EXPORT, 'a mapped code recorded at placement is shared'],
            [['5|none' => self::EXPORT], $mixed, 'US', self::EXPORT, 'an unmapped class beside it does not make the codes disagree'],
            [['5|none' => self::EXPORT, '7|none' => self::ART20], $mixed, 'US', null, 'codes that disagreed at placement give no code'],
        ];
    }

    /**
     * A record written before it listed the shared codes gives a line with no
     * class the one code its recorded lines carry (the fee line aside), and
     * no code when they disagree or carry none.
     *
     * @dataProvider recordWithNoSharedKeyCases
     */
    public function testARecordWithNoSharedKeySharesItsRecordedCodes(string $record, ?string $expected, string $description): void
    {
        $order = $this->order([['simple', 0.0], ['simple', 0.0]], ['US', '10001'], 'US');
        $this->save($order);
        $order->setData(TaxCodeResolver::STORED_CODES, $record);

        // Two items, then the adjustment, then shipping (class 9, unmapped).
        $codes = $this->codes($this->refund($order, 'ES', []));
        $this->assertSame($expected, $codes[2], $description);
    }

    public static function recordWithNoSharedKeyCases(): array
    {
        $export = self::EXPORT;
        $art20 = self::ART20;
        return [
            ["{\"item:101\":\"$export\",\"item:102\":\"$export\"}", self::EXPORT, 'recorded codes that agree are shared'],
            ["{\"item:101\":\"$export\",\"item:102\":null}", self::EXPORT, 'a line recorded with no code does not make them disagree'],
            ["{\"item:101\":\"$export\",\"item:102\":\"$art20\"}", null, 'recorded codes that disagree give no code'],
            ["{\"item:101\":null,\"item:102\":null,\"fee\":\"$art20\"}", null, 'no recorded code (the fee line aside) gives no code'],
            ['{}', null, 'an empty record gives no code'],
        ];
    }

    /**
     * With the merchant's country unknown, no buyer can be told apart from a
     * domestic one, so step 1 never fires.
     */
    public function testStepOneNeedsTheMerchantsCountry(): void
    {
        $order = $this->order([['simple', 0.0]], ['DE', '10115'], 'DE 10115 DE123');

        $create = $this->create($order, '', ['5|exempt' => self::INTRA]);
        $this->assertSame([null], $this->codes($create['line_items']));
    }

    /**
     * A shop with no row set never looks up its tax: nothing would read it.
     */
    public function testAShopWithNoRowSetNeverLooksUpItsTax(): void
    {
        $shop = $this->createMock(ShopTaxRates::class);
        $shop->expects($this->never())->method('taxAddress');
        $shop->expects($this->never())->method('rates');
        $order = $this->order([['simple', 0.0]], ['DE', '10115'], 'DE 10115 DE123', true);
        $lines = [
            ['order_item_id' => 'x', 'type' => 'PHYSICAL', 'tax_rate' => '0.00'],
            ['order_item_id' => 'shipping', 'type' => 'SHIPPING_FEE', 'tax_rate' => '0.00'],
        ];

        (new TaxCodeResolver($this->config([]), $this->records('ES'), $shop))
            ->apply($lines, $order, ['0' => $order->itemsById[1]]);
    }

    /**
     * The buyer VAT number comes from the billing address VAT id, then the
     * order's customer tax/VAT number, as entered and trimmed of leading and
     * trailing whitespace only; an address VAT id that a VAT check which got an
     * answer marked invalid is no number at all.
     *
     * @dataProvider vatSourceCases
     * @param mixed $vatIsValid the billing address VAT check result
     * @param mixed $requestSuccess whether the VAT check request got an answer
     */
    public function testBuyerVatNumberSourceOrder(
        ?string $vatId,
        $vatIsValid,
        $requestSuccess,
        ?string $taxvat,
        string $expected,
        string $description
    ): void {
        $order = $this->order([['simple', 0.0]], null, 'DE 10115');
        $order->billing->setData('vat_id', $vatId);
        $order->billing->setData('vat_is_valid', $vatIsValid);
        $order->billing->setData('vat_request_success', $requestSuccess);
        $order->setData('customer_taxvat', $taxvat);

        $this->assertSame($expected, TaxCodeResolver::buyerVatNumber($order), $description);
    }

    public static function vatSourceCases(): array
    {
        return [
            ['DE111111111', null, null, 'DE222222222', 'DE111111111', 'address VAT id first'],
            [null, null, null, 'DE222222222', 'DE222222222', 'customer VAT number when the address has none'],
            [' ', null, null, 'DE222222222', 'DE222222222', 'a blank address VAT id is none'],
            ['DE111111111', 1, 1, 'DE222222222', 'DE111111111', 'a VAT check that passed keeps the address VAT id'],
            ['DE111111111', '0', '1', 'DE222222222', '', 'a refused address VAT id is no number, not the customer VAT number'],
            ['DE111111111', 0, 1, 'DE111111111', '', 'a refused address VAT id is not sent again as the customer VAT number'],
            [null, 0, 1, 'DE222222222', 'DE222222222', 'with no address VAT id a refusal leaves the customer VAT number'],
            ['DE111111111', 0, 1, null, '', 'answered invalid and no customer VAT number is no number'],
            ['DE111111111', 0, 0, 'DE222222222', 'DE111111111', 'a VAT check request that failed keeps the address VAT id'],
            ['DE111111111', 0, null, null, 'DE111111111', 'invalid with no request result keeps the address VAT id'],
            ['DE111111111', '', 1, null, 'DE111111111', 'an empty check result is no check'],
            ['111111111', null, null, null, '111111111', 'an unprefixed number is kept as entered'],
            [" \tDE123456789\n ", null, null, null, 'DE123456789', 'leading and trailing whitespace is trimmed'],
            ['de 123.456-789', null, null, null, 'de 123.456-789', 'inner spaces, dots, hyphens and case are kept'],
            [null, null, null, '   ', '', 'a whitespace-only customer VAT number is no number'],
            [null, null, null, null, '', 'neither source is no number'],
        ];
    }

    /**
     * TWO-26153: order create sends buyer_vat_number only for a Spanish
     * merchant and a buyer outside Spain; otherwise the key is absent. An
     * edit never sends it.
     *
     * @dataProvider sentVatCases
     */
    public function testCreateSendsTheBuyerVatNumber(
        string $merchant,
        string $billing,
        ?string $vatId,
        ?string $taxvat,
        ?string $expected,
        string $description
    ): void {
        $order = $this->order([['simple', 0.0]], null, $billing);
        $order->billing->setData('vat_id', $vatId);
        $order->setData('customer_taxvat', $taxvat);

        $create = $this->create($order, $merchant, []);
        $this->assertSame($expected, $create['buyer_vat_number'] ?? null, "$description: create");
        $this->assertSame($expected !== null, array_key_exists('buyer_vat_number', $create), "$description: key");

        $this->save($order);
        $edit = $this->composer(ComposeOrder::class, $merchant, [])
            ->execute($order, 'ref', ['isEdit' => true, 'placedTerms' => null]);
        $this->assertArrayNotHasKey('buyer_vat_number', $edit, "$description: edit");
    }

    public static function sentVatCases(): array
    {
        return [
            ['ES', 'DE 10115', ' DE 123.456.789 ', null, 'DE 123.456.789', 'Spanish merchant, EU buyer: sent as entered, trimmed'],
            ['ES', 'DE 10115', '123456789', null, '123456789', 'unprefixed number sent as entered'],
            ['ES', 'GR 10431', 'EL123456789', null, 'EL123456789', 'Greek buyer sent with EL as entered'],
            ['ES', 'US 10001', 'US123', null, 'US123', 'Spanish merchant, non-EU buyer with a number'],
            ['ES', 'DE 10115', null, 'DE222222222', 'DE222222222', 'customer VAT number when the address has none'],
            ['ES', 'ES 28001', 'ESB12345678', null, null, 'Spanish buyer: never sent'],
            ['NO', 'DE 10115', 'DE123456789', null, null, 'non-Spanish merchant with a VAT number'],
            ['ES', 'DE 10115', null, null, null, 'Spanish merchant, no VAT number'],
        ];
    }

    /**
     * Core's shipping class None is class 0, which the repository reports as null.
     */
    public function testShippingOnClassNoneTakesTheNoneRowsMapping(): void
    {
        $this->shippingClass = null;
        $order = $this->order([['simple', 21.0]], ['ES', '28001'], 'ES', true);

        $create = $this->composer(ComposeOrder::class, 'ES', ['0' => 'ES_IVA_EXEMPT_ART22'])->execute($order, 'ref', []);

        $this->assertSame([null, 'ES_IVA_EXEMPT_ART22'], $this->codes($create['line_items']));
    }

    /**
     * Capture, shipment and refund send the codes placement recorded, whatever
     * has changed since; only a line placement never sent resolves afresh.
     *
     * @dataProvider laterPayloads
     */
    public function testLaterPayloadsSendTheCodesRecordedAtPlacement(string $payload, array $expected): void
    {
        $order = $this->order([['simple', 0.0], ['virtual', 0.0]], ['US', '10001'], 'US', true);
        $map = ['5' => self::EXPORT, '7' => self::ART20, '9' => self::EXPORT];
        $this->composer(ComposeOrder::class, 'ES', $map)->execute($order, 'ref', []);
        $this->save($order);
        $order->shipping = new UnderscoreDataObject(['country_id' => 'ES', 'postcode' => '28001']);
        $order->billing = new UnderscoreDataObject(['country_id' => 'ES', 'postcode' => '28001']);

        $this->assertSame($expected, $this->codes($this->{$payload}($order, 'ES', [])), $payload);
    }

    public static function laterPayloads(): array
    {
        return [
            ['capture', [self::EXPORT, self::ART20, self::EXPORT]],
            ['shipment', [self::EXPORT, self::ART20, self::EXPORT]],
            // The adjustment was never placed, and steps 1 to 3 gave two codes at placement: no code (step 4).
            ['refund', [self::EXPORT, self::ART20, null, self::EXPORT]],
            ['edit', [self::EXPORT, self::ART20, self::EXPORT]],
        ];
    }

    /**
     * Placement recorded no code; a mapping set since is ignored.
     *
     * @dataProvider recordedNoCodePayloads
     */
    public function testARecordedNoCodeStaysNoCode(string $payload, array $expected): void
    {
        $order = $this->order([['simple', 0.0]], ['ES', '28001'], 'ES', true);
        $this->composer(ComposeOrder::class, 'ES', [])->execute($order, 'ref', []);
        $this->save($order);

        $map = ['5' => self::EXPORT, '9' => self::EXPORT];
        $this->assertSame($expected, $this->codes($this->{$payload}($order, 'ES', $map)), $payload);
    }

    public static function recordedNoCodePayloads(): array
    {
        return [
            ['capture', [null, null]],
            ['shipment', [null, null]],
            // The adjustment was never placed: no line was coded at placement, so it has nothing to share.
            ['refund', [null, null, null]],
            ['edit', [null, null]],
        ];
    }

    /**
     * An untaxed "Other charges" line is recorded at placement like any other line.
     *
     * @dataProvider feeLinePayloads
     */
    public function testAFeeLineSendsTheCodeRecordedAtPlacement(string $payload, array $expected): void
    {
        $order = $this->order([['simple', 0.0]], ['US', '10001'], 'US', true, 12.50);
        $create = $this->composer(ComposeOrder::class, 'ES', ['5' => self::EXPORT, '9' => self::EXPORT])
            ->execute($order, 'ref', []);
        $this->assertSame([self::EXPORT, self::EXPORT, self::EXPORT], $this->codes($create['line_items']));
        $this->assertSame('other_charges', $create['line_items'][2]['order_item_id']);
        $this->save($order);

        $this->assertSame($expected, $this->codes($this->{$payload}($order, 'ES', [])), $payload);
    }

    public static function feeLinePayloads(): array
    {
        return [
            ['capture', [self::EXPORT, self::EXPORT, self::EXPORT]],
            ['edit', [self::EXPORT, self::EXPORT, self::EXPORT]],
            // The adjustment takes the one code steps 1 to 3 gave at placement (step 4).
            ['refund', [self::EXPORT, self::EXPORT, self::EXPORT, self::EXPORT]],
        ];
    }

    /**
     * A fee a provider itemizes only once the order is saved reaches the
     * create as "Other charges" and the edit under its own id; both are fee
     * lines and send the one code placement recorded.
     */
    public function testAFeeWhoseIdAppearsAfterPlacementSendsTheRecordedCode(): void
    {
        $order = $this->order([['simple', 0.0]], ['US', '10001'], 'US', true, 12.50);
        $pool = new FeeLineProviderPool([new class implements \Two\Gateway\Api\Fee\FeeLineProviderInterface {
            public function getFeeLines($entity): array
            {
                return $entity->getId() ? [[
                    'order_item_id' => 'acme_fee_1', 'name' => 'Fee', 'description' => 'Fee', 'type' => 'OTHER',
                    'gross_amount' => '12.50', 'net_amount' => '12.50', 'tax_amount' => '0.00',
                    'discount_amount' => '0.00', 'tax_rate' => '0.000000', 'unit_price' => '12.500000',
                    'quantity' => 1, 'quantity_unit' => 'sc',
                ]] : [];
            }
        }]);
        $create = $this->composer(ComposeOrder::class, 'ES', ['5' => self::EXPORT, '9' => self::EXPORT], null, $pool)
            ->execute($order, 'ref', []);
        $this->assertSame('other_charges', $create['line_items'][2]['order_item_id']);
        $this->save($order);

        $edit = $this->composer(ComposeOrder::class, 'ES', [], null, $pool)
            ->execute($order, 'ref', ['isEdit' => true, 'placedTerms' => null]);

        $this->assertSame('acme_fee_1', $edit['line_items'][2]['order_item_id']);
        $this->assertSame([self::EXPORT, self::EXPORT, self::EXPORT], $this->codes($edit['line_items']));
    }

    /**
     * Two lines sharing a SKU and name each record under their own quote item.
     */
    public function testTheSameSkuTwiceRecordsEachItemSeparately(): void
    {
        $order = $this->order([['virtual', 0.0], ['simple', 0.0]], ['US', '10001'], 'US');
        $order->itemsById[2]->data['sku'] = 'SKU1';
        $order->itemsById[2]->data['name'] = 'Item 1';
        $create = $this->composer(ComposeOrder::class, 'ES', ['7' => self::ART20, '5' => self::EXPORT])
            ->execute($order, 'ref', []);

        $this->assertSame([self::ART20, self::EXPORT], $this->codes($create['line_items']));
        $this->assertSame(
            ['item:101' => self::ART20, 'item:102' => self::EXPORT, 'shared' => [self::ART20, self::EXPORT]],
            json_decode((string)$order->getData(TaxCodeResolver::STORED_CODES), true)
        );
    }

    /**
     * A line another extension renamed still finds its item by SKU, so its mapping applies.
     */
    public function testARenamedLineStillTakesItsItemsMapping(): void
    {
        $order = $this->order([['virtual', 0.0]], ['US', '10001'], 'US');
        $service = $this->composer(ComposeOrder::class, 'ES', ['7' => self::ART20]);
        $lines = $service->getLineItemsOrder($order);
        $lines[0]['name'] = 'Item 1 (renamed)';

        $this->assertSame([self::ART20], $this->codes($service->applyTaxCodes($lines, $order, true)));
    }

    /**
     * A line whose SKU another extension changed matches no item, so it has no
     * class and takes the code the order's other lines share (step 4), not
     * its item's class's row.
     */
    public function testAnUnmatchedProductLineTakesStepFour(): void
    {
        $order = $this->order([['virtual', 0.0], ['simple', 0.0]], ['FR', '75001'], 'FR 75001 FR12345678901');
        $service = $this->composer(ComposeOrder::class, 'ES', ['5|exempt' => self::INTRA]);
        $lines = $service->getLineItemsOrder($order);
        $lines[0]['details']['barcodes'][0]['value'] = 'CHANGED';

        $this->assertSame([self::INTRA, self::INTRA], $this->codes($service->applyTaxCodes($lines, $order, true)));
    }

    /**
     * A configurable parent and a bundle child keep the codes recorded under their own quote items.
     */
    public function testConfigurableAndBundleChildLinesKeepTheirRecordedCodes(): void
    {
        $order = $this->order([['configurable', 0.0, true], ['simple', 0.0]], ['US', '10001'], 'US', true);
        $order->itemsById[2]->data['parent_item_id'] = 50;
        $create = $this->composer(ComposeOrder::class, 'ES', ['7' => self::ART20, '5' => self::EXPORT])
            ->execute($order, 'ref', []);
        $this->assertSame([self::ART20, self::EXPORT, null], $this->codes($create['line_items']));
        $this->save($order);
        $order->shipping = new UnderscoreDataObject(['country_id' => 'ES', 'postcode' => '28001']);

        foreach (['capture', 'shipment', 'refund'] as $payload) {
            $codes = $this->codes($this->{$payload}($order, 'ES', []));
            $this->assertSame([self::ART20, self::EXPORT], array_slice($codes, 0, 2), $payload);
        }
    }

    /**
     * A fee line whose order_item_id happens to be numeric is not taken for that order item.
     */
    public function testANumericFeeIdIsNotAnOrderItem(): void
    {
        $order = $this->order([['simple', 0.0]], ['US', '10001'], 'US');
        $this->save($order);
        $resolver = new TaxCodeResolver($this->config(['5' => self::ART20]), $this->records('ES'), $this->shopRates());
        $lines = [['order_item_id' => 1, 'type' => 'OTHER', 'tax_rate' => '0.00']];

        $this->assertSame([null], $this->codes($resolver->apply($lines, $order)));
    }

    /**
     * Placement matches product lines to their items by identity, not position,
     * so a plugin that reorders getLineItemsOrder() cannot swap their classes.
     */
    public function testPlacementMatchesLinesToItemsWhateverTheirOrder(): void
    {
        $order = $this->order([['virtual', 0.0], ['simple', 0.0]], ['US', '10001'], 'US');
        $service = $this->composer(ComposeOrder::class, 'ES', ['7' => self::ART20, '5' => self::EXPORT]);
        $reordered = array_reverse($service->getLineItemsOrder($order));

        $this->assertSame([self::EXPORT, self::ART20], $this->codes($service->applyTaxCodes($reordered, $order, true)));
    }

    /**
     * An order placed before codes were recorded resolves every line as at placement.
     *
     * @dataProvider unrecordedPayloads
     */
    public function testAnOrderWithNoRecordResolvesAsAtPlacement(string $payload, array $expected): void
    {
        $order = $this->order([['simple', 0.0], ['virtual', 0.0]], ['ES', '28001'], 'DE 10115 DE123456789', true);
        $this->save($order);

        $this->assertSame($expected, $this->codes($this->{$payload}($order, 'ES', ['5' => self::ART20])), $payload);
    }

    public static function unrecordedPayloads(): array
    {
        return [
            ['capture', [self::ART20, null, null]],
            ['shipment', [self::ART20, null, null]],
            // The adjustment has no class: it takes the one code steps 1 to 3 gave (step 4).
            ['refund', [self::ART20, null, self::ART20, null]],
        ];
    }

    /**
     * Non-zero lines, and a non-Spanish merchant with no mapping, compose byte for byte as before.
     *
     * @dataProvider unchangedCases
     */
    public function testPayloadIsByteIdenticalToBefore(string $merchant, float $percent, string $description): void
    {
        foreach (['create', 'capture', 'shipment', 'refund'] as $payload) {
            $sent = [];
            foreach ([true, false] as $withResolver) {
                $this->withResolver = $withResolver;
                $order = $this->order([['simple', $percent]], ['US', '10001'], 'US', true);
                if ($payload !== 'create') {
                    $this->save($order);
                }
                $sent[] = json_encode($this->{$payload}($order, $merchant, []));
            }

            $this->assertSame($sent[1], $sent[0], "$description: $payload");
        }
    }

    public static function unchangedCases(): array
    {
        return [
            ['NO', 0.0, 'non-Spanish merchant, 0% lines, no mapping'],
            ['FR', 0.0, 'another non-Spanish merchant'],
        ];
    }

    public function testASpanishMerchantsNonZeroLinesAreByteIdentical(): void
    {
        $sent = [];
        foreach ([true, false] as $withResolver) {
            $this->withResolver = $withResolver;
            $order = $this->order([['simple', 21.0], ['virtual', 10.0]], ['US', '10001'], 'DE', true);
            $order->setShippingTaxAmount(2.10);
            $order->setData('two_shipping_tax_rate_source', OrderService::SHIPPING_RATE_DECLARED);
            $order->setData('two_shipping_tax_rate', 21.0);
            $order->setGrandTotal((float)$order->getGrandTotal() + 2.10);
            $order->setTaxAmount((float)$order->getTaxAmount() + 2.10);
            $sent[] = json_encode($this->create($order, 'ES', []));
        }

        $this->assertSame($sent[1], $sent[0]);
    }

    /**
     * A code a fee provider or earlier step already set is left alone.
     */
    public function testACodeAlreadyOnTheLineIsKept(): void
    {
        $resolver = new TaxCodeResolver($this->config([]), $this->records('ES'), $this->shopRates());
        $order = $this->order([['simple', 0.0]], ['US', '10001'], 'US');
        $lines = [['order_item_id' => 'fee', 'type' => 'OTHER', 'tax_rate' => '0.00', 'tax_code' => 'ES_IVA_ZERO']];

        $this->assertSame($lines, $resolver->apply($lines, $order));
    }

    private function edit(Order $order, string $merchant, array $map): array
    {
        return $this->composer(ComposeOrder::class, $merchant, $map)
            ->execute($order, 'ref', ['isEdit' => true, 'placedTerms' => null])['line_items'];
    }

    private function create(Order $order, string $merchant, array $map): array
    {
        return $this->composer(ComposeOrder::class, $merchant, $map)->execute($order, 'ref', []);
    }

    private function capture(Order $order, string $merchant, array $map): array
    {
        $invoice = new Invoice();
        $invoice->setOrder($order);
        $items = [];
        foreach ($order->itemsById as $id => $item) {
            $items[] = $this->item(['order_item_id' => $id, 'qty' => 1, 'qty_ordered' => 2, 'row_total' => 50.00,
                'tax_amount' => $item->getTaxAmount() / 2, 'name' => "Item $id", 'sku' => "SKU$id"]);
        }
        $invoice->setAllItems($items);
        $invoice->setShippingAmount(10.00);
        $invoice->setShippingTaxAmount(0.0);
        $invoice->setShippingInclTax(10.00);
        $tax = array_sum(array_map(static fn ($item) => $item->getTaxAmount(), $items));
        $invoice->setGrandTotal(50.00 * count($items) + 10.00 + $tax + (float)$order->getData('test_fee'));
        $invoice->setTaxAmount($tax);
        $invoice->setDiscountAmount(0.0);

        return $this->composer(ComposeCapture::class, $merchant, $map)->execute($invoice)['line_items'];
    }

    private function shipment(Order $order, string $merchant, array $map): array
    {
        $shipment = new class extends Shipment {
            /** @var array */
            public $items = [];

            public function getAllItems()
            {
                return $this->items;
            }

            public function getId()
            {
                return 5;
            }
        };
        foreach (array_keys($order->itemsById) as $id) {
            $shipment->items[] = $this->item(['order_item_id' => $id, 'qty' => 1, 'name' => "Item $id", 'sku' => "SKU$id"]);
        }

        return $this->composer(ComposeShipment::class, $merchant, $map, $order)->execute($shipment, $order)['line_items'];
    }

    private function refund(Order $order, string $merchant, array $map): array
    {
        $creditmemo = new Creditmemo();
        $items = [];
        $tax = 0.0;
        foreach ($order->itemsById as $id => $item) {
            $items[] = $this->item(['order_item_id' => $id, 'qty' => 1, 'row_total' => 50.00, 'name' => "Item $id", 'sku' => "SKU$id"]);
            $tax += $item->getTaxAmount() / 2;
        }
        $creditmemo->setItems($items);
        $creditmemo->setAdjustmentPositive(5.00);
        $creditmemo->setShippingAmount(10.00);
        $creditmemo->setShippingInclTax(10.00);
        $creditmemo->setShippingTaxAmount(0.0);
        $creditmemo->setGrandTotal(50.00 * count($items) + 15.00 + $tax + (float)$order->getData('test_fee'));
        $creditmemo->setTaxAmount($tax);
        $creditmemo->setOrder($order);

        return $this->composer(ComposeRefund::class, $merchant, $map, $order)
            ->execute($creditmemo, (float)$creditmemo->getGrandTotal(), $order)['line_items'];
    }

    private function codes(array $lines): array
    {
        return array_map(static fn (array $line) => $line['tax_code'] ?? null, array_values($lines));
    }

    /**
     * The order as saved: it and its items now have ids.
     */
    private function save(Order $order): void
    {
        $order->setData('id', 7);
        foreach ($order->itemsById as $id => $item) {
            $item->data['id'] = $id;
            $item->data['item_id'] = $id;
        }
    }

    /**
     * A placement-shaped order: no ids yet, items keyed by their future id.
     *
     * @param array $products [product type, tax percent, is virtual] per item, 100.00 net each, quantity 2
     * @param array|null $shipping [country, postcode] of the delivery address; null for none
     * @param string $billing billing country, optionally followed by the billing postcode and VAT id,
     *                        space separated
     * @param bool $withShipping whether the order charges 10.00 shipping at 0%
     * @param float $fee an untaxed charge in the grand total that no line itemizes
     */
    private function order(
        array $products,
        ?array $shipping,
        string $billing,
        bool $withShipping = false,
        float $fee = 0.0
    ): Order
    {
        $order = new class extends Order {
            /** @var array */
            public $itemsById = [];
            /** @var UnderscoreDataObject|null */
            public $billing;
            /** @var UnderscoreDataObject|null */
            public $shipping;

            public function getItemById($id)
            {
                foreach ($this->itemsById as $item) {
                    if ($item->getId() !== null && (string)$item->getId() === (string)$id) {
                        return $item;
                    }
                }
                return null;
            }

            public function getAllVisibleItems()
            {
                return array_values($this->itemsById);
            }

            public function getBillingAddress()
            {
                return $this->billing;
            }

            public function getShippingAddress()
            {
                return $this->shipping;
            }
        };
        $grand = 0.0;
        $taxTotal = 0.0;
        foreach (array_values($products) as $i => $product) {
            [$type, $percent] = $product;
            $id = $i + 1;
            $tax = round(100.00 * $percent / 100, 2);
            $order->itemsById[$id] = $this->item([
                'quote_item_id' => 100 + $id,
                'name' => "Item $id",
                'sku' => "SKU$id",
                'product_type' => $type,
                'is_virtual' => $virtual = $product[2] ?? in_array($type, ['virtual', 'downloadable'], true),
                'product' => new DataObject(['tax_class_id' => $type === 'configurable' ? '3' : ($virtual ? '7' : '5')]),
                // A configurable is taxed by its child's class.
                'children_items' => $type === 'configurable'
                    ? [$this->item(['product' => new DataObject(['tax_class_id' => $virtual ? '7' : '5'])])]
                    : null,
                'qty_ordered' => 2,
                'row_total' => 100.00,
                'tax_amount' => $tax,
                'tax_percent' => $percent,
                'discount_amount' => 0.0,
            ]);
            $grand += 100.00 + $tax;
            $taxTotal += $tax;
        }
        [$billingCountry, $billingPostcode, $vatId] = array_pad(explode(' ', $billing, 3), 3, null);
        $order->billing = new UnderscoreDataObject(
            ['country_id' => $billingCountry, 'postcode' => $billingPostcode ?? '00000', 'vat_id' => $vatId]
        );
        $order->shipping = $shipping
            ? new UnderscoreDataObject(['country_id' => $shipping[0], 'postcode' => $shipping[1]])
            : null;
        $order->setStoreId(1);
        $order->setOrderCurrencyCode('EUR');
        $order->setIncrementId('100000001');
        $order->setShippingDescription('Flat Rate');
        $order->setShippingMethod('flatrate_flatrate');
        $order->setIsVirtual($withShipping ? 0 : 1);
        if ($withShipping) {
            $order->setShippingAmount(10.00);
            $order->setShippingTaxAmount(0.0);
            $order->setShippingInclTax(10.00);
            $order->setData('two_shipping_tax_rate_source', OrderService::SHIPPING_RATE_NONE);
            $grand += 10.00;
            $order->setShipmentsCollection(new class {
                public function getFirstItem()
                {
                    return new DataObject(['id' => 5]);
                }
            });
        }
        $order->setGrandTotal($grand + $fee);
        $order->setData('test_fee', $fee);
        $order->setTaxAmount($taxTotal);

        return $order;
    }

    private function item(array $data): Order\Item
    {
        return new class ($data) extends Order\Item implements \Magento\Sales\Api\Data\OrderItemInterface {
            /** @var array */
            public $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function __call($method, $args)
            {
                $key = strtolower(preg_replace('/(.)([A-Z])/', '$1_$2', substr($method, 3)));
                return $this->data[$key] ?? null;
            }
        };
    }

    /**
     * A map in the old class => code shape becomes every row of that class,
     * as the upgrade fans it out; rows already in the new shape are kept.
     */
    private function config(array $map): ConfigRepository
    {
        $rows = [];
        foreach ($map as $key => $code) {
            if (ctype_digit((string)$key)) {
                $rows[$key . '|exempt'] = $rows[$key . '|none'] = $code;
            } else {
                $rows[$key] = $code;
            }
        }
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getTaxCodeMap')->willReturn($rows);
        $config->method('getShippingTaxClassId')->willReturn($this->shippingClass);
        $config->method('getSurchargeTaxClassId')->willReturn(null);
        $config->method('isTaxSubtotalsEnabled')->willReturn(true);
        $config->method('getWeightUnit')->willReturn('kg');
        $config->method('getVendorSiteName')->willReturn('');
        $config->method('getAllBuyerTerms')->willReturn([30]);
        $config->method('isBuyerTermAvailable')->willReturn(true);
        $config->method('getDefaultPaymentTerm')->willReturn(30);
        $config->method('getPaymentTermsType')->willReturn('invoice_date');

        return $config;
    }

    /**
     * The shop's tax: the order taxed on its delivery address (billing when
     * none), with $this->rates as the rates each product tax class matches there.
     */
    private function shopRates(): ShopTaxRates
    {
        $shop = $this->createMock(ShopTaxRates::class);
        $shop->method('taxAddress')->willReturnCallback(static function (Order $order): array {
            $address = $order->getShippingAddress() ?: $order->getBillingAddress();
            return ['country' => (string)$address->getCountryId(), 'postcode' => (string)$address->getPostcode()];
        });
        $shop->method('rates')->willReturnCallback(fn (Order $order, int $classId): array => $this->rates[$classId] ?? []);

        return $shop;
    }

    private function records(string $merchantCountry): RecordProvider
    {
        $records = $this->createMock(RecordProvider::class);
        $records->method('getRecord')->willReturn(['id' => 'm', 'country_code' => $merchantCountry]);

        return $records;
    }

    /**
     * A real composer; only the catalogue lookups and the order's addresses are stubbed.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function composer(
        string $class,
        string $merchant,
        array $map,
        ?Order $order = null,
        ?FeeLineProviderPool $pool = null
    )
    {
        $stubs = ['getProduct', 'getProductImageUrl', 'getCategories', 'getOrderItem'];
        if ($class === ComposeOrder::class) {
            $stubs = array_merge($stubs, ['getAddress', 'getBuyer']);
        }
        $service = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods($stubs)->getMock();
        $service->method('getProduct')->willReturn(new class extends Product {
            public function getCategoryIds()
            {
                return [];
            }

            public function getProductUrl()
            {
                return '';
            }
        });
        $service->method('getProductImageUrl')->willReturn('');
        $service->method('getCategories')->willReturn([]);
        if ($order !== null) {
            $service->method('getOrderItem')->willReturnCallback(static fn (int $id) => $order->getItemById($id));
        }
        if ($class === ComposeOrder::class) {
            $service->method('getAddress')->willReturn([]);
            $service->method('getBuyer')->willReturn([]);
            $service->url = $this->createMock(Url::class);
            $service->url->method('getUrl')->willReturn('https://example.test/two');
            (new \ReflectionProperty(ComposeOrder::class, 'checkoutSession'))->setValue($service, new CheckoutSession());
        }

        $config = $this->config($map);
        $properties = [
            'logRepository' => $this->createMock(LogRepository::class),
            'feeLineProviderPool' => $pool ?? new FeeLineProviderPool([]),
        ];
        if ($this->withResolver) {
            $properties['taxCodeResolver'] = new TaxCodeResolver($config, $this->records($merchant), $this->shopRates());
        }
        foreach ($properties as $name => $value) {
            (new \ReflectionProperty(OrderService::class, $name))->setValue($service, $value);
        }
        $service->configRepository = $config;

        return $service;
    }
}
