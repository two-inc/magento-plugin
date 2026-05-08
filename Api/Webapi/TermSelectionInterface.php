<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Api\Webapi;

interface TermSelectionInterface
{
    /**
     * Set the buyer's selected payment term and recalculate quote totals.
     *
     * The route is anonymous (guest checkout requires it). The session
     * cookie is the auth boundary — server uses checkoutSession->getQuote()
     * as the authoritative quote source. The $cartId path parameter is
     * retained for URL routing back-compat and is not used for
     * authorization; UserContextInterface does not populate on routes
     * declared with `<resource ref="anonymous"/>`, so an ownership check
     * via QuoteIdMaskFactory would be dead code on this surface (see
     * ABN-374 for the reasoning that applies to both anonymous surcharge
     * endpoints).
     *
     * $termDays is validated against the merchant's configured terms
     * (ConfigRepository::getAllBuyerTerms) before any state mutation —
     * an unconfigured term would otherwise persist to the session via
     * setTwoSelectedTerm and flow through to ComposeOrder at checkout
     * completion, causing the Two API to receive a term the merchant
     * never offered. See ABN-387.
     *
     * @api
     *
     * @param string $cartId URL-routing only; ignored server-side.
     * @param int $termDays Must be one of the configured buyer terms.
     * @return array Wrapped totals + per-term recalculated surcharges.
     * @throws \Magento\Framework\Exception\InputException If $termDays is not configured.
     */
    public function selectTerm(string $cartId, int $termDays): array;
}
