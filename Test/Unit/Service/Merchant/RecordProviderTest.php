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
        $this->assertSame(1, count($saved));
        $this->assertStringEndsWith('_cooldown', $saved[0]);
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
            return strpos($key, 'two_gateway_merchant_record_') === 0 && substr($key, -9) !== '_cooldown';
        }, ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $recordSaves);
        [$data, $tags, $lifetime] = array_values($recordSaves)[0];
        $this->assertStringContainsString('"available_terms"', $data);
        $this->assertSame([['TWO_GATEWAY'], 3600], [$tags, $lifetime]);
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
            function ($data, $identifier, $tags) use (&$sequence) {
                $sequence[] = (substr($identifier, -9) === '_cooldown' ? 'arm cooldown ' : 'store record ') . implode(',', $tags);
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
                ['arm cooldown TWO_GATEWAY', 'fetch', 'fetch', 'store record TWO_GATEWAY', 'clear cooldown'],
                'armed first, record stored, cooldown cleared so readers are not stranded on null',
            ],
            'fetch fails' => [
                ['http_status' => 503],
                ['arm cooldown TWO_GATEWAY', 'fetch', 'fetch'],
                'armed first and left armed, nothing stored',
            ],
        ];
    }

    /**
     * @param CacheInterface|\PHPUnit\Framework\MockObject\MockObject $cache
     */
    private function providerWith($cache, string $apiKey = 'test-api-key', string $mode = 'sandbox'): RecordProvider
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn($apiKey);
        $configRepository->method('getMode')->willReturn($mode);

        return new RecordProvider(
            $this->apiAdapter,
            $configRepository,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );
    }

    public function testRefreshIgnoresTheCachedRecordAndWritesTheFreshOneForward(): void
    {
        // Given a cached record; when refreshed; then the fresh one replaces it.
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"record":{"available_terms":[30]}}');
        $fresh = ['id' => 'abc-123', 'available_terms' => [30, 60, 90]];
        $this->stubApi(['id' => 'abc-123'], $fresh);
        $cache->expects($this->once())->method('save')->with(
            $this->stringContains('"available_terms":[30,60,90]'),
            $this->stringContains('two_gateway_merchant_record_'),
            ['TWO_GATEWAY'],
            3600
        );

        $provider = $this->providerWith($cache);

        $this->assertSame($fresh, $provider->refresh('sandbox', 'test-api-key', 1));
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
        $this->assertSame([], $saved, 'nothing is written — not the record, not a cooldown');
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
            array_filter($saved, static function (string $identifier): bool {
                return !str_ends_with($identifier, '_cooldown');
            }),
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
        $saved = null;
        $this->cache->method('save')->willReturnCallback(
            function (string $data, string $key) use (&$saved) {
                $saved = $key;
                return true;
            }
        );

        $this->provider->refresh('production', 'other-key', 1);

        $this->assertSame([[1, 'other-key', 'production'], [1, 'other-key', 'production']], $calls);
        $this->assertStringEndsWith(hash('sha256', "production\0other-key"), (string)$saved);
    }
}
