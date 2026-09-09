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
use Two\Gateway\Model\GenericPaymentMethod;
use Two\Gateway\Model\Two as TwoPayment;
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

    private function makeOrder(string $method = 'two'): OrderModel
    {
        $order = new OrderModel();
        $order->setData('grand_total', 112.00);
        $order->setData('tax_amount', 22.00);
        if ($method === 'none') {
            return $order;
        }
        $instance = $method === 'other'
            ? new \stdClass()
            : $this->createMock($method === 'acme_payment' ? GenericPaymentMethod::class : TwoPayment::class);
        $order->setData('payment', new class ($instance) {
            private $instance;

            public function __construct($instance)
            {
                $this->instance = $instance;
            }

            public function getMethodInstance()
            {
                return $this->instance;
            }
        });

        return $order;
    }

    /**
     * A fee extension applies store-wide, so the residual is resolved only for
     * an order this module may move the refund totals of — by payment-method
     * INSTANCE, since a brand overlay extends Two under its own code. Without
     * this the credit-memo form would offer an editable charge row on someone
     * else's order and silently discard whatever was typed into it.
     *
     * @dataProvider paymentGateProvider
     */
    public function testOnlyTwoOrdersIncludingBrandOverlaysResolveAResidual(
        string $method,
        bool $expectResolved,
        string $description
    ): void {
        $order = $this->makeOrder($method);
        $residual = ['net_amount' => '10.00', 'tax_amount' => '2.00'];

        $this->composeRefund->method('getKnownLineAmountsOrder')->willReturn([]);
        $this->composeRefund->method('getFeeLines')->willReturn([]);
        $this->composeRefund->expects($expectResolved ? $this->once() : $this->never())
            ->method('getOtherChargesLineItem')
            ->willReturn($residual);

        $this->assertSame(
            $expectResolved ? $residual : null,
            $this->resolver->forOrder($order),
            $description
        );
    }

    public static function paymentGateProvider(): array
    {
        return [
            ['two', true, 'the base payment method'],
            ['acme_payment', true, 'a brand overlay extending it under its own code'],
            ['other', false, 'an order paid by an unrelated method'],
            ['none', false, 'an order with no payment at all'],
        ];
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
