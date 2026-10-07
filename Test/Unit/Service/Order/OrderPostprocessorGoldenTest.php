<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Url;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Hook;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\ComposeCapture;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\ComposeRefund;
use Two\Gateway\Service\Order\ComposeShipment;
use Two\Gateway\Test\Unit\Service\Order\Doubles\PostprocessorFactory;

/**
 * With no subscriber, what the real composers build is sent unchanged and
 * never refused (TWO-26092), including the residuals the plugin already
 * leaves unitemised: store credit, gift cards and reward points (payment,
 * not taxed lines), and a taxed third-party fee whose rate Magento does not
 * vouch for.
 */
class OrderPostprocessorGoldenTest extends TestCase
{
    use PostprocessorFactory;

    /**
     * @dataProvider goldenCases
     */
    public function testAComposedPayloadIsSentUnchanged(
        string $requestType,
        string $fixture,
        string $residual,
        string $description
    ): void {
        $composed = $this->{$fixture}();
        $block = $composed['partial'] ?? $composed;
        $lines = number_format(array_sum(array_map('floatval', array_column($block['line_items'], 'gross_amount'))), 2, '.', '');
        $this->assertSame(
            $residual,
            number_format((float)($block['gross_amount'] ?? $block['amount']) - (float)$lines, 2, '.', ''),
            "$description: the fixture's residual"
        );

        $sent = $this->buildPostprocessor()->process($requestType, $composed, ['trigger' => 'test', 'endpoint' => 'test']);

        $this->assertSame(json_encode($composed), json_encode($sent), $description);
    }

    public static function goldenCases(): array
    {
        return [
            [Hook::REQUEST_ORDER_CREATE, 'createWithStoreCredit', '-20.00', 'create paid partly with store credit'],
            [Hook::REQUEST_ORDER_UPDATE, 'createWithStoreCredit', '-20.00', 'the same order re-sent on an address edit'],
            [Hook::REQUEST_ORDER_CREATE, 'createWithThirdPartyFee', '12.50', 'create with a taxed third-party fee of unverified rate'],
            [Hook::REQUEST_CAPTURE, 'partialCaptureWithGiftCard', '-10.00', 'partial capture of 1 of 3 units, a gift card share applied'],
            [Hook::REQUEST_CAPTURE, 'partialShipment', '0.00', 'shipment of 1 of 2 discounted units, shipping on the first shipment'],
            [Hook::REQUEST_REFUND, 'proratedRefundWithRewardPoints', '-5.00', 'prorated refund of 1 of 3 units with float tax, reward points returned'],
            [Hook::REQUEST_REFUND, 'refundWithAdjustmentsAndFee', '12.50', 'refund with adjustment lines and a taxed third-party fee'],
            [Hook::REQUEST_CAPTURE, 'captureOfUnreconciledLine', '0.00', 'capture of an order whose line tax does not follow its rate, placed before the line tax gate'],
            [Hook::REQUEST_REFUND, 'refundOfUnreconciledLine', '0.00', 'refund of that order'],
        ];
    }

    private function createWithStoreCredit(): array
    {
        $order = $this->order([
            [1, 1, 100.00, 25.00, 25.0, 0.0],
            [2, 1, 40.00, 4.80, 12.0, 0.0],
        ], 149.80, 29.80);

        return $this->composeOrder()->execute($order, 'ref', []);
    }

    private function createWithThirdPartyFee(): array
    {
        $order = $this->order([[1, 1, 100.00, 25.00, 25.0, 0.0]], 137.50, 27.50);

        return $this->composeOrder()->execute($order, 'ref', []);
    }

    private function partialCaptureWithGiftCard(): array
    {
        $order = $this->order([[1, 3, 99.99, 25.00, 25.0, 0.0]], 124.99, 25.00);
        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setAllItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'qty_ordered' => 3, 'row_total' => 33.33, 'tax_amount' => 8.33, 'name' => 'Widget', 'sku' => 'W'])]);
        $invoice->setGrandTotal(31.66);
        $invoice->setTaxAmount(8.33);
        $invoice->setDiscountAmount(0.0);

        return ['partial' => $this->composer(ComposeCapture::class)->execute($invoice)];
    }

    private function partialShipment(): array
    {
        $order = $this->order([[1, 2, 200.00, 50.00, 25.0, 20.0]], 238.00, 50.00, [8.00, 0.00]);
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
        $shipment->items = [$this->item(['order_item_id' => 1, 'qty' => 1, 'name' => 'Widget', 'sku' => 'W'])];

        return $this->composer(ComposeShipment::class, $order)->execute($shipment, $order);
    }

    private function proratedRefundWithRewardPoints(): array
    {
        $order = $this->order([[1, 3, 100.00, 25.00, 25.0, 0.0]], 125.00, 25.00);
        $creditmemo = new Creditmemo();
        $creditmemo->setItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'row_total' => 33.33, 'name' => 'Widget', 'sku' => 'W'])]);
        $creditmemo->setGrandTotal(36.67);
        $creditmemo->setTaxAmount(8.34);
        $creditmemo->setOrder($order);

        return $this->composer(ComposeRefund::class, $order)->execute($creditmemo, 36.67, $order);
    }

    private function refundWithAdjustmentsAndFee(): array
    {
        $order = $this->order([[1, 1, 100.00, 25.00, 25.0, 0.0]], 137.50, 27.50);
        $creditmemo = new Creditmemo();
        $creditmemo->setItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'row_total' => 100.00, 'name' => 'Widget', 'sku' => 'W'])]);
        $creditmemo->setAdjustmentPositive(5.00);
        $creditmemo->setAdjustmentNegative(2.00);
        $creditmemo->setGrandTotal(140.50);
        $creditmemo->setTaxAmount(27.50);
        $creditmemo->setOrder($order);

        return $this->composer(ComposeRefund::class, $order)->execute($creditmemo, 140.50, $order);
    }

    private function captureOfUnreconciledLine(): array
    {
        $order = $this->order([[1, 1, 100.00, 26.00, 25.0, 0.0]], 126.00, 26.00);
        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setAllItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'qty_ordered' => 1, 'row_total' => 100.00, 'tax_amount' => 26.00, 'name' => 'Widget', 'sku' => 'W'])]);
        $invoice->setGrandTotal(126.00);
        $invoice->setTaxAmount(26.00);
        $invoice->setDiscountAmount(0.0);

        return ['partial' => $this->composer(ComposeCapture::class)->execute($invoice)];
    }

    private function refundOfUnreconciledLine(): array
    {
        $order = $this->order([[1, 1, 100.00, 26.00, 25.0, 0.0]], 126.00, 26.00);
        $creditmemo = new Creditmemo();
        $creditmemo->setItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'row_total' => 100.00, 'name' => 'Widget', 'sku' => 'W'])]);
        $creditmemo->setGrandTotal(126.00);
        $creditmemo->setTaxAmount(26.00);
        $creditmemo->setOrder($order);

        return $this->composer(ComposeRefund::class, $order)->execute($creditmemo, 126.00, $order);
    }

    /**
     * @param array $items [id, qty, row total, tax, tax percent, discount] per item
     * @param float $grandTotal
     * @param float $taxTotal
     * @param array|null $shipping [net, tax], shipped on shipment 5
     * @return Order
     */
    private function order(array $items, float $grandTotal, float $taxTotal, ?array $shipping = null): Order
    {
        $order = new class extends Order {
            /** @var array */
            public $itemsById = [];

            public function getItemById($id)
            {
                return $this->itemsById[$id];
            }

            public function getAllVisibleItems()
            {
                return array_values($this->itemsById);
            }
        };
        foreach ($items as [$id, $qty, $rowTotal, $tax, $percent, $discount]) {
            $order->itemsById[$id] = $this->item([
                'id' => $id,
                'item_id' => $id,
                'name' => "Item $id",
                'sku' => "SKU$id",
                'qty_ordered' => $qty,
                'row_total' => $rowTotal,
                'tax_amount' => $tax,
                'tax_percent' => $percent,
                'discount_amount' => $discount,
            ]);
        }
        $order->setStoreId(1);
        $order->setGrandTotal($grandTotal);
        $order->setTaxAmount($taxTotal);
        $order->setOrderCurrencyCode('EUR');
        $order->setIncrementId('100000001');
        $order->setShippingDescription('Flat Rate');
        $order->setShippingMethod('flatrate_flatrate');
        $order->setIsVirtual($shipping === null ? 1 : 0);
        if ($shipping !== null) {
            $order->setShippingAmount($shipping[0]);
            $order->setShippingTaxAmount($shipping[1]);
            $order->setShippingInclTax($shipping[0] + $shipping[1]);
            $order->setShipmentsCollection(new class {
                public function getFirstItem()
                {
                    return new \Magento\Framework\DataObject(['id' => 5]);
                }
            });
        }

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

    private function composeOrder(): ComposeOrder
    {
        $service = $this->composer(ComposeOrder::class, null, ['getAddress', 'getBuyer']);
        $service->method('getAddress')->willReturn([]);
        $service->method('getBuyer')->willReturn([]);
        $service->url = $this->createMock(Url::class);
        $service->url->method('getUrl')->willReturn('https://example.test/two');
        (new \ReflectionProperty(ComposeOrder::class, 'checkoutSession'))->setValue($service, new CheckoutSession());

        return $service;
    }

    /**
     * A real composer; only the catalogue and repository lookups are stubbed.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function composer(string $class, ?Order $order = null, array $extraStubs = [])
    {
        $service = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_merge(['getProduct', 'getProductImageUrl', 'getCategories', 'getOrderItem'], $extraStubs))
            ->getMock();
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
        foreach ([
            'logRepository' => $this->createMock(LogRepository::class),
            'feeLineProviderPool' => new FeeLineProviderPool([]),
        ] as $name => $value) {
            (new \ReflectionProperty(OrderService::class, $name))->setValue($service, $value);
        }

        $config = $this->createMock(ConfigRepository::class);
        $config->method('isTaxSubtotalsEnabled')->willReturn(true);
        $config->method('getWeightUnit')->willReturn('kg');
        $config->method('getVendorSiteName')->willReturn('');
        $config->method('getAllBuyerTerms')->willReturn([30]);
        $config->method('isBuyerTermAvailable')->willReturn(true);
        $config->method('getDefaultPaymentTerm')->willReturn(30);
        $config->method('getPaymentTermsType')->willReturn('invoice_date');
        $service->configRepository = $config;

        return $service;
    }
}
