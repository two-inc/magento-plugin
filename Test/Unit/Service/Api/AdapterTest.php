<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\ApiCall;
use Two\Gateway\Api\ApiResult;
use Two\Gateway\Api\ApiTranslatorInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Model\ApiTranslator\PassthroughTrait;
use Two\Gateway\Service\Api\Adapter;

class AdapterTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var Curl|\PHPUnit\Framework\MockObject\MockObject */
    private $curl;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var BrandRegistryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $brandRegistry;

    /** @var Adapter */
    private $adapter;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->curl = $this->createMock(Curl::class);
        $this->logRepository = $this->createMock(LogRepository::class);
        $this->brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $this->brandRegistry->method('getProductName')->willReturn('Two');

        $this->configRepository->method('getCheckoutApiUrl')->willReturn('https://api.two.inc');
        $this->configRepository->method('addVersionDataInURL')->willReturnArgument(0);
        $this->configRepository->method('getApiKey')->willReturn('test-key');

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->adapter = new Adapter(
            $this->configRepository,
            $this->brandRegistry,
            $curlFactory,
            $this->logRepository,
            new NullApiTranslator()
        );
    }

    // ── 2xx responses ───────────────────────────────────────────────────

    public function testSuccessfulPostReturnsDecodedJson(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"id":"abc"}');

        $result = $this->adapter->execute('/v1/order', ['amount' => 100]);

        $this->assertEquals(['id' => 'abc'], $result);
    }

    public function testGetRoutesThoughGetMethod(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"ok":true}');

        $this->curl->expects($this->once())->method('get');
        $this->curl->expects($this->never())->method('post');

        $result = $this->adapter->execute('/v1/order/123', [], 'GET');

        $this->assertEquals(['ok' => true], $result);
    }

    public function testSuccessEmptyBodyNonTokenEndpoint(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('');

        $result = $this->adapter->execute('/v1/order', ['foo' => 'bar']);

        $this->assertEquals([], $result);
    }

    public function testSuccessEmptyBodyTokenEndpointReturnsHeaders(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('');
        $this->curl->method('getHeaders')->willReturn([
            'X-Delegation-Token' => 'abc123',
            'Content-Type' => 'application/json',
        ]);

        $result = $this->adapter->execute('/registry/v1/delegation');

        $this->assertArrayHasKey('x-delegation-token', $result);
        $this->assertEquals('abc123', $result['x-delegation-token']);
    }

    // ── Non-2xx responses ───────────────────────────────────────────────

    public function testNon2xxWithBodyReturnsJsonPlusHttpStatus(): void
    {
        $this->curl->method('getStatus')->willReturn(422);
        $this->curl->method('getBody')->willReturn(
            '{"error_code":"VALIDATION_ERROR","error_message":"Invalid field"}'
        );

        $result = $this->adapter->execute('/v1/order', ['amount' => 100]);

        $this->assertEquals('VALIDATION_ERROR', $result['error_code']);
        $this->assertEquals('Invalid field', $result['error_message']);
        $this->assertEquals(422, $result['http_status']);
    }

    public function testNon2xxWithMalformedJsonBody(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('not json');

        $result = $this->adapter->execute('/v1/order', ['amount' => 100]);

        $this->assertEquals(500, $result['http_status']);
    }

    public function testNon2xxWithEmptyBodyReturnsCaughtException(): void
    {
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('');

        $result = $this->adapter->execute('/v1/order', ['amount' => 100]);

        $this->assertEquals(400, $result['error_code']);
        $this->assertStringContainsString('Invalid API response from Two.', $result['error_message']);
        // The real status is preserved rather than being swallowed by the
        // catch-all. Callers that categorise failures need it: without a
        // status, an empty-bodied 5xx is indistinguishable from a transport
        // failure, so a service outage would be reported to the merchant as
        // "could not be reached" instead of "the service errored".
        $this->assertSame(500, $result['http_status']);
    }

    public function testTransportFailureCarriesNoHttpStatus(): void
    {
        // The counterpart to the case above, and the reason it matters: when
        // no HTTP exchange completes at all, there is no status to report.
        // The absence of http_status is what distinguishes the two.
        $this->curl->method('getStatus')
            ->willThrowException(new \RuntimeException('Error in transfer'));

        $result = $this->adapter->execute('/v1/order', ['amount' => 100]);

        $this->assertEquals(400, $result['error_code']);
        $this->assertArrayNotHasKey('http_status', $result);
    }

    // ── Edge cases ──────────────────────────────────────────────────────

    public function testPostWithEmptyPayloadSendsEmptyString(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('[]');

        $this->curl->expects($this->once())
            ->method('post')
            ->with($this->anything(), '');

        $this->adapter->execute('/v1/order', []);
    }

    public function testPutRoutesThoughPostBranch(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $this->curl->expects($this->once())->method('post');
        $this->curl->expects($this->never())->method('get');

        $this->adapter->execute('/v1/order/123', ['status' => 'fulfilled'], 'PUT');
    }

    public function testExceptionDuringRequestReturnsCaughtError(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->configRepository->method('getCheckoutApiUrl')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $adapter = new Adapter(
            $this->configRepository,
            $this->brandRegistry,
            $curlFactory,
            $this->logRepository,
            new NullApiTranslator()
        );

        $result = $adapter->execute('/v1/order');

        $this->assertEquals(400, $result['error_code']);
        $this->assertEquals('Connection failed', $result['error_message']);
    }

    // ── TWO-25386: SSL verification toggle ───────────────────────────────

    public function testSslVerificationIsOnByDefault(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(false);

        $calls = [];
        $this->curl->method('setOption')->willReturnCallback(function ($opt, $val) use (&$calls) {
            $calls[$opt] = $val;
        });

        $this->adapter->execute('/v1/order', ['amount' => 100]);

        $this->assertSame(2, $calls[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(true, $calls[CURLOPT_SSL_VERIFYPEER]);
    }

    public function testSslVerificationDisabledWhenToggleIsOn(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');
        $this->configRepository->method('isSslVerificationDisabled')->willReturn(true);

        $calls = [];
        $this->curl->method('setOption')->willReturnCallback(function ($opt, $val) use (&$calls) {
            $calls[$opt] = $val;
        });

        $this->adapter->execute('/v1/order', ['amount' => 100]);

        $this->assertSame(0, $calls[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(0, $calls[CURLOPT_SSL_VERIFYPEER]);
    }

    /**
     * disable_ssl_verify is store-view-scoped (showInWebsite="1"
     * showInStore="1" in system.xml), same as the other scoped calls this
     * method already makes (getMode($storeId), getApiKey($storeId)). The
     * check must resolve at the caller's store, not default/global scope —
     * otherwise a store-level override (e.g. to work around a corporate
     * proxy) is silently ignored.
     */
    public function testSslVerificationCheckIsScopedToTheCallersStore(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $this->configRepository->expects($this->once())
            ->method('isSslVerificationDisabled')
            ->with(7)
            ->willReturn(false);

        $this->adapter->execute('/v1/order', ['amount' => 100], 'POST', 7);
    }

    // ── ApiTranslator hook ──────────────────────────────────────────────

    public function testTranslatorRewritesUrl(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"ok":true}');

        $capturedUrl = null;
        $this->curl->method('post')->willReturnCallback(function ($u) use (&$capturedUrl) {
            $capturedUrl = $u;
        });

        $translator = new class implements ApiTranslatorInterface {
            use PassthroughTrait;
            public function translateRequest(ApiCall $call): ApiCall
            {
                $call->url = str_replace('/v1/', '/brand-proxy/v1/', $call->url);
                return $call;
            }
        };

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $adapter = new Adapter(
            $this->configRepository,
            $this->brandRegistry,
            $curlFactory,
            $this->logRepository,
            $translator
        );
        $adapter->execute('/v1/order', ['x' => 1]);

        $this->assertSame('https://api.two.inc/brand-proxy/v1/order', $capturedUrl);
    }

    public function testTranslatorThrowReturns502Envelope(): void
    {
        $translator = new class implements ApiTranslatorInterface {
            use PassthroughTrait;
            public function translateRequest(ApiCall $call): ApiCall
            {
                throw new \RuntimeException('boom');
            }
        };

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $adapter = new Adapter(
            $this->configRepository,
            $this->brandRegistry,
            $curlFactory,
            $this->logRepository,
            $translator
        );
        $result = $adapter->execute('/v1/order');

        $this->assertSame(502, $result['error_code']);
        $this->assertSame('api_translator', $result['error_source']);
        $this->assertSame('Translator failure', $result['error_message']);
    }

    /**
     * Given the merchant's configured headers; When any server-side call runs;
     * Then every one of them is on the wire, ticked for the browser or not.
     *
     * @dataProvider customHeaderSets
     *
     * @param array<string, string> $configured
     * @param array<string, string|null> $expected
     */
    public function testEveryConfiguredHeaderIsSentOnServerSideCalls(
        array $configured,
        array $expected,
        string $description
    ): void {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getCheckoutApiUrl')->willReturn('https://api.two.inc');
        $configRepository->method('addVersionDataInURL')->willReturnArgument(0);
        $configRepository->method('getApiKey')->willReturn('test-key');
        $configRepository->method('getCustomHeaders')->willReturn($configured);
        // Never consulted here: the browser tick governs the browser's own
        // direct call, not this one.
        $configRepository->expects($this->never())->method('getBrowserCustomHeaders');

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"id":"abc"}');
        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(
            function ($name, $value) use (&$headers) {
                $headers[$name] = $value;
            }
        );

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);
        $adapter = new Adapter(
            $configRepository,
            $this->brandRegistry,
            $curlFactory,
            $this->logRepository,
            new NullApiTranslator()
        );
        $adapter->execute('/v1/order', ['amount' => 100]);

        foreach ($expected as $name => $value) {
            $this->assertSame($value, $headers[$name] ?? null, $description);
        }
        $this->assertSame('test-key', $headers['X-API-Key'], 'the API key is unaffected');
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: array<string, string|null>, 2: string}>
     */
    public static function customHeaderSets(): array
    {
        return [
            'one header' => [
                ['X-WAF-TOKEN' => 'waf-token'],
                ['X-WAF-TOKEN' => 'waf-token'],
                'a configured header is relayed',
            ],
            'several headers' => [
                ['X-WAF-TOKEN' => 'waf-token', 'X-Gateway' => 'edge-1'],
                ['X-WAF-TOKEN' => 'waf-token', 'X-Gateway' => 'edge-1'],
                'every row is relayed, not just the first',
            ],
            'none configured' => [
                [],
                ['X-WAF-TOKEN' => null],
                'no headers configured sends no extra header at all',
            ],
            'the extension owns its own headers' => [
                ['X-API-Key' => 'hijacked'],
                ['X-API-Key' => 'test-key'],
                'a stored row can never displace a header the extension sets',
            ],
            'whatever the casing' => [
                ['x-api-key' => 'hijacked'],
                ['X-API-Key' => 'test-key', 'x-api-key' => null],
                'field names are case-insensitive, so a second one is a conflict not a new header',
            ],
        ];
    }

    /**
     * @dataProvider apiKeySources
     */
    public function testTheAuthenticationHeaderComesFromTheOverrideWhenOneIsGiven(
        ?string $override,
        string $expectedKey,
        string $description
    ): void {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"id":"abc"}');

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(
            function ($name, $value) use (&$headers) {
                $headers[$name] = $value;
            }
        );

        $this->adapter->execute('/v1/merchant/verify_api_key', [], 'GET', null, $override);

        $this->assertSame($expectedKey, $headers['X-API-Key'], $description);
    }

    /**
     * @return array<string, array{0: string|null, 1: string, 2: string}>
     */
    public static function apiKeySources(): array
    {
        return [
            'stored key' => [null, 'test-key', 'no override uses the configured key'],
            'candidate key' => [
                'candidate-key',
                'candidate-key',
                'an override authenticates with an unsaved candidate instead',
            ],
        ];
    }
}
