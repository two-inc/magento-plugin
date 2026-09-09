<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Source\SurchargeType;

/**
 * Whether the buyer fee for the term a checkout would be charged for can be
 * priced right now (ABN-546).
 *
 * The quote happens on the request being judged, because no later request is
 * guaranteed to notice an unpriceable fee: the term-chip endpoints answer
 * after the payment list has rendered, and the totals collector only prices
 * once this method is already selected.
 */
class FeeQuoteGate
{
    /**
     * A render-path call cannot wait on the adapter's 60s default — a hanging
     * endpoint would stall the payment step for a minute per request.
     */
    private const TIMEOUT_SECONDS = 5;

    private AppState $appState;

    private ConfigRepository $configRepository;

    private ChargedTermResolver $chargedTermResolver;

    private CheckoutSession $checkoutSession;

    private SurchargeCalculator $surchargeCalculator;

    private BuyerCountryResolver $buyerCountryResolver;

    private LogRepository $logRepository;

    public function __construct(
        AppState $appState,
        ConfigRepository $configRepository,
        ChargedTermResolver $chargedTermResolver,
        CheckoutSession $checkoutSession,
        SurchargeCalculator $surchargeCalculator,
        BuyerCountryResolver $buyerCountryResolver,
        LogRepository $logRepository
    ) {
        $this->appState = $appState;
        $this->configRepository = $configRepository;
        $this->chargedTermResolver = $chargedTermResolver;
        $this->checkoutSession = $checkoutSession;
        $this->surchargeCalculator = $surchargeCalculator;
        $this->buyerCountryResolver = $buyerCountryResolver;
        $this->logRepository = $logRepository;
    }

    /**
     * True whenever the fee can be priced, and also whenever there is nothing
     * to price — the guards concede the method rather than withhold it.
     */
    public function isQuotable(?CartInterface $quote, ?int $storeId): bool
    {
        if (!$quote instanceof Quote || $this->isAdmin()) {
            return true;
        }
        $store = $quote->getStore();
        if ($store === null) {
            return true;
        }
        $storeId = $storeId ?? (int)$store->getId();
        try {
            if ($this->configRepository->getSurchargeType($storeId) === SurchargeType::NONE) {
                return true;
            }
            if ($quote->getAllVisibleItems() === []) {
                return true;
            }
            // Fee-exclusive, matching the collector and the chip endpoints: the
            // grand total already carries any fee this quote priced, and pricing
            // that would both compound the fee and miss their cached quote.
            $grossAmount = (float)$quote->getGrandTotal()
                - (float)$this->checkoutSession->getTwoSurchargeGross();
            if ($grossAmount <= 0.0) {
                return true;
            }
            $currency = (string)($quote->getQuoteCurrencyCode() ?: $store->getBaseCurrencyCode());
            if ($currency === '') {
                return true;
            }
            $chargedTerm = $this->chargedTermResolver->resolve($storeId);
            if ($chargedTerm <= 0) {
                return true;
            }
            $this->surchargeCalculator->calculate(
                $grossAmount,
                $chargedTerm,
                $this->buyerCountryResolver->resolve($quote),
                $currency,
                $storeId,
                self::TIMEOUT_SECONDS
            );
            return true;
        } catch (\Exception $e) {
            // Nothing downstream records this one, and the withhold is invisible
            // to buyer and merchant alike. Class and message only.
            $this->logRepository->addErrorLog('Buyer fee quote failed, payment method withheld', [
                'error' => get_class($e),
                'reason' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Admin order create evaluates payment availability against a quote, and
     * the ruling is that no admin path prices a buyer fee.
     */
    private function isAdmin(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (\Exception) {
            return false;
        }
    }
}
