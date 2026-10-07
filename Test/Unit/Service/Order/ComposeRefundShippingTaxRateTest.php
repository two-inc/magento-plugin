<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Fee\FeeLineProviderPool;
use Two\Gateway\Service\Order as OrderService;
use Two\Gateway\Service\Order\ComposeRefund;

/**
 * TWO-26117: a refund's shipping line takes the order's rate and never
 * re-checks the tax it carries. The order recorded no shipping rate, so the
 * rate is 0% with the control blank and the Tax Class for Shipping's rate
 * (25% here) with it populated. Drives the real execute() path.
 */
class ComposeRefundShippingTaxRateTest extends TestCase
{
    /**
     * @dataProvider refunds
     */
    public function testTheRefundShippingLineRelaysTheOrdersRate(
        bool $populated,
        float $shippingTax,
        string $expectedRate,
        string $description
    ): void {
        $order = new Order();
        $order->setStoreId(1);
        $order->setOrderCurrencyCode('EUR');
        $order->setShippingDescription('Flat Rate');
        $order->setIsVirtual(0);
        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setStoreId(1);
        $creditmemo->setItems([]);
        $creditmemo->setShippingAmount(10.00);
        $creditmemo->setShippingTaxAmount($shippingTax);
        $creditmemo->setGrandTotal(10.00 + $shippingTax);
        $creditmemo->setTaxAmount($shippingTax);

        $payload = $this->composeRefund($populated)->execute($creditmemo, 10.00 + $shippingTax, $order);

        $shipping = $payload['line_items'][0];
        $this->assertSame('SHIPPING_FEE', $shipping['type'], $description);
        $this->assertSame($expectedRate, $shipping['tax_rate'], $description);
        $this->assertEqualsWithDelta($shippingTax, (float)$shipping['tax_amount'], 0.001, $description);
    }

    public static function refunds(): array
    {
        return [
            [false, 2.50, '0.000000', 'control blank, taxed shipping refunded: 0% and the tax as charged, never refused'],
            [true, 2.50, '0.250000', 'control populated, tax reconciles: the class rate'],
            [true, 2.00, '0.250000', 'control populated, tax off the class rate: relayed, not re-checked'],
        ];
    }

    /**
     * @return ComposeRefund|\PHPUnit\Framework\MockObject\MockObject
     */
    private function composeRefund(bool $populated)
    {
        $service = $this->getMockBuilder(ComposeRefund::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProduct', 'getProductImageUrl', 'getCategories'])
            ->getMock();

        $taxCalculation = $this->createMock(\Magento\Tax\Model\Calculation::class);
        $taxCalculation->method('getRateRequest')->willReturn(new \Magento\Framework\DataObject());
        $taxCalculation->method('getRate')->willReturn(25.0);
        foreach ([
            'logRepository' => $this->createMock(LogRepository::class),
            'feeLineProviderPool' => new FeeLineProviderPool([]),
            'taxCalculation' => $taxCalculation,
            'groupRepository' => $this->createMock(\Magento\Customer\Api\GroupRepositoryInterface::class),
        ] as $name => $value) {
            (new \ReflectionProperty(OrderService::class, $name))->setValue($service, $value);
        }

        $config = $this->createMock(ConfigRepository::class);
        $config->method('isTaxSubtotalsEnabled')->willReturn(true);
        $config->method('isShippingTaxFallbackEnabled')->willReturn($populated);
        $config->method('getShippingTaxClassId')->willReturn(2);
        $service->configRepository = $config;

        return $service;
    }
}
