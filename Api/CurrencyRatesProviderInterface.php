<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Service contract for currency exchange rate lookups.
 *
 * Rates are sourced from Two's EUR-pivot spot-rate table
 * (GET /refdata/v1/fx-rates), cached with a 6h background refresh and a
 * last-known-good fallback, so conversions use the same rates Two applies
 * server-side. Callers depend on this contract; the fetch/cache protocol
 * lives behind the single implementation.
 */
interface CurrencyRatesProviderInterface
{
    /**
     * Get the exchange rate from one currency to another, computed through
     * the EUR pivot of Two's spot-rate table: one unit of $fromCurrency is
     * worth `rate` units of $toCurrency.
     *
     * Null means the pair cannot currently be converted — a currency absent
     * from the table, or no table has ever been fetched (e.g. no API key
     * configured, or the very first fetch failed). Once a table has been
     * fetched, lookups keep resolving from the last-known-good table even
     * when refreshes fail; callers apply their own posture to null
     * (minimum-order platform floor fails closed, merchant bar fails open,
     * display conversions fail soft).
     *
     * @param string $fromCurrency ISO 4217 code
     * @param string $toCurrency   ISO 4217 code
     * @param int|null $storeId    Store scope for API-key resolution; null = default scope
     * @return float|null Rate, or null when the pair cannot be resolved
     */
    public function getRate(string $fromCurrency, string $toCurrency, ?int $storeId = null): ?float;
}
