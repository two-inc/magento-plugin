<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * The scope -> cache-identity mapping behind the hourly cron, the API-key /
 * environment save and the Diagnostics button.
 */
class RecordRefresherTest extends TestCase
{
    use ConfiguresScopes;

    /** @var RecordProvider|\PHPUnit\Framework\MockObject\MockObject */
    private $recordProvider;

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    protected function setUp(): void
    {
        $this->recordProvider = $this->createMock(RecordProvider::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->logRepository = $this->createMock(LogRepository::class);
    }

    private function refresher(): RecordRefresher
    {
        return new RecordRefresher(
            $this->recordProvider,
            $this->storeManager,
            $this->configRepository,
            $this->logRepository
        );
    }

    public function testTheDefaultScopeIsReadAsSuchNotThroughTheCurrentStore(): void
    {
        // Under cron the current store is the default store view, which may override the key.
        $this->configure(
            [1 => 1, 2 => 1],
            ['default:' => ['key-d', 'sandbox'], '1' => ['key-s', 'sandbox']],
            1
        );

        $this->assertSame(
            [['mode' => 'sandbox', 'api_key' => 'key-d', 'store_id' => null]],
            $this->refresher()->governedIdentities('default', 0),
            'a default save governs the default key, read at the default scope'
        );
    }

    /**
     * @param array<int,string> $apiKeys one identity per key, the ones named in $throwing throw
     * @param array<int,string> $throwing
     * @param array<int,array<string,mixed>|null> $expectedRecords
     * @dataProvider budgets
     */
    public function testRefreshWithinAttemptsInOrderContainsThrowsAndStopsAtTheBudget(
        array $apiKeys,
        array $throwing,
        float $budget,
        array $expectedRecords,
        int $expectedSkipped,
        int $expectedErrors,
        string $description
    ): void {
        $identities = array_map(static function (string $apiKey): array {
            return ['mode' => 'sandbox', 'api_key' => $apiKey, 'store_id' => null];
        }, $apiKeys);
        $this->recordProvider->method('refresh')->willReturnCallback(
            function (string $mode, string $apiKey) use ($throwing) {
                if (in_array($apiKey, $throwing, true)) {
                    throw new \InvalidArgumentException('Unable to serialize value.');
                }
                return ['id' => $apiKey];
            }
        );
        $this->logRepository->expects($this->exactly($expectedErrors))->method('addErrorLog');

        $outcome = $this->refresher()->refreshWithin($identities, $budget);

        $this->assertSame(['records' => $expectedRecords, 'skipped' => $expectedSkipped], $outcome, $description);
    }

    /**
     * @return array<string, array{0: array<int,string>, 1: array<int,string>, 2: float, 3: array<int,array<string,mixed>|null>, 4: int, 5: int, 6: string}>
     */
    public static function budgets(): array
    {
        return [
            'all within budget' => [
                ['a', 'b'],
                [],
                INF,
                [['id' => 'a'], ['id' => 'b']],
                0,
                0,
                'every identity attempted, in order',
            ],
            'one throws' => [
                ['a', 'b', 'c'],
                ['b'],
                INF,
                [['id' => 'a'], null, ['id' => 'c']],
                0,
                1,
                'a throwing identity is a failed record, logged, and the rest still run',
            ],
            'budget spent' => [
                ['a', 'b', 'c'],
                [],
                0.0,
                [['id' => 'a']],
                2,
                0,
                'the first identity is always attempted; the rest are skipped once the budget is spent',
            ],
            'nothing to do' => [
                [],
                [],
                0.0,
                [],
                0,
                0,
                'no identities, no attempts',
            ],
        ];
    }

    /**
     * @param array<int,int> $stores
     * @param array<string,array{0: string, 1: string}> $config
     * @param array<int,array{0: string, 1: string, 2: int|null}> $expected
     * @dataProvider allScopeSets
     */
    public function testTheCronRefreshesOncePerDistinctModeAndApiKey(
        array $stores,
        array $config,
        array $expected,
        string $description
    ): void {
        // Same (mode, key) is one cache entry; same key on two environments is two.
        $this->configure($stores, $config);
        $this->recordProvider->method('isDue')->willReturn(true);
        $calls = [];
        $this->recordProvider->method('refresh')->willReturnCallback(
            function (string $mode, string $apiKey, ?int $storeId) use (&$calls) {
                $calls[] = [$mode, $apiKey, $storeId];
                return null;
            }
        );

        $this->refresher()->refreshDue();

        $this->assertSame($expected, $calls, $description);
    }

    public function testTheCronRefreshesOnlyWhatIsDueAndNotesItsRunForEveryIdentity(): void
    {
        // A record under a day old is left alone; the run is still recorded so a read miss before it stops signalling.
        $this->configure(
            [1 => 1, 2 => 1],
            ['default:' => ['key-a', 'sandbox'], '2' => ['key-b', 'production']]
        );
        $this->recordProvider->method('isDue')->willReturnCallback(
            static function (string $mode, string $apiKey): bool {
                return $apiKey === 'key-b';
            }
        );
        $noted = [];
        $this->recordProvider->method('noteScheduledRun')->willReturnCallback(
            static function (string $mode, string $apiKey) use (&$noted): void {
                $noted[] = [$mode, $apiKey];
            }
        );
        $refreshed = [];
        $this->recordProvider->method('refresh')->willReturnCallback(
            static function (string $mode, string $apiKey) use (&$refreshed) {
                $refreshed[] = [$mode, $apiKey];
                return ['id' => $apiKey];
            }
        );

        $this->refresher()->refreshDue();

        $this->assertSame([['sandbox', 'key-a'], ['production', 'key-b']], $noted);
        $this->assertSame([['production', 'key-b']], $refreshed);
    }

    /**
     * @return array<string, array{0: array<int,int>, 1: array<string,array{0: string, 1: string}>, 2: array<int,array{0: string, 1: string, 2: int|null}>, 3: string}>
     */
    public static function allScopeSets(): array
    {
        return [
            'shared key and mode' => [
                [1 => 1, 2 => 1],
                ['default:' => ['key-a', 'sandbox'], '1' => ['key-a', 'sandbox'], '2' => ['key-a', 'sandbox']],
                [['sandbox', 'key-a', null]],
                'one key on one environment across every scope is one refresh',
            ],
            'per-store key override' => [
                [1 => 1, 2 => 1],
                ['default:' => ['key-a', 'sandbox'], '1' => ['key-a', 'sandbox'], '2' => ['key-b', 'sandbox']],
                [['sandbox', 'key-a', null], ['sandbox', 'key-b', 2]],
                'a store with its own key gets its own refresh',
            ],
            'shared key, split environments' => [
                [1 => 1, 2 => 1],
                ['default:' => ['key-a', 'sandbox'], '1' => ['key-a', 'sandbox'], '2' => ['key-a', 'production']],
                [['sandbox', 'key-a', null], ['production', 'key-a', 2]],
                'the same key on two environments is two merchants, so two refreshes',
            ],
            'no key anywhere' => [
                [1 => 1],
                [],
                [],
                'nothing to refresh without a key',
            ],
            'key only at store scope' => [
                [1 => 1],
                ['1' => ['key-a', 'sandbox']],
                [['sandbox', 'key-a', 1]],
                'an unconfigured default scope does not block a configured store',
            ],
            'no store views' => [
                [],
                ['default:' => ['key-a', 'sandbox']],
                [['sandbox', 'key-a', null]],
                'a single-store install refreshes the default scope',
            ],
        ];
    }

    /**
     * @param array<int,int> $stores
     * @param array<string,array{0: string, 1: string}> $config
     * @param array<int,array{mode: string, api_key: string, store_id: int|null}> $expected
     * @dataProvider governedScopes
     */
    public function testAScopeGovernsTheIdentitiesReadThroughItsApiKey(
        array $stores,
        array $config,
        string $scope,
        int $scopeId,
        array $expected,
        string $description
    ): void {
        // A scope governs exactly the identities whose key is the one set there.
        $this->configure($stores, $config);

        $this->assertSame($expected, $this->refresher()->governedIdentities($scope, $scopeId), $description);
    }

    /**
     * @return array<string, array{0: array<int,int>, 1: array<string,array{0: string, 1: string}>, 2: string, 3: int, 4: array<int,array{mode: string, api_key: string, store_id: int|null}>, 5: string}>
     */
    public static function governedScopes(): array
    {
        $website = ['websites:1' => ['key-w', 'sandbox']];
        $default = ['default:' => ['key-d', 'sandbox']];

        return [
            'store save' => [
                [7 => 1],
                ['7' => ['key-s', 'production']],
                'stores',
                7,
                [['mode' => 'production', 'api_key' => 'key-s', 'store_id' => 7]],
                'a store save governs exactly that store view\'s identity',
            ],
            'website save, default store overrides' => [
                [1 => 1, 2 => 1],
                $website + ['1' => ['key-s', 'sandbox'], '2' => ['key-w', 'sandbox']],
                'websites',
                1,
                [['mode' => 'sandbox', 'api_key' => 'key-w', 'store_id' => 2]],
                'the website key is refreshed through the store view that inherits it, not the default store\'s override',
            ],
            'website save, website itself inherits the default key' => [
                [1 => 1, 2 => 1],
                ['default:' => ['key-d', 'sandbox']],
                'websites',
                1,
                [['mode' => 'sandbox', 'api_key' => 'key-d', 'store_id' => 1]],
                'a website with no key of its own reads the default key, and so do its store views',
            ],
            'website save, inheriting store views share one identity' => [
                [1 => 1, 2 => 1, 3 => 2],
                $website + ['1' => ['key-w', 'sandbox'], '2' => ['key-w', 'sandbox'], '3' => ['key-w', 'sandbox']],
                'websites',
                1,
                [['mode' => 'sandbox', 'api_key' => 'key-w', 'store_id' => 1]],
                'one identity, one refresh; a store view in another website is not under this scope',
            ],
            'website save, environment split over the inherited key' => [
                [1 => 1, 2 => 1],
                $website + ['1' => ['key-w', 'sandbox'], '2' => ['key-w', 'production']],
                'websites',
                1,
                [
                    ['mode' => 'sandbox', 'api_key' => 'key-w', 'store_id' => 1],
                    ['mode' => 'production', 'api_key' => 'key-w', 'store_id' => 2],
                ],
                'a store view overriding only the environment still reads the saved key, so both merchants refresh',
            ],
            'default save' => [
                [1 => 1, 2 => 1],
                $default + ['1' => ['key-d', 'sandbox'], '2' => ['key-s', 'sandbox']],
                'default',
                0,
                [['mode' => 'sandbox', 'api_key' => 'key-d', 'store_id' => null]],
                'a default save governs the default identity and not a store view with its own key',
            ],
            'unknown scope reads as default' => [
                [],
                $default,
                'groups',
                0,
                [['mode' => 'sandbox', 'api_key' => 'key-d', 'store_id' => null]],
                'anything but websites/stores is the default scope',
            ],
        ];
    }

    /**
     * @param array<int,int> $stores
     * @param array<string,array{0: string, 1: string}> $config
     * @dataProvider ungovernedScopes
     */
    public function testAScopeNothingReadsThroughSaysWhy(
        array $stores,
        array $config,
        string $scope,
        int $scopeId,
        string $exception,
        string $fragment,
        string $description
    ): void {
        $this->configure($stores, $config);
        $this->recordProvider->expects($this->never())->method('refresh');

        try {
            $this->refresher()->governedIdentities($scope, $scopeId);
            $this->fail($description);
        } catch (LocalizedException $e) {
            $this->assertInstanceOf($exception, $e, $description);
            $this->assertStringContainsString($fragment, $e->getMessage(), $description);
        }
    }

    /**
     * @return array<string, array{0: array<int,int>, 1: array<string,array{0: string, 1: string}>, 2: string, 3: int, 4: string, 5: string, 6: string}>
     */
    public static function ungovernedScopes(): array
    {
        $website = ['websites:1' => ['key-w', 'sandbox']];

        return [
            'website gone' => [
                [1 => 1],
                $website,
                'websites',
                5,
                NoSuchEntityException::class,
                'gone',
                'a deleted website is not the default scope',
            ],
            'store view gone' => [
                [1 => 1],
                ['5' => ['key-s', 'sandbox']],
                'stores',
                5,
                NoSuchEntityException::class,
                'gone',
                'a deleted store view is not the default scope',
            ],
            'store id 0' => [
                [1 => 1],
                ['0' => ['key-s', 'sandbox'], 'default:' => ['key-s', 'sandbox']],
                'stores',
                0,
                NoSuchEntityException::class,
                'store_id',
                'the admin store is not a store view and must not become the default scope',
            ],
            'website id 0' => [
                [1 => 1],
                ['websites:0' => ['key-w', 'sandbox']],
                'websites',
                0,
                NoSuchEntityException::class,
                'website_id',
                'a website id of 0 must not become the default scope',
            ],
            'no key at the saved scope' => [
                [1 => 1],
                ['1' => ['key-s', 'sandbox']],
                'websites',
                1,
                LocalizedException::class,
                'No API key',
                'a website with no key in effect has no merchant profile',
            ],
            'website without store views' => [
                [1 => 1],
                ['websites:9' => ['key-w', 'sandbox']],
                'websites',
                9,
                LocalizedException::class,
                'no store view',
                'nothing reads a key nobody inherits',
            ],
            'website without store views or key' => [
                [1 => 1],
                ['websites:9' => ['', 'sandbox']],
                'websites',
                9,
                LocalizedException::class,
                'no store view',
                'the missing store view is the cause worth reporting, not the missing key',
            ],
            'every store view overrides' => [
                [1 => 1, 2 => 1],
                $website + ['1' => ['key-a', 'sandbox'], '2' => ['key-b', 'sandbox']],
                'websites',
                1,
                LocalizedException::class,
                'its own API key',
                'the website key is read by nobody, so refreshing it would refresh a merchant for nobody',
            ],
        ];
    }

    public function testTheWalkTakesTheCallersCacheIdentity(): void
    {
        // The FX table is keyed on the API key alone, so it collapses environments the record keeps apart.
        $this->configure(
            [1 => 1, 2 => 1],
            ['default:' => ['key-a', 'sandbox'], '1' => ['key-a', 'sandbox'], '2' => ['key-a', 'production']]
        );
        $refresher = $this->refresher();
        $keyOnly = static function (?int $storeId, string $apiKey): string {
            return hash('sha256', $apiKey);
        };

        $this->assertSame(
            [['store_id' => null, 'api_key' => 'key-a']],
            $refresher->distinctScopes($keyOnly, $refresher->storeScopes()),
            'a key-only identity sees one entry'
        );
        $this->assertSame(
            [['store_id' => null, 'api_key' => 'key-a'], ['store_id' => 2, 'api_key' => 'key-a']],
            $refresher->distinctScopes($refresher->recordIdentity(), $refresher->storeScopes()),
            'the record identity keeps the two environments apart'
        );
    }
}
