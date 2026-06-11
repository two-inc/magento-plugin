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
 * Enforces a brand's minimum order value (e.g. the ABN AMRO product
 * requires a €250 minimum) when deciding whether the payment method
 * is offered at checkout.
 *
 * The minimum is declared per brand in brand.xml as
 * `<minimum_order amount="250" currency="EUR"/>` and compares the
 * basket's NET total (grand total minus tax) — the funding partner's
 * risk rule enforces the same net semantics server-side. Baskets in a
 * different currency are converted to the brand currency via the
 * store's exchange rates before comparing. When no rate is
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
    public function isSatisfied(BrandRegistryInterface $brand, ?CartInterface $quote): bool
    {
        $minimumOrder = $brand->getMinimumOrder();
        if ($minimumOrder === null || !$quote instanceof Quote) {
            return true;
        }

        // Net basket value: the server-side funding-partner rule compares
        // net, so the display gate must too — a gross-compared gate shows
        // the method on baskets the credit check then declines.
        $taxAmount = 0.0;
        foreach ($quote->getAllAddresses() as $address) {
            $taxAmount += (float)$address->getTaxAmount();
        }
        $netTotal = (float)$quote->getGrandTotal() - $taxAmount;
        $store = $quote->getStore();
        $quoteCurrency = (string)($quote->getQuoteCurrencyCode()
            ?: ($store !== null ? $store->getBaseCurrencyCode() : ''));

        if ($quoteCurrency === '') {
            $this->reportFailClosed('(unresolved)', $minimumOrder['currency']);
            return false;
        }

        if ($quoteCurrency === $minimumOrder['currency']) {
            return $netTotal >= $minimumOrder['amount'];
        }

        $rate = $this->ratesProvider->getRate(
            $quoteCurrency,
            $minimumOrder['currency'],
            $quote->getStoreId() !== null ? (int)$quote->getStoreId() : null
        );
        if ($rate === null || $rate <= 0) {
            $this->reportFailClosed($quoteCurrency, $minimumOrder['currency']);
            return false;
        }

        // Compare at currency precision: full-precision arithmetic,
        // rounded once at the decision boundary (the plugin-wide model).
        return round($netTotal * $rate, 2) >= $minimumOrder['amount'];
    }

    /**
     * Whether a net amount sits below (or within a small band above) the
     * brand's minimum — used to decide if a backend decline should carry
     * the minimum-order hint. The band covers rate skew between this
     * plugin's store FX rates and the risk engine's spot rates: a basket
     * that passed the display gate here can still be marginally below
     * the threshold by the backend's rates. Fail-soft: unresolvable
     * conversion means no hint, never a blocked message.
     *
     * @param float $netAmount Net amount in $currency
     */
    public function isNearOrBelowMinimum(
        BrandRegistryInterface $brand,
        float $netAmount,
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
            $netAmount = round($netAmount * $rate, 2);
        }

        return $netAmount < $minimumOrder['amount'] * 1.05;
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
