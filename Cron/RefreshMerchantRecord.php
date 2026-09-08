<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Cron;

use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * Hourly check of the cached merchant record: refreshes it once a day old,
 * ahead of the cache lifetime, so a commercial value changed on Two's side
 * lands within a day and no checkout render ever pays for the fetch.
 */
class RefreshMerchantRecord
{
    /**
     * @var RecordRefresher
     */
    private $recordRefresher;

    public function __construct(RecordRefresher $recordRefresher)
    {
        $this->recordRefresher = $recordRefresher;
    }

    public function execute(): void
    {
        $this->recordRefresher->refreshDue();
    }
}
