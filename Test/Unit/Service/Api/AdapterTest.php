<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\ApiTranslatorInterface;
use Two\Gateway\Api\Webapi\SoleTraderInterface;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Model\ApiTranslator\PassthroughTrait;
use Two\Gateway\Service\Api\Adapter;

class AdapterTest extends TestCase
{
    /** @var ConfigRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var BrandRegistryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $brandRegistry;

    /** @var Curl&\PHPUnit\Framework\MockObject\MockObject */
    private $curl;

    /** @var CurlFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $curlFactory;

    /** @var LogRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var Psr17Factory */
    private $psr17;

    protected function setUp(): void
    {
        if (!class_exists(Psr17Factory::class)) {
            $this->markTestSkipped('nyholm/psr7 not installed (run composer install)');
        }

        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->configRepository->method('getCheckoutApiUrl')->willReturn('https://api.two.inc');
        $this->configRepository->method('addVersionDataInURL')->willReturnArgument(0);
        $this->configRepository->method('getApiKey')->willReturn('test-key');

        $this->brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $this->brandRegistry->method('getProductName')->willReturn('Two');

        $this->curl = $this->createMock(Curl::class);
        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curlFactory->method('create')->willReturn($this->curl);

        $this->logRepository = $this->createMock(LogRepository::class);

        $this->psr17 = new Psr17Factory();
    }

    private function adapter(ApiTranslatorInterface $translator): Adapter
    {
        return new Adapter(
            $this->configRepository,
            $this->brandRegistry,
            $this->curlFactory,
            $this->logRepository,
            $translator,
            $this->psr17,
            $this->psr17,
            $this->psr17
        );
    }

    public function testNullApiTranslatorPassthroughBaseline(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"id":"abc"}');
        $this->curl->method('getHeaders')->willReturn([]);

        $captured = ['headers' => [], 'url' => null, 'body' => null];
        $this->curl->method('addHeader')->willReturnCallback(function ($n, $v) use (&$captured) {
            $captured['headers'][$n] = $v;
        });
        $this->curl->method('post')->willReturnCallback(function ($u, $b) use (&$captured) {
            $captured['url'] = $u;
            $captured['body'] = $b;
        });

        $result = $this->adapter(new NullApiTranslator())
            ->execute('/v1/order', ['amount' => 100]);

        $this->assertSame(['id' => 'abc'], $result);
        $this->assertSame('https://api.two.inc/v1/order', $captured['url']);
        $this->assertSame('{"amount":100}', $captured['body']);
        $this->assertSame('application/json', $captured['headers']['Content-Type']);
        $this->assertSame('test-key', $captured['headers']['X-API-Key']);
        $this->assertSame((string)strlen('{"amount":100}'), $captured['headers']['Content-Length']);
    }

    public function testTranslatorRewritesUrlStatusHeaders(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"native":true}');
        $this->curl->method('getHeaders')->willReturn([]);

        $captured = ['url' => null];
        $this->curl->method('post')->willReturnCallback(function ($u) use (&$captured) {
            $captured['url'] = $u;
        });

        $translator = new class implements ApiTranslatorInterface {
            use PassthroughTrait;
            public function translateRequest(RequestInterface $r): RequestInterface
            {
                return $r->withUri($r->getUri()->withPath('/brand-proxy' . $r->getUri()->getPath()));
            }
            public function translateResponse(ResponseInterface $r): ResponseInterface
            {
                return $r->withStatus(201)
                    ->withBody((new Psr17Factory())->createStream('{"id":"x"}'));
            }
        };

        $result = $this->adapter($translator)
            ->execute('/v1/order', ['x' => 1]);

        $this->assertSame(['id' => 'x'], $result);
        $this->assertSame('https://api.two.inc/brand-proxy/v1/order', $captured['url']);
    }

    public function testTranslatorRequestRuntimeExceptionReturns502(): void
    {
        $translator = new class implements ApiTranslatorInterface {
            use PassthroughTrait;
            public function translateRequest(RequestInterface $r): RequestInterface
            {
                throw new \RuntimeException('boom');
            }
        };

        $result = $this->adapter($translator)->execute('/v1/order');

        $this->assertSame(502, $result['error_code']);
        $this->assertSame('api_translator', $result['error_source']);
        $this->assertSame('Translator failure', $result['error_message']);
    }

    public function testTranslatorRequestTypeErrorReturns502(): void
    {
        $translator = new class implements ApiTranslatorInterface {
            use PassthroughTrait;
            public function translateRequest(RequestInterface $r): RequestInterface
            {
                /** @phpstan-ignore-next-line */
                return null; // forces TypeError on return
            }
        };

        $result = $this->adapter($translator)->execute('/v1/order');

        $this->assertSame(502, $result['error_code']);
        $this->assertSame('api_translator', $result['error_source']);
    }

    public function testContentLengthRecomputedAfterBodyRewrite(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');
        $this->curl->method('getHeaders')->willReturn([]);

        $captured = ['len' => null];
        $this->curl->method('addHeader')->willReturnCallback(function ($n, $v) use (&$captured) {
            if (strcasecmp($n, 'Content-Length') === 0) {
                $captured['len'] = $v;
            }
        });

        $longBody = str_repeat('a', 5000);
        $translator = new class($longBody) implements ApiTranslatorInterface {
            use PassthroughTrait;
            private $body;
            public function __construct(string $body) { $this->body = $body; }
            public function translateRequest(RequestInterface $r): RequestInterface
            {
                return $r->withBody((new Psr17Factory())->createStream($this->body));
            }
        };

        $this->adapter($translator)->execute('/v1/order', ['x' => 1]);

        $this->assertSame('5000', $captured['len']);
    }

    public function testResponseBodyReadableTwice(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"a":1}');
        $this->curl->method('getHeaders')->willReturn([]);

        $sawInTranslator = null;
        $translator = new class($sawInTranslator) implements ApiTranslatorInterface {
            use PassthroughTrait;
            public $saw;
            public function __construct(&$saw) { $this->saw = &$saw; }
            public function translateResponse(ResponseInterface $r): ResponseInterface
            {
                $this->saw = (string)$r->getBody();
                return $r;
            }
        };

        $result = $this->adapter($translator)->execute('/v1/order');

        $this->assertSame('{"a":1}', $translator->saw);
        $this->assertSame(['a' => 1], $result);
    }

    /**
     * Preserve-list restoration is unit-tested directly against the
     * `restorePreservedHeaders` helper (protected). Exercising via execute()
     * is awkward because the Adapter owns pre-translation request shape.
     */
    public function testPreservedHeaderRestoredAfterStrip(): void
    {
        $pre = $this->psr17->createRequest('POST', 'https://api.two.inc/x')
            ->withHeader('X-Request-ID', 'req-1')
            ->withHeader('X-Idempotency-Key', 'idem-1')
            ->withHeader('X-Trace-Span', 'span-1');
        $stripped = $pre->withoutHeader('X-Idempotency-Key')->withoutHeader('X-Trace-Span');

        $adapter = new class(
            $this->configRepository,
            $this->brandRegistry,
            $this->curlFactory,
            $this->logRepository,
            new NullApiTranslator(),
            $this->psr17,
            $this->psr17,
            $this->psr17
        ) extends Adapter {
            public function exposeRestore(RequestInterface $r, RequestInterface $pre, string $endpoint): RequestInterface
            {
                return $this->restorePreservedHeaders($r, $pre, $endpoint);
            }
        };

        $this->logRepository->expects($this->atLeastOnce())->method('addDebugLog');

        $restored = $adapter->exposeRestore($stripped, $pre, '/v1/order');

        $this->assertTrue($restored->hasHeader('X-Idempotency-Key'));
        $this->assertSame('idem-1', $restored->getHeaderLine('X-Idempotency-Key'));
        $this->assertTrue($restored->hasHeader('X-Trace-Span'));
        $this->assertSame('span-1', $restored->getHeaderLine('X-Trace-Span'));
        $this->assertTrue($restored->hasHeader('X-Request-ID'));
    }

    public function testTokenHeaderStripReturns502(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('');
        $this->curl->method('getHeaders')->willReturn([
            'two-delegated-authority-token' => 'TKN',
        ]);

        $translator = new class implements ApiTranslatorInterface {
            use PassthroughTrait;
            public function translateResponse(ResponseInterface $r): ResponseInterface
            {
                return $r->withoutHeader('two-delegated-authority-token');
            }
        };

        $result = $this->adapter($translator)
            ->execute(SoleTraderInterface::DELEGATION_TOKEN_ENDPOINT);

        $this->assertSame(502, $result['error_code']);
        $this->assertSame('api_translator', $result['error_source']);
    }

    public function testTranslatorEmptyBodyReturns502(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"native":true}');
        $this->curl->method('getHeaders')->willReturn([]);

        $emptier = new class implements ApiTranslatorInterface {
            use PassthroughTrait;
            public function translateResponse(ResponseInterface $r): ResponseInterface
            {
                return $r->withBody((new Psr17Factory())->createStream(''));
            }
        };

        $result = $this->adapter($emptier)->execute('/v1/order');

        $this->assertSame(502, $result['error_code']);
        $this->assertSame('api_translator', $result['error_source']);
    }

    public function testTranslatorEmptyObjectBodyIsSuccess(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"native":true}');
        $this->curl->method('getHeaders')->willReturn([]);

        $remap = new class implements ApiTranslatorInterface {
            use PassthroughTrait;
            public function translateResponse(ResponseInterface $r): ResponseInterface
            {
                return $r->withBody((new Psr17Factory())->createStream('{}'));
            }
        };

        $result = $this->adapter($remap)->execute('/v1/order');

        $this->assertSame([], $result);
    }
}
