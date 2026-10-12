<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order\Doubles;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Framework\Url;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\OrderTenders;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\ComposeRefund;
use Two\Gateway\Service\Order\ComposeShipment;
use Two\Gateway\Service\Order\TenderReader;

/**
 * An order and its documents built by the real composers, with Adobe Commerce
 * tender fields set (TWO-26309): one product, 100.00 net at 21% (121.00), and
 * 29.00 of untaxed shipping, 150.00 of lines; Magento's grand total is the
 * lines less the tenders. Shared by the amount-rules contract test and the
 * staging API test, so both send the same bodies.
 */
trait TenderOrderFixtures
{
    protected static function sum(array $tenders): float
    {
        return round(array_sum($tenders), 2);
    }

    protected function create(Order $order): array
    {
        return $this->composer(ComposeOrder::class, $order)->execute($order, 'ref', []);
    }

    protected function edit(Order $order): array
    {
        $order->setData('id', 7);

        return $this->composer(ComposeOrder::class, $order)->execute($order, 'ref', ['isEdit' => true, 'placedTerms' => null]);
    }

    /** The product invoiced alone, with the shipping, and the tenders Magento took off this first invoice. */
    protected function capture(Order $order, array $tenders): array
    {
        $order->setData('id', 7);
        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setAllItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'qty_ordered' => 1, 'row_total' => 100.00,
            'tax_amount' => 21.00, 'tax_percent' => 21.0, 'name' => 'Item 1', 'sku' => 'SKU1'])]);
        $invoice->setShippingAmount(29.00);
        $invoice->setShippingTaxAmount(0.0);
        $invoice->setShippingInclTax(29.00);
        $invoice->setGrandTotal(150.00 - self::sum($tenders));
        $invoice->setTaxAmount(21.00);
        $invoice->setDiscountAmount(0.0);
        foreach ($tenders as $field => $amount) {
            $invoice->setData($field, $amount);
        }

        return $this->composer(ComposeCapture::class, $order)->execute($invoice);
    }

    /** The first shipment: the product and the shipping, the order's tenders front-loaded on it. */
    protected function shipment(Order $order): array
    {
        $order->setData('id', 7);
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
        $shipment->items[] = $this->item(['order_item_id' => 1, 'qty' => 1, 'name' => 'Item 1', 'sku' => 'SKU1']);

        return $this->composer(ComposeShipment::class, $order)->execute($shipment, $order);
    }

    /** The product refunded, with what the memo returns to the tenders off its grand total. */
    protected function refund(Order $order, array $tenders): array
    {
        $order->setData('id', 7);
        $creditmemo = new Creditmemo();
        $creditmemo->setItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'row_total' => 100.00,
            'tax_amount' => 21.00, 'tax_percent' => 21.0, 'name' => 'Item 1', 'sku' => 'SKU1'])]);
        $creditmemo->setShippingAmount(0.0);
        $creditmemo->setGrandTotal(121.00 - self::sum($tenders));
        $creditmemo->setTaxAmount(21.00);
        foreach ($tenders as $field => $amount) {
            $creditmemo->setData($field, $amount);
        }
        $creditmemo->setOrder($order);

        return $this->composer(ComposeRefund::class, $order)
            ->execute($creditmemo, (float)$creditmemo->getGrandTotal(), $order);
    }

    protected function order(array $tenders): Order
    {
        $order = new class extends Order {
            /** @var array */
            public $itemsById = [];

            public function getItemById($id)
            {
                return $this->itemsById[(int)$id] ?? null;
            }

            public function getAllVisibleItems()
            {
                return array_values($this->itemsById);
            }

            public function getBillingAddress()
            {
                return null;
            }
        };
        $order->itemsById[1] = $this->item([
            'id' => 1, 'item_id' => 1, 'quote_item_id' => 101, 'name' => 'Item 1', 'sku' => 'SKU1',
            'product_type' => 'simple', 'product' => new DataObject(['tax_class_id' => '5']),
            'qty_ordered' => 1, 'row_total' => 100.00, 'tax_amount' => 21.00, 'tax_percent' => 21.0,
            'discount_amount' => 0.0,
        ]);
        $order->setStoreId(1);
        $order->setOrderCurrencyCode('EUR');
        $order->setIncrementId('100000001');
        $order->setShippingDescription('Flat Rate');
        $order->setShippingMethod('flatrate_flatrate');
        $order->setIsVirtual(0);
        $order->setShippingAmount(29.00);
        $order->setShippingTaxAmount(0.0);
        $order->setShippingInclTax(29.00);
        $order->setData('two_shipping_tax_rate_source', OrderService::SHIPPING_RATE_DECLARED);
        $order->setData('two_shipping_tax_rate', 0.0);
        $order->setShipmentsCollection(new class {
            public function getFirstItem()
            {
                return new DataObject(['id' => 5]);
            }
        });
        $order->setGrandTotal(150.00 - self::sum($tenders));
        $order->setTaxAmount(21.00);
        foreach ($tenders as $field => $amount) {
            $order->setData($field, $amount);
        }

        return $order;
    }

    protected function item(array $data): Order\Item
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
     * A real composer with the plugin's own tender reader; only the catalogue
     * lookups and the order's addresses are stubbed.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    protected function composer(string $class, Order $order)
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
        $service->method('getOrderItem')->willReturnCallback(static fn (int $id) => $order->getItemById($id));
        if ($class === ComposeOrder::class) {
            $service->method('getAddress')->willReturn([]);
            $service->method('getBuyer')->willReturn([]);
            $service->url = $this->createMock(Url::class);
            $service->url->method('getUrl')->willReturn('https://example.test/two');
            (new \ReflectionProperty(ComposeOrder::class, 'checkoutSession'))->setValue($service, new CheckoutSession());
        }
        $config = $this->createMock(ConfigRepository::class);
        $config->method('isTaxSubtotalsEnabled')->willReturn(true);
        $config->method('getWeightUnit')->willReturn('kg');
        $config->method('getVendorSiteName')->willReturn('');
        $config->method('getAllBuyerTerms')->willReturn([30]);
        $config->method('isBuyerTermAvailable')->willReturn(true);
        $config->method('getDefaultPaymentTerm')->willReturn(30);
        $config->method('getPaymentTermsType')->willReturn('invoice_date');
        $log = $this->createMock(LogRepository::class);
        foreach ([
            'logRepository' => $log,
            'feeLineProviderPool' => new FeeLineProviderPool([]),
            'tenderReader' => new TenderReader(new OrderTenders(), $log),
        ] as $name => $value) {
            (new \ReflectionProperty(OrderService::class, $name))->setValue($service, $value);
        }
        $service->configRepository = $config;

        return $service;
    }
}
