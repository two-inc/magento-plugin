<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * Categorisation and caching of the API-key verification result.
 *
 * The categorisation cases are the point of the change: every non-200
 * outcome used to be reported as "the key is invalid", so a store that
 * could not reach the API sent its admin off replacing a working key.
 */
class ApiKeyStatusTest extends TestCase
{
    private const KEY = 'test-api-key';

    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $apiAdapter;

    /** @var CacheInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    protected function setUp(): void
    {
        $this->apiAdapter = $this->createMock(Adapter::class);
        $this->cache = $this->createMock(CacheInterface::class);
    }

    private function build(string $apiKey = self::KEY): ApiKeyStatus
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn($apiKey);

        return new ApiKeyStatus(
            $this->apiAdapter,
            $configRepository,
            $this->cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );
    }

    // ── Categorisation ──────────────────────────────────────────────────

    /**
     * @dataProvider verificationOutcomes
     * @param array<string,mixed> $apiResponse
     */
    public function testAdapterResultIsCategorized(
        array $apiResponse,
        string $expectedStatus,
        ?int $expectedCode
    ): void {
        $this->cache->method('load')->willReturn(false);
        $this->apiAdapter->method('execute')->willReturn($apiResponse);

        $status = $this->build()->getStatus();

        $this->assertSame($expectedStatus, $status['status']);
        $this->assertSame($expectedCode, $status['code']);
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: string, 2: int|null}>
     */
    public static function verificationOutcomes(): array
    {
        return [
            // A 401/403 is the ONLY outcome that means "the key is wrong".
            'rejected 401 is an invalid key' => [
                ['error_message' => 'unauthorized', 'http_status' => 401],
                ApiKeyStatus::INVALID_KEY,
                401,
            ],
            'rejected 403 is an invalid key' => [
                ['error_message' => 'forbidden', 'http_status' => 403],
                ApiKeyStatus::INVALID_KEY,
                403,
            ],
            // 5xx says nothing about the key — the service failed.
            'server 500 is a service error' => [
                ['error_message' => 'boom', 'http_status' => 500],
                ApiKeyStatus::SERVICE_ERROR,
                500,
            ],
            'gateway 502 is a service error' => [
                ['error_message' => 'bad gateway', 'http_status' => 502],
                ApiKeyStatus::SERVICE_ERROR,
                502,
            ],
            'unavailable 503 is a service error' => [
                ['error_message' => 'unavailable', 'http_status' => 503],
                ApiKeyStatus::SERVICE_ERROR,
                503,
            ],
            // An empty-bodied 5xx now carries its status through the
            // Adapter's catch-all, so it categorises as a service error
            // rather than falling into "unreachable".
            'empty-bodied 500 keeps its status' => [
                ['error_code' => 400, 'http_status' => 500, 'error_message' => 'Invalid API response from Two.'],
                ApiKeyStatus::SERVICE_ERROR,
                500,
            ],
            // No HTTP exchange completed at all: connection refused, DNS,
            // TLS, routing, timeout. The Adapter signals this with an
            // error_code and no http_status.
            'transport failure is unreachable' => [
                ['error_code' => 400, 'error_message' => 'Error in transfer'],
                ApiKeyStatus::UNREACHABLE,
                null,
            ],
            'timeout is unreachable' => [
                ['error_code' => 400, 'error_message' => 'Operation timed out after 60000 milliseconds'],
                ApiKeyStatus::UNREACHABLE,
                null,
            ],
            'other non-2xx is a plain error' => [
                ['error_message' => 'not found', 'http_status' => 404],
                ApiKeyStatus::ERROR,
                404,
            ],
            // A 2xx that does not name a merchant is not a verification we
            // can rely on, and must not read as a silent success.
            'success without a merchant id is malformed' => [
                ['short_name' => 'acme'],
                ApiKeyStatus::MALFORMED_RESPONSE,
                null,
            ],
            'empty success body is malformed' => [
                [],
                ApiKeyStatus::MALFORMED_RESPONSE,
                null,
            ],
            'success with a merchant id verifies' => [
                ['id' => 'abc-123', 'short_name' => 'acme'],
                ApiKeyStatus::OK,
                200,
            ],
        ];
    }

    public function testSuccessCarriesTheMerchantRecord(): void
    {
        $this->cache->method('load')->willReturn(false);
        $merchant = ['id' => 'abc-123', 'short_name' => 'acme'];
        $this->apiAdapter->method('execute')->willReturn($merchant);

        $this->assertSame($merchant, $this->build()->getStatus()['merchant']);
    }

    public function testFailureNeverCarriesTheResponseBody(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->apiAdapter->method('execute')->willReturn([
            'error_message' => 'api key not recognised for merchant acme',
            'http_status' => 401,
        ]);

        // The merchant/body slot stays empty on every failure category, so
        // no caller can render an upstream error payload to an admin.
        $this->assertNull($this->build()->getStatus()['merchant']);
    }

    // ── isVerified ──────────────────────────────────────────────────────

    public function testIsVerifiedOnlyOnOk(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->apiAdapter->method('execute')->willReturn(['id' => 'abc-123']);

        $this->assertTrue($this->build()->isVerified());
    }

    /**
     * @dataProvider failingOutcomes
     * @param array<string,mixed> $apiResponse
     */
    public function testIsNotVerifiedForEveryFailureCategory(array $apiResponse): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->apiAdapter->method('execute')->willReturn($apiResponse);

        $this->assertFalse($this->build()->isVerified());
    }

    /**
     * @return array<string, array{0: array<string,mixed>}>
     */
    public static function failingOutcomes(): array
    {
        return [
            'invalid key' => [['http_status' => 401]],
            'service error' => [['http_status' => 503]],
            'unreachable' => [['error_code' => 400, 'error_message' => 'Error in transfer']],
            'other error' => [['http_status' => 404]],
            'malformed' => [[]],
        ];
    }

    public function testNoApiKeyIsNotConfiguredAndMakesNoCall(): void
    {
        $this->apiAdapter->expects($this->never())->method('execute');
        $this->cache->expects($this->never())->method('load');

        $status = $this->build('')->getStatus();

        $this->assertSame(ApiKeyStatus::NOT_CONFIGURED, $status['status']);
        $this->assertFalse($this->build('')->isVerified());
    }

    // ── Caching ─────────────────────────────────────────────────────────

    public function testSuccessIsCachedForTheFullLifetime(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->apiAdapter->method('execute')->willReturn(['id' => 'abc-123']);

        $this->cache->expects($this->once())
            ->method('save')
            ->with($this->anything(), $this->anything(), [], 300);

        $this->build()->getStatus();
    }

    public function testFailureIsCachedButOnlyBriefly(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->apiAdapter->method('execute')->willReturn(['http_status' => 503]);

        // Caching a failure is what stops an outage adding a live, timing-out
        // call to every checkout render; the short TTL is what lets a
        // recovery be noticed quickly.
        $this->cache->expects($this->once())
            ->method('save')
            ->with($this->anything(), $this->anything(), [], 60);

        $this->build()->getStatus();
    }

    public function testCachedVerdictIsServedWithoutAnApiCall(): void
    {
        $cached = ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => ['id' => 'abc-123']];
        $this->cache->method('load')->willReturn((new Json())->serialize($cached));
        $this->apiAdapter->expects($this->never())->method('execute');

        $this->assertSame($cached, $this->build()->getStatus());
    }

    public function testRepeatedReadsInOneRequestCostOneApiCall(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->apiAdapter->expects($this->once())->method('execute')->willReturn(['id' => 'abc-123']);

        $service = $this->build();
        $service->getStatus();
        $service->getStatus();
        $service->isVerified();
    }

    public function testCacheKeyTracksTheApiKeySoAKeySwapMisses(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->apiAdapter->method('execute')->willReturn(['id' => 'abc-123']);

        $keys = [];
        $this->cache->method('save')->willReturnCallback(
            function ($data, $identifier) use (&$keys) {
                $keys[] = $identifier;
                return true;
            }
        );

        $this->build('key-one')->getStatus();
        $this->build('key-two')->getStatus();

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'a different API key must use a different cache slot');
        // The key itself must never appear in a cache identifier.
        $this->assertStringNotContainsString('key-one', $keys[0]);
    }

    public function testRefreshIgnoresTheCacheAndWritesItsResultForward(): void
    {
        // A stale "invalid" verdict sits in the cache; the admin page has
        // just been loaded with a corrected key.
        $stale = ['status' => ApiKeyStatus::INVALID_KEY, 'code' => 401, 'merchant' => null];
        $this->cache->method('load')->willReturn((new Json())->serialize($stale));
        $this->apiAdapter->expects($this->once())->method('execute')->willReturn(['id' => 'abc-123']);
        $this->cache->expects($this->once())->method('save');

        $service = $this->build();

        $this->assertSame(ApiKeyStatus::OK, $service->refresh()['status']);
        // And the fresh verdict is what subsequent reads in this request see,
        // rather than the cached one it just superseded.
        $this->assertSame(ApiKeyStatus::OK, $service->getStatus()['status']);
    }

    public function testCorruptCacheEntryFallsBackToALiveCheck(): void
    {
        $this->cache->method('load')->willReturn('not json at all');
        $this->apiAdapter->method('execute')->willReturn(['id' => 'abc-123']);

        $this->assertSame(ApiKeyStatus::OK, $this->build()->getStatus()['status']);
    }
}
