<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

/**
 * The orders a status fulfilment was sent for in this request (TWO-26302).
 *
 * A copy of the order loaded before the fulfilment marker was set, and saved
 * later in the same request, writes its stale payment back, so neither it nor
 * a fresh load after it carries the marker. This record stops that save
 * fulfilling the order a second time. Shared (the object manager's default),
 * so every StatusFulfilment in the request sees the same instance.
 */
class FulfilmentAttempts
{
    /** @var array<int, true> */
    private $attempted = [];

    public function markAttempted(int $orderId): void
    {
        $this->attempted[$orderId] = true;
    }

    public function wasAttempted(int $orderId): bool
    {
        return isset($this->attempted[$orderId]);
    }
}
