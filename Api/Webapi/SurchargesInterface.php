<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Api\Webapi;

interface SurchargesInterface
{
    /**
     * Get the per-term surcharges for the current checkout-session quote.
     *
     * Read-only — does not change the selected term. Use /select-term for
     * mutation. This endpoint exists so the frontend can populate chip
     * values asynchronously after totals settle.
     *
     * The route is anonymous (guest checkout requires it). The session
     * cookie is the auth boundary — server uses checkoutSession->getQuote()
     * as the authoritative quote source, and computes the basis from it
     * (grand_total minus any surcharge segment already collected this
     * pass). The $cartId path parameter is retained for URL routing and
     * is not used for authorization; trusting an attacker-controlled
     * basis here previously allowed unauthenticated probing of merchant
     * surcharge configuration.
     *
     * The server runs an in-memory collectTotals() before reading so
     * the basis matches what the frontend's totals observable would
     * compute even if no persisting Magento call has fired since the
     * last frontend update — preferred over accepting an unbounded
     * caller-supplied basis, even at the cost of an extra collector
     * pass per request.
     *
     * @api
     *
     * @param string $cartId URL-routing only; ignored server-side.
     * @return string JSON-encoded {term_surcharges: [{days: int, net: float}, ...]}.
     */
    public function get(string $cartId): string;
}
