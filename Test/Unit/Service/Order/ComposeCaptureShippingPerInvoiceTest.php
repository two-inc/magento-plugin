<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order\ComposeCapture;

/**
 * TWO-26091: Magento invoices shipping once, on whichever invoice is created
 * first after the order (see Invoice\Total\Shipping). The capture's shipping
 * line must come from the invoice, not the order, or every later partial
 * capture counts shipping again in its lines and tax_subtotals.
 *
 * Drives the real execute() path. Every row checks that the payload totals
 * equal the sum of its lines and of its tax_subtotals, with no residual
 * other_charges line.
 */
class ComposeCaptureShippingPerInvoiceTest extends TestCase
{
    private const ORDER_SHIPPING = [10.00, 2.50];

    public static function captureProvider(): array
    {
        // [invoice lines [net, tax, rate %], invoice shipping [net, tax], expected shipping line, tax_subtotals count, description]
        return [
            [[[100.00, 25.00, 25]], [10.00, 2.50], ['12.50', '10.00', '2.50'], 1, 'first partial capture with shipping'],
            [[[100.00, 25.00, 25]], [0.00, 0.00], null, 1, 'second partial capture (no shipping)'],
            [[[100.00, 25.00, 25], [50.00, 12.50, 25]], [10.00, 2.50], ['12.50', '10.00', '2.50'], 1, 'full capture'],
            [[[100.00, 25.00, 25], [100.00, 12.00, 12]], [10.00, 2.50], ['12.50', '10.00', '2.50'], 2, 'multi-rate lines plus shipping'],
            [[], [10.00, 2.50], ['12.50', '10.00', '2.50'], 1, 'shipping-only invoice (shipping on a later invoice)'],
        ];
    }

    /**
     * @dataProvider captureProvider
     */
    public function testShippingLineFollowsTheInvoice(
        array $lines,
        array $invoiceShipping,
        ?array $expectedShipping,
        int $subtotalCount,
        string $description
    ): void {
        $payload = $this->composeCapture()->execute($this->invoice($lines, $invoiceShipping));

        $shippingLines = array_values(array_filter(
            $payload['line_items'],
            static fn(array $line): bool => $line['order_item_id'] === 'shipping'
        ));
        if ($expectedShipping === null) {
            $this->assertSame([], $shippingLines, "$description: shipping line sent");
        } else {
            $this->assertCount(1, $shippingLines, "$description: shipping line count");
            $actual = [
                $shippingLines[0]['gross_amount'],
                $shippingLines[0]['net_amount'],
                $shippingLines[0]['tax_amount'],
            ];
            $this->assertSame($expectedShipping, $actual, "$description: shipping gross/net/tax");
            $this->assertSame('0.250000', $shippingLines[0]['tax_rate'], "$description: shipping rate");
        }

        $this->assertNotContains(
            'other_charges',
            array_column($payload['line_items'], 'order_item_id'),
            "$description: residual reconciled as other_charges"
        );
        foreach (['gross_amount', 'net_amount', 'tax_amount'] as $key) {
            $this->assertSame($payload[$key], $this->sum($payload['line_items'], $key), "$description: $key vs lines");
        }

        $subtotals = $payload['tax_subtotals'];
        $this->assertCount($subtotalCount, $subtotals, "$description: tax_subtotals rates");
        $this->assertSame(
            $this->sum($payload['line_items'], 'net_amount'),
            $this->sum($subtotals, 'taxable_amount'),
            "$description: taxable_amount vs line nets"
        );
        $this->assertSame($payload['tax_amount'], $this->sum($subtotals, 'tax_amount'), "$description: subtotal tax");
    }

    private function sum(array $rows, string $key): string
    {
        return number_format(array_sum(array_map('floatval', array_column($rows, $key))), 2, '.', '');
    }

    private function invoice(array $lines, array $invoiceShipping): Invoice
    {
        $order = new class extends Order {
            /** @var array */
            public $itemsById = [];

            public function getItemById($id)
            {
                return $this->itemsById[$id];
            }
        };
        $order->setStoreId(1);
        $order->setShippingMethod('flatrate_flatrate');
        [$orderShipping, $orderShippingTax] = self::ORDER_SHIPPING;
        $order->setShippingAmount($orderShipping);
        $order->setShippingTaxAmount($orderShippingTax);

        $items = [];
        $grand = $invoiceShipping[0] + $invoiceShipping[1];
        $tax = $invoiceShipping[1];
        foreach ($lines as $i => [$net, $lineTax, $ratePercent]) {
            $item = $this->item([
                'order_item_id' => $i + 1,
                'name' => "Item $i",
                'sku' => "SKU$i",
                'qty' => 1,
                'qty_ordered' => 1,
                'row_total' => $net,
                'tax_amount' => $lineTax,
                'tax_percent' => $ratePercent,
            ]);
            $order->itemsById[$i + 1] = $item;
            $items[] = $item;
            $grand += $net + $lineTax;
            $tax += $lineTax;
        }

        $invoice = new Invoice();
        $invoice->setOrder($order);
        $invoice->setStoreId(1);
        $invoice->setAllItems($items);
        $invoice->setShippingAmount($invoiceShipping[0]);
        $invoice->setShippingTaxAmount($invoiceShipping[1]);
        $invoice->setShippingInclTax($invoiceShipping[0] + $invoiceShipping[1]);
        $invoice->setGrandTotal($grand);
        $invoice->setTaxAmount($tax);
        $invoice->setDiscountAmount(0.0);

        return $invoice;
    }

    private function item(array $data): Order\Item
    {
        return new class ($data) extends Order\Item {
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

    /**
     * @return ComposeCapture|\PHPUnit\Framework\MockObject\MockObject
     */
    private function composeCapture()
    {
        $service = $this->getMockBuilder(ComposeCapture::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getProductImageUrl', 'getCategories'])
            ->getMock();
        $service->method('getProduct')->willReturn(new class extends Product {
            public function getCategoryIds()
            {
                return [];
            }
        });
        $service->method('getProductImageUrl')->willReturn('');
        $service->method('getCategories')->willReturn([]);

        $taxCalculation = $this->createMock(\Magento\Tax\Model\Calculation::class);
        $taxCalculation->method('getRateRequest')->willReturn(new \Magento\Framework\DataObject());
        $taxCalculation->method('getRate')->willReturn(25.0);
        foreach (['logRepository' => $this->createMock(LogRepository::class),
                     'feeLineProviderPool' => new FeeLineProviderPool([]),
                     'taxCalculation' => $taxCalculation,
                     'groupRepository' => $this->createMock(\Magento\Customer\Api\GroupRepositoryInterface::class),
                 ] as $name => $value) {
            (new \ReflectionProperty(\Two\Gateway\Service\Order::class, $name))->setValue($service, $value);
        }

        $config = $this->createMock(ConfigRepository::class);
        $config->method('isTaxSubtotalsEnabled')->willReturn(true);
        $config->method('getWeightUnit')->willReturn('kg');
        // Resolves the shipping rate through the shipping tax fallback and core's shipping class (TWO-26073).
        $config->method('isShippingTaxFallbackEnabled')->willReturn(true);
        $config->method('getShippingTaxClassId')->willReturn(2);
        $service->configRepository = $config;

        return $service;
    }
}
