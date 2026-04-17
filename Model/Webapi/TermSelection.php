<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
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
    private $quoteRepository;

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
        CartRepositoryInterface $quoteRepository,
        CartTotalRepositoryInterface $cartTotalRepository,
        ConfigRepository $configRepository,
        SurchargeCalculator $surchargeCalculator
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->configRepository = $configRepository;
        $this->surchargeCalculator = $surchargeCalculator;
    }

    /**
     * @inheritDoc
     */
    public function selectTerm(string $cartId, int $termDays): array
    {
        $this->checkoutSession->setTwoSelectedTerm($termDays);

        $quote = $this->checkoutSession->getQuote();
        $quote->collectTotals();
        $this->quoteRepository->save($quote);

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
