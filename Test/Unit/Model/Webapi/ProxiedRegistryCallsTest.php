<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Model\Webapi\CompanyLookup;
use Two\Gateway\Model\Webapi\OrderIntent;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * The registry and order-intent calls the checkout used to make straight from
 * the buyer's browser, now served by the plugin's own routes.
 *
 * Built on a REAL Adapter with only the HTTP client faked, because the point
 * of the move is that these calls pick up the credentials the Adapter
 * attaches — a mocked Adapter would assert nothing about that.
 */
class ProxiedRegistryCallsTest extends TestCase
{
    /** @var Curl|\PHPUnit\Framework\MockObject\MockObject */
    private $curl;

    /** @var array<string,string> */
    private $headers = [];

    /** @var string */
    private $requestedUrl = '';

    /** @var string */
    private $requestedBody = '';

    /** @var ConfigRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $configRepository;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"items":[]}');
        $this->curl->method('addHeader')->willReturnCallback(
            function ($name, $value) {
                $this->headers[$name] = $value;
            }
        );
        $this->curl->method('get')->willReturnCallback(
            function ($url) {
                $this->requestedUrl = $url;
            }
        );
        $this->curl->method('post')->willReturnCallback(
            function ($url, $body) {
                $this->requestedUrl = $url;
                $this->requestedBody = $body;
            }
        );

        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->configRepository->method('getCheckoutApiUrl')->willReturn('https://api.two.inc');
        $this->configRepository->method('addVersionDataInURL')->willReturnArgument(0);
        $this->configRepository->method('getApiKey')->willReturn('merchant-key');
        $this->configRepository->method('getFirewallToken')->willReturn('waf-token');
    }

    private function adapter(): Adapter
    {
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        return new Adapter(
            $this->configRepository,
            $brand,
            $curlFactory,
            $this->createMock(LogRepository::class),
            new NullApiTranslator()
        );
    }

    private function orderIntent(string $status = ApiKeyStatus::OK): OrderIntent
    {
        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('getStatus')->willReturn([
            'status' => $status,
            'code' => 200,
            'merchant' => $status === ApiKeyStatus::OK
                ? ['id' => 'merchant-uuid', 'short_name' => 'acme']
                : null,
        ]);

        return new OrderIntent($this->adapter(), $apiKeyStatus);
    }

    /**
     * Given each proxied route; When called; Then the merchant credentials the
     * browser never holds are on the upstream request.
     *
     * @dataProvider proxiedEndpoints
     */
    public function testEveryProxiedEndpointAuthenticatesAsTheMerchant(
        string $endpoint,
        string $description
    ): void {
        $this->invoke($endpoint);

        $this->assertSame('merchant-key', $this->headers['X-API-Key'] ?? null, $description);
        $this->assertSame('waf-token', $this->headers['X-WAF-TOKEN'] ?? null, $description);
    }

    /**
     * @dataProvider proxiedEndpoints
     */
    public function testEveryProxiedEndpointReturnsAnEnvelopeTheBrowserCanReadPassOrFailFrom(
        string $endpoint,
        string $description
    ): void {
        $decoded = json_decode($this->invoke($endpoint), true);

        $this->assertTrue($decoded['ok'], $description);
        $this->assertSame(200, $decoded['status'], $description);
        $this->assertSame(['items' => []], $decoded['body'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function proxiedEndpoints(): array
    {
        return [
            'company search' => ['search', 'company search runs as the merchant'],
            'company by id' => ['get', 'company detail runs as the merchant'],
            'order intent' => ['order-intent', 'order intent runs as the merchant'],
        ];
    }

    private function invoke(string $endpoint): string
    {
        if ($endpoint === 'search') {
            return (new CompanyLookup($this->adapter()))->search('no', 'acme');
        }
        if ($endpoint === 'get') {
            return (new CompanyLookup($this->adapter()))->get('lookup-1');
        }

        return $this->orderIntent()->place('{"gross_amount":"10.00"}');
    }

    public function testSearchAsksTheRegistryForTheServerSideLimitNotACallerSuppliedOne(): void
    {
        (new CompanyLookup($this->adapter()))->search('no', 'acme ltd');

        parse_str((string)parse_url($this->requestedUrl, PHP_URL_QUERY), $query);
        $this->assertSame('NO', $query['country']);
        $this->assertSame('acme ltd', $query['q']);
        $this->assertSame('50', $query['limit']);
        $this->assertSame('0', $query['offset']);
    }

    public function testCompanyDetailEncodesTheLookupIdIntoThePath(): void
    {
        (new CompanyLookup($this->adapter()))->get('NO/123 456');

        $this->assertSame(
            'https://api.two.inc/companies/v2/company/NO%2F123%20456',
            $this->requestedUrl
        );
    }

    public function testOrderIntentReplacesTheMerchantIdentityTheBrowserSent(): void
    {
        $this->orderIntent()->place((string)json_encode([
            'gross_amount' => '10.00',
            'merchant_id' => 'spoofed',
            'merchant_short_name' => 'spoofed',
        ]));

        $sent = json_decode($this->requestedBody, true);
        $this->assertSame('merchant-uuid', $sent['merchant_id']);
        $this->assertSame('acme', $sent['merchant_short_name']);
    }

    public function testOrderIntentSendsNoMerchantIdentityWhenTheStoredKeyDoesNotVerify(): void
    {
        $this->orderIntent(ApiKeyStatus::INVALID_KEY)->place('{"gross_amount":"10.00"}');

        $sent = json_decode($this->requestedBody, true);
        $this->assertNull($sent['merchant_id']);
        $this->assertNull($sent['merchant_short_name']);
    }

    public function testOrderIntentRefusesAPayloadThatIsNotAnObject(): void
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $this->orderIntent()->place('not json');
    }

    public function testAnUpstreamFailureIsRelayedAsANotOkEnvelopeRatherThanASuccess(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(422);
        $curl->method('getBody')->willReturn('{"error_code":"SCHEMA_ERROR"}');
        $this->curl = $curl;

        $decoded = json_decode((new CompanyLookup($this->adapter()))->search('no', 'x'), true);

        $this->assertFalse($decoded['ok']);
        $this->assertSame(422, $decoded['status']);
        $this->assertSame('SCHEMA_ERROR', $decoded['body']['error_code']);
    }
}
