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
 * Recorded before the call to Two, so a refusal or timeout is not sent again
 * by a second save in the same request. A copy of the order loaded before the
 * marker was set and saved later in the request also carries no marker; if
 * its payment was changed, that save writes the payment back without it, so a
 * fresh load cannot see it either (an unmodified payment is not written, as
 * the payment resource is version-controlled). This record stops that save
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
