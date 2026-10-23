<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\ComposeRefund;

/**
 * TWO-26638: a refund line goes to Two with its net and its tax together. A
 * line whose net rounds to 0 while its tax does not is refused, and so is a
 * line with net and no tax whose tax due, abs(net) x rate rounded to the cent,
 * is more than 0.02, as Two's refund validation compares it. A 0% line and a
 * line within that go out unchanged; the plugin never fills the tax in.
 */
class ComposeRefundNetAndTaxTest extends TestCase
{
    private const TAX_ONLY = '"%s" cannot be refunded as tax alone: Two refunds a line\'s tax only together'
        . ' with its net amount. To correct the tax charged, refund the whole order and rebook it with the'
        . ' correct tax rates.';
    private const NET_ONLY = '"%s" has no tax entered: Two refunds a line\'s net amount only together with its'
        . ' tax.';

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $log;

    /**
     * @dataProvider lines
     * @param string|float $tax product lines carry a float, the others a 2dp string
     */
    public function testARefundLineIsSentWithItsNetAndTaxTogether(
        string $type,
        string $name,
        string $net,
        $tax,
        string $rate,
        ?string $refusal,
        string $description
    ): void {
        $service = $this->composeRefund(false);
        $order = $this->order();
        $line = [
            'name' => $name,
            'type' => $type,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'tax_rate' => $rate,
        ];
        $this->log->expects($refusal ? $this->once() : $this->never())
            ->method('addErrorLog')
            ->with('RefundLineRefused', $this->callback(function ($message) use ($refusal, $name) {
                return strpos($message, '(' . $refusal . ' only)') !== false
                    && strpos($message, '"' . $name . '"') !== false
                    && strpos($message, 'order 000000042') !== false;
            }));

        try {
            $service->assertLinesCarryNetAndTax([$line], $order);
            $this->assertNull($refusal, $description);
        } catch (LocalizedException $e) {
            $this->assertNotNull($refusal, $description . ': ' . $e->getMessage());
            $this->assertSame(
                sprintf($refusal === 'tax' ? self::TAX_ONLY : self::NET_ONLY, $name),
                $e->getMessage(),
                $description
            );
        }
    }

    public static function lines(): array
    {
        $ship = 'Shipping - Flat Rate';
        return [
            ['PHYSICAL', 'Widget', '10.00', 2.1, '0.210000', null, 'product line with net and tax'],
            ['PHYSICAL', 'Widget', '0.00', 2.1, '0.210000', 'tax', 'product line, tax only'],
            ['PHYSICAL', 'Widget', '10.00', 0.0, '0.210000', 'net', 'product line, net only at 21%'],
            ['PHYSICAL', 'Widget', '10.00', 0.0, '0.000000', null, 'product line at 0%'],
            ['PHYSICAL', 'Widget', '0.004', 0.01, '0.210000', 'tax', 'net under half a cent reads as 0'],
            ['SHIPPING_FEE', $ship, '10.00', '2.50', '0.250000', null, 'shipping with net and tax'],
            ['SHIPPING_FEE', $ship, '0.00', '2.50', '0.250000', 'tax', 'shipping, tax only'],
            ['SHIPPING_FEE', $ship, '10.00', '0.00', '0.250000', 'net', 'shipping, net only at 25%'],
            ['SHIPPING_FEE', $ship, '10.00', '0.00', '0.000000', null, 'shipping at 0%'],
            ['BUYER_FEE', 'Payment terms fee', '4.00', '1.00', '0.250000', null, 'surcharge with net and tax'],
            ['BUYER_FEE', 'Payment terms fee', '0.00', '1.00', '0.250000', 'tax', 'surcharge, tax only'],
            ['BUYER_FEE', 'Payment terms fee', '4.00', '0.00', '0.250000', 'net', 'surcharge, net only at 25%'],
            ['OTHER', 'Adjustment', '5.00', '0.00', '0.00', null, 'adjustment refund goes at 0%: exempt'],
            ['OTHER', 'Adjustment', '-5.00', '0.00', '0.00', null, 'adjustment fee goes at 0%: exempt'],
            ['OTHER', 'Other charges', '3.00', '0.00', '0.000000', null, 'untaxed residual at 0%'],
            ['OTHER', 'Other charges', '0.00', '0.75', '0.250000', 'tax', 'residual, tax only'],
            ['OTHER', 'Other charges', '3.00', '0.00', '0.250000', 'net', 'residual, net only at 25%'],
            ['PHYSICAL', 'Widget', '0.01', 0.0, '0.210000', null, 'tax due 0.0021, within 0.02: unchanged'],
            ['PHYSICAL', 'Widget', '0.03', 0.0, '0.210000', null, 'tax due 0.0063, within 0.02: unchanged'],
            ['PHYSICAL', 'Widget', '0.09', 0.0, '0.210000', null, 'tax due 0.0189 rounds to 0.02: unchanged'],
            ['PHYSICAL', 'Widget', '0.08', 0.0, '0.250000', null, 'tax due exactly 0.02: unchanged'],
            ['PHYSICAL', 'Widget', '0.10', 0.0, '0.210000', null, 'tax due 0.021 rounds to 0.02: unchanged'],
            ['PHYSICAL', 'Widget', '0.20', 0.0, '0.100000', null, 'tax due 0.02 at 10%: unchanged'],
            // PHP rounds half up, so 0.025 is 0.03 and over the tolerance.
            ['PHYSICAL', 'Widget', '0.10', 0.0, '0.250000', 'net', 'tax due 0.025 rounds to 0.03: refused'],
            ['PHYSICAL', 'Widget', '0.12', 0.0, '0.250000', 'net', 'tax due 0.03: refused'],
            ['OTHER', 'Adjustment', '-10.00', '0.00', '0.250000', 'net', 'a negative net is measured by size'],
            ['PHYSICAL', 'Widget', '10.00', 1.0, '0.250000', null, 'mismatched non-zero tax: out of scope'],
            ['PHYSICAL', 'Widget', '0.00', 0.0, '0.210000', null, 'nothing refunded on the line'],
        ];
    }

    public function testTheFirstRefusedLineIsNamedAndNothingIsSent(): void
    {
        $service = $this->composeRefund(false);
        $this->log->expects($this->once())->method('addErrorLog');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(sprintf(self::NET_ONLY, 'Shipping - Flat Rate'));
        $service->assertLinesCarryNetAndTax([
            ['name' => 'Widget', 'net_amount' => '10.00', 'tax_amount' => 2.1, 'tax_rate' => '0.210000'],
            ['name' => 'Shipping - Flat Rate', 'net_amount' => '5.00', 'tax_amount' => '0.00', 'tax_rate' => '0.25'],
            ['name' => 'Payment terms fee', 'net_amount' => '0.00', 'tax_amount' => '1.00', 'tax_rate' => '0.25'],
        ], $this->order());
    }

    /**
     * The guard runs inside execute(), on the lines the credit memo composes:
     * shipping refunded with no tax on an order whose shipping rate is 25%.
     */
    public function testExecuteRefusesANetOnlyShippingLine(): void
    {
        $order = $this->order();
        $creditmemo = $this->creditmemo($order, 10.00, 0.00, 0.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(sprintf(self::NET_ONLY, 'Shipping - Flat Rate'));
        $this->composeRefund(true)->execute($creditmemo, 10.00, $order);
    }

    /**
     * A partial quantity scales the order item's net and gross, so the line
     * keeps its tax in proportion, and a cheap line whose tax rounds away
     * stays within the tolerance.
     *
     * @dataProvider partialQuantities
     */
    public function testExecuteSendsAPartialQuantityItem(
        float $rowTotal,
        float $discount,
        float $tax,
        string $net,
        string $lineTax,
        string $description
    ): void {
        $order = $this->order([
            'item_id' => 1,
            'name' => 'Widget',
            'sku' => 'W',
            'qty_ordered' => 3,
            'row_total' => $rowTotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'tax_percent' => 21.0,
        ]);
        $creditmemo = $this->creditmemo($order, 0.0, 0.0, 0.0);
        $creditmemo->setItems([$this->item(['order_item_id' => 1, 'qty' => 1, 'row_total' => $rowTotal / 3,
            'name' => 'Widget', 'sku' => 'W'])]);
        $gross = (float)$net + (float)$lineTax;
        $creditmemo->setGrandTotal($gross);
        $creditmemo->setTaxAmount((float)$lineTax);

        $payload = $this->composeRefund(false, $order)->execute($creditmemo, $gross, $order);

        $line = $payload['line_items'][0];
        $this->assertCount(1, $payload['line_items'], $description);
        $this->assertSame($net, $line['net_amount'], $description);
        $this->assertEqualsWithDelta((float)$lineTax, (float)$line['tax_amount'], 0.001, $description);
        $this->assertSame('0.210000', $line['tax_rate'], $description);
    }

    public static function partialQuantities(): array
    {
        return [
            [30.00, 0.0, 6.30, '10.00', '2.10', 'one of three at 10.00 and 21%: net and tax scaled together'],
            [0.09, 0.01, 0.02, '0.03', '0.00', 'one of three cheap items: tax due 0.0063 is within 0.02'],
        ];
    }

    public function testExecuteSendsAnAdjustmentAtZeroPercent(): void
    {
        $order = $this->order();
        $creditmemo = $this->creditmemo($order, 0.0, 0.0, 5.00);

        $payload = $this->composeRefund(false)->execute($creditmemo, 5.00, $order);

        $this->assertSame('Adjustment', $payload['line_items'][0]['name']);
        $this->assertSame('5.00', $payload['line_items'][0]['net_amount']);
    }

    private function order(array $item = []): Order
    {
        $order = new class extends Order {
            /** @var array */
            public $itemsById = [];

            public function getItemById($id)
            {
                return $this->itemsById[$id];
            }
        };
        if ($item) {
            $order->itemsById[$item['item_id']] = $this->item($item + ['id' => $item['item_id']]);
        }
        $order->setStoreId(1);
        $order->setIncrementId('000000042');
        $order->setOrderCurrencyCode('EUR');
        $order->setShippingDescription('Flat Rate');
        $order->setIsVirtual(0);
        return $order;
    }

    private function creditmemo(Order $order, float $shipping, float $shippingTax, float $adjustment): Creditmemo
    {
        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setStoreId(1);
        $creditmemo->setItems([]);
        $creditmemo->setShippingAmount($shipping);
        $creditmemo->setShippingTaxAmount($shippingTax);
        $creditmemo->setAdjustmentPositive($adjustment);
        $creditmemo->setGrandTotal($shipping + $shippingTax + $adjustment);
        $creditmemo->setTaxAmount($shippingTax);
        return $creditmemo;
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

    /**
     * @return ComposeRefund|\PHPUnit\Framework\MockObject\MockObject
     */
    private function composeRefund(bool $shippingFallback, ?Order $order = null)
    {
        $service = $this->getMockBuilder(ComposeRefund::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getProductImageUrl', 'getCategories', 'getOrderItem'])
            ->getMock();
        $service->method('getProduct')->willReturn(new class extends \Magento\Catalog\Model\Product {
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

        $taxCalculation = $this->createMock(\Magento\Tax\Model\Calculation::class);
        $taxCalculation->method('getRateRequest')->willReturn(new \Magento\Framework\DataObject());
        $taxCalculation->method('getRate')->willReturn(25.0);
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $this->log = $this->createMock(LogRepository::class);
        foreach ([
            'logRepository' => $this->log,
            'feeLineProviderPool' => new FeeLineProviderPool([]),
            'taxCalculation' => $taxCalculation,
            'groupRepository' => $this->createMock(\Magento\Customer\Api\GroupRepositoryInterface::class),
            'brandRegistry' => $brand,
        ] as $name => $value) {
            (new \ReflectionProperty(OrderService::class, $name))->setValue($service, $value);
        }

        $config = $this->createMock(ConfigRepository::class);
        $config->method('isTaxSubtotalsEnabled')->willReturn(true);
        $config->method('isShippingTaxFallbackEnabled')->willReturn($shippingFallback);
        $config->method('getShippingTaxClassId')->willReturn(2);
        $service->configRepository = $config;

        return $service;
    }
}
