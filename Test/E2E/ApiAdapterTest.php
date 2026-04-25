<?php
declare(strict_types=1);

namespace Two\Gateway\Test\E2E\Service\Api;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Test\E2E\Http\RealCurl;

/**
 * End-to-end tests for Service\Api\Adapter against the real Two API.
 * Defaults to staging unless TWO_API_BASE_URL overrides it.
 *
 * Run via: TWO_API_KEY=xxx make test-e2e
 */
class ApiAdapterTest extends TestCase
{
    private Adapter $adapter;

    protected function setUp(): void
    {
        $apiKey = (string)getenv('TWO_API_KEY');
        $baseUrl = getenv('TWO_API_BASE_URL') ?: 'https://api.staging.two.inc';

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getCheckoutApiUrl')->willReturn($baseUrl);
        $config->method('addVersionDataInURL')->willReturnArgument(0);
        $config->method('getApiKey')->willReturn($apiKey);

        $log = $this->createMock(LogRepository::class);

        $this->adapter = new Adapter($config, new RealCurl(), $log);
    }

    public function testApiKeyIsValid(): void
    {
        $result = $this->adapter->execute('/v1/merchant/verify_api_key', [], 'GET');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error_code', $result);
    }

    public function testInvalidApiKeyReturns401WithStructuredError(): void
    {
        $baseUrl = getenv('TWO_API_BASE_URL') ?: 'https://api.staging.two.inc';

        $config = $this->createMock(ConfigRepository::class);
        $config->method('getCheckoutApiUrl')->willReturn($baseUrl);
        $config->method('addVersionDataInURL')->willReturnArgument(0);
        $config->method('getApiKey')->willReturn('invalid-key');

        $log = $this->createMock(LogRepository::class);

        $adapter = new Adapter($config, new RealCurl(), $log);
        $result = $adapter->execute('/v1/merchant/verify_api_key', [], 'GET');

        $this->assertEquals(401, $result['http_status']);
    }

    public function testBadOrderPayloadReturnsStructuredError(): void
    {
        $result = $this->adapter->execute('/v1/order', []);

        $this->assertArrayHasKey('http_status', $result);
        $this->assertGreaterThanOrEqual(400, $result['http_status']);
    }
}
