<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api\Webapi;

/**
 * Server-side proxy for the order-intent check the payment tile used to call
 * straight from the buyer's browser, so the merchant API key and any
 * configured firewall token stay server-side.
 */
interface OrderIntentInterface
{
    public const ENDPOINT = '/v1/order_intent';

    /**
     * Ask whether an order for the given buyer and basket would be approved.
     *
     * Anonymous route — guest checkout requires it. The merchant identity in
     * $payload is replaced with the one this store's API key resolves to; a
     * browser-supplied merchant id is never relayed.
     *
     * @api
     *
     * @param string $payload JSON-encoded order-intent request body
     * @return string JSON-encoded {ok: bool, status: int, body: object}
     */
    public function place(string $payload): string;
}
