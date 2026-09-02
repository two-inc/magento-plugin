<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\Webapi\SurchargesInterface;
use Two\Gateway\Service\Order\TermSurchargePreview;
use Two\Gateway\Service\RateLimiter;

/**
 * Read-only endpoint that returns per-term surcharges for the current quote.
 *
 * Companion to TermSelection: that one mutates the selected term and refreshes
 * totals; this one observes. Used by the checkout chip loader to populate
 * values asynchronously after Magento's collectTotals settles so the basis
 * matches the live quote (subtotal + shipping + other collectors), not the
 * partial state available at server render time.
 */
class Surcharges implements SurchargesInterface
{
    /**
     * The chip loader refetches on every totals settle, so several per address edit.
     * The calculator's cache is request-scoped, so each call still spends one
     * upstream pricing call per configured term on the merchant's key.
     */
    private const LIMIT_PER_MINUTE = 60;

    private const WINDOW_SECONDS = 60;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var TermSurchargePreview
     */
    private $termSurchargePreview;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var RateLimiter
     */
    private $rateLimiter;

    public function __construct(
        CheckoutSession $checkoutSession,
        ConfigRepository $configRepository,
        TermSurchargePreview $termSurchargePreview,
        LogRepository $logRepository,
        RateLimiter $rateLimiter
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->configRepository = $configRepository;
        $this->termSurchargePreview = $termSurchargePreview;
        $this->logRepository = $logRepository;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * @inheritDoc
     */
    public function get(string $cartId): string
    {
        // Outside the try: the catch below turns a failure into an empty
        // success body, which would swallow the refusal.
        $this->rateLimiter->assertWithinLimit('two_surcharges', self::LIMIT_PER_MINUTE, self::WINDOW_SECONDS);

        try {
            // Session is the auth boundary — $cartId from the URL is
            // unverifiable on an anonymous route (UserContextInterface
            // does not populate from the customer session cookie when
            // the framework skips auth) and is therefore ignored.
            $quote = $this->checkoutSession->getQuote();

            // Force an in-memory collectTotals so the basis we read
            // matches what the frontend's totals observable would
            // compute. Without this the values are whatever was last
            // persisted, which can lag the live state by more than one
            // /totals-information step — anything that updated the
            // frontend without persisting leaves us computing against
            // stale data. Note: in-memory only, the quote is not
            // saved — read-only endpoint semantics preserved.
            $quote->collectTotals();

            $storeId = (int)$quote->getStoreId();

            // Basis = current quote grand_total minus any surcharge
            // segment the collector just wrote. Identical to the post-
            // mutation recompute in Model\Webapi\TermSelection so chip
            // math is server-authoritative across both endpoints. Both
            // fields are written by Model\Total\Surcharge::collect() in
            // the same pass, so they're consistent.
            $basis = (float)$quote->getGrandTotal()
                - (float)$this->checkoutSession->getTwoSurchargeGross();

            if ($basis <= 0) {
                // Empty quote, anonymous probe, or fully-discounted
                // cart (100% coupon). The fee on a zero basis is zero
                // for any term. Return per-term entries with net=0
                // rather than [] so the frontend chips render zero
                // values instead of staying in loader state — the
                // legitimate full-discount user can still pick a term.
                return (string)json_encode([
                    'term_surcharges' => $this->termSurchargePreview->zeroed(
                        $this->configRepository->getAllBuyerTerms($storeId)
                    ),
                    'tax_display' => $this->termSurchargePreview->taxDisplay($quote),
                ]);
            }

            $currency = $quote->getQuoteCurrencyCode()
                ?: $quote->getStore()->getBaseCurrencyCode();

            $country = 'NO';
            $billing = $quote->getBillingAddress();
            $shipping = $quote->getShippingAddress();
            if ($billing && $billing->getCountryId()) {
                $country = $billing->getCountryId();
            } elseif ($shipping && $shipping->getCountryId()) {
                $country = $shipping->getCountryId();
            }

            $surcharges = $this->termSurchargePreview->build(
                $quote,
                $basis,
                $this->configRepository->getAllBuyerTerms($storeId),
                $country,
                $currency,
                $storeId,
                'Surcharges webapi'
            );

            return (string)json_encode([
                'term_surcharges' => $surcharges,
                'tax_display' => $this->termSurchargePreview->taxDisplay($quote),
            ]);
        } catch (\Exception $e) {
            // Don't 500 — frontend treats empty as "stay in loader state".
            $this->logRepository->addErrorLog('Surcharges webapi', $e->getMessage());
            return (string)json_encode(['term_surcharges' => []]);
        }
    }
}
