<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Webapi\Exception as WebapiException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\RateLimiter;

/**
 * The ceiling on the module's anonymous webapi routes.
 */
class RateLimiterTest extends TestCase
{
    /** @var array<string,string> */
    private $entries = [];

    /** @var array<string,int|null> */
    private $lifetimes = [];

    private function cache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn($id) => $this->entries[$id] ?? false
        );
        $cache->method('save')->willReturnCallback(
            function ($data, $id, $tags = [], $lifeTime = null) {
                $this->entries[$id] = (string)$data;
                $this->lifetimes[$id] = $lifeTime;
                return true;
            }
        );

        return $cache;
    }

    /**
     * @param array<string,string> $server the CGI environment of the request
     */
    private function limiterFor(array $server): RateLimiter
    {
        $request = new HttpRequest();
        $request->setTestEnvironment($server);

        return new RateLimiter($this->cache(), $request);
    }

    private function limiter(string $peer = '198.51.100.7'): RateLimiter
    {
        return $this->limiterFor(['REMOTE_ADDR' => $peer]);
    }

    /**
     * Given a ceiling of N; When N calls are made; Then the N+1th is refused
     * with the status a client can back off on.
     */
    public function testTheCallAfterTheCeilingIsRefusedAs429(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 3; $i++) {
            $limiter->assertWithinLimit('route', 3, 60);
        }

        try {
            $limiter->assertWithinLimit('route', 3, 60);
            $this->fail('a fourth call should not have been allowed');
        } catch (WebapiException $e) {
            $this->assertSame(429, $e->getHttpCode());
        }
    }

    /**
     * Given one caller at its ceiling; When the axis under test differs;
     * Then the budget is a separate one.
     *
     * @dataProvider independentBudgets
     */
    public function testBudgetsAreKeptPerRouteAndPerCaller(
        string $route,
        string $peer,
        string $description
    ): void {
        $this->limiter()->assertWithinLimit('route-a', 1, 60);

        $fresh = $this->limiter($peer);
        $fresh->assertWithinLimit($route, 1, 60);

        $this->assertCount(2, $this->entries, $description);

        $this->expectException(WebapiException::class);
        $fresh->assertWithinLimit($route, 1, 60);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function independentBudgets(): array
    {
        return [
            'another route' => ['route-b', '198.51.100.7', 'one route cannot exhaust another'],
            'another caller' => ['route-a', '203.0.113.9', 'one caller cannot exhaust another'],
        ];
    }

    /**
     * Given one peer rotating a forwarding header per request; When the
     * ceiling is reached; Then the next call is still refused, and no
     * extra cache entry was minted along the way.
     *
     * Stock Magento trusts X-Forwarded-For with no proxy allow-list, so a
     * limiter keyed on the framework's resolved remote address would hand
     * this caller a fresh bucket — and a fresh cache key — every request.
     *
     * @dataProvider spoofableHeaders
     */
    public function testARotatedForwardingHeaderDoesNotBuyAFreshBucket(
        string $header,
        string $description
    ): void {
        $ceiling = 3;

        for ($i = 0; $i < $ceiling; $i++) {
            $this->limiterFor([
                'REMOTE_ADDR' => '198.51.100.7',
                $header => '203.0.113.' . $i,
            ])->assertWithinLimit('route', $ceiling, 60);
        }

        $this->assertCount(1, $this->entries, $description);

        $this->expectException(WebapiException::class);
        $this->limiterFor([
            'REMOTE_ADDR' => '198.51.100.7',
            $header => '203.0.113.99',
        ])->assertWithinLimit('route', $ceiling, 60);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spoofableHeaders(): array
    {
        return [
            'x-forwarded-for' => [
                'HTTP_X_FORWARDED_FOR',
                'the header stock Magento trusts unconditionally cannot key the limiter',
            ],
            'client-ip' => [
                'HTTP_CLIENT_IP',
                'no client-supplied address header keys the limiter',
            ],
        ];
    }

    /**
     * The counter's lifetime is what retires a window — nothing sweeps it.
     */
    public function testTheCounterExpiresWithItsWindow(): void
    {
        $this->limiter()->assertWithinLimit('route', 5, 90);

        $this->assertSame([90], array_values(array_unique($this->lifetimes)));
    }

    /**
     * An unresolvable peer shares one bucket rather than skipping the
     * ceiling entirely.
     */
    public function testAnUnresolvableCallerIsStillCounted(): void
    {
        $limiter = $this->limiterFor([]);
        $limiter->assertWithinLimit('route', 1, 60);

        $this->expectException(WebapiException::class);
        $limiter->assertWithinLimit('route', 1, 60);
    }
}
