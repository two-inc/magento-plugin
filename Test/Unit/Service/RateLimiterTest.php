<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
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

    private function limiter(string $ip = '198.51.100.7'): RateLimiter
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn($id) => $this->entries[$id] ?? false
        );
        $cache->method('save')->willReturnCallback(
            function ($data, $id, $tags, $lifeTime) {
                $this->entries[$id] = (string)$data;
                $this->lifetimes[$id] = $lifeTime;
                return true;
            }
        );

        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn($ip);

        return new RateLimiter($cache, $remoteAddress);
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
        string $ip,
        string $description
    ): void {
        $this->limiter()->assertWithinLimit('route-a', 1, 60);

        $fresh = $this->limiter($ip);
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
     * The counter's lifetime is what retires a window — nothing sweeps it.
     */
    public function testTheCounterExpiresWithItsWindow(): void
    {
        $this->limiter()->assertWithinLimit('route', 5, 90);

        $this->assertSame([90], array_values(array_unique($this->lifetimes)));
    }

    /**
     * An unresolvable address shares one bucket rather than skipping the
     * ceiling entirely.
     */
    public function testAnUnresolvableCallerIsStillCounted(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn($id) => $this->entries[$id] ?? false);
        $cache->method('save')->willReturnCallback(
            function ($data, $id) {
                $this->entries[$id] = (string)$data;
                return true;
            }
        );
        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn(false);

        $limiter = new RateLimiter($cache, $remoteAddress);
        $limiter->assertWithinLimit('route', 1, 60);

        $this->expectException(WebapiException::class);
        $limiter->assertWithinLimit('route', 1, 60);
    }
}
