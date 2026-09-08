<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Cron;

use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * Nightly refresh of the cached merchant record, so a commercial value
 * changed on Two's side lands without a checkout render paying for the
 * fetch; without this job only expiry refreshes it.
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
        $this->recordRefresher->refreshAll();
    }
}
