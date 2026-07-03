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
        // Multiple consumers (min-order gate, admin terms/surcharge, default
        // term) hit the record per request; it must cost one verify + one
        // merchant fetch, not one pair per consumer.
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
        $provider = new RecordProvider(
            $this->apiAdapter,
            $configRepository,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );

        $this->assertSame(['available_terms' => [30, 60, 90]], $provider->getRecord(1));
    }

    public function testCachesTheNullOutcome(): void
    {
        // An unresolvable merchant is the degraded case and must not cost
        // two API calls per page view: the resolved null is cached too.
        $this->stubApi(['error' => 'unauthorized']);
        $this->cache->expects($this->once())->method('save')->with(
            '{"record":null}',
            $this->stringContains('two_gateway_merchant_record_'),
            [],
            900
        );

        $this->assertNull($this->provider->getRecord(1));
    }
}
