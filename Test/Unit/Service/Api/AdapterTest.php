<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Api;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;

class AdapterTest extends TestCase
{
    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    /** @var Curl|\PHPUnit\Framework\MockObject\MockObject */
    private $curl;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /** @var Adapter */
    private $adapter;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->curl = $this->createMock(Curl::class);
        $this->logRepository = $this->createMock(LogRepository::class);

        $this->configRepository->method('getCheckoutApiUrl')->willReturn('https://api.two.inc');
        $this->configRepository->method('addVersionDataInURL')->willReturnArgument(0);
        $this->configRepository->method('getApiKey')->willReturn('test-key');

        $this->adapter = new Adapter(
            $this->configRepository,
            $this->curl,
            $this->logRepository
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

    // ── Non-2xx responses (ABN-287 critical) ────────────────────────────

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

        $adapter = new Adapter(
            $this->configRepository,
            $this->curl,
            $this->logRepository
        );

        $result = $adapter->execute('/v1/order');

        $this->assertEquals(400, $result['error_code']);
        $this->assertEquals('Connection failed', $result['error_message']);
    }
}
