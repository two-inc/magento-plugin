<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

/**
 * Request-scoped state for the fulfil-on status fulfilment (TWO-26302).
 *
 * Core's refund entry points save the order and its credit memo one after the
 * other, so sales_order_save_after can fire with the memo not yet saved: the
 * REST routes save the order first. While one of them runs, the status observer queues the order here instead of telling
 * Two, and Plugin\Model\Sales\FulfilAfterRefund\* flush the queue once the
 * refund call has returned. Shared (the object manager's default), so the
 * observer and the plugins see the same instance.
 */
class FulfilmentDeferral
{
    /** @var int */
    private $depth = 0;

    /** @var array<int, true> order ids waiting for the refund call to return */
    private $queue = [];

    /** @var array<int, true> order ids already sent to Two in this request */
    private $attempted = [];

    public function enterRefund(): void
    {
        $this->depth++;
    }

    /**
     * @return bool whether this left the outermost refund call
     */
    public function leaveRefund(): bool
    {
        $this->depth--;

        return $this->depth === 0;
    }

    public function isInsideRefund(): bool
    {
        return $this->depth > 0;
    }

    public function queue(int $orderId): void
    {
        $this->queue[$orderId] = true;
    }

    /**
     * Empties the queue.
     *
     * @return int[]
     */
    public function takeQueue(): array
    {
        $ids = array_keys($this->queue);
        $this->queue = [];

        return $ids;
    }

    public function markAttempted(int $orderId): void
    {
        $this->attempted[$orderId] = true;
    }

    /**
     * An order sent to Two once in this request is not sent again in it, even
     * by a stale copy of the order that never saw the fulfilment marker.
     */
    public function wasAttempted(int $orderId): bool
    {
        return isset($this->attempted[$orderId]);
    }
}
