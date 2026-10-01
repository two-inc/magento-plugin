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
    private const INTRA = 'ES_IVA_INTRA_COMMUNITY_GOODS';
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
     * @param string $billing buyer (billing) country, optionally followed by a space and the billing postcode
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
            ['ES', [], $goods, ['DE', '10115'], 'FR', [self::INTRA], 'goods to another EU state, buyer in another EU state'],
            ['ES', [], $goods, ['MC', '98000'], 'MC', [self::INTRA], 'goods to Monaco, which counts as France'],
            ['ES', [], $goods, ['FR', '75001'], 'ES', [null], 'goods to another EU state, Spanish buyer'],
            ['ES', [], $goods, ['ES', '28001'], 'ES', [null], 'domestic goods'],
            ['ES', [], $goods, ['ES', '07001'], 'ES', [null], 'goods to the Balearics'],
            ['ES', [], $goods, ['ES', '28001'], 'FR', [null], 'goods delivered in Spain to a French buyer'],
            ['ES', [], $service, null, 'DE', [self::SERVICES], 'service to a buyer in another EU state'],
            ['ES', [], [['downloadable', 0.0]], null, 'FR', [self::SERVICES], 'download to a buyer in another EU state'],
            ['ES', [], [['bundle', 0.0, true]], null, 'FR', [self::SERVICES], 'a bundle with nothing to ship is a service'],
            ['ES', [], [['bundle', 0.0, false]], ['FR', '75001'], 'FR', [self::INTRA], 'a bundle that ships is goods'],
            ['ES', [], [['configurable', 0.0, true]], ['ES', '28001'], 'DE', [self::SERVICES], 'a configurable with a virtual child is a service'],
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
            ['ES', [], $service, ['US', '10001'], 'DE', [self::SERVICES, self::SERVICES], 'shipping follows services', true],
            ['ES', [], $mixed, ['ES', '28001'], 'DE', [self::SERVICES, null, null], 'a mixed order: service by buyer, goods and shipping by delivery', true],
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
        $order = $this->order([['virtual', 0.0], ['simple', 0.0]], ['FR', '75001'], 'FR');
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
        $order = $this->order([['simple', 0.0], ['virtual', 0.0]], ['ES', '28001'], 'DE', true);
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
     * @param string $billing billing country, optionally followed by a space and the billing postcode
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
        [$billingCountry, $billingPostcode] = array_pad(explode(' ', $billing, 2), 2, '00000');
        $order->billing = new UnderscoreDataObject(['country_id' => $billingCountry, 'postcode' => $billingPostcode]);
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
