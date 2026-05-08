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
use Two\Gateway\Service\Order\SurchargeCalculator;

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
     * @var SurchargeCalculator
     */
    private $surchargeCalculator;

    public function __construct(
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $cartRepository,
        CartTotalRepositoryInterface $cartTotalRepository,
        ConfigRepository $configRepository,
        SurchargeCalculator $surchargeCalculator
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->configRepository = $configRepository;
        $this->surchargeCalculator = $surchargeCalculator;
    }

    /**
     * @inheritDoc
     */
    public function selectTerm(string $cartId, int $termDays): array
    {
        // Session is the auth boundary on this anonymous webapi route —
        // $cartId is unverifiable here (UserContextInterface doesn't
        // populate when the framework skips auth) and is therefore
        // ignored. See ABN-374 for the full reasoning that applies to
        // both anonymous surcharge endpoints in this module.
        $quote = $this->checkoutSession->getQuote();
        // (int)null = 0 if the quote has no store assigned yet (transient
        // quote, anonymous probe). getAllBuyerTerms(0) resolves to the
        // default scope's terms, which is acceptable: ComposeOrder
        // resolves the real store later, and any term valid in the
        // default scope is a reasonable validation subset.
        $storeId = (int)$quote->getStoreId();

        // Reject termDays the merchant hasn't configured (ABN-387).
        // Without this guard, an anonymous caller can persist any int
        // into the session via setTwoSelectedTerm; the persisted value
        // then flows through collectTotals → cartRepository->save →
        // ComposeOrder, so the order placed on Two's API would
        // reference a term the merchant never offered. Validate
        // BEFORE any state mutation so an invalid call doesn't poison
        // the session even on the throw path.
        $allowedTerms = $this->configRepository->getAllBuyerTerms($storeId);
        if (!in_array($termDays, $allowedTerms, true)) {
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
        ]];
    }

    /**
     * Compute net surcharges for all available terms.
     */
    private function computeAllTermSurcharges(float $baseAmount, $quote): array
    {
        $storeId = (int)$quote->getStoreId();
        $terms = $this->configRepository->getAllBuyerTerms($storeId);
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
        foreach ($terms as $days) {
            try {
                $result = $this->surchargeCalculator->calculate(
                    $baseAmount,
                    $days,
                    $country,
                    $currency,
                    $storeId
                );
                $surcharges[] = ['days' => $days, 'net' => (float)$result['amount']];
            } catch (\Exception $e) {
                $surcharges[] = ['days' => $days, 'net' => 0.0];
            }
        }

        return $surcharges;
    }
}
