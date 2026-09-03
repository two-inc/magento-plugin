<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Sales\Model\Order as OrderModel;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\ComposeRefund;
use Two\Gateway\Service\Order\OtherChargesResolver;

/**
 * Whatever composition treats as a known line must reach the reconciliation
 * here too, or the collector refunds as a residual what the payload already
 * itemizes.
 */
class OtherChargesResolverTest extends TestCase
{
    /** @var ComposeRefund|\PHPUnit\Framework\MockObject\MockObject */
    private $composeRefund;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    private OtherChargesResolver $resolver;

    protected function setUp(): void
    {
        $this->composeRefund = $this->getMockBuilder(ComposeRefund::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getKnownLineAmountsOrder', 'getFeeLines', 'getOtherChargesLineItem'])
            ->getMock();
        $this->logRepository = $this->createMock(LogRepository::class);
        $this->resolver = new OtherChargesResolver($this->composeRefund, $this->logRepository);
    }

    private function makeOrder(): OrderModel
    {
        $order = new OrderModel();
        $order->setData('grand_total', 112.00);
        $order->setData('tax_amount', 22.00);

        return $order;
    }

    public function testTheOrdersOwnTotalsAndKnownAmountsAreWhatGetReconciled(): void
    {
        $order = $this->makeOrder();
        $known = [['gross_amount' => '100.00', 'tax_amount' => '20.00']];
        $residual = ['net_amount' => '10.00', 'tax_amount' => '2.00'];

        $this->composeRefund->method('getKnownLineAmountsOrder')->with($order)->willReturn($known);
        $this->composeRefund->method('getFeeLines')->willReturn([]);
        $this->composeRefund->expects($this->once())
            ->method('getOtherChargesLineItem')
            ->with($known, $order, 112.00, 22.00)
            ->willReturn($residual);

        $this->assertSame($residual, $this->resolver->forOrder($order));
    }

    /**
     * A fee a registered provider already itemizes is a known line, exactly as
     * reconcileOtherCharges() treats it — otherwise the collector would refund
     * it a second time.
     */
    public function testRegisteredFeeProviderLinesCountAsKnown(): void
    {
        $order = $this->makeOrder();
        $known = [['gross_amount' => '100.00', 'tax_amount' => '20.00']];
        $feeLine = ['gross_amount' => '12.00', 'tax_amount' => '2.00'];

        $this->composeRefund->method('getKnownLineAmountsOrder')->willReturn($known);
        $this->composeRefund->method('getFeeLines')->with($order)->willReturn([$feeLine]);
        $this->composeRefund->expects($this->once())
            ->method('getOtherChargesLineItem')
            ->with([$known[0], $feeLine], $order, 112.00, 22.00)
            ->willReturn(null);

        $this->assertNull($this->resolver->forOrder($order));
    }

    public function testAFailedResolutionIsAnErrorNotADebugNote(): void
    {
        $order = $this->makeOrder();

        $this->composeRefund->method('getKnownLineAmountsOrder')
            ->willThrowException(new \RuntimeException('tax service down'));
        $this->logRepository->expects($this->once())
            ->method('addErrorLog')
            ->with('OtherChargesResolver', $this->stringContains('tax service down'));

        $this->assertNull($this->resolver->forOrder($order));
    }
}
