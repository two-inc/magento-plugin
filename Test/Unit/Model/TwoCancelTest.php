<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\LifecycleEventDispatcher;
use Two\Gateway\Service\Order\OrderPostprocessor;

/**
 * TWO-26298: an admin cancel sends one cancel to Two, from the
 * order_cancel_after observer, so the payment method's cancel() sends none.
 * An admin Void reaches no such observer and still sends its own.
 */
class TwoCancelTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testCancelRequestsSent(string $action, int $expectedCalls, string $description): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->expects($this->exactly($expectedCalls))
            ->method('execute')
            ->with('/v1/order/two-order-id/cancel', [], 'POST', 1)
            ->willReturn([]);

        $postprocessor = $this->createMock(OrderPostprocessor::class);
        $postprocessor->method('process')->willReturn([]);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Brand');

        $order = new Order();
        $order->setTwoOrderId('two-order-id');
        $order->setStoreId(1);
        $order->setStatus('canceled');
        $payment = new class ($order) implements InfoInterface {
            private $order;

            public function __construct(Order $order)
            {
                $this->order = $order;
            }

            public function getOrder(): Order
            {
                return $this->order;
            }
        };

        $reflection = new \ReflectionClass(Two::class);
        $model = $reflection->newInstanceWithoutConstructor();
        $properties = [
            'apiAdapter' => $adapter,
            'orderPostprocessor' => $postprocessor,
            'brandRegistry' => $brandRegistry,
            'lifecycleEvents' => $this->createMock(LifecycleEventDispatcher::class),
            'orderRepository' => $this->createMock(OrderRepositoryInterface::class),
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($model, $value);
        }

        $this->assertSame($model, $model->{$action}($payment), $description);
    }

    public static function cases(): array
    {
        return [
            ['cancel', 0, 'cancel() leaves the request to the order_cancel_after observer'],
            ['void', 1, 'void() sends the cancel itself'],
        ];
    }
}
