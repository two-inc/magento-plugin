<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\MinimumOrderProvider;

class MinimumOrderProviderTest extends TestCase
{
    /** @var Adapter|\PHPUnit\Framework\MockObject\MockObject */
    private $apiAdapter;

    /** @var CacheInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    /** @var MinimumOrderProvider */
    private $provider;

    protected function setUp(): void
    {
        $this->apiAdapter = $this->createMock(Adapter::class);
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn('test-api-key');
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);

        $this->provider = new MinimumOrderProvider(
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

    public function testResolvesMinimumFromMerchantEndpoint(): void
    {
        $this->stubApi(['id' => 'abc-123'], [
            'id' => 'abc-123',
            'min_order_amount' => '250.00',
            'min_order_currency' => 'EUR',
            'min_order_basis' => 'net',
        ]);

        $this->assertSame(
            ['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'],
            $this->provider->getMinimum(1)
        );
    }

    public function testNoMinimumWhenApiOmitsTheTuple(): void
    {
        // The common case: merchant has no minimum configured, the API
        // omits all three fields.
        $this->stubApi(['id' => 'abc-123'], ['id' => 'abc-123']);

        $this->assertNull($this->provider->getMinimum(1));
    }

    public function testPartialTupleResolvesToNoMinimum(): void
    {
        $this->stubApi(['id' => 'abc-123'], [
            'min_order_amount' => '250.00',
            'min_order_currency' => 'EUR',
            // basis missing - never gate on a guessed tax basis
        ]);

        $this->assertNull($this->provider->getMinimum(1));
    }

    public function testUnresolvableMerchantIdResolvesToNoMinimum(): void
    {
        $this->stubApi(['error' => 'unauthorized']);

        $this->assertNull($this->provider->getMinimum(1));
    }

    public function testNoApiKeyShortCircuitsWithoutApiCall(): void
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn('');
        $this->apiAdapter->expects($this->never())->method('execute');

        $provider = new MinimumOrderProvider(
            $this->apiAdapter,
            $configRepository,
            $this->cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );

        $this->assertNull($provider->getMinimum(1));
    }

    public function testMemoisesWithinTheRequest(): void
    {
        // isAvailable() fires many times per page view; two getMinimum()
        // calls must cost one verify + one merchant fetch, not two.
        $this->apiAdapter->expects($this->exactly(2))->method('execute')->willReturnCallback(
            function (string $endpoint) {
                return $endpoint === '/v1/merchant/verify_api_key'
                    ? ['id' => 'abc-123']
                    : [
                        'min_order_amount' => '250.00',
                        'min_order_currency' => 'EUR',
                        'min_order_basis' => 'net',
                    ];
            }
        );

        $first = $this->provider->getMinimum(1);
        $second = $this->provider->getMinimum(1);

        $this->assertSame($first, $second);
    }

    public function testCacheHitSkipsTheApi(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"minimum":{"amount":250.0,"currency":"EUR","basis":"net"}}');
        $this->apiAdapter->expects($this->never())->method('execute');

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getApiKey')->willReturn('test-api-key');
        $provider = new MinimumOrderProvider(
            $this->apiAdapter,
            $configRepository,
            $cache,
            new Json(),
            $this->createMock(LogRepository::class)
        );

        $this->assertSame(
            ['amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net'],
            $provider->getMinimum(1)
        );
    }

    public function testCachesTheNoMinimumOutcome(): void
    {
        // "No minimum" is the common case and must not cost two API calls
        // per page view: the resolved null is cached like a real tuple.
        $this->stubApi(['id' => 'abc-123'], ['id' => 'abc-123']);
        $this->cache->expects($this->once())->method('save')->with(
            '{"minimum":null}',
            $this->stringContains('two_gateway_minimum_order_'),
            [],
            900
        );

        $this->assertNull($this->provider->getMinimum(1));
    }

    public function testNormalisesCurrencyCase(): void
    {
        $this->stubApi(['id' => 'abc-123'], [
            'min_order_amount' => '250.00',
            'min_order_currency' => 'eur',
            'min_order_basis' => 'net',
        ]);

        // A lowercase currency must not force the gate's FX branch into
        // permanent fail-closed against uppercase quote currency codes.
        $minimum = $this->provider->getMinimum(1);
        $this->assertSame('EUR', $minimum['currency']);
    }
}
