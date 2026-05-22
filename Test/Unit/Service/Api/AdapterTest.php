<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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

    /** @var BrandRegistryInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $brandRegistry;

    /** @var Curl|\PHPUnit\Framework\MockObject\MockObject */
    private $curl;

    /** @var CurlFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $curlFactory;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
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

    public function testPassthroughBehavesLikePreTranslator(): void
    {
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"id":"abc"}');
        $this->curl->method('getHeaders')->willReturn([]);

        $captured = ['url' => null, 'body' => null];
        $this->curl->method('post')->willReturnCallback(function ($u, $b) use (&$captured) {
            $captured['url'] = $u;
            $captured['body'] = $b;
        });

        $result = $this->adapter(new NullApiTranslator())
            ->execute('/v1/order', ['amount' => 100]);

        $this->assertSame(['id' => 'abc'], $result);
        $this->assertSame('https://api.two.inc/v1/order', $captured['url']);
        $this->assertSame('{"amount":100}', $captured['body']);
    }

    public function testTranslatorRewritesUrl(): void
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
        };

        $this->adapter($translator)->execute('/v1/order', ['x' => 1]);

        $this->assertSame('https://api.two.inc/brand-proxy/v1/order', $captured['url']);
    }

    public function testTranslatorThrowReturns502Envelope(): void
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
}
