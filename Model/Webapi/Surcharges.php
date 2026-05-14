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
use Two\Gateway\Service\Order\SurchargeCalculator;

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
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var SurchargeCalculator
     */
    private $surchargeCalculator;

    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(
        CheckoutSession $checkoutSession,
        ConfigRepository $configRepository,
        SurchargeCalculator $surchargeCalculator,
        LogRepository $logRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->configRepository = $configRepository;
        $this->surchargeCalculator = $surchargeCalculator;
        $this->logRepository = $logRepository;
    }

    /**
     * @inheritDoc
     */
    public function get(string $cartId): string
    {
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
                $emptySurcharges = [];
                foreach ($this->configRepository->getAllBuyerTerms($storeId) as $days) {
                    $emptySurcharges[] = ['days' => (int)$days, 'net' => 0.0];
                }
                return (string)json_encode(['term_surcharges' => $emptySurcharges]);
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

            $surcharges = [];
            foreach ($this->configRepository->getAllBuyerTerms($storeId) as $days) {
                try {
                    $result = $this->surchargeCalculator->calculate(
                        $basis,
                        $days,
                        $country,
                        $currency,
                        $storeId
                    );
                    $surcharges[] = ['days' => (int)$days, 'net' => (float)$result['amount']];
                } catch (\Exception $e) {
                    // Per-term failure: keep the other terms responsive, but
                    // log loudly so the silent zero doesn't mask a broken
                    // pricing path that will later detonate at checkout when
                    // the buyer actually picks this term.
                    $this->logRepository->addErrorLog(
                        sprintf('Surcharges webapi: term %d failed', $days),
                        $e->getMessage()
                    );
                    $surcharges[] = ['days' => (int)$days, 'net' => 0.0];
                }
            }

            return (string)json_encode(['term_surcharges' => $surcharges]);
        } catch (\Exception $e) {
            // Don't 500 — frontend treats empty as "stay in loader state".
            $this->logRepository->addErrorLog('Surcharges webapi', $e->getMessage());
            return (string)json_encode(['term_surcharges' => []]);
        }
    }
}
