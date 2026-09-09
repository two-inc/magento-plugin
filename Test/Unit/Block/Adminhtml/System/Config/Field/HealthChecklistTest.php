<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\System\Config\Field;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Block\Adminhtml\System\Config\Field\HealthChecklist;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;

/**
 * TWO-25386: the admin "Health checklist" panel. Four checks: API key,
 * environment, SSL verification, merchant profile refresh.
 */
class HealthChecklistTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var ApiKeyStatus|\PHPUnit\Framework\MockObject\MockObject */
    private $apiKeyStatus;

    /** @var RecordProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $recordProvider;

    /** @var HealthChecklist */
    private $block;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $this->recordProvider = $this->createMock(RecordProvider::class);
        $this->recordProvider->method('status')
            ->willReturn(['fetched_at' => time() - 60, 'absent_on_read_at' => null]);

        $this->block = new HealthChecklistTestable();
        $this->block->setDependencies($this->configRepository, $this->apiKeyStatus, $this->recordProvider);
    }

    /**
     * @param array{fetched_at: int|null, absent_on_read_at: int|null} $status
     * @dataProvider refreshStates
     */
    public function testTheMerchantProfileRowReportsTheRefresh(
        array $status,
        bool $expectedOk,
        string $expectedFragment,
        string $description
    ): void {
        $this->recordProvider = $this->createMock(RecordProvider::class);
        // The panel must read the identity it is rendering, not another environment's stamp.
        $this->recordProvider->expects($this->once())->method('status')
            ->with('sandbox', 'key-a')
            ->willReturn($status);
        $this->block->setDependencies($this->configRepository, $this->apiKeyStatus, $this->recordProvider);
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('getApiKey')->willReturn('key-a');

        $row = $this->block->getChecklistRows()[3];

        $this->assertSame('Merchant profile', $row['label'], $description);
        $this->assertSame($expectedOk, $row['ok'], $description);
        $this->assertStringContainsString($expectedFragment, $row['value'], $description);
    }

    /**
     * @return array<string, array{0: array{fetched_at: int|null, absent_on_read_at: int|null}, 1: bool, 2: string, 3: string}>
     */
    public static function refreshStates(): array
    {
        // Ages, not fixed instants: the row now judges the stamp against the
        // staleness bound, so a stamp from 2023 is stale rather than healthy.
        $recent = time() - 60;
        $stale = time() - RecordProvider::STALE_AFTER - 1;

        return [
            'refreshed' => [
                ['fetched_at' => $recent, 'absent_on_read_at' => null],
                true,
                'Refreshed @' . $recent,
                'a refreshed profile shows when',
            ],
            'never refreshed' => [
                ['fetched_at' => null, 'absent_on_read_at' => null],
                false,
                'Never refreshed',
                'no stamp yet is not ok',
            ],
            'absent on read, unclaimed for longer than a cron run' => [
                ['fetched_at' => $recent, 'absent_on_read_at' => time() - RecordProvider::CRON_INTERVAL - 1],
                false,
                'hourly refresh appears not to be running',
                'a read miss the cron never cleared outranks a stamp',
            ],
            'absent on read, within this cron interval' => [
                ['fetched_at' => $recent, 'absent_on_read_at' => time()],
                true,
                'Refreshed @' . $recent,
                'a read miss the cron has not had a run to clear is the ordinary first read',
            ],
            'stamp older than the staleness bound' => [
                ['fetched_at' => $stale, 'absent_on_read_at' => null],
                false,
                'hourly refresh appears not to be running',
                'a record the cron has stopped refreshing is reported, and still served',
            ],
        ];
    }

    public function testAllHealthyRows(): void
    {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('production');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(false);

        $rows = $this->block->getChecklistRows();

        $this->assertTrue($rows[0]['ok']);
        $this->assertSame('PRODUCTION', $rows[1]['value']);
        $this->assertTrue($rows[2]['ok']);
        $this->assertFalse($this->block->isProductionWithSslDisabled());
    }

    public function testUnverifiedApiKeyRowIsNotOk(): void
    {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::INVALID_KEY]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(false);

        $rows = $this->block->getChecklistRows();

        $this->assertFalse($rows[0]['ok']);
    }

    public function testSslDisabledRowIsNotOk(): void
    {
        $this->apiKeyStatus->method('getStatus')->willReturn(['status' => ApiKeyStatus::OK]);
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $rows = $this->block->getChecklistRows();

        $this->assertFalse($rows[2]['ok']);
    }

    public function testProductionWithSslDisabledWarns(): void
    {
        $this->configRepository->method('getMode')->willReturn('production');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $this->assertTrue($this->block->isProductionWithSslDisabled());
    }

    public function testSandboxWithSslDisabledDoesNotWarn(): void
    {
        $this->configRepository->method('getMode')->willReturn('sandbox');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $this->assertFalse($this->block->isProductionWithSslDisabled());
    }
}

/**
 * Constructor-free subclass — the heavy Field base constructor needs a
 * full Magento admin Context, which this exercises no need for.
 */
class HealthChecklistTestable extends HealthChecklist
{
    public function __construct()
    {
    }

    public function setDependencies(
        ConfigRepository $configRepository,
        ApiKeyStatus $apiKeyStatus,
        RecordProvider $recordProvider
    ): void {
        $ref = new \ReflectionClass(HealthChecklist::class);

        $recordProp = $ref->getProperty('recordProvider');
        $recordProp->setAccessible(true);
        $recordProp->setValue($this, $recordProvider);

        $configProp = $ref->getProperty('configRepository');
        $configProp->setAccessible(true);
        $configProp->setValue($this, $configRepository);

        $apiKeyProp = $ref->getProperty('apiKeyStatus');
        $apiKeyProp->setAccessible(true);
        $apiKeyProp->setValue($this, $apiKeyStatus);
    }

    /** The real one needs the locale from Context; render the epoch instead. */
    protected function formatTimestamp(int $timestamp): string
    {
        return '@' . $timestamp;
    }
}
