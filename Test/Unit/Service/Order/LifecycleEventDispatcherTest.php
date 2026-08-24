<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\LifecycleEventDispatcher;

/**
 * TWO-25503: the plugin's own order-lifecycle events, the supported hook for
 * downstream integrations.
 */
class LifecycleEventDispatcherTest extends TestCase
{
    private function makeOrder(int $entityId = 7, string $twoOrderId = 'two-abc'): Order
    {
        $order = new Order();
        $order->setData('entity_id', $entityId);
        $order->setData('two_order_id', $twoOrderId);
        return $order;
    }

    /**
     * @return object recording event manager: ->dispatched is a list of
     *         [event name, data] pairs
     */
    private function makeEventManager(?\Throwable $throw = null): object
    {
        return new class ($throw) implements EventManager {
            /** @var array<int, array{0: string, 1: array}> */
            public $dispatched = [];

            /** @var \Throwable|null */
            private $throw;

            public function __construct(?\Throwable $throw)
            {
                $this->throw = $throw;
            }

            public function dispatch($eventName, array $data = [])
            {
                $this->dispatched[] = [$eventName, $data];
                if ($this->throw !== null) {
                    throw $this->throw;
                }
            }
        };
    }

    /**
     * @dataProvider transitionProvider
     */
    public function testEachTransitionDispatchesItsOwnEvent(
        string $method,
        string $expectedEvent,
        string $case
    ): void {
        $events = $this->makeEventManager();
        $dispatcher = new LifecycleEventDispatcher($events, $this->createMock(LogRepository::class));
        $order = $this->makeOrder();

        $dispatcher->$method($order, ['probe' => 1]);

        $this->assertCount(1, $events->dispatched, $case);
        [$name, $data] = $events->dispatched[0];
        $this->assertSame($expectedEvent, $name, $case);
        $this->assertSame($order, $data['order'], $case);
        $this->assertSame('two-abc', $data['two_order_id'], $case);
        $this->assertSame(['probe' => 1], $data['two_data'], $case);
    }

    public function transitionProvider(): array
    {
        return [
            ['dispatchCreated', 'two_payment_order_created', 'order created'],
            ['dispatchCompleted', 'two_payment_order_completed', 'order completed'],
            ['dispatchCancelled', 'two_payment_order_cancelled', 'order cancelled'],
            ['dispatchRefunded', 'two_payment_order_refunded', 'order refunded'],
        ];
    }

    /**
     * One Magento cancellation reaches both Two::cancel() and
     * SalesOrderCancelAfter, so observers must not have to be idempotent.
     */
    public function testTheSameTransitionOnTheSameOrderDispatchesOnce(): void
    {
        $events = $this->makeEventManager();
        $dispatcher = new LifecycleEventDispatcher($events, $this->createMock(LogRepository::class));
        $order = $this->makeOrder();

        $dispatcher->dispatchCancelled($order);
        $dispatcher->dispatchCancelled($order);

        $this->assertCount(1, $events->dispatched);
    }

    public function testDedupeIsPerOrderAndPerEvent(): void
    {
        $events = $this->makeEventManager();
        $dispatcher = new LifecycleEventDispatcher($events, $this->createMock(LogRepository::class));

        $dispatcher->dispatchCancelled($this->makeOrder(7));
        $dispatcher->dispatchCancelled($this->makeOrder(8));
        $dispatcher->dispatchCompleted($this->makeOrder(7));

        $this->assertCount(3, $events->dispatched);
    }

    /**
     * The created event fires during placement, before the order has an
     * entity id. Multishipping places several orders in one request, so
     * without a second key every order after the first would be deduped away.
     */
    public function testUnsavedOrdersAreDistinguishedByIncrementId(): void
    {
        $events = $this->makeEventManager();
        $dispatcher = new LifecycleEventDispatcher($events, $this->createMock(LogRepository::class));

        $first = new Order();
        $first->setData('increment_id', '100000001');
        $second = new Order();
        $second->setData('increment_id', '100000002');

        $dispatcher->dispatchCreated($first);
        $dispatcher->dispatchCreated($second);
        $dispatcher->dispatchCreated($first);

        $this->assertCount(2, $events->dispatched);
    }

    /**
     * A broken third-party observer must not turn a successful refund (or
     * fulfilment, or cancellation) into an error the merchant sees.
     */
    public function testAThrowingObserverIsLoggedAndSwallowed(): void
    {
        $events = $this->makeEventManager(new \RuntimeException('observer boom'));
        $log = $this->createMock(LogRepository::class);
        $log->expects($this->once())
            ->method('addErrorLog')
            ->with(
                'Lifecycle event two_payment_order_refunded observer failed',
                'observer boom'
            );
        $dispatcher = new LifecycleEventDispatcher($events, $log);

        $dispatcher->dispatchRefunded($this->makeOrder());

        $this->assertCount(1, $events->dispatched);
    }
}
