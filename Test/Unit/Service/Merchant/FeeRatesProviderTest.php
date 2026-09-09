<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\FeeRatesProvider;

/**
 * The admin fee column used to blank on any upstream failure, which reads as
 * "this term has no fee". The last retrieved set is kept instead, and the
 * caller is told whether what it got is current (ABN-512).
 */
class FeeRatesProviderTest extends TestCase
{
    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $apiAdapter;

    private const RATES = ['rates' => [['net_terms' => 30, 'percentage_fee' => '1.5', 'fixed_fee' => '0.5']]];

    protected function setUp(): void
    {
        $this->apiAdapter = $this->createMock(Adapter::class);
    }

    /**
     * @param CacheInterface|\PHPUnit\Framework\MockObject\MockObject $cache
     */
    private function build($cache, string $apiKey = 'test-api-key'): FeeRatesProvider
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn($apiKey);
        $configRepository->method('getMode')->willReturn('sandbox');

        return new FeeRatesProvider(
            $this->apiAdapter,
            $configRepository,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );
    }

    /** A cache that holds nothing. */
    private function emptyCache()
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        return $cache;
    }

    public function testASuccessfulFetchIsServedFreshAndCachedWithoutExpiry(): void
    {
        $this->apiAdapter->method('execute')->willReturn(self::RATES);
        $cache = $this->emptyCache();
        $saves = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier, $tags, $lifeTime) use (&$saves) {
                $saves[] = [$identifier, $tags, $lifeTime];
                return true;
            }
        );

        $rates = $this->build($cache)->getRates([30], 'NL', 1);

        $this->assertTrue($rates['success']);
        $this->assertFalse($rates['stale']);
        $this->assertSame(['30' => ['percentage' => 1.5, 'fixed' => 0.5]], $rates['fees']);
        $this->assertCount(1, $saves);
        $this->assertSame([['TWO_GATEWAY'], null], array_slice($saves[0], 1), 'the entry never expires');
    }

    public function testAFailedFetchServesTheLastSetAndSaysItIsNotCurrent(): void
    {
        $this->apiAdapter->method('execute')->willReturn(['error_code' => 503]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(
            (new Json())->serialize([
                'success' => true,
                'currency' => 'EUR',
                'fees' => ['30' => ['percentage' => 1.5, 'fixed' => 0.5]],
                'fetched_at' => 1700000000,
                'stale' => false,
            ])
        );
        $cache->expects($this->never())->method('save');

        $rates = $this->build($cache)->getRates([30], 'NL', 1);

        $this->assertTrue($rates['success']);
        $this->assertTrue($rates['stale']);
        $this->assertSame(1700000000, $rates['fetched_at'], 'the age reported is when the set was retrieved');
        $this->assertSame(['30' => ['percentage' => 1.5, 'fixed' => 0.5]], $rates['fees']);
    }

    /**
     * @param array<string,mixed> $response
     * @dataProvider unusableResponses
     */
    public function testAFailedFetchWithNothingCachedReportsTheFailure(array $response, string $description): void
    {
        $this->apiAdapter->method('execute')->willReturn($response);

        $rates = $this->build($this->emptyCache())->getRates([30], 'NL', 1);

        $this->assertSame(['success' => false, 'error' => 'upstream'], $rates, $description);
    }

    /**
     * @return array<int, array{0: array<string,mixed>, 1: string}>
     */
    public static function unusableResponses(): array
    {
        return [
            [['error_code' => 503], 'the adapter failure envelope is not a fee set'],
            [['http_status' => 500], 'a 5xx is not a fee set'],
            [[], 'an empty body is not a fee set'],
        ];
    }

    public function testACorruptCachedSetIsDiscardedRatherThanServed(): void
    {
        $this->apiAdapter->method('execute')->willReturn(['error_code' => 503]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('not json at all');

        $this->assertSame(
            ['success' => false, 'error' => 'upstream'],
            $this->build($cache)->getRates([30], 'NL', 1)
        );
    }

    /**
     * @param int[] $terms
     * @dataProvider distinctRequests
     */
    public function testAnswersForDifferentRequestsAreCachedSeparately(
        array $terms,
        string $country,
        string $apiKey,
        string $description
    ): void {
        $this->apiAdapter->method('execute')->willReturn(self::RATES);
        $cache = $this->emptyCache();
        $keys = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$keys) {
                $keys[] = $identifier;
                return true;
            }
        );

        $this->build($cache)->getRates([30], 'NL', 1);
        $this->build($cache, $apiKey)->getRates($terms, $country, 1);

        $this->assertNotSame($keys[0], $keys[1], $description);
    }

    /**
     * @return array<int, array{0: int[], 1: string, 2: string, 3: string}>
     */
    public static function distinctRequests(): array
    {
        return [
            [[30, 60], 'NL', 'test-api-key', 'another term set is another answer'],
            [[30], 'GB', 'test-api-key', 'another buyer country is another answer'],
            [[30], 'NL', 'other-api-key', 'another merchant is another answer'],
        ];
    }

    public function testTheTermOrderDoesNotChangeTheCacheIdentity(): void
    {
        $this->apiAdapter->method('execute')->willReturn(self::RATES);
        $cache = $this->emptyCache();
        $keys = [];
        $cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$keys) {
                $keys[] = $identifier;
                return true;
            }
        );

        $provider = $this->build($cache);
        $provider->getRates([30, 60], 'NL', 1);
        $provider->getRates([60, 30], 'NL', 1);

        $this->assertSame($keys[0], $keys[1]);
    }

    public function testNoStoredApiKeyIsNeverCachedAgainstAnIdentity(): void
    {
        // There is no merchant to key the answer against, so nothing is written.
        $this->apiAdapter->method('execute')->willReturn(self::RATES);
        $cache = $this->emptyCache();
        $cache->expects($this->never())->method('save');

        $this->assertTrue($this->build($cache, '')->getRates([30], 'NL', 1)['success']);
    }
}
