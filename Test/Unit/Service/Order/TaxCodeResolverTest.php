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
 * 9 the shipping tax class.
 */
class TaxCodeResolverTest extends TestCase
{
    private const EXPORT = 'ES_IVA_EXPORT';
    private const INTRA = 'ES_IVA_INTRA_COMMUNITY';
    private const REVERSE = 'ES_IVA_REVERSE_CHARGE';

    /** @var bool whether the composers get a resolver; false composes as before TWO-24877 */
    private $withResolver = true;

    /**
     * @dataProvider createCases
     * @param array $products [product type, tax percent] per line
     * @param array|null $shipping [delivery country, postcode], null for no delivery address
     * @param string $billing buyer (billing) country
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
        foreach ([[], ['isEdit' => true, 'placedTerms' => null]] as $additionalData) {
            $payload = $this->composer(ComposeOrder::class, $merchant, $map)->execute($order, 'ref', $additionalData);
            $this->assertSame($expected, $this->codes($payload['line_items']), $description);
        }
    }

    public static function createCases(): array
    {
        $goods = [['simple', 0.0]];
        $service = [['virtual', 0.0]];
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
            ['ES', [], $service, null, 'DE', [self::REVERSE], 'service to a buyer in another EU state'],
            ['ES', [], [['downloadable', 0.0]], null, 'FR', [self::REVERSE], 'download to a buyer in another EU state'],
            ['ES', [], $service, null, 'ES', [null], 'service to a Spanish buyer'],
            ['ES', [], $service, null, 'NO', [null], 'service to a buyer outside the EU'],
            ['ES', [], $goods, ['US', '10001'], 'US', [self::EXPORT, self::EXPORT], 'shipping follows goods', true],
            ['ES', [], $service, ['US', '10001'], 'DE', [self::REVERSE, self::REVERSE], 'shipping follows services', true],
            ['ES', [], [['virtual', 0.0], ['simple', 0.0]], ['ES', '28001'], 'DE', [self::REVERSE, null, null], 'shipping in a mixed order follows the goods', true],
            ['ES', ['5' => 'ES_IVA_EXEMPT_ART20'], $goods, ['US', '10001'], 'US', ['ES_IVA_EXEMPT_ART20'], 'the mapping beats the derivation'],
            ['ES', ['5' => 'ES_IVA_EXEMPT_ART20'], $goods, ['ES', '28001'], 'ES', ['ES_IVA_EXEMPT_ART20'], 'the mapping covers a line nothing derives'],
            ['ES', ['9' => 'ES_IVA_EXEMPT_ART22'], $goods, ['US', '10001'], 'US', [self::EXPORT, 'ES_IVA_EXEMPT_ART22'], 'the shipping tax class maps the shipping line', true],
            ['NO', [], $goods, ['US', '10001'], 'US', [null], 'a non-Spanish merchant with no mapping'],
            ['DE', ['5' => 'DE_ZERO'], $goods, ['DE', '10115'], 'DE', ['DE_ZERO'], 'a non-Spanish merchant with a mapping'],
            ['ES', ['5' => 'ES_IVA_EXEMPT_ART20'], [['simple', 21.0]], ['US', '10001'], 'US', [null], 'a mapped line at 21%'],
            ['ES', [], [['simple', 21.0], ['simple', 0.0]], ['US', '10001'], 'US', [null, self::EXPORT], 'only the 0% line of two'],
        ];
    }

    /**
     * Fulfil (partial capture and partial shipment) and refund carry the same code as the create.
     *
     * @dataProvider laterPayloadCases
     */
    public function testLaterPayloadsCarryTheCode(string $payload, array $expected, string $description): void
    {
        $order = $this->order([['simple', 0.0]], ['US', '10001'], 'US', true);

        $this->assertSame($expected, $this->codes($this->{$payload}($order, 'ES')), $description);
    }

    public static function laterPayloadCases(): array
    {
        return [
            ['capture', [self::EXPORT, self::EXPORT], 'partial capture, with shipping'],
            ['shipment', [self::EXPORT, self::EXPORT], 'partial shipment, with shipping on the first'],
            ['refund', [self::EXPORT, self::EXPORT, self::EXPORT], 'refund of a line, the adjustment and shipping'],
        ];
    }

    /**
     * Non-zero lines, and a non-Spanish merchant with no mapping, compose byte for byte as before.
     *
     * @dataProvider unchangedCases
     */
    public function testPayloadIsByteIdenticalToBefore(string $merchant, float $percent, string $description): void
    {
        $order = $this->order([['simple', $percent]], ['US', '10001'], 'US', true);
        foreach (['create', 'capture', 'shipment', 'refund'] as $payload) {
            $this->withResolver = true;
            $with = json_encode($this->{$payload}($order, $merchant));
            $this->withResolver = false;
            $without = json_encode($this->{$payload}($order, $merchant));

            $this->assertSame($without, $with, "$description: $payload");
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
        $order = $this->order([['simple', 21.0], ['virtual', 10.0]], ['US', '10001'], 'DE');
        $order->setShippingAmount(10.00);
        $order->setShippingTaxAmount(2.10);
        $order->setData('two_shipping_tax_rate_source', OrderService::SHIPPING_RATE_DECLARED);
        $order->setData('two_shipping_tax_rate', 21.0);

        $this->withResolver = true;
        $with = json_encode($this->create($order, 'ES'));
        $this->withResolver = false;

        $this->assertSame(json_encode($this->create($order, 'ES')), $with);
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

    private function create(Order $order, string $merchant): array
    {
        return $this->composer(ComposeOrder::class, $merchant, [])->execute($order, 'ref', []);
    }

    private function capture(Order $order, string $merchant): array
    {
        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setAllItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'qty_ordered' => 2, 'row_total' => 50.00, 'tax_amount' => 0.0, 'name' => 'Item 1', 'sku' => 'SKU1'])]);
        $invoice->setShippingAmount(10.00);
        $invoice->setShippingTaxAmount(0.0);
        $invoice->setShippingInclTax(10.00);
        $invoice->setGrandTotal(60.00);
        $invoice->setTaxAmount(0.0);
        $invoice->setDiscountAmount(0.0);

        return $this->composer(ComposeCapture::class, $merchant, [])->execute($invoice)['line_items'];
    }

    private function shipment(Order $order, string $merchant): array
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
        $shipment->items = [$this->item(['order_item_id' => 1, 'qty' => 1, 'name' => 'Item 1', 'sku' => 'SKU1'])];

        return $this->composer(ComposeShipment::class, $merchant, [], $order)->execute($shipment, $order)['line_items'];
    }

    private function refund(Order $order, string $merchant): array
    {
        $creditmemo = new Creditmemo();
        $creditmemo->setItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'row_total' => 50.00, 'name' => 'Item 1', 'sku' => 'SKU1'])]);
        $creditmemo->setAdjustmentPositive(5.00);
        $creditmemo->setShippingAmount(10.00);
        $creditmemo->setShippingInclTax(10.00);
        $creditmemo->setShippingTaxAmount(0.0);
        $creditmemo->setGrandTotal(65.00);
        $creditmemo->setTaxAmount(0.0);
        $creditmemo->setOrder($order);

        return $this->composer(ComposeRefund::class, $merchant, [], $order)->execute($creditmemo, 65.00, $order)['line_items'];
    }

    private function codes(array $lines): array
    {
        return array_map(static fn (array $line) => $line['tax_code'] ?? null, array_values($lines));
    }

    /**
     * @param array $products [product type, tax percent] per item, 50.00 net each, quantity 2
     * @param array|null $shipping [country, postcode] of the delivery address; null for none
     * @param string $billing billing country
     * @param bool $withShipping whether the order charges 10.00 shipping at 0%
     */
    private function order(array $products, ?array $shipping, string $billing, bool $withShipping = false): Order
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
                return $this->itemsById[$id] ?? null;
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
        foreach (array_values($products) as $i => [$type, $percent]) {
            $id = $i + 1;
            $tax = round(100.00 * $percent / 100, 2);
            $order->itemsById[$id] = $this->item([
                'id' => $id,
                'item_id' => $id,
                'name' => "Item $id",
                'sku' => "SKU$id",
                'product_type' => $type,
                'product' => new DataObject(['tax_class_id' => $type === 'simple' ? '5' : '7']),
                'qty_ordered' => 2,
                'row_total' => 100.00,
                'tax_amount' => $tax,
                'tax_percent' => $percent,
                'discount_amount' => 0.0,
            ]);
            $grand += 100.00 + $tax;
            $taxTotal += $tax;
        }
        $order->billing = new UnderscoreDataObject(['country_id' => $billing, 'postcode' => '00000']);
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
        $order->setGrandTotal($grand);
        $order->setTaxAmount($taxTotal);

        return $order;
    }

    private function item(array $data): Order\Item
    {
        return new class ($data) extends Order\Item implements \Magento\Sales\Api\Data\OrderItemInterface {
            /** @var array */
            private $data;

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
        $config->method('getShippingTaxClassId')->willReturn(9);
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
    private function composer(string $class, string $merchant, array $map, ?Order $order = null)
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
            'feeLineProviderPool' => new FeeLineProviderPool([]),
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
