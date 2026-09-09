<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Cron\RefreshMerchantRecord;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * The hourly merchant-record job and the schedule it is declared on.
 */
class RefreshMerchantRecordTest extends TestCase
{
    public function testTheJobRefreshesWhatIsDue(): void
    {
        $refresher = $this->createMock(RecordRefresher::class);
        $refresher->expects($this->once())->method('refreshDue');

        (new RefreshMerchantRecord($refresher))->execute();
    }

    public function testTheDeclaredScheduleIsHourlyAndMatchesTheProvidersInterval(): void
    {
        // Refresh-ahead only holds if the job actually runs once per CRON_INTERVAL.
        $crontab = simplexml_load_file(__DIR__ . '/../../../etc/crontab.xml');
        $schedule = (string)$crontab->xpath('//job[@name="two_gateway_refresh_merchant_record"]/schedule')[0];

        $this->assertSame('0 * * * *', $schedule);
        $this->assertSame(3600, RecordProvider::CRON_INTERVAL);
    }

    public function testTheRecordIsNeverEvictedWhileTheCronRunsOnSchedule(): void
    {
        // Refreshed at MAX_AGE, at most one interval late, still inside the lifetime.
        $this->assertGreaterThan(
            RecordProvider::MAX_AGE + RecordProvider::CRON_INTERVAL,
            RecordProvider::CACHE_LIFETIME
        );
    }
}
