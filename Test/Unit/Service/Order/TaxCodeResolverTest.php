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
    private const NON_EU = 'ES_IVA_NON_EU_SERVICES';
    private const ART20 = 'ES_IVA_EXEMPT_ART20';

    /** @var bool whether the composers get a resolver; false composes as before TWO-24877 */
    private $withResolver = true;

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
            ['ES', [], $goods, ['US', '10001'], 'US', [self::EXPORT], 'goods delivered outside the EU'],
            ['ES', [], $goods, ['US', '10001'], 'ES', [self::EXPORT], 'goods delivered outside the EU to a Spanish buyer'],
            ['ES', [], $goods, ['ES', '35001'], 'ES', [self::EXPORT], 'goods delivered to Las Palmas'],
            ['ES', [], $goods, ['ES', '38001'], 'ES', [self::EXPORT], 'goods delivered to Tenerife'],
            ['ES', [], $goods, ['ES', '51001'], 'ES', [self::EXPORT], 'goods delivered to Ceuta'],
            ['ES', [], $goods, ['ES', '52001'], 'ES', [self::EXPORT], 'goods delivered to Melilla'],
            ['ES', [], $goods, ['DE', '10115'], 'FR 75001 FR12345678901', [self::INTRA], 'goods to another EU state, buyer in another EU state'],
            ['ES', [], $goods, ['MC', '98000'], 'MC 98000 FR12345678901', [self::INTRA], 'goods to Monaco, which counts as France'],
            ['ES', [], $goods, ['FR', '75001'], 'ES', [null], 'goods to another EU state, Spanish buyer'],
            ['ES', [], $goods, ['ES', '28001'], 'ES', [null], 'domestic goods'],
            ['ES', [], $goods, ['ES', '07001'], 'ES', [null], 'goods to the Balearics'],
            ['ES', [], $goods, ['ES', '28001'], 'FR', [null], 'goods delivered in Spain to a French buyer'],
            ['ES', [], $service, null, 'DE 10115 DE123456789', [self::SERVICES], 'service to a buyer in another EU state'],
            ['ES', [], [['downloadable', 0.0]], null, 'FR 75001 FR12345678901', [self::SERVICES], 'download to a buyer in another EU state'],
            ['ES', [], [['bundle', 0.0, true]], null, 'FR 75001 FR12345678901', [self::SERVICES], 'a bundle with nothing to ship is a service'],
            ['ES', [], [['bundle', 0.0, false]], ['FR', '75001'], 'FR 75001 FR12345678901', [self::INTRA], 'a bundle that ships is goods'],
            ['ES', [], [['configurable', 0.0, true]], ['ES', '28001'], 'DE 10115 DE123456789', [self::SERVICES], 'a configurable with a virtual child is a service'],
            ['ES', ['7' => self::ART20], [['configurable', 0.0, true]], ['US', '10001'], 'US', [self::ART20], 'a configurable maps by its child\'s class'],
            ['ES', [], $service, null, 'ES', [null], 'service to a Spanish buyer'],
            ['ES', [], $service, null, 'NO', [self::NON_EU], 'service to a buyer outside the EU'],
            ['ES', [], $service, ['ES', '28001'], 'US', [self::NON_EU], 'service to a buyer outside the EU, delivered in Spain'],
            ['ES', [], $service, null, 'ES 35001', [self::NON_EU], 'service to a buyer billed in Las Palmas'],
            ['ES', [], $service, null, 'ES 38001', [self::NON_EU], 'service to a buyer billed in Tenerife'],
            ['ES', [], $service, null, 'ES 51001', [self::NON_EU], 'service to a buyer billed in Ceuta'],
            ['ES', [], $service, null, 'ES 52001', [self::NON_EU], 'service to a buyer billed in Melilla'],
            ['ES', [], $service, ['ES', '35001'], 'ES 28001', [null], 'service delivered to the Canaries for a mainland buyer'],
            ['ES', [], $goods, ['ES', '28001'], 'ES 35001', [null], 'goods delivered in mainland Spain for a buyer billed in the Canaries'],
            ['ES', [], $goods, ['US', '10001'], 'US', [self::EXPORT, self::EXPORT], 'shipping follows goods', true],
            ['ES', [], $service, ['US', '10001'], 'DE 10115 DE123456789', [self::SERVICES, self::SERVICES], 'shipping follows services', true],
            ['ES', [], $service, ['US', '10001'], 'NO', [self::NON_EU, self::NON_EU], 'shipping follows non-EU services', true],
            ['ES', [], $mixed, ['ES', '28001'], 'DE 10115 DE123456789', [self::SERVICES, null, null], 'a mixed order: service by buyer, goods and shipping by delivery', true],
            ['ES', [], $mixed, ['US', '10001'], 'US', [self::NON_EU, self::EXPORT, self::EXPORT], 'a mixed order: the service is a non-EU service, not an export', true],
            ['ES', ['5' => self::ART20], $goods, ['US', '10001'], 'US', [self::ART20], 'the mapping beats the derivation'],
            ['ES', ['5' => self::ART20], $goods, ['ES', '28001'], 'ES', [self::ART20], 'the mapping covers a line nothing derives'],
            ['ES', ['7' => self::ART20], $mixed, ['US', '10001'], 'US', [self::ART20, self::EXPORT, self::EXPORT], 'a mapped service in a mixed order', true],
            ['ES', ['9' => 'ES_IVA_EXEMPT_ART22'], $goods, ['US', '10001'], 'US', [self::EXPORT, 'ES_IVA_EXEMPT_ART22'], 'the shipping tax class maps the shipping line', true],
            ['NO', [], $goods, ['US', '10001'], 'US', [null], 'a non-Spanish merchant with no mapping'],
            ['DE', ['5' => 'DE_ZERO'], $goods, ['DE', '10115'], 'DE', ['DE_ZERO'], 'a non-Spanish merchant with a mapping'],
            ['ES', ['5' => self::ART20], [['simple', 21.0]], ['US', '10001'], 'US', [null], 'a mapped line at 21%'],
            ['ES', [], [['simple', 21.0], ['simple', 0.0]], ['US', '10001'], 'US', [null, self::EXPORT], 'only the 0% line of two'],
        ];
    }

    /**
     * TWO-26153: both intra-community codes need a buyer VAT number whose
     * prefix is an EU state other than the merchant's country. Without one the
     * line gets no code, never the export or non-EU services code instead.
     *
     * @dataProvider vatCases
     * @param array $products [product type, tax percent] per line
     * @param array|null $shipping [delivery country, postcode], null for no delivery address
     * @param string $billing billing country and postcode
     * @param string|null $vatId billing address VAT id
     * @param string|null $taxvat the order's customer tax/VAT number
     * @param array $expected tax_code per line
     */
    public function testTheIntraCommunityCodesNeedABuyerVatNumber(
        array $map,
        array $products,
        ?array $shipping,
        string $billing,
        ?string $vatId,
        ?string $taxvat,
        array $expected,
        string $description
    ): void {
        $order = $this->order($products, $shipping, $billing);
        $order->billing->setData('vat_id', $vatId);
        $order->setData('customer_taxvat', $taxvat);

        $create = $this->create($order, 'ES', $map);
        $this->assertSame($expected, $this->codes($create['line_items']), $description);
    }

    public static function vatCases(): array
    {
        $goods = [['simple', 0.0]];
        $service = [['virtual', 0.0]];
        $art20 = ['5' => self::ART20, '7' => self::ART20];
        return [
            [[], $goods, ['FR', '75001'], 'FR 75001', 'FR12345678901', null, [self::INTRA], 'goods: VAT from another EU state'],
            [[], $goods, ['FR', '75001'], 'FR 75001', null, null, [null], 'goods: no VAT number derives nothing, not an export'],
            [[], $goods, ['FR', '75001'], 'FR 75001', 'ESB12345678', null, [null], 'goods: VAT prefix of the merchant\'s country'],
            [[], $goods, ['FR', '75001'], 'FR 75001', 'GB123456789', null, [null], 'goods: VAT prefix outside the EU'],
            [[], $goods, ['GR', '10431'], 'GR 10431', 'EL123456789', null, [self::INTRA], 'goods: EL is Greece'],
            [[], $goods, ['GR', '10431'], 'GR 10431', '123456789', null, [null], 'goods: an unprefixed number names no country'],
            [[], $goods, ['MC', '98000'], 'MC 98000', 'FR12345678901', null, [self::INTRA], 'goods: a Monaco buyer with a French number'],
            [[], $goods, ['MC', '98000'], 'MC 98000', 'MC12345678901', null, [null], 'goods: MC is not a VAT prefix'],
            [[], $goods, ['FR', '75001'], 'DE 10115', 'FR12345678901', null, [self::INTRA], 'goods: VAT state need not be the buyer\'s or delivery state'],
            [[], $goods, ['US', '10001'], 'US 10001', null, null, [self::EXPORT], 'goods: an export needs no VAT number'],
            [[], $service, null, 'DE 10115', 'DE123456789', null, [self::SERVICES], 'services: VAT from another EU state'],
            [[], $service, null, 'DE 10115', null, null, [null], 'services: no VAT number derives nothing, not non-EU services'],
            [[], $service, null, 'DE 10115', '', '  ', [null], 'services: blank VAT numbers are none'],
            [[], $service, null, 'DE 10115', 'ES B12345678', null, [null], 'services: VAT prefix of the merchant\'s country'],
            [[], $service, null, 'DE 10115', 'NO123456789', null, [null], 'services: VAT prefix outside the EU'],
            [[], $service, null, 'GR 10431', ' EL 123 456 789 ', null, [self::SERVICES], 'services: EL is Greece, inner spaces kept'],
            [[], $service, null, 'GR 10431', 'el123456789', null, [null], 'services: a lower-case prefix names no country'],
            [[], $service, null, 'DE 10115', '123.456-789', null, [null], 'services: an unprefixed number names no country'],
            [[], $service, null, 'DE 10115', null, 'DE123456789', [self::SERVICES], 'services: the customer VAT number when the address has none'],
            [[], $service, null, 'US 10001', null, null, [self::NON_EU], 'services: a non-EU buyer needs no VAT number'],
            [$art20, $goods, ['FR', '75001'], 'FR 75001', null, null, [self::ART20], 'goods: the mapping wins without a VAT number'],
            [$art20, $service, null, 'DE 10115', 'DE123456789', null, [self::ART20], 'services: the mapping wins over a VAT number'],
        ];
    }

    /**
     * TWO-26153: the VAT condition compares the prefix with the merchant's
     * country, whatever that country is.
     *
     * @dataProvider deriveVatCases
     */
    public function testDeriveComparesTheVatPrefixWithTheMerchantsCountry(
        bool $isService,
        string $merchantCountry,
        string $vat,
        ?string $expected,
        string $description
    ): void {
        $this->assertSame(
            $expected,
            TaxCodeResolver::derive($isService, 'DE', '10115', 'DE', '10115', $merchantCountry, $vat),
            $description
        );
    }

    public static function deriveVatCases(): array
    {
        return [
            [false, 'ES', 'FR12345678901', self::INTRA, 'goods: prefix of another EU state'],
            [false, 'FR', 'FR12345678901', null, 'goods: prefix equal to the merchant\'s country'],
            [true, 'ES', 'FR12345678901', self::SERVICES, 'services: prefix of another EU state'],
            [true, 'FR', 'FR12345678901', null, 'services: prefix equal to the merchant\'s country'],
            [true, 'GR', 'EL123456789', null, 'services: EL equals a Greek merchant\'s country'],
            [true, 'ES', '', null, 'services: no VAT number'],
            [false, 'ES', 'MC123456789', null, 'goods: MC is not a VAT prefix'],
            [true, 'ES', 'MC123456789', null, 'services: MC is not a VAT prefix'],
        ];
    }

    /**
     * @dataProvider normaliseCases
     */
    public function testNormaliseVatNumber(string $raw, string $expected, string $description): void
    {
        $this->assertSame($expected, TaxCodeResolver::normaliseVatNumber($raw), $description);
    }

    public static function normaliseCases(): array
    {
        return [
            [" \tDE123456789\n ", 'DE123456789', 'trims leading and trailing whitespace'],
            ['de 123.456-789', 'de 123.456-789', 'inner spaces, dots, hyphens and case are kept'],
            ['123456789', '123456789', 'an unprefixed number gets no prefix'],
            ['GR123456789', 'GR123456789', 'a GR prefix is kept as entered'],
            ['DE/123456789', 'DE/123456789', 'a slash is kept'],
            ['n/a', 'n/a', 'a value with no digit is still a number'],
            ['   ', '', 'whitespace only is no number'],
        ];
    }

    /**
     * The buyer VAT number comes from the billing address VAT id, then the
     * order's customer tax/VAT number; an address VAT id that a VAT check which
     * got an answer marked invalid is no number at all.
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
        $this->composer(ComposeOrder::class, 'ES', ['7' => self::ART20])->execute($order, 'ref', []);
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
            // The adjustment was never placed, so it resolves against the edited Madrid address.
            ['refund', [self::EXPORT, self::ART20, null, self::EXPORT]],
            ['edit', [self::EXPORT, self::ART20, self::EXPORT]],
        ];
    }

    /**
     * Placement recorded no code; a later change that would now derive one is ignored.
     *
     * @dataProvider recordedNoCodePayloads
     */
    public function testARecordedNoCodeStaysNoCode(string $payload, array $expected): void
    {
        $order = $this->order([['simple', 0.0]], ['ES', '28001'], 'ES', true);
        $this->composer(ComposeOrder::class, 'ES', [])->execute($order, 'ref', []);
        $this->save($order);
        $order->shipping = new UnderscoreDataObject(['country_id' => 'US', 'postcode' => '10001']);

        $this->assertSame($expected, $this->codes($this->{$payload}($order, 'ES', [])), $payload);
    }

    public static function recordedNoCodePayloads(): array
    {
        return [
            ['capture', [null, null]],
            ['shipment', [null, null]],
            // The adjustment was never placed, so it resolves against the edited US address.
            ['refund', [null, self::EXPORT, null]],
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
        $create = $this->composer(ComposeOrder::class, 'ES', [])->execute($order, 'ref', []);
        $this->assertSame([self::EXPORT, self::EXPORT, self::EXPORT], $this->codes($create['line_items']));
        $this->assertSame('other_charges', $create['line_items'][2]['order_item_id']);
        $this->save($order);
        $order->shipping = new UnderscoreDataObject(['country_id' => 'ES', 'postcode' => '28001']);

        $this->assertSame($expected, $this->codes($this->{$payload}($order, 'ES', [])), $payload);
    }

    public static function feeLinePayloads(): array
    {
        return [
            ['capture', [self::EXPORT, self::EXPORT, self::EXPORT]],
            ['edit', [self::EXPORT, self::EXPORT, self::EXPORT]],
            // The adjustment stays out of the record and resolves against the edited Madrid address.
            ['refund', [self::EXPORT, null, self::EXPORT, self::EXPORT]],
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
        $create = $this->composer(ComposeOrder::class, 'ES', [], null, $pool)->execute($order, 'ref', []);
        $this->assertSame('other_charges', $create['line_items'][2]['order_item_id']);
        $this->save($order);
        $order->shipping = new UnderscoreDataObject(['country_id' => 'ES', 'postcode' => '28001']);

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
        $create = $this->composer(ComposeOrder::class, 'ES', ['7' => self::ART20])->execute($order, 'ref', []);

        $this->assertSame([self::ART20, self::EXPORT], $this->codes($create['line_items']));
        $this->assertSame(
            ['item:101' => self::ART20, 'item:102' => self::EXPORT],
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
     * A line whose SKU another extension changed matches no item, but its own
     * DIGITAL type still makes it a service in a mixed order.
     */
    public function testAnUnmatchedDigitalLineIsAService(): void
    {
        $order = $this->order([['virtual', 0.0], ['simple', 0.0]], ['FR', '75001'], 'FR 75001 FR12345678901');
        $service = $this->composer(ComposeOrder::class, 'ES', []);
        $lines = $service->getLineItemsOrder($order);
        $lines[0]['details']['barcodes'][0]['value'] = 'CHANGED';

        $this->assertSame([self::SERVICES, self::INTRA], $this->codes($service->applyTaxCodes($lines, $order, true)));
    }

    /**
     * A configurable parent and a bundle child keep the codes recorded under their own quote items.
     */
    public function testConfigurableAndBundleChildLinesKeepTheirRecordedCodes(): void
    {
        $order = $this->order([['configurable', 0.0, true], ['simple', 0.0]], ['US', '10001'], 'US', true);
        $order->itemsById[2]->data['parent_item_id'] = 50;
        $create = $this->composer(ComposeOrder::class, 'ES', ['7' => self::ART20])->execute($order, 'ref', []);
        $this->assertSame([self::ART20, self::EXPORT, self::EXPORT], $this->codes($create['line_items']));
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
        $resolver = new TaxCodeResolver($this->config(['5' => self::ART20]), $this->records('ES'));
        $lines = [['order_item_id' => 1, 'type' => 'OTHER', 'tax_rate' => '0.00']];

        $this->assertSame([self::EXPORT], $this->codes($resolver->apply($lines, $order)));
    }

    /**
     * Placement matches product lines to their items by identity, not position,
     * so a plugin that reorders getLineItemsOrder() cannot swap their classes.
     */
    public function testPlacementMatchesLinesToItemsWhateverTheirOrder(): void
    {
        $order = $this->order([['virtual', 0.0], ['simple', 0.0]], ['US', '10001'], 'US');
        $service = $this->composer(ComposeOrder::class, 'ES', ['7' => self::ART20]);
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
            ['capture', [self::ART20, self::SERVICES, null]],
            ['shipment', [self::ART20, self::SERVICES, null]],
            ['refund', [self::ART20, self::SERVICES, null, null]],
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
        $resolver = new TaxCodeResolver($this->config([]), $this->records('ES'));
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

    private function config(array $map): ConfigRepository
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getTaxCodeMap')->willReturn($map);
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
            $properties['taxCodeResolver'] = new TaxCodeResolver($config, $this->records($merchant));
        }
        foreach ($properties as $name => $value) {
            (new \ReflectionProperty(OrderService::class, $name))->setValue($service, $value);
        }
        $service->configRepository = $config;

        return $service;
    }
}
