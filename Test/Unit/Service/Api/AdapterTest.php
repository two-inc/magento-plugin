<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Psr\Log\LoggerInterface;

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

    /** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $refusalLogger;

    /** @var Adapter */
    private $adapter;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->curl = $this->createMock(Curl::class);
        $this->logRepository = $this->createMock(LogRepository::class);
        $this->brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $this->refusalLogger = $this->createMock(LoggerInterface::class);
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
            new NullApiTranslator(),
            $this->refusalLogger
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

    /**
     * TWO-26150: Curl::post() always issues a POST, so every other
     * non-GET method must name itself through CURLOPT_CUSTOMREQUEST.
     *
     * @return array<string, array{string, string, ?string, string}>
     *         [method, client call, CURLOPT_CUSTOMREQUEST, description]
     */
    public static function methodCases(): array
    {
        return [
            'POST' => ['POST', 'post', null, 'POST goes through post() with no override'],
            'PUT' => ['PUT', 'post', 'PUT', 'an order edit PUT must reach the API as PUT'],
            'DELETE' => ['DELETE', 'post', 'DELETE', 'DELETE must not fall through to a GET'],
            'GET' => ['GET', 'get', null, 'GET goes through get() with no override'],
        ];
    }

    #[DataProvider('methodCases')]
    public function testEachMethodIsSentAsItself(
        string $method,
        string $clientCall,
        ?string $customRequest,
        string $description
    ): void {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');
        $options = [];
        $this->curl->method('setOption')->willReturnCallback(
            function ($option, $value) use (&$options): void {
                $options[$option] = $value;
            }
        );
        $calls = [];
        $this->curl->method('post')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'post';
        });
        $this->curl->method('get')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'get';
        });

        $this->adapter->execute('/v1/order/123', ['status' => 'x'], $method);

        $this->assertSame([$clientCall], $calls, $description);
        $this->assertSame($customRequest, $options[CURLOPT_CUSTOMREQUEST] ?? null, $description);
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
            new NullApiTranslator(),
            $this->refusalLogger
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
     * @dataProvider callTimeouts
     */
    public function testTheCallTimeoutIsTheOneTheCallerAskedFor(
        ?int $requested,
        int $expected,
        string $description
    ): void {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $calls = [];
        $this->curl->method('setOption')->willReturnCallback(function ($opt, $val) use (&$calls) {
            $calls[$opt] = $val;
        });

        $this->adapter->execute('/v1/order', [], 'POST', null, null, null, $requested);

        $this->assertSame($expected, $calls[CURLOPT_TIMEOUT], $description);
    }

    /**
     * @return array<int,array{0: int|null, 1: int, 2: string}>
     */
    public static function callTimeouts(): array
    {
        return [
            [null, 60, 'no timeout asked for keeps the default'],
            [10, 10, 'a caller bounding its own wall clock gets the shorter ceiling'],
            [120, 120, 'a longer ceiling is relayed, not clamped to the default'],
        ];
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
            $translator,
            $this->refusalLogger
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
            $translator,
            $this->refusalLogger
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
            new NullApiTranslator(),
            $this->refusalLogger
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
     * Given the merchant's configured headers; When the pre-auth API-key
     * verification call runs with an unsaved candidate key; Then it carries
     * them too — the firewall those headers clear does not exempt the one
     * call made before the key is known to work.
     *
     * @dataProvider apiKeySources
     */
    public function testTheApiKeyVerificationCallCarriesTheConfiguredHeaders(
        ?string $override,
        string $expectedKey,
        string $description
    ): void {
        $this->configRepository->method('getCustomHeaders')
            ->willReturn(['X-WAF-TOKEN' => 'waf-token', 'X-Gateway' => 'edge-1']);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"id":"abc"}');

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(
            function ($name, $value) use (&$headers) {
                $headers[$name] = $value;
            }
        );

        $this->adapter->execute('/v1/merchant/verify_api_key', [], 'GET', null, $override);

        $this->assertSame('waf-token', $headers['X-WAF-TOKEN'] ?? null, $description);
        $this->assertSame('edge-1', $headers['X-Gateway'] ?? null, $description);
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

    // ── Debug logging ───────────────────────────────────────────────────

    /**
     * Given the store's "Enable debug logging" setting; When a call returns
     * a given status; Then the request and response bodies are logged only
     * with debug on, once, with the key and custom header values redacted,
     * a refusal going to the error log and the rest to the debug log.
     *
     * @dataProvider debugLoggingCases
     */
    public function testExchangeIsLoggedOnlyWithDebugOn(
        bool $debug,
        int $status,
        int $debugLogs,
        int $errorLogs,
        string $description
    ): void {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getCheckoutApiUrl')->willReturn('https://api.two.inc');
        $configRepository->method('addVersionDataInURL')->willReturnArgument(0);
        $configRepository->method('getApiKey')->willReturn('test-key');
        $configRepository->method('getCustomHeaders')->willReturn(['X-WAF-TOKEN' => 'waf-token']);
        $configRepository->method('isDebugMode')->with(7)->willReturn($debug);

        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn('{"buyer":"Jane Doe"}');
        $this->curl->method('getHeaders')->willReturn(['X-Trace' => 't1']);

        $logged = ['debug' => [], 'error' => []];
        $logRepository = $this->createMock(LogRepository::class);
        $logRepository->method('addDebugLog')->willReturnCallback(
            function ($type, $data) use (&$logged) {
                // Only entries carrying the exchange; a refusal's reason line has no data.
                if ($data !== null) {
                    $logged['debug'][] = [$type, $data];
                }
            }
        );
        $logRepository->method('addErrorLog')->willReturnCallback(
            function ($type, $data) use (&$logged) {
                // Only entries carrying the exchange; a refusal's reason line has no data.
                if ($data !== null) {
                    $logged['error'][] = [$type, $data];
                }
            }
        );

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);
        $adapter = new Adapter(
            $configRepository,
            $this->brandRegistry,
            $curlFactory,
            $logRepository,
            new NullApiTranslator(),
            $this->refusalLogger
        );
        $adapter->execute('/v1/order', ['buyer' => 'Jane Doe'], 'POST', 7);

        $this->assertCount($debugLogs, $logged['debug'], $description);
        $this->assertCount($errorLogs, $logged['error'], $description);
        foreach (array_merge($logged['debug'], $logged['error']) as [$type, $data]) {
            $this->assertSame('POST /v1/order', $type, $description);
            $this->assertSame(['buyer' => 'Jane Doe'], $data['request']['body'], $description);
            $this->assertSame('[REDACTED]', $data['request']['headers']['X-API-Key'], $description);
            $this->assertSame('[REDACTED]', $data['request']['headers']['X-WAF-TOKEN'], $description);
            $this->assertSame($status, $data['response']['status_code'], $description);
            $this->assertSame(['buyer' => 'Jane Doe'], $data['response']['body'], $description);
            $this->assertSame(['X-Trace' => 't1'], $data['response']['headers'], $description);
        }
    }

    /**
     * @return array<string, array{0: bool, 1: int, 2: int, 3: int, 4: string}>
     */
    public static function debugLoggingCases(): array
    {
        return [
            'debug off, success' => [false, 200, 0, 0, 'no body is logged with debug off'],
            'debug off, refused' => [false, 422, 0, 0, 'no body is logged with debug off, even on a refusal'],
            'debug on, success' => [true, 200, 1, 0, 'a success is one debug log entry'],
            'debug on, refused' => [true, 422, 0, 1, 'a refusal is one error log entry'],
        ];
    }

    /**
     * Given an order request the API refuses; When it returns, whatever the
     * debug setting; Then one reason-only line is logged (a warning for an
     * intent, an error for the rest), and no line for a read or a non-order
     * call. The buyer's details never appear in it.
     *
     * @dataProvider refusalCases
     */
    public function testOrderRequestRefusalIsLoggedWithTheReason(
        string $method,
        string $endpoint,
        ?string $level,
        string $description
    ): void {
        $this->configRepository->method('isDebugMode')->willReturn(false);
        $this->curl->method('getStatus')->willReturn(422);
        $this->curl->method('getBody')->willReturn(
            '{"error_code":"SCHEMA_ERROR","error_message":"Invalid order",'
            . '"error_json":[{"loc":["buyer","email"],"msg":"field required"}],"buyer":"Jane Doe"}'
        );

        $logged = [];
        $this->logRepository->method('addDebugLog')->willReturnCallback(
            function ($type, $data) use (&$logged) {
                $logged[] = ['debug', $type, $data];
            }
        );
        $this->logRepository->method('addErrorLog')->willReturnCallback(
            function ($type, $data) use (&$logged) {
                $logged[] = ['error log repository', $type, $data];
            }
        );
        foreach (['error', 'warning'] as $lvl) {
            $this->refusalLogger->method($lvl)->willReturnCallback(
                function ($message, array $context = []) use (&$logged, $lvl) {
                    $logged[] = [$lvl, $message, $context ?: null];
                }
            );
        }

        $this->adapter->execute($endpoint, ['buyer' => 'Jane Doe'], $method, 1);

        if ($level === null) {
            $this->assertSame([], $logged, $description);
            return;
        }
        $this->assertSame(
            [[
                $level,
                "$method $endpoint refused by the API (HTTP 422): "
                    . 'SCHEMA_ERROR; Invalid order; buyer.email: field required',
                null,
            ]],
            $logged,
            $description
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: string}>
     */
    public static function refusalCases(): array
    {
        return [
            'create' => ['POST', '/v1/order', 'error', 'a refused create is an error'],
            'edit' => ['PUT', '/v1/order/abc', 'error', 'a refused edit is an error'],
            'fulfilment' => ['POST', '/v1/order/abc/fulfillments', 'error', 'a refused fulfilment is an error'],
            'refund' => ['POST', '/v1/order/abc/refund', 'error', 'a refused refund is an error'],
            'cancel' => ['POST', '/v1/order/abc/cancel', 'error', 'a refused cancel is an error'],
            'confirm' => ['POST', '/v1/order/abc/confirm', 'error', 'a refused confirm is an error'],
            'intent' => ['POST', '/v1/order_intent', 'warning', 'a refused intent is only a warning'],
            'order read' => ['GET', '/v1/order/abc', null, 'a read is not an order request'],
            'non-order call' => ['POST', '/v1/pricing/order/fee', null, 'a non-order endpoint is not logged'],
        ];
    }

    /**
     * A refused create names its quote and carries the API's trace id, in
     * the one line logged for it.
     */
    public function testARefusedCreateNamesItsQuoteAndTraceId(): void
    {
        $this->configRepository->method('isDebugMode')->willReturn(false);
        $this->curl->method('getStatus')->willReturn(400);
        $this->curl->method('getBody')->willReturn(
            '{"error_code":"ORDER_INVALID","error_message":"Order is invalid","error_trace_id":"trace-1"}'
        );
        $this->refusalLogger->expects($this->once())->method('error')->with(
            'POST /v1/order refused by the API (HTTP 400) for quote 42: ORDER_INVALID; Order is invalid [Trace ID: trace-1]'
        );
        $this->logRepository->expects($this->never())->method('addErrorLog');

        $this->adapter->withLogSubject('quote 42')->execute('/v1/order', ['buyer' => 'Jane Doe'], 'POST', 1);
    }

    public function testRefusalReasonFallsBackToTheRawBody(): void
    {
        $this->configRepository->method('isDebugMode')->willReturn(false);
        $this->curl->method('getStatus')->willReturn(502);
        $this->curl->method('getBody')->willReturn('Bad gateway');
        $this->refusalLogger->expects($this->once())->method('error')
            ->with('POST /v1/order/abc/refund refused by the API (HTTP 502): Bad gateway');

        $this->adapter->execute('/v1/order/abc/refund', [], 'POST', 1);
    }

    /**
     * A subject names one call chain only: the shared adapter, which every
     * other caller is handed, keeps logging without one.
     */
    public function testALogSubjectLeavesTheSharedAdapterAlone(): void
    {
        $this->configRepository->method('isDebugMode')->willReturn(false);
        $this->curl->method('getStatus')->willReturn(400);
        $this->curl->method('getBody')->willReturn('{"error_message":"Order is invalid"}');
        $messages = [];
        $this->refusalLogger->method('error')->willReturnCallback(function ($message) use (&$messages) {
            $messages[] = $message;
        });

        $this->adapter->withLogSubject('quote 42');
        $this->adapter->execute('/v1/order', [], 'POST', 1);

        $this->assertSame(['POST /v1/order refused by the API (HTTP 400): Order is invalid'], $messages);
    }

    /**
     * The adapter is extended outside this module, so its public signatures
     * stay as they were: execute() and executeWithStatus() take the same
     * seven parameters, and the refusal logger is optional.
     *
     * @dataProvider unchangedSignatures
     */
    public function testThePublicSignaturesAreUnchanged(string $method, int $parameters): void
    {
        $this->assertSame($parameters, (new \ReflectionMethod(Adapter::class, $method))->getNumberOfParameters(), $method);
        $this->assertSame(5, (new \ReflectionMethod(Adapter::class, '__construct'))->getNumberOfRequiredParameters());
    }

    public static function unchangedSignatures(): array
    {
        return [
            'execute' => ['execute', 7],
            'executeWithStatus' => ['executeWithStatus', 7],
        ];
    }
}
