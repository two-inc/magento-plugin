<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Enforces a brand's minimum order value when deciding whether the
 * payment method is offered at checkout.
 *
 * The minimum is declared per brand in brand.xml as
 * `<minimum_order amount="250" currency="EUR" basis="net"/>` and
 * compares the basket's net (grand total minus tax) or gross value per
 * the declared basis — always explicit, since funding-partner rules and
 * platform country defaults may differ. Baskets in a different
 * currency are converted to the brand currency via the store's
 * exchange rates before comparing. When no rate is
 * configured the gate fails closed: the method is hidden rather
 * than offered on an order we cannot prove satisfies the brand's
 * product minimum.
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
     * Whether the quote satisfies the brand's minimum order value.
     *
     * The brand is passed in by the payment-method instance rather than
     * resolved from DI here, keeping the gate decoupled from brand
     * resolution; ActiveBrandResolver guarantees a single active brand
     * per install. Non-Quote CartInterface implementations pass the gate:
     * every storefront/GraphQL/admin checkout flow hands the concrete
     * Quote model to isAvailable(), and hiding the method on an unknown
     * cart type would be a worse failure than skipping the minimum.
     *
     * @return bool false when the basket currency or an exchange rate
     *              cannot be resolved for a cross-currency basket
     *              (fail-closed).
     */
    public function isSatisfied(
        BrandRegistryInterface $brand,
        ?CartInterface $quote,
        ?array $merchantMinimum = null
    ): bool {
        if (!$quote instanceof Quote) {
            return true;
        }

        // The brand minimum is the platform/partner floor; the merchant
        // minimum (admin setting, validated to exceed the floor on save)
        // may only raise the bar — both must be satisfied.
        foreach ([$brand->getMinimumOrder(), $merchantMinimum] as $minimum) {
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
     * The basket value the minimum compares against, per the brand's
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
     * Whether an order value (in $currency, on the brand's basis) is
     * strictly below the brand's minimum — the fallback signal for the
     * decline hint when the API response carries no machine-readable
     * decline reason. Fail-soft: unresolvable conversion means no hint,
     * never a blocked message.
     */
    public function isBelowMinimum(
        BrandRegistryInterface $brand,
        float $amount,
        string $currency,
        ?int $storeId
    ): bool {
        $minimumOrder = $brand->getMinimumOrder();
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
     * The brand minimum expressed in $currency for buyer-facing display,
     * or null when no minimum exists / no rate is available.
     *
     * @return array{amount: float, basis: string}|null
     */
    public function getMinimumForDisplay(
        BrandRegistryInterface $brand,
        string $currency,
        ?int $storeId
    ): ?array {
        $minimumOrder = $brand->getMinimumOrder();
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
            'MinimumOrderGate: cannot convert basket to brand currency, hiding payment method',
            ['from' => $from, 'to' => $to]
        );
    }
}
