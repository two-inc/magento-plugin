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
 * line with net and no tax at a non-zero rate whose tax due rounds to at least
 * 0.01. A 0% line and a line too small to carry tax go out unchanged; the
 * plugin never fills the tax in.
 */
class ComposeRefundNetAndTaxTest extends TestCase
{
    private const TAX_ONLY = '"%s" cannot be refunded as tax alone: Two refunds a line\'s tax only together'
        . ' with its net amount. To correct the tax charged, refund the whole order and rebook it with the'
        . ' correct tax rates.';
    private const NET_ONLY = '"%s" has no tax entered: Two refunds a line\'s net amount only together with its'
        . ' tax. Enter the tax refunded for this line as well.';

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
            ['PHYSICAL', 'Widget', '0.01', 0.0, '0.210000', null, 'tax due 0.0021 rounds to 0: unchanged'],
            ['PHYSICAL', 'Widget', '0.02', 0.0, '0.210000', null, 'tax due 0.0042 rounds to 0: unchanged'],
            ['PHYSICAL', 'Widget', '0.03', 0.0, '0.210000', 'net', 'tax due 0.0063 rounds to 0.01: refused'],
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

    public function testExecuteSendsAnAdjustmentAtZeroPercent(): void
    {
        $order = $this->order();
        $creditmemo = $this->creditmemo($order, 0.0, 0.0, 5.00);

        $payload = $this->composeRefund(false)->execute($creditmemo, 5.00, $order);

        $this->assertSame('Adjustment', $payload['line_items'][0]['name']);
        $this->assertSame('5.00', $payload['line_items'][0]['net_amount']);
    }

    private function order(): Order
    {
        $order = new Order();
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

    /**
     * @return ComposeRefund|\PHPUnit\Framework\MockObject\MockObject
     */
    private function composeRefund(bool $shippingFallback)
    {
        $service = $this->getMockBuilder(ComposeRefund::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getProductImageUrl', 'getCategories'])
            ->getMock();

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
