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
 * When no rate is configured the platform floor fails closed: the
 * method is hidden rather than offered on an order we cannot prove
 * satisfies the funding partner's product minimum. The merchant's own
 * extra minimum (local admin config) fails open instead: a locally
 * misconfigured preference must not block checkout.
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
     * Currency pairs (per fail mode) already reported this request. An
     * unconvertible pair is a stable store misconfiguration, not a
     * per-quote event, and isAvailable() fires many times per page view
     * — one log line per pair is the correct cardinality.
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
     * @return bool false when the quote is below an evaluable minimum, or
     *              when the basket currency / exchange rate cannot be
     *              resolved for the platform floor's currency (fail-closed;
     *              the merchant minimum fails open instead — see the class
     *              docblock for the rationale).
     */
    public function isSatisfied(
        ?array $platformMinimum,
        ?CartInterface $quote,
        ?array $merchantMinimum = null
    ): bool {
        if (!$quote instanceof Quote) {
            return true;
        }

        // Both must be satisfied; only the platform floor fails closed on
        // unconvertible FX (see class docblock).
        if ($platformMinimum !== null
            && !$this->satisfiesMinimum($quote, $platformMinimum, failClosedOnUnconvertible: true)
        ) {
            return false;
        }
        if ($merchantMinimum !== null
            && !$this->satisfiesMinimum($quote, $merchantMinimum, failClosedOnUnconvertible: false)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param array{amount: float, currency: string, basis: string} $minimum
     * @param bool $failClosedOnUnconvertible whether an unresolvable basket
     *             currency or missing/invalid exchange rate blocks the
     *             method (platform floor) or passes the check (merchant's
     *             own extra minimum).
     */
    private function satisfiesMinimum(
        Quote $quote,
        array $minimum,
        bool $failClosedOnUnconvertible
    ): bool {
        $basketValue = $this->basketValue($quote, $minimum['basis']);
        $store = $quote->getStore();
        $quoteCurrency = (string)($quote->getQuoteCurrencyCode()
            ?: ($store !== null ? $store->getBaseCurrencyCode() : ''));

        if ($quoteCurrency === '') {
            return $this->handleUnconvertible('(unresolved)', $minimum['currency'], $failClosedOnUnconvertible);
        }

        if ($quoteCurrency === $minimum['currency']) {
            return $basketValue >= $minimum['amount'];
        }

        $rate = $this->ratesProvider->getRate(
            $quoteCurrency,
            $minimum['currency'],
            $quote->getStoreId() !== null ? (int)$quote->getStoreId() : null
        );
        if ($rate === null || $rate <= 0 || !is_finite($rate)) {
            return $this->handleUnconvertible($quoteCurrency, $minimum['currency'], $failClosedOnUnconvertible);
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
     * or null when no minimum exists / no rate is available. This
     * projection sits on the enforcement paths too — the client
     * visibility gate and the placement backstop both apply the split
     * fail policy to a null — so the unconvertible case is logged
     * through the same channel as satisfiesMinimum(): a fail-closed
     * null must be visible in the monitored error log, never silent.
     *
     * @param array{amount: float, currency: string, basis: string}|null $minimumOrder
     * @param bool $failClosedOnUnconvertible whether the caller treats an
     *             unconvertible minimum as blocking (platform floor) or
     *             skips it (merchant's own extra minimum). Controls the
     *             log channel only; the return value is null either way.
     * @return array{amount: float, basis: string}|null
     */
    public function getMinimumForDisplay(
        ?array $minimumOrder,
        string $currency,
        ?int $storeId,
        bool $failClosedOnUnconvertible
    ): ?array {
        if ($minimumOrder === null) {
            return null;
        }
        $amount = $minimumOrder['amount'];
        if ($currency !== $minimumOrder['currency']) {
            $rate = $this->ratesProvider->getRate($minimumOrder['currency'], $currency, $storeId);
            if ($rate === null || $rate <= 0 || !is_finite($rate)) {
                $this->handleUnconvertible(
                    $minimumOrder['currency'],
                    $currency === '' ? '(unresolved)' : $currency,
                    $failClosedOnUnconvertible
                );
                return null;
            }
            $amount = round($amount * $rate, 2);
        }
        return ['amount' => $amount, 'basis' => $minimumOrder['basis']];
    }

    /**
     * Log an unconvertible currency conversion (basket-to-minimum in
     * satisfiesMinimum(), minimum-to-display in getMinimumForDisplay())
     * and return the satisfiesMinimum() outcome for it: false (blocked)
     * when failing closed, true (treated as satisfied) when failing
     * open. Fail-closed hides the method or rejects the order — a
     * revenue stop — so it lands in the monitored error log; fail-open
     * blocks nothing and logs at debug level.
     */
    private function handleUnconvertible(string $from, string $to, bool $failClosedOnUnconvertible): bool
    {
        $pair = ($failClosedOnUnconvertible ? 'closed:' : 'open:') . $from . '->' . $to;
        if (!isset($this->reportedPairs[$pair])) {
            $this->reportedPairs[$pair] = true;
            if ($failClosedOnUnconvertible) {
                $this->logRepository->addErrorLog(
                    'MinimumOrderGate: cannot convert platform minimum for comparison, '
                        . 'failing closed (method hidden or order rejected)',
                    ['from' => $from, 'to' => $to]
                );
            } else {
                $this->logRepository->addDebugLog(
                    'MinimumOrderGate: cannot convert merchant minimum for comparison, '
                        . 'failing open (merchant minimum skipped)',
                    ['from' => $from, 'to' => $to]
                );
            }
        }
        return !$failClosedOnUnconvertible;
    }
}
