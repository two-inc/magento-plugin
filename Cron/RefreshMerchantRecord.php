<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Cron;

use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * Hourly check of the cached merchant record: refreshes it once a day old, so
 * a commercial value changed on Two's side lands within a day and no checkout
 * render pays for the fetch. The record itself has no expiry — this job is the
 * only thing that replaces it, and a read only stands in once it is
 * STALE_AFTER old, which is what says this job is not running.
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
