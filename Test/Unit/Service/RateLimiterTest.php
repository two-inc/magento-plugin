<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Webapi\Exception as WebapiException;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
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

    /** @var array<int,array{0: string, 1: mixed}> */
    private $errorLog = [];

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
     * @param string[] $trustedProxies
     */
    private function limiterFor(
        array $server,
        array $trustedProxies = [],
        bool $rateLimitDisabled = false
    ): RateLimiter {
        $request = new HttpRequest();
        $request->setTestEnvironment($server);

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getTrustedProxies')->willReturn($trustedProxies);
        $config->method('isRateLimitDisabled')->willReturn($rateLimitDisabled);

        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(
            function ($type, $data) {
                $this->errorLog[] = [$type, $data];
                return null;
            }
        );

        return new RateLimiter($this->cache(), $request, $config, $log);
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

    /**
     * Given a request arriving from an address the merchant named as its own
     * proxy; When two buyers behind it are forwarded; Then each gets its own
     * budget instead of sharing the proxy's.
     *
     * This is the whole point of the setting: without it a store behind a
     * reverse proxy, CDN or ALB counts every buyer as one caller and the
     * per-caller ceiling becomes a store-wide one.
     *
     * @dataProvider trustedProxyForms
     */
    public function testATrustedProxyLetsTheForwardedBuyerKeyTheLimiter(
        array $trustedProxies,
        string $peer,
        string $description
    ): void {
        $this->limiterFor(
            ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'],
            $trustedProxies
        )->assertWithinLimit('route', 1, 60);

        $second = $this->limiterFor(
            ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => '203.0.113.6'],
            $trustedProxies
        );
        $second->assertWithinLimit('route', 1, 60);

        $this->assertCount(2, $this->entries, $description);

        // Still a ceiling: the second buyer is refused on its own budget.
        $this->expectException(WebapiException::class);
        $second->assertWithinLimit('route', 1, 60);
    }

    /**
     * @return array<string, array{0: string[], 1: string, 2: string}>
     */
    public static function trustedProxyForms(): array
    {
        return [
            'exact address' => [
                ['198.51.100.7'],
                '198.51.100.7',
                'a proxy named by address is trusted',
            ],
            'ipv4 cidr' => [
                ['10.0.0.0/8'],
                '10.4.5.6',
                'a proxy inside a named range is trusted',
            ],
            'ipv6 cidr' => [
                ['2001:db8::/32'],
                '2001:db8:1234::9',
                'ranges are matched for IPv6 too',
            ],
            'one of several' => [
                ['192.0.2.1', '10.0.0.0/8'],
                '10.4.5.6',
                'any entry in the list may match',
            ],
        ];
    }

    /**
     * Given trusted proxies configured; When the request arrives from an
     * address that is not one of them; Then the forwarding header it carries
     * is still ignored.
     */
    public function testAPeerOutsideTheTrustedSetCannotNameItsOwnClient(): void
    {
        $server = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'];

        $this->limiterFor($server, ['10.0.0.0/8'])->assertWithinLimit('route', 1, 60);
        $rotated = $this->limiterFor(
            ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99'],
            ['10.0.0.0/8']
        );

        $this->assertCount(1, $this->entries, 'an untrusted peer keys on its own address');

        $this->expectException(WebapiException::class);
        $rotated->assertWithinLimit('route', 1, 60);
    }

    /**
     * Given a chain of hops; When the trailing ones are the merchant's own;
     * Then the buyer is the last address none of them account for.
     */
    public function testTheProxyHopsAreStrippedFromTheForwardedChain(): void
    {
        $chain = [
            'REMOTE_ADDR' => '10.0.0.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.0.0.4, 10.0.0.9',
        ];
        $this->limiterFor($chain, ['10.0.0.0/8'])->assertWithinLimit('route', 1, 60);

        // The same buyer through a different hop of the same estate shares
        // the budget, which only holds if the hops were stripped.
        $viaOtherHop = $this->limiterFor(
            ['REMOTE_ADDR' => '10.0.0.4', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 10.0.0.4'],
            ['10.0.0.0/8']
        );

        $this->assertCount(1, $this->entries, 'the buyer, not the hop, keys the budget');

        $this->expectException(WebapiException::class);
        $viaOtherHop->assertWithinLimit('route', 1, 60);
    }

    /**
     * Given the Diagnostics escape hatch is on; When a caller runs far past
     * the ceiling; Then nothing is refused and no counter is kept.
     */
    public function testTheDiagnosticsToggleSwitchesTheCeilingOffEntirely(): void
    {
        $limiter = $this->limiterFor(['REMOTE_ADDR' => '198.51.100.7'], [], true);

        for ($i = 0; $i < 25; $i++) {
            $limiter->assertWithinLimit('route', 1, 60);
        }

        $this->assertSame([], $this->entries, 'a disabled limiter keeps no state');
        $this->assertSame([], $this->errorLog, 'and refuses nothing to report');
    }

    /**
     * Given every refusal in the window comes from one address and no trusted
     * proxies are set; When a call is refused; Then the log says so and names
     * the two settings that resolve it.
     */
    public function testOneAddressBehindEveryRefusalIsCalledOutInTheLog(): void
    {
        $limiter = $this->limiterFor(['REMOTE_ADDR' => '198.51.100.7']);
        $limiter->assertWithinLimit('route', 1, 60);

        try {
            $limiter->assertWithinLimit('route', 1, 60);
        } catch (WebapiException $e) {
            // The log is what this test is about.
        }

        $this->assertCount(1, $this->errorLog);
        [$line, $hint] = $this->errorLog[0];
        $this->assertStringContainsString('[rate-limit-exceeded] route=route', $line);
        $this->assertStringContainsString('caller=198.51.100.7', $line);
        $this->assertStringContainsString('distinct_callers_refused=1', $line);
        $this->assertStringContainsString('trusted_proxies=0', $line);
        $this->assertStringContainsString('Trusted proxies', (string)$hint);
        $this->assertStringContainsString('Disable checkout rate limiting', (string)$hint);
    }

    /**
     * Given refusals spread across several addresses; When they are logged;
     * Then the count says so and the single-address hint is withheld — that
     * is an abusive-caller picture, not a collapsed-bucket one.
     */
    public function testRefusalsFromSeveralAddressesAreCountedApart(): void
    {
        foreach (['198.51.100.7', '203.0.113.9', '192.0.2.44'] as $peer) {
            $limiter = $this->limiterFor(['REMOTE_ADDR' => $peer]);
            $limiter->assertWithinLimit('route', 1, 60);
            try {
                $limiter->assertWithinLimit('route', 1, 60);
            } catch (WebapiException $e) {
                // The log is what this test is about.
            }
        }

        $this->assertCount(3, $this->errorLog);
        [$line, $hint] = $this->errorLog[2];
        $this->assertStringContainsString('distinct_callers_refused=3', $line);
        $this->assertStringContainsString('refusals_in_window=3', $line);
        $this->assertNull($hint, 'several callers is not the collapsed-bucket picture');
    }
}
