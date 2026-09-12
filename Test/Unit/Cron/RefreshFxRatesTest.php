<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Cron;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Cron\RefreshFxRates;
use Two\Gateway\Service\Fx\RateTableProvider;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\RecordRefresher;
use Two\Gateway\Test\Unit\Service\Merchant\ConfiguresScopes;

/**
 * The FX rate cron, driven through the real scope walk so the key the walk
 * dedups on and the key the table is refreshed under are the same read.
 */
class RefreshFxRatesTest extends TestCase
{
    use ConfiguresScopes;

    /** @var RateTableProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $rateTableProvider;

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    protected function setUp(): void
    {
        $this->rateTableProvider = $this->createMock(RateTableProvider::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
    }

    /**
     * @param array<int,int> $stores
     * @param array<string,array{0: string, 1: string}> $config
     * @param array<int,string> $expectedSlots mode:key per refresh, in order
     * @dataProvider scopeSets
     */
    public function testRefreshesEachRateTableOnce(
        array $stores,
        array $config,
        ?int $currentStoreId,
        array $expectedSlots,
        string $description
    ): void {
        $this->configure($stores, $config, $currentStoreId);
        $refreshed = [];
        $this->rateTableProvider->method('refresh')->willReturnCallback(
            function (string $mode, string $apiKey) use (&$refreshed) {
                $refreshed[] = $mode . ':' . $apiKey;
                return true;
            }
        );

        $this->cron()->execute();

        $this->assertSame($expectedSlots, $refreshed, $description);
    }

    /**
     * @return array<string, array{0: array<int,int>, 1: array<string,array{0: string, 1: string}>, 2: int|null, 3: array<int,string>, 4: string}>
     */
    public static function scopeSets(): array
    {
        return [
            'default only' => [
                [1 => 1],
                ['default:' => ['key-d', 'sandbox']],
                1,
                ['sandbox:key-d'],
                'a single-store install refreshes one table',
            ],
            'default plus an override' => [
                [1 => 1, 2 => 1],
                ['default:' => ['key-d', 'sandbox'], '2' => ['key-s', 'sandbox']],
                1,
                ['sandbox:key-d', 'sandbox:key-s'],
                'one refresh per distinct key',
            ],
            'current store overrides, sibling inherits' => [
                [1 => 1, 2 => 1],
                ['default:' => ['key-d', 'sandbox'], '1' => ['key-s', 'sandbox']],
                1,
                ['sandbox:key-d', 'sandbox:key-s'],
                'the default table is refreshed once and the override once, whatever the cron area\'s current store',
            ],
            'shared key, split environments' => [
                [1 => 1, 2 => 1],
                ['default:' => ['key-d', 'sandbox'], '2' => ['key-d', 'production']],
                1,
                ['sandbox:key-d', 'production:key-d'],
                'one key against both environments holds two tables and needs both refreshed',
            ],
            'nothing configured' => [
                [1 => 1],
                [],
                1,
                [],
                'no API key anywhere means no call',
            ],
        ];
    }

    private function cron(): RefreshFxRates
    {
        $refresher = new RecordRefresher(
            $this->createMock(RecordProvider::class),
            $this->storeManager,
            $this->configRepository,
            $this->createMock(LogRepository::class)
        );

        return new RefreshFxRates($this->rateTableProvider, $refresher);
    }
}
