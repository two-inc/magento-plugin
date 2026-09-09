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
        $identities = $this->recordRefresher->distinctScopes($this->recordRefresher->storeScopes());
        foreach ($identities as $identity) {
            $this->rateTableProvider->refresh(
                $identity['mode'],
                $identity['api_key'],
                $identity['store_id']
            );
        }
    }
}
