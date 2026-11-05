<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Two;
use Two\Gateway\Observer\SalesOrderCancelAfter;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Service\Payment\OrderService;

/**
 * TWO-26301: a Void Two accepted already cancelled the order at Two, so the
 * Cancel that follows sends nothing more; it used to post the cancel a second
 * time, and a refusal of that second cancel blocked the Magento cancel.
 */
class SalesOrderCancelAfterVoidTest extends TestCase
{
    public static function cases(): array
    {
        return [
            // void response (null = no Void), cancels the observer sends, description
            'Void accepted' => [[], 0, 'a Void Two accepted: the Cancel sends nothing more'],
            'Void refused' => [
                ['http_status' => 400, 'error_code' => 'ORDER_INVALID', 'error_message' => 'Order is already cancelled'],
                1,
                'a Void Two refused: the Cancel still sends',
            ],
            'no Void' => [null, 1, 'no Void: the Cancel sends'],
        ];
    }

    #[DataProvider('cases')]
    public function testTheCancelAfterAVoidIsSentOnlyWhenTwoDidNotCancel(
        ?array $voidResponse,
        int $expectedSends,
        string $description
    ): void {
        $order = $this->order();
        if ($voidResponse !== null) {
            $this->two($voidResponse)->void($order->getPayment());
        }

        $service = $this->getMockBuilder(OrderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['cancelTwoOrder'])
            ->getMock();
        $service->expects($this->exactly($expectedSends))->method('cancelTwoOrder')->willReturn(true);
        $this->observer($service)->execute($this->event($order));

        $this->assertSame(
            $voidResponse === [] ? true : null,
            $order->getPayment()->getAdditionalInformation(Two::CANCELLED_AT_TWO),
            $description
        );
    }

    private function order(): Order
    {
        $order = new Order();
        $order->setData('two_order_id', 'remote-order-id');
        $order->setData('increment_id', '100000042');
        $order->setData('store_id', 1);
        $payment = new VoidTestPayment();
        $payment->setData('method', 'two_payment');
        $payment->setOrder($order);
        $order->setData('payment', $payment);

        return $order;
    }

    private function two(array $response): Two
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('withIdempotencyKey')->willReturnSelf();
        $adapter->method('execute')->willReturn($response);
        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturnArgument(1);

        $two = $this->getMockBuilder(Two::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $values = [
            'apiAdapter' => $adapter,
            'orderPostprocessor' => $postprocessor,
            'brandRegistry' => $this->brand(),
            'lifecycleEvents' => $this->lifecycle(),
            'orderRepository' => $this->createMock(OrderRepositoryInterface::class),
        ];
        foreach ($values as $property => $value) {
            (new \ReflectionProperty(Two::class, $property))->setValue($two, $value);
        }

        return $two;
    }

    private function observer(OrderService $service): SalesOrderCancelAfter
    {
        $overlay = $this->createMock(BrandOverlayRegistryInterface::class);
        $overlay->method('isTwoStackMethod')->willReturn(true);
        $reflection = new \ReflectionClass(SalesOrderCancelAfter::class);
        $observer = $reflection->newInstanceWithoutConstructor();
        $values = [
            'orderService' => $service,
            'brandRegistry' => $this->brand(),
            'overlayRegistry' => $overlay,
            'lifecycleEvents' => $this->lifecycle(),
        ];
        foreach ($values as $property => $value) {
            $reflection->getProperty($property)->setValue($observer, $value);
        }

        return $observer;
    }

    private function brand(): BrandRegistryInterface
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');

        return $brand;
    }

    private function lifecycle(): object
    {
        return new class {
            public function dispatchCancelled($order, array $twoData = [])
            {
            }
        };
    }

    private function event(Order $order): Observer
    {
        return new class (new DataObject(['order' => $order])) extends Observer {
            private $event;

            public function __construct($event)
            {
                $this->event = $event;
            }

            public function getEvent()
            {
                return $this->event;
            }
        };
    }
}

/**
 * Core's order payment is an InfoInterface; the shared stub loads before the
 * interface can be declared, so the test adds it.
 */
class VoidTestPayment extends Order\Payment implements InfoInterface
{
}
