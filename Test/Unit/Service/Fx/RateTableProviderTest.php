<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Fx;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Fx\RateTableProvider;

class RateTableProviderTest extends TestCase
{
    private const RATES_RESPONSE = [
        'base' => 'EUR',
        'as_of' => '2026-07-15',
        'rates' => ['EUR' => 1.0, 'GBP' => 1.17, 'NOK' => 0.085, 'SEK' => 0.088],
    ];

    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $apiAdapter;

    protected function setUp(): void
    {
        $this->apiAdapter = $this->createMock(Adapter::class);
    }

    /**
     * @param CacheInterface|\PHPUnit\Framework\MockObject\MockObject|null $cache
     */
    private function provider($cache = null, string $apiKey = 'test-api-key'): RateTableProvider
    {
        if ($cache === null) {
            $cache = $this->createMock(CacheInterface::class);
            $cache->method('load')->willReturn(false);
        }
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn($apiKey);

        return new RateTableProvider(
            $this->apiAdapter,
            $configRepository,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );
    }

    /**
     * A cache mock whose main-key load returns $entry (JSON) and whose
     * failure-cooldown load returns $cooldown.
     *
     * @return CacheInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function cacheWith(?array $entry, $cooldown = false)
    {
        $cache = $this->createMock(CacheInterface::class);
        $json = new Json();
        $cache->method('load')->willReturnCallback(
            function (string $key) use ($entry, $cooldown, $json) {
                if (strpos($key, '_cooldown') !== false) {
                    return $cooldown;
                }
                return $entry === null ? false : $json->serialize($entry);
            }
        );
        return $cache;
    }

    private function freshEntry(): array
    {
        return [
            'rates' => ['EUR' => 1.0, 'GBP' => 1.17],
            'as_of' => '2026-07-15',
            'fetched_at' => time(),
        ];
    }

    private function staleEntry(): array
    {
        return [
            'rates' => ['EUR' => 1.0, 'GBP' => 1.10],
            'as_of' => '2026-07-10',
            'fetched_at' => time() - RateTableProvider::REFRESH_INTERVAL - 60,
        ];
    }

    // ── Fetch and cache ──────────────────────────────────────────────

    public function testFetchesTableFromEndpointWhenUncached(): void
    {
        $this->apiAdapter->expects($this->once())->method('execute')
            ->with('/refdata/v1/fx-rates', [], 'GET', 1)
            ->willReturn(self::RATES_RESPONSE);

        $table = $this->provider()->getRateTable(1);

        $this->assertSame(self::RATES_RESPONSE['rates'], $table['rates']);
        $this->assertSame('2026-07-15', $table['as_of']);
    }

    public function testCachesFetchedTableWithoutExpiry(): void
    {
        // The table is the last-known-good source for gate conversions:
        // it must be written with NO lifetime, so a refresh outage can
        // never evict it.
        $this->apiAdapter->method('execute')->willReturn(self::RATES_RESPONSE);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save')->with(
            $this->stringContains('"rates"'),
            $this->stringContains('two_gateway_fx_rate_table_'),
            [],
            null
        );

        $this->provider($cache)->getRateTable(1);
    }

    public function testSecondLookupInsideWindowHitsCacheNotEndpoint(): void
    {
        // Core over-fetch guard: a cached table younger than the 6h window
        // is served as-is; the endpoint must not be called at all.
        $this->apiAdapter->expects($this->never())->method('execute');

        $table = $this->provider($this->cacheWith($this->freshEntry()))->getRateTable(1);

        $this->assertSame(1.17, $table['rates']['GBP']);
    }

    public function testMemoisesWithinTheRequest(): void
    {
        // getRate() sits on the payment method's isAvailable() hot path —
        // many lookups per page view must cost one fetch, not one each.
        $this->apiAdapter->expects($this->once())->method('execute')->willReturn(self::RATES_RESPONSE);

        $provider = $this->provider();
        $first = $provider->getRateTable(1);
        $second = $provider->getRateTable(1);

        $this->assertSame($first, $second);
    }

    public function testNoApiKeyShortCircuitsWithoutApiCall(): void
    {
        $this->apiAdapter->expects($this->never())->method('execute');

        $this->assertNull($this->provider(null, '')->getRateTable(1));
    }

    // ── Cache-entry robustness ───────────────────────────────────────

    public function testCorruptCacheEntryIsDiscardedAndRefetched(): void
    {
        // A corrupt cache value must degrade to "missing" and refetch —
        // never throw on the isAvailable() hot path.
        $this->apiAdapter->expects($this->once())->method('execute')->willReturn(self::RATES_RESPONSE);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn (string $key) => strpos($key, '_cooldown') !== false ? false : 'not-json{{'
        );
        $cache->expects($this->once())->method('save')->with(
            $this->stringContains('"rates"'),
            $this->stringContains('two_gateway_fx_rate_table_'),
            [],
            null
        );

        $table = $this->provider($cache)->getRateTable(1);

        $this->assertSame(1.17, $table['rates']['GBP']);
    }

    public function testWrongShapedCacheEntryIsDiscardedAndRefetched(): void
    {
        $this->apiAdapter->expects($this->once())->method('execute')->willReturn(self::RATES_RESPONSE);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn (string $key) => strpos($key, '_cooldown') !== false ? false : '{"unexpected":true}'
        );

        $table = $this->provider($cache)->getRateTable(1);

        $this->assertSame(1.17, $table['rates']['GBP']);
    }

    // ── Staleness ────────────────────────────────────────────────────

    public function testStaleTableIsRefreshedFromEndpointAndPersisted(): void
    {
        $this->apiAdapter->expects($this->once())->method('execute')->willReturn(self::RATES_RESPONSE);
        $cache = $this->cacheWith($this->staleEntry());
        $cache->expects($this->once())->method('save')->with(
            $this->stringContains('"as_of":"2026-07-15"'),
            $this->stringContains('two_gateway_fx_rate_table_'),
            [],
            null
        );

        $table = $this->provider($cache)->getRateTable(1);

        // The fresh endpoint rate (1.17), not the stale cached one (1.10).
        $this->assertSame(1.17, $table['rates']['GBP']);
    }

    public function testServesLastKnownGoodWhenRefreshFails(): void
    {
        // Gate conversions are specified to use last-known-good: a failed
        // refresh serves the stale table, never null.
        $this->apiAdapter->method('execute')->willReturn(['error_code' => 400, 'error_message' => 'boom']);

        $table = $this->provider($this->cacheWith($this->staleEntry()))->getRateTable(1);

        $this->assertSame(1.10, $table['rates']['GBP']);
    }

    public function testFailedRefreshDoesNotOverwriteLastKnownGood(): void
    {
        $this->apiAdapter->method('execute')->willReturn(['http_status' => 503]);
        $cache = $this->cacheWith($this->staleEntry());
        // Only the failure cooldown is written — never the table key.
        $cache->expects($this->once())->method('save')->with(
            $this->anything(),
            $this->stringContains('_cooldown'),
            [],
            300
        );

        $this->provider($cache)->getRateTable(1);
    }

    public function testFailureCooldownSuppressesRefetch(): void
    {
        // While the cooldown is live, the stale table is served without
        // touching the endpoint — an API outage must not add a fetch
        // round-trip to every page view.
        $this->apiAdapter->expects($this->never())->method('execute');

        $table = $this->provider($this->cacheWith($this->staleEntry(), '1'))->getRateTable(1);

        $this->assertSame(1.10, $table['rates']['GBP']);
    }

    public function testNeverFetchedAndFetchFailingResolvesToNull(): void
    {
        // Only case that yields null: no table has EVER been fetched and
        // one cannot be fetched now (callers fail closed / soft on null).
        $this->apiAdapter->method('execute')->willReturn(['error_code' => 400]);

        $this->assertNull($this->provider()->getRateTable(1));
    }

    public function testMalformedPayloadIsAFailedFetch(): void
    {
        $this->apiAdapter->method('execute')->willReturn(['base' => 'EUR', 'as_of' => '2026-07-15']);

        $this->assertNull($this->provider()->getRateTable(1));
    }

    public function testNonPositiveAndBogusRatesAreDropped(): void
    {
        $this->apiAdapter->method('execute')->willReturn([
            'as_of' => '2026-07-15',
            'rates' => ['EUR' => 1.0, 'GBP' => -1.17, 'NOK' => 0, 'SEK' => 'abc'],
        ]);

        $table = $this->provider()->getRateTable(1);

        $this->assertSame(['EUR' => 1.0], $table['rates']);
    }

    // ── Background refresh (cron path) ───────────────────────────────

    public function testRefreshPersistsFreshTable(): void
    {
        $this->apiAdapter->method('execute')->willReturn(self::RATES_RESPONSE);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save')->with(
            $this->stringContains('"as_of":"2026-07-15"'),
            $this->stringContains('two_gateway_fx_rate_table_'),
            [],
            null
        );

        $this->assertTrue($this->provider($cache)->refresh(1));
    }

    public function testRefreshFailureLeavesCacheUntouched(): void
    {
        $this->apiAdapter->method('execute')->willReturn(['error_code' => 500]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('save');

        $this->assertFalse($this->provider($cache)->refresh(1));
    }

    public function testRefreshWithoutApiKeyIsANoop(): void
    {
        $this->apiAdapter->expects($this->never())->method('execute');

        $this->assertFalse($this->provider(null, '')->refresh(1));
    }
}
