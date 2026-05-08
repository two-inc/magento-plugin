<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Api;

/**
 * Service contract for currency exchange rate lookups.
 *
 * Magento ships no read-side service contract for currency rate lookups — its
 * rate data is only reachable via the legacy Currency active-record model
 * (Magento\Directory\Model\Currency::load). This interface wraps that access
 * so callers see a clean contract, and the one internal implementation is
 * the single place where the phpstan "service contracts" rule is suppressed.
 */
interface CurrencyRatesProviderInterface
{
    /**
     * Get the exchange rate from one currency to another, resolved via the
     * store's configured base-currency rate table.
     *
     * Rates are always computed through base:
     * - from == to           → 1.0
     * - from == base         → base-to-target rate (from DB)
     * - to == base           → inverse of base-to-source rate
     * - neither is base      → cross-rate via base: (base→to) / (base→from)
     *
     * This avoids the stale-direct-cross-rate trap where admins only
     * maintain base→* rates but historic DB entries exist for other pairs.
     *
     * @param string $fromCurrency ISO 4217 code
     * @param string $toCurrency   ISO 4217 code
     * @param int|null $storeId    Store scope; null = current store
     * @return float|null Rate, or null when no rate is configured for the pair
     */
    public function getRate(string $fromCurrency, string $toCurrency, ?int $storeId = null): ?float;
}
