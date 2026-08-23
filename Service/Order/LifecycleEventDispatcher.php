<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Model\Order;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * TWO-25503: dispatches the plugin's own order-lifecycle events so downstream
 * integrations (ERP sync, analytics, custom fulfilment) can hook the Two-side
 * transitions. The plugin previously only SUBSCRIBED to Magento core events
 * and dispatched nothing of its own, leaving no supported extension point.
 *
 * Each event carries the Magento order plus the Two-side data for the
 * transition, so an observer never has to re-read the API to know what
 * happened.
 */
class LifecycleEventDispatcher
{
    public const EVENT_CREATED = 'two_payment_order_created';
    public const EVENT_COMPLETED = 'two_payment_order_completed';
    public const EVENT_CANCELLED = 'two_payment_order_cancelled';
    public const EVENT_REFUNDED = 'two_payment_order_refunded';

    /** @var EventManager */
    private $eventManager;

    /** @var LogRepository */
    private $logRepository;

    /**
     * Events already dispatched in this request, keyed "<event>:<order id>".
     *
     * A single Magento cancellation reaches both Two::cancel() (via
     * Order::cancel() -> payment cancel) and SalesOrderCancelAfter (via
     * order_cancel_after), and a fulfilment reaches both Two::capture() and
     * SalesOrderShipmentAfter depending on the configured trigger. Observers
     * must not have to be idempotent to survive that.
     *
     * @var array<string, true>
     */
    private $dispatched = [];

    public function __construct(EventManager $eventManager, LogRepository $logRepository)
    {
        $this->eventManager = $eventManager;
        $this->logRepository = $logRepository;
    }

    /**
     * @param array<string, mixed> $twoData Two-side payload for the transition
     */
    public function dispatchCreated(Order $order, array $twoData = []): void
    {
        $this->dispatch(self::EVENT_CREATED, $order, $twoData);
    }

    /**
     * @param array<string, mixed> $twoData
     */
    public function dispatchCompleted(Order $order, array $twoData = []): void
    {
        $this->dispatch(self::EVENT_COMPLETED, $order, $twoData);
    }

    /**
     * @param array<string, mixed> $twoData
     */
    public function dispatchCancelled(Order $order, array $twoData = []): void
    {
        $this->dispatch(self::EVENT_CANCELLED, $order, $twoData);
    }

    /**
     * @param array<string, mixed> $twoData
     */
    public function dispatchRefunded(Order $order, array $twoData = []): void
    {
        $this->dispatch(self::EVENT_REFUNDED, $order, $twoData);
    }

    /**
     * @param array<string, mixed> $twoData
     */
    private function dispatch(string $event, Order $order, array $twoData): void
    {
        $key = $event . ':' . (string)$order->getEntityId();
        if (isset($this->dispatched[$key])) {
            return;
        }
        $this->dispatched[$key] = true;

        // Fail open, and never let a third-party observer break the
        // transaction it is observing: a broken integration must not turn a
        // successful payment, fulfilment, cancellation or refund into an error
        // the buyer or the merchant sees.
        try {
            $this->eventManager->dispatch($event, [
                'order' => $order,
                'two_order_id' => (string)$order->getTwoOrderId(),
                'two_data' => $twoData,
            ]);
        } catch (\Throwable $e) {
            $this->logRepository->addErrorLog(
                sprintf('Lifecycle event %s observer failed', $event),
                $e->getMessage()
            );
        }
    }
}
