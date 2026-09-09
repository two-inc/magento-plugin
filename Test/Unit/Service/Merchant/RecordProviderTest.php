<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\RecordProvider;

class RecordProviderTest extends TestCase
{
    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $apiAdapter;

    /** @var CacheInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    /** @var RecordProvider */
    private $provider;

    protected function setUp(): void
    {
        $this->apiAdapter = $this->createMock(Adapter::class);
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn('test-api-key');
        $configRepository->method('getMode')->willReturn('sandbox');
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);

        $this->provider = new RecordProvider(
            $this->apiAdapter,
            $configRepository,
            $this->cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );
    }

    private function stubApi(array $verifyResponse, array $merchantResponse = []): void
    {
        $this->apiAdapter->method('execute')->willReturnCallback(
            function (string $endpoint) use ($verifyResponse, $merchantResponse) {
                return $endpoint === '/v1/merchant/verify_api_key' ? $verifyResponse : $merchantResponse;
            }
        );
    }

    public function testResolvesRecordFromMerchantEndpoint(): void
    {
        $record = [
            'id' => 'abc-123',
            'available_terms' => [30, 60, 90],
            'surcharge_limit_amount' => '25.00',
            'surcharge_limit_currency' => 'EUR',
        ];
        $this->stubApi(['id' => 'abc-123'], $record);

        $this->assertSame($record, $this->provider->getRecord(1));
    }

    public function testUnresolvableMerchantIdResolvesToNull(): void
    {
        $this->stubApi(['error' => 'unauthorized']);

        $this->assertNull($this->provider->getRecord(1));
    }

    public function testNoApiKeyShortCircuitsWithoutApiCall(): void
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn('');
        $this->apiAdapter->expects($this->never())->method('execute');

        $provider = new RecordProvider(
            $this->apiAdapter,
            $configRepository,
            $this->cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );

        $this->assertNull($provider->getRecord(1));
    }

    public function testMemoisesWithinTheRequest(): void
    {
        // Several consumers read the record per request; one verify+fetch pair, not one each.
        $this->apiAdapter->expects($this->exactly(2))->method('execute')->willReturnCallback(
            function (string $endpoint) {
                return $endpoint === '/v1/merchant/verify_api_key'
                    ? ['id' => 'abc-123']
                    : ['id' => 'abc-123', 'available_terms' => [30, 60, 90]];
            }
        );

        $first = $this->provider->getRecord(1);
        $second = $this->provider->getRecord(1);

        $this->assertSame($first, $second);
    }

    public function testCacheHitSkipsTheApi(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"record":{"available_terms":[30,60,90]}}');
        $this->apiAdapter->expects($this->never())->method('execute');

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn('test-api-key');
        $configRepository->method('getMode')->willReturn('sandbox');
        $provider = new RecordProvider(
            $this->apiAdapter,
            $configRepository,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );

        $this->assertSame(['available_terms' => [30, 60, 90]], $provider->getRecord(1));
    }

    public function testMerchantErrorPayloadResolvesToNull(): void
    {
        // An error_code dict from the adapter is not a record.
        $this->stubApi(['id' => 'abc-123'], ['error_code' => 400, 'error_message' => 'boom']);

        $this->assertNull($this->provider->getRecord(1));
    }

    public function testDoesNotCacheFailureAsTheRecordSoALaterRequestRetries(): void
    {
        // A failure is never persisted as the record (TWO-24952), only as a short cooldown.
        $this->stubApi(['id' => 'abc-123'], ['http_status' => 503]);
        $saved = [];
        $this->cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$saved) {
                $saved[] = $identifier;
                return true;
            }
        );

        $this->assertNull($this->provider->getRecord(1));
        $this->assertSame([], preg_grep('/_record_[0-9a-f]{64}$/', $saved), 'the record key is never written');
        $this->assertSame([], preg_grep('/_fetched_at$/', $saved), 'the success stamp does not move');
        $this->assertCount(1, preg_grep('/_cooldown$/', $saved));
    }

    public function testCachesSuccessfulRecord(): void
    {
        // Written cross-request under the module's own cache tag.
        $record = ['id' => 'abc-123', 'available_terms' => [30, 60, 90]];
        $this->stubApi(['id' => 'abc-123'], $record);
        $saves = [];
        $this->cache->method('save')->willReturnCallback(
            function ($data, $identifier, $tags, $lifetime) use (&$saves) {
                $saves[$identifier] = [$data, $tags, $lifetime];
                return true;
            }
        );

        $this->assertSame($record, $this->provider->getRecord(1));

        $recordSaves = array_filter($saves, static function (string $key): bool {
            return (bool)preg_match('/_record_[0-9a-f]{64}$/', $key);
        }, ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $recordSaves);
        [$data, $tags, $lifetime] = array_values($recordSaves)[0];
        $this->assertStringContainsString('"available_terms"', $data);
        $this->assertSame([['TWO_GATEWAY'], null], [$tags, $lifetime], 'the record entry never expires');
        $stamps = preg_grep('/_fetched_at$/', array_keys($saves));
        $this->assertCount(1, $stamps, 'the success stamp is written beside the record');
        $this->assertSame([['TWO_GATEWAY'], null], array_slice($saves[reset($stamps)], 1));
    }

    /**
     * @dataProvider fetchOutcomes
     */
    public function testTheCooldownIsArmedBeforeTheFetchAndClearedOnlyOnSuccess(
        array $merchantResponse,
        array $expectedSequence,
        string $description
    ): void {
        // Concurrent renders during an outage share one attempt; a success must not leave readers on null.
        $sequence = [];
        $this->apiAdapter->method('execute')->willReturnCallback(
            function (string $endpoint) use ($merchantResponse, &$sequence) {
                $sequence[] = 'fetch';
                return $endpoint === '/v1/merchant/verify_api_key' ? ['id' => 'abc-123'] : $merchantResponse;
            }
        );
        $this->cache->method('save')->willReturnCallback(
            function ($data, $identifier, $tags, $lifetime) use (&$sequence) {
                $sequence[] = self::describe($identifier) . ' ' . implode(',', $tags)
                    . ' ' . ($lifetime === null ? 'no expiry' : $lifetime);
                return true;
            }
        );
        $this->cache->method('remove')->willReturnCallback(
            function ($identifier) use (&$sequence) {
                $sequence[] = substr($identifier, -9) === '_cooldown' ? 'clear cooldown' : 'remove ' . $identifier;
                return true;
            }
        );

        $this->provider->getRecord(1);

        $this->assertSame($expectedSequence, $sequence, $description);
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: array<int,string>, 2: string}>
     */
    public static function fetchOutcomes(): array
    {
        return [
            'fetch succeeds' => [
                ['id' => 'abc-123'],
                [
                    'mark absent TWO_GATEWAY no expiry',
                    'arm cooldown TWO_GATEWAY 60',
                    'fetch',
                    'fetch',
                    'store record TWO_GATEWAY no expiry',
                    'store stamp TWO_GATEWAY no expiry',
                    'clear cooldown',
                ],
                'armed first, record and stamp stored, cooldown cleared so readers are not stranded on null',
            ],
            'fetch fails' => [
                ['http_status' => 503],
                ['mark absent TWO_GATEWAY no expiry', 'arm cooldown TWO_GATEWAY 60', 'fetch', 'fetch'],
                'armed first and left armed for 60s only, nothing stored, stamp untouched',
            ],
        ];
    }

    private static function describe(string $identifier): string
    {
        $names = ['_cooldown' => 'arm cooldown', '_fetched_at' => 'store stamp', '_absent_on_read' => 'mark absent'];
        foreach ($names as $suffix => $name) {
            if (str_ends_with($identifier, $suffix)) {
                return $name;
            }
        }
        return 'store record';
    }

    /**
     * @param CacheInterface|\PHPUnit\Framework\MockObject\MockObject $cache
     */
    private function providerWith(
        $cache,
        string $apiKey = 'test-api-key',
        string $mode = 'sandbox',
        ?LogRepository $logRepository = null
    ): RecordProvider {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn($apiKey);
        $configRepository->method('getMode')->willReturn($mode);

        return new RecordProvider(
            $this->apiAdapter,
            $configRepository,
            $cache,
            new Json(),
            $logRepository ?? $this->createMock(LogRepository::class)
        );
    }

    /** The identifier the provider must compute for an identity; anything else is a different merchant. */
    private static function entryFor(string $mode, string $apiKey): string
    {
        return 'two_gateway_merchant_record_' . hash('sha256', $mode . "\0" . $apiKey);
    }

    /**
     * A cache holding a record and/or a success stamp of the given age, under
     * one identity's own identifiers only.
     *
     * @return CacheInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function cacheWith(
        bool $record,
        ?int $stampAge,
        ?int $absentAge = null,
        string $mode = 'sandbox',
        string $apiKey = 'test-api-key',
        bool $staleCooldown = false
    ) {
        $entry = self::entryFor($mode, $apiKey);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            function (string $identifier) use ($record, $stampAge, $absentAge, $entry, $staleCooldown) {
                if ($identifier === $entry . '_stale_cooldown') {
                    return $staleCooldown ? '1' : false;
                }
                if ($identifier === $entry . '_fetched_at') {
                    return $stampAge === null ? false : (string)(time() - $stampAge);
                }
                if ($identifier === $entry . '_absent_on_read') {
                    return $absentAge === null ? false : (string)(time() - $absentAge);
                }
                if ($identifier === $entry) {
                    return $record ? '{"record":{"available_terms":[30]}}' : false;
                }
                return false;
            }
        );

        return $cache;
    }

    /**
     * @dataProvider ages
     */
    public function testTheCronRefreshesOnceTheRecordIsMaxAgeOldOrGone(
        bool $record,
        ?int $stampAge,
        bool $expectedDue,
        string $description
    ): void {
        $this->assertSame(
            $expectedDue,
            $this->providerWith($this->cacheWith($record, $stampAge))->isDue('sandbox', 'test-api-key'),
            $description
        );
    }

    /**
     * @return array<string, array{0: bool, 1: int|null, 2: bool, 3: string}>
     */
    public static function ages(): array
    {
        return [
            'just under' => [true, RecordProvider::MAX_AGE - 1, false, 'a record just under a day old is left alone'],
            'just over' => [true, RecordProvider::MAX_AGE + 1, true, 'a record just over a day old is due'],
            'no stamp' => [true, null, true, 'a record with no success stamp is due'],
            'stamp, record gone' => [false, 10, true, 'a fresh stamp whose record was dropped is due, not fresh'],
            'nothing cached' => [false, null, true, 'nothing cached is due'],
        ];
    }

    /**
     * @dataProvider otherIdentities
     */
    public function testAnIdentityNeverReadsAnothersEntry(string $mode, string $apiKey, string $description): void
    {
        // Given a cache holding sandbox + test-api-key; when another identity is asked; then it is a miss.
        $provider = $this->providerWith($this->cacheWith(true, 10));

        $this->assertTrue($provider->isDue($mode, $apiKey), $description);
        $this->assertNull($provider->status($mode, $apiKey)['fetched_at'], $description);
    }

    /**
     * @return array<int,array{0: string, 1: string, 2: string}>
     */
    public static function otherIdentities(): array
    {
        return [
            ['production', 'test-api-key', 'the same key in the other environment is another merchant'],
            ['sandbox', 'other-key', 'another key in the same environment is another merchant'],
        ];
    }

    public function testTheRecordIsFetchedFromTheMerchantIdVerifyNamed(): void
    {
        // Given verify names a merchant; when the record is read; then that id is the endpoint fetched.
        $endpoints = [];
        $this->apiAdapter->method('execute')->willReturnCallback(
            function (string $endpoint) use (&$endpoints) {
                $endpoints[] = $endpoint;

                return $endpoint === '/v1/merchant/verify_api_key'
                    ? ['id' => 'abc-123']
                    : ['available_terms' => [30]];
            }
        );

        $this->provider->getRecord(1);

        $this->assertSame(['/v1/merchant/verify_api_key', '/v1/merchant/abc-123'], $endpoints);
    }

    public function testNoKeyIsNeverDue(): void
    {
        $this->assertFalse($this->providerWith($this->cacheWith(false, null), '')->isDue('sandbox', ''));
    }

    public function testAReadMissIsLoggedAndMarked(): void
    {
        // The entry never expires, so a miss is a fresh install or a flush.
        $this->stubApi(['id' => 'abc-123'], ['id' => 'abc-123']);
        $log = $this->createMock(LogRepository::class);
        $log->expects($this->once())->method('addErrorLog')
            ->with($this->stringContains('merchant record absent on read'), $this->anything());
        $cache = $this->cacheWith(false, null);
        $marked = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$marked) {
                if (str_ends_with($identifier, '_absent_on_read')) {
                    $marked[] = (int)$data;
                }
                return true;
            }
        );

        $this->assertNotNull($this->providerWith($cache, 'test-api-key', 'sandbox', $log)->getRecord(1), 'still fetched');
        $this->assertCount(1, $marked, 'the miss is recorded for Diagnostics');
    }

    public function testACacheHitLogsNothing(): void
    {
        $log = $this->createMock(LogRepository::class);
        $log->expects($this->never())->method('addErrorLog');

        $this->providerWith($this->cacheWith(true, 10), 'test-api-key', 'sandbox', $log)->getRecord(1);
    }

    public function testStatusReportsTheStampAndTheLastMissAndTheCronRunClearsTheMiss(): void
    {
        $cache = $this->cacheWith(true, 100, 50);
        $removed = [];
        $cache->method('remove')->willReturnCallback(
            function (string $identifier) use (&$removed) {
                $removed[] = $identifier;
                return true;
            }
        );
        $provider = $this->providerWith($cache);

        $status = $provider->status('sandbox', 'test-api-key');
        $provider->noteScheduledRun('sandbox', 'test-api-key');

        $this->assertEqualsWithDelta(time() - 100, $status['fetched_at'], 2);
        $this->assertEqualsWithDelta(time() - 50, $status['absent_on_read_at'], 2);
        $this->assertCount(1, preg_grep('/_absent_on_read$/', $removed));
    }

    public function testEveryConsumerReadsThroughGetRecordSoAFailedFetchServesTheLastKnownGoodToAll(): void
    {
        // getRecord() serves the cached record after a failed refresh; a consumer bypassing it could not.
        $root = dirname(__DIR__, 4);
        $offenders = [];
        foreach (['Service', 'Model', 'Block', 'Controller', 'Observer', 'Cron'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Service/Merchant/Record')) {
                    continue;
                }
                $source = (string)file_get_contents($file->getPathname());
                if (!str_contains($source, 'RecordProvider')) {
                    continue;
                }
                preg_match_all('/recordProvider->(\w+)\(/', $source, $calls);
                foreach (array_unique($calls[1]) as $method) {
                    if (!in_array($method, ['getRecord', 'status'], true)) {
                        $offenders[] = substr($file->getPathname(), strlen($root) + 1) . '::' . $method;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testRefreshIgnoresTheCachedRecordAndWritesTheFreshOneForward(): void
    {
        // Given a cached record; when refreshed; then the fresh one replaces it.
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"record":{"available_terms":[30]}}');
        $fresh = ['id' => 'abc-123', 'available_terms' => [30, 60, 90]];
        $this->stubApi(['id' => 'abc-123'], $fresh);
        $saves = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$saves) {
                $saves[$identifier] = $data;
                return true;
            }
        );

        $provider = $this->providerWith($cache);

        $this->assertSame($fresh, $provider->refresh('sandbox', 'test-api-key', 1));
        $recordKeys = preg_grep('/_record_[0-9a-f]{64}$/', array_keys($saves));
        $this->assertCount(1, $recordKeys);
        $this->assertStringContainsString('"available_terms":[30,60,90]', $saves[reset($recordKeys)]);
        $this->assertCount(1, preg_grep('/_fetched_at$/', array_keys($saves)), 'the success stamp moves');
        $this->assertSame($fresh, $provider->getRecord(1), 'the refreshed record replaces the memo too');
    }

    public function testAFailedRefreshLeavesTheCachedRecordInPlaceAndStillServesIt(): void
    {
        // Given the API is down; when refreshed; then the cached record survives and is still served.
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            function (string $identifier) {
                return str_ends_with($identifier, '_cooldown')
                    ? false
                    : '{"record":{"available_terms":[30]}}';
            }
        );
        $this->stubApi(['id' => 'abc-123'], ['http_status' => 503]);
        $saved = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$saved) {
                $saved[] = $identifier;
                return true;
            }
        );
        $cache->expects($this->never())->method('remove');

        $provider = $this->providerWith($cache);

        $this->assertNull($provider->refresh('sandbox', 'test-api-key', 1), 'the caller is told the fetch failed');
        $this->assertSame([], $saved, 'nothing is written — not the record, not the stamp, not a cooldown');
        $this->assertSame(
            ['available_terms' => [30]],
            $provider->getRecord(1),
            'the surviving entry is memoised, not the failure'
        );
    }

    public function testAFailedRefreshDoesNotArmTheReaderCooldown(): void
    {
        // Arming it here would let one admin press push every reader to "no record" for a minute.
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $armed = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$armed) {
                $armed[] = $identifier;
                return true;
            }
        );
        $this->stubApi(['id' => 'abc-123'], ['http_status' => 503]);

        $this->assertNull($this->providerWith($cache)->refresh('sandbox', 'test-api-key', 1));
        $this->assertSame([], $armed);
    }

    public function testAnEmptyMerchantBodyIsNotTheRecord(): void
    {
        // An empty 200 body decodes to [] with no error marker, and [] is not a record.
        $this->stubApi(['id' => 'abc-123'], []);
        $saved = [];
        $this->cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$saved) {
                $saved[] = $identifier;
                return true;
            }
        );

        $this->assertNull($this->provider->getRecord(1));
        $this->assertSame(
            [],
            preg_grep('/_record_[0-9a-f]{64}$/', $saved),
            'an empty body is never written as the record'
        );
    }

    public function testACooldownStopsEveryReadRetryingDuringAnOutage(): void
    {
        // Cold cache plus unreachable API must not cost a timing-out pair per read.
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            function (string $identifier) {
                return str_ends_with($identifier, '_cooldown') ? '1' : false;
            }
        );
        $this->apiAdapter->expects($this->never())->method('execute');

        $this->assertNull($this->providerWith($cache)->getRecord(1));
    }

    public function testARefreshIgnoresTheCooldown(): void
    {
        // The cooldown protects unattended reads only.
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            function (string $identifier) {
                return str_ends_with($identifier, '_cooldown') ? '1' : false;
            }
        );
        $record = ['id' => 'abc-123', 'available_terms' => [30]];
        $this->stubApi(['id' => 'abc-123'], $record);

        $this->assertSame($record, $this->providerWith($cache)->refresh('sandbox', 'test-api-key', 1));
    }

    /**
     * @dataProvider unusableCacheValues
     */
    public function testAnUnusableCacheEntryDegradesToAFetch(string $cached, string $description): void
    {
        // On the isAvailable() path a bad entry refetches, never throws.
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            function (string $identifier) use ($cached) {
                return str_ends_with($identifier, '_cooldown') ? false : $cached;
            }
        );
        $record = ['id' => 'abc-123', 'available_terms' => [30]];
        $this->stubApi(['id' => 'abc-123'], $record);

        $this->assertSame($record, $this->providerWith($cache)->getRecord(1), $description);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unusableCacheValues(): array
    {
        return [
            'not json' => ['{not json', 'a truncated or corrupt cache write'],
            'not an array' => ['"a string"', 'a scalar where a wrapper was expected'],
            'no record key' => ['{"other":1}', 'a wrapper from an older shape'],
            'record not an array' => ['{"record":"abc"}', 'a record that is not a record'],
            'empty record' => ['{"record":{}}', 'an empty 200 body that reached the cache'],
            'null record' => ['{"record":null}', 'a failure that reached the cache from somewhere'],
        ];
    }

    public function testTheModeIsPartOfTheCacheKeySoEnvironmentsDoNotCollide(): void
    {
        // One key on two environments is two merchants.
        $keys = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            function (string $identifier) use (&$keys) {
                if (!str_ends_with($identifier, '_cooldown')) {
                    $keys[] = $identifier;
                }
                return false;
            }
        );
        $this->stubApi(['id' => 'abc-123'], ['id' => 'abc-123']);

        $this->providerWith($cache, 'shared-key', 'sandbox')->getRecord(1);
        $this->providerWith($cache, 'shared-key', 'production')->getRecord(2);

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    /**
     * @dataProvider readAndWriteEntryPoints
     */
    public function testEveryEntryPointShortCircuitsWithoutAnApiKey(
        string $method,
        array $arguments,
        string $description
    ): void
    {
        // Given no API key; when either entry point is called; then no round trip and no cache touch.
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('load');
        $cache->expects($this->never())->method('save');
        $this->apiAdapter->expects($this->never())->method('execute');

        $provider = $this->providerWith($cache, '');
        $provider->{$method}(...$arguments);

        $this->assertNull($provider->getRecord(1), $description);
    }

    /**
     * @return array<string, array{0: string, 1: array<int,mixed>, 2: string}>
     */
    public static function readAndWriteEntryPoints(): array
    {
        return [
            'read' => ['getRecord', [1], 'a read with no key configured'],
            'refresh' => ['refresh', ['sandbox', '', 1], 'a cron or admin refresh with no key configured'],
        ];
    }

    public function testARefreshFetchesTheIdentityItIsGivenNotTheStoresConfig(): void
    {
        // Given a store resolving one identity; when another is refreshed through it; then that one is fetched and cached.
        $calls = [];
        $this->apiAdapter->method('execute')->willReturnCallback(
            function (string $endpoint, array $payload, string $method, ...$identity) use (&$calls) {
                $calls[] = $identity;
                return ['id' => 'abc-123'];
            }
        );
        $saved = [];
        $this->cache->method('save')->willReturnCallback(
            function (string $data, string $key) use (&$saved) {
                $saved[] = $key;
                return true;
            }
        );

        $this->provider->refresh('production', 'other-key', 1);

        $identity = hash('sha256', "production\0other-key");
        $this->assertSame([[1, 'other-key', 'production', 10], [1, 'other-key', 'production', 10]], $calls);
        $this->assertSame(
            ['two_gateway_merchant_record_' . $identity, 'two_gateway_merchant_record_' . $identity . '_fetched_at'],
            $saved
        );
    }

    public function testAStaleRecordIsRefreshedInPlaceAndTheFresherOneServed(): void
    {
        $fresh = ['id' => 'abc-123', 'available_terms' => [30, 60]];
        $this->stubApi(['id' => 'abc-123'], $fresh);
        $cache = $this->cacheWith(true, RecordProvider::STALE_AFTER + 1);
        $writes = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$writes) {
                $writes[] = $identifier;
                return true;
            }
        );

        $this->assertSame($fresh, $this->providerWith($cache)->getRecord(1));
        $this->assertCount(
            1,
            preg_grep('/_stale_cooldown$/', $writes),
            'the stand-in refresh is bounded to one attempt per run the cron owes'
        );
    }

    public function testAStaleRecordSurvivesAFailedRefreshAndIsStillServed(): void
    {
        // Staleness never withholds: the held record is the answer either way.
        $this->stubApi(['id' => 'abc-123'], ['http_status' => 503]);
        $cache = $this->cacheWith(true, RecordProvider::STALE_AFTER + 1);
        $writes = [];
        $removes = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$writes) {
                $writes[] = $identifier;
                return true;
            }
        );
        $cache->method('remove')->willReturnCallback(
            function (string $identifier) use (&$removes) {
                $removes[] = $identifier;
                return true;
            }
        );

        $this->assertSame(['available_terms' => [30]], $this->providerWith($cache)->getRecord(1));
        $this->assertSame([], preg_grep('/_record_[0-9a-f]{64}$/', $writes), 'the record is not rewritten');
        $this->assertSame([], preg_grep('/_fetched_at$/', $writes), 'the success stamp does not move');
        $this->assertSame([], $removes, 'nothing is ever evicted');
    }

    public function testAStaleRecordIsNotRefetchedWhileTheCooldownStands(): void
    {
        $this->apiAdapter->expects($this->never())->method('execute');
        $cache = $this->cacheWith(true, RecordProvider::STALE_AFTER + 1, null, 'sandbox', 'test-api-key', true);

        $this->assertSame(['available_terms' => [30]], $this->providerWith($cache)->getRecord(1));
    }

    /**
     * @dataProvider freshAges
     */
    public function testAFreshEnoughRecordIsServedWithNoApiCall(int $stampAge, string $description): void
    {
        $this->apiAdapter->expects($this->never())->method('execute');
        $cache = $this->cacheWith(true, $stampAge);

        $this->assertSame(['available_terms' => [30]], $this->providerWith($cache)->getRecord(1), $description);
    }

    /**
     * @return array<int, array{0: int, 1: string}>
     */
    public static function freshAges(): array
    {
        return [
            [10, 'a record fetched moments ago is served as it is'],
            [RecordProvider::MAX_AGE + 1, 'a record the cron owes a refresh is still not stale'],
            [RecordProvider::STALE_AFTER - 1, 'a record just under the staleness bound is still not stale'],
        ];
    }
}
