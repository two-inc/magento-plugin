<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\InputException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Webapi\TermSelectionInterface;
use Two\Gateway\Service\Order\TermSurchargePreview;
use Two\Gateway\Service\RateLimiter;

/**
 * Sets the buyer's selected payment term and returns recalculated totals.
 *
 * Called from the checkout chip selector via AJAX. Stores the term in
 * the checkout session, triggers collectTotals() (which runs the
 * Two surcharge total collector), and returns the updated totals
 * plus recalculated surcharges for all terms (so chip labels refresh).
 */
class TermSelection implements TermSelectionInterface
{
    /**
     * A chip click per term the merchant offers, with room to change mind.
     * Metered despite being session-scoped: the recompute below spends one
     * upstream pricing call per configured term on the merchant's key.
     */
    private const LIMIT_PER_MINUTE = 30;

    private const WINDOW_SECONDS = 60;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var CartTotalRepositoryInterface
     */
    private $cartTotalRepository;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var TermSurchargePreview
     */
    private $termSurchargePreview;

    /**
     * @var RateLimiter
     */
    private $rateLimiter;

    public function __construct(
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $cartRepository,
        CartTotalRepositoryInterface $cartTotalRepository,
        ConfigRepository $configRepository,
        TermSurchargePreview $termSurchargePreview,
        RateLimiter $rateLimiter
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->configRepository = $configRepository;
        $this->termSurchargePreview = $termSurchargePreview;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * @inheritDoc
     */
    public function selectTerm(string $cartId, int $termDays): array
    {
        $this->rateLimiter->assertWithinLimit('two_select_term', self::LIMIT_PER_MINUTE, self::WINDOW_SECONDS);

        // Session is the auth boundary on this anonymous webapi route —
        // $cartId is unverifiable here (UserContextInterface doesn't
        // populate when the framework skips auth) and is therefore
        // ignored. See internal ticket for the full reasoning that applies to
        // both anonymous surcharge endpoints in this module.
        $quote = $this->checkoutSession->getQuote();
        // (int)null = 0 if the quote has no store assigned yet (transient
        // quote, anonymous probe). getAllBuyerTerms(0) resolves to the
        // default scope's terms, which is acceptable: ComposeOrder
        // resolves the real store later, and any term valid in the
        // default scope is a reasonable validation subset.
        $storeId = (int)$quote->getStoreId();

        // Reject termDays the merchant hasn't configured.
        // Without this guard, an anonymous caller can persist any int
        // into the session via setTwoSelectedTerm; the persisted value
        // then flows through collectTotals → cartRepository->save →
        // ComposeOrder, so the order placed on Two's API would
        // reference a term the merchant never offered. Validate
        // BEFORE any state mutation so an invalid call doesn't poison
        // the session even on the throw path.
        if (!$this->configRepository->isBuyerTermAvailable($termDays, $storeId)) {
            throw new InputException(__('Selected payment term is not available.'));
        }

        $this->checkoutSession->setTwoSelectedTerm($termDays);

        $quote->collectTotals();
        $this->cartRepository->save($quote);

        // Build totals response
        $totals = $this->cartTotalRepository->get($quote->getId());
        $segments = [];
        foreach ($totals->getTotalSegments() as $segment) {
            $segments[] = [
                'code' => $segment->getCode(),
                'title' => $segment->getTitle(),
                'value' => $segment->getValue(),
            ];
        }

        // Recalculate surcharges for all terms using the current grand total
        // (minus the surcharge itself, to avoid circular base)
        $surchargeGross = (float)$this->checkoutSession->getTwoSurchargeGross();
        $baseAmount = (float)$totals->getGrandTotal() - $surchargeGross;
        $termSurcharges = $this->computeAllTermSurcharges($baseAmount, $quote);

        // Wrap in outer array so Magento's webapi serializer preserves keys
        return [[
            'grand_total' => $totals->getGrandTotal(),
            'base_grand_total' => $totals->getBaseGrandTotal(),
            'tax_amount' => $totals->getTaxAmount(),
            'total_segments' => $segments,
            'term_surcharges' => $termSurcharges,
            'tax_display' => $this->termSurchargePreview->taxDisplay($quote),
        ]];
    }

    /**
     * Compute per-term surcharge previews (net and gross) for all terms.
     */
    private function computeAllTermSurcharges(float $baseAmount, $quote): array
    {
        $storeId = (int)$quote->getStoreId();
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

        return $this->termSurchargePreview->build(
            $quote,
            $baseAmount,
            $this->configRepository->getAllBuyerTerms($storeId),
            $country,
            $currency,
            $storeId,
            'TermSelection webapi'
        );
    }
}
