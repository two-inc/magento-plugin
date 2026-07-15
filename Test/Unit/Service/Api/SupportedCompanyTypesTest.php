<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\SupportedCompanyTypes;

class SupportedCompanyTypesTest extends TestCase
{
    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $apiAdapter;

    /** @var CacheInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    /** @var SupportedCompanyTypes */
    private $service;

    protected function setUp(): void
    {
        $this->apiAdapter = $this->createMock(Adapter::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);

        $this->service = new SupportedCompanyTypes(
            $this->apiAdapter,
            $this->cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );
    }

    // ── happy path ──────────────────────────────────────────────────────

    public function testParsesTypesFromRegistryEndpoint(): void
    {
        $this->apiAdapter->expects($this->once())->method('execute')
            ->with('/registry/v1/supported-company-types/GB', [], 'GET')
            ->willReturn(['supported_company_types' => ['SOLE_TRADER']]);

        $this->assertSame(['SOLE_TRADER'], $this->service->getForCountry('GB'));
        $this->assertTrue($this->service->isSoleTraderSupported('GB'));
    }

    public function testNormalisesCountryCase(): void
    {
        $this->apiAdapter->expects($this->once())->method('execute')
            ->with('/registry/v1/supported-company-types/GB', [], 'GET')
            ->willReturn(['supported_company_types' => ['SOLE_TRADER']]);

        $this->assertSame(['SOLE_TRADER'], $this->service->getForCountry(' gb '));
    }

    public function testEmptyListIsALegitimateAnswerAndIsCached(): void
    {
        $this->apiAdapter->method('execute')
            ->willReturn(['supported_company_types' => []]);
        // A genuine empty list (business-only country) is a successful
        // answer and must be cached like any other.
        $this->cache->expects($this->once())->method('save')->with(
            json_encode(['types' => []]),
            'two_gateway_supported_company_types_NO',
            [],
            3600
        );

        $this->assertSame([], $this->service->getForCountry('NO'));
        $this->assertFalse($this->service->isSoleTraderSupported('NO'));
    }

    public function testFiltersNonStringEntries(): void
    {
        $this->apiAdapter->method('execute')
            ->willReturn(['supported_company_types' => ['SOLE_TRADER', 42, null, ['nested']]]);

        $this->assertSame(['SOLE_TRADER'], $this->service->getForCountry('GB'));
    }

    // ── caching ─────────────────────────────────────────────────────────

    public function testSuccessfulAnswerIsCachedWithTtl(): void
    {
        $this->apiAdapter->method('execute')
            ->willReturn(['supported_company_types' => ['SOLE_TRADER']]);
        $this->cache->expects($this->once())->method('save')->with(
            json_encode(['types' => ['SOLE_TRADER']]),
            'two_gateway_supported_company_types_GB',
            [],
            3600
        );

        $this->service->getForCountry('GB');
    }

    public function testCacheHitAvoidsApiCall(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')
            ->with('two_gateway_supported_company_types_GB')
            ->willReturn(json_encode(['types' => ['SOLE_TRADER']]));
        $this->apiAdapter->expects($this->never())->method('execute');

        $service = new SupportedCompanyTypes(
            $this->apiAdapter,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );

        $this->assertSame(['SOLE_TRADER'], $service->getForCountry('GB'));
    }

    public function testMemoizesWithinRequest(): void
    {
        $this->apiAdapter->expects($this->once())->method('execute')
            ->willReturn(['supported_company_types' => ['SOLE_TRADER']]);

        $this->service->getForCountry('GB');
        $this->assertSame(['SOLE_TRADER'], $this->service->getForCountry('gb'));
    }

    // ── fail-soft ───────────────────────────────────────────────────────

    /**
     * @dataProvider failureResponses
     */
    public function testFailureResolvesToEmptyListWithoutCaching(array $response): void
    {
        $this->apiAdapter->method('execute')->willReturn($response);
        // Failures must NOT be persisted, so a transient registry blip
        // does not suppress the sole-trader option for the whole TTL.
        $this->cache->expects($this->never())->method('save');

        $this->assertSame([], $this->service->getForCountry('GB'));
        $this->assertFalse($this->service->isSoleTraderSupported('GB'));
    }

    public static function failureResponses(): array
    {
        return [
            'adapter error marker (network / exception)' => [
                ['error_code' => 400, 'error_message' => 'timeout'],
            ],
            'non-200 with body' => [
                ['error' => 'invalid country code', 'http_status' => 400],
            ],
            'malformed body (key missing)' => [
                ['unexpected' => 'shape'],
            ],
            'malformed body (key not a list)' => [
                ['supported_company_types' => 'SOLE_TRADER'],
            ],
        ];
    }

    public function testFailureIsMemoizedPerRequestButRetriesViaApiOnNewInstance(): void
    {
        // Within one request the failure is memoized (no second call)…
        $this->apiAdapter->expects($this->once())->method('execute')
            ->willReturn(['error_code' => 400, 'error_message' => 'timeout']);

        $this->assertSame([], $this->service->getForCountry('GB'));
        $this->assertSame([], $this->service->getForCountry('GB'));
    }

    public function testInvalidCountryCodeShortCircuitsWithoutApiCall(): void
    {
        $this->apiAdapter->expects($this->never())->method('execute');

        $this->assertSame([], $this->service->getForCountry(''));
        $this->assertSame([], $this->service->getForCountry('GBR'));
        $this->assertSame([], $this->service->getForCountry('g!'));
    }
}
