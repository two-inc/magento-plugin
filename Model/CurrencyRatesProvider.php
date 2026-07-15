<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model;

use Two\Gateway\Api\CurrencyRatesProviderInterface;
use Two\Gateway\Service\Fx\RateTableProvider;

/**
 * Resolves exchange rates from Two's EUR-pivot spot-rate table
 * (GET /refdata/v1/fx-rates), replacing the retired Magento Directory
 * rate-table source (TWO-25103).
 *
 * The table maps each currency to the EUR value of one unit, so any
 * cross rate is computed through the EUR pivot without depending on the
 * store's base currency or admin-maintained Directory rates. Fetching
 * and caching (6h background refresh, last-known-good fallback) is owned
 * by {@see RateTableProvider}.
 */
class CurrencyRatesProvider implements CurrencyRatesProviderInterface
{
    /** @var RateTableProvider */
    private $rateTableProvider;

    public function __construct(RateTableProvider $rateTableProvider)
    {
        $this->rateTableProvider = $rateTableProvider;
    }

    /**
     * @inheritDoc
     */
    public function getRate(string $fromCurrency, string $toCurrency, ?int $storeId = null): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $table = $this->rateTableProvider->getRateTable($storeId);
        if ($table === null) {
            return null;
        }

        $rates = $table['rates'];
        $fromInEur = $rates[$fromCurrency] ?? 0.0;
        $toInEur = $rates[$toCurrency] ?? 0.0;
        if ($fromInEur <= 0 || $toInEur <= 0) {
            return null;
        }

        // rates[CCY] is the EUR value of 1 CCY, so units of `to` per one
        // `from` is rate(from) / rate(to) — the same computation the
        // endpoint performs for its single cross-rate form.
        return $fromInEur / $toInEur;
    }
}
