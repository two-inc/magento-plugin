<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Enforces the merchant's minimum order value when deciding whether the
 * payment method is offered at checkout.
 *
 * The minimum is resolved from the Two API by MinimumOrderProvider
 * (`{amount, currency, basis}`) and compares the basket's net (grand
 * total minus tax) or gross value per the declared basis — always
 * explicit, since funding-partner rules and platform country defaults
 * may differ. Baskets in a different currency are converted to the
 * minimum's currency via the store's exchange rates before comparing.
 * When no rate is configured the gate fails closed: the method is
 * hidden rather than offered on an order we cannot prove satisfies the
 * funding partner's product minimum.
 */
class MinimumOrderGate
{
    /**
     * @var CurrencyRatesProviderInterface
     */
    private $ratesProvider;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * Currency pairs already reported this request. The fail-closed
     * condition is a stable store misconfiguration, not a per-quote
     * event, and isAvailable() fires many times per page view — one
     * log line per pair is the correct cardinality.
     *
     * @var array<string,true>
     */
    private $reportedPairs = [];

    public function __construct(
        CurrencyRatesProviderInterface $ratesProvider,
        LogRepository $logRepository
    ) {
        $this->ratesProvider = $ratesProvider;
        $this->logRepository = $logRepository;
    }

    /**
     * Whether the quote satisfies the minimum order value.
     *
     * The platform minimum is passed in by the payment-method instance
     * (resolved from the API by MinimumOrderProvider), keeping the gate a
     * pure comparison. Non-Quote CartInterface implementations pass the
     * gate: every storefront/GraphQL/admin checkout flow hands the
     * concrete Quote model to isAvailable(), and hiding the method on an
     * unknown cart type would be a worse failure than skipping the
     * minimum.
     *
     * @param array{amount: float, currency: string, basis: string}|null $platformMinimum
     * @param array{amount: float, currency: string, basis: string}|null $merchantMinimum
     * @return bool false when the basket currency or an exchange rate
     *              cannot be resolved for a cross-currency basket
     *              (fail-closed).
     */
    public function isSatisfied(
        ?array $platformMinimum,
        ?CartInterface $quote,
        ?array $merchantMinimum = null
    ): bool {
        if (!$quote instanceof Quote) {
            return true;
        }

        // The platform minimum is the funding-partner floor; the merchant
        // minimum (admin setting, validated to meet or exceed the floor on save)
        // may only raise the bar — both must be satisfied.
        foreach ([$platformMinimum, $merchantMinimum] as $minimum) {
            if ($minimum !== null && !$this->satisfiesMinimum($quote, $minimum)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{amount: float, currency: string, basis: string} $minimum
     */
    private function satisfiesMinimum(Quote $quote, array $minimum): bool
    {
        $basketValue = $this->basketValue($quote, $minimum['basis']);
        $store = $quote->getStore();
        $quoteCurrency = (string)($quote->getQuoteCurrencyCode()
            ?: ($store !== null ? $store->getBaseCurrencyCode() : ''));

        if ($quoteCurrency === '') {
            $this->reportFailClosed('(unresolved)', $minimum['currency']);
            return false;
        }

        if ($quoteCurrency === $minimum['currency']) {
            return $basketValue >= $minimum['amount'];
        }

        $rate = $this->ratesProvider->getRate(
            $quoteCurrency,
            $minimum['currency'],
            $quote->getStoreId() !== null ? (int)$quote->getStoreId() : null
        );
        if ($rate === null || $rate <= 0) {
            $this->reportFailClosed($quoteCurrency, $minimum['currency']);
            return false;
        }

        // Compare at currency precision: full-precision arithmetic,
        // rounded once at the decision boundary (the plugin-wide model).
        return round($basketValue * $rate, 2) >= $minimum['amount'];
    }

    /**
     * The basket value the minimum compares against, per the minimum's
     * declared basis (net = grand total minus tax, gross = grand total).
     */
    private function basketValue(Quote $quote, string $basis): float
    {
        if ($basis === 'gross') {
            return (float)$quote->getGrandTotal();
        }
        $taxAmount = 0.0;
        foreach ($quote->getAllAddresses() as $address) {
            $taxAmount += (float)$address->getTaxAmount();
        }
        return (float)$quote->getGrandTotal() - $taxAmount;
    }

    /**
     * Whether an order value (in $currency, on the minimum's basis) is
     * strictly below the minimum — the fallback signal for the
     * decline hint when the API response carries no machine-readable
     * decline reason. Fail-soft: unresolvable conversion means no hint,
     * never a blocked message.
     */
    public function isBelowMinimum(
        ?array $minimumOrder,
        float $amount,
        string $currency,
        ?int $storeId
    ): bool {
        if ($minimumOrder === null) {
            return false;
        }

        if ($currency !== $minimumOrder['currency']) {
            $rate = $this->ratesProvider->getRate($currency, $minimumOrder['currency'], $storeId);
            if ($rate === null || $rate <= 0) {
                return false;
            }
            $amount = round($amount * $rate, 2);
        }

        return $amount < $minimumOrder['amount'];
    }

    /**
     * The minimum expressed in $currency for buyer-facing display,
     * or null when no minimum exists / no rate is available.
     *
     * @return array{amount: float, basis: string}|null
     */
    public function getMinimumForDisplay(
        ?array $minimumOrder,
        string $currency,
        ?int $storeId
    ): ?array {
        if ($minimumOrder === null) {
            return null;
        }
        $amount = $minimumOrder['amount'];
        if ($currency !== $minimumOrder['currency']) {
            $rate = $this->ratesProvider->getRate($minimumOrder['currency'], $currency, $storeId);
            if ($rate === null || $rate <= 0) {
                return null;
            }
            $amount = round($amount * $rate, 2);
        }
        return ['amount' => $amount, 'basis' => $minimumOrder['basis']];
    }

    /**
     * Failing closed hides the payment method outright — a revenue stop
     * if the cause is a missing exchange rate on a live store — so it
     * must land in the monitored error log, not the debug log.
     */
    private function reportFailClosed(string $from, string $to): void
    {
        $pair = $from . '->' . $to;
        if (isset($this->reportedPairs[$pair])) {
            return;
        }
        $this->reportedPairs[$pair] = true;
        $this->logRepository->addErrorLog(
            'MinimumOrderGate: cannot convert basket to minimum currency, hiding payment method',
            ['from' => $from, 'to' => $to]
        );
    }
}
