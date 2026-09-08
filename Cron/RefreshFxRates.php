<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Cron;

use Two\Gateway\Service\Fx\RateTableProvider;
use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * Background refresh of the cached FX rate table (every 6 hours).
 *
 * Keeps checkout rate lookups off the fetch path: the read side serves
 * the cached table and only fetches itself when the table is missing or
 * this job has not kept it fresh.
 */
class RefreshFxRates
{
    /** @var RateTableProvider */
    private $rateTableProvider;

    /** @var RecordRefresher */
    private $recordRefresher;

    public function __construct(
        RateTableProvider $rateTableProvider,
        RecordRefresher $recordRefresher
    ) {
        $this->rateTableProvider = $rateTableProvider;
        $this->recordRefresher = $recordRefresher;
    }

    public function execute(): void
    {
        $points = $this->recordRefresher->distinctScopes(
            $this->rateTableIdentity(),
            $this->recordRefresher->storeScopes()
        );
        foreach ($points as $point) {
            $this->rateTableProvider->refresh($point['api_key'], $point['store_id']);
        }
    }

    /**
     * RateTableProvider keys on the API key alone, so two store views sharing
     * a key across environments share one entry and must not both refresh it.
     *
     * @return callable(int|null, string): string
     */
    private function rateTableIdentity(): callable
    {
        return static function (?int $storeId, string $apiKey): string {
            return hash('sha256', $apiKey);
        };
    }
}
