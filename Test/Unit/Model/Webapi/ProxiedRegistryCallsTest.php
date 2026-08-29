<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Magento\Framework\Exception\LocalizedException;
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
use Two\Gateway\Service\RateLimiter;

/**
 * Built on a REAL Adapter with only the HTTP client faked: the point of
 * proxying is the credentials the Adapter attaches, which a mocked Adapter
 * would assert nothing about.
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
        $this->stageUpstream(200, '{"items":[]}');

        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->configRepository->method('getCheckoutApiUrl')->willReturn('https://api.two.inc');
        $this->configRepository->method('addVersionDataInURL')->willReturnArgument(0);
        $this->configRepository->method('getApiKey')->willReturn('merchant-key');
        $this->configRepository->method('getFirewallToken')->willReturn('waf-token');
    }

    private function stageUpstream(int $status, string $body): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
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

        return new OrderIntent($this->adapter(), $apiKeyStatus, $this->rateLimiter());
    }

    private function companyLookup(): CompanyLookup
    {
        return new CompanyLookup($this->adapter(), $this->rateLimiter());
    }

    private function rateLimiter(): RateLimiter
    {
        return $this->createMock(RateLimiter::class);
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
        $this->assertSame(['items' => []], $decoded['body'], $description);
    }

    /**
     * Given an upstream status; When the envelope is built; Then it carries
     * that status and the pass/fail verdict that follows from it.
     *
     * @dataProvider upstreamStatuses
     */
    public function testTheEnvelopeCarriesTheStatusTheUpstreamActuallyAnswered(
        int $upstream,
        bool $ok,
        string $description
    ): void {
        $this->stageUpstream($upstream, '{"items":[]}');

        $decoded = json_decode($this->companyLookup()->search('no', 'x'), true);

        $this->assertSame($upstream, $decoded['status'], $description);
        $this->assertSame($ok, $decoded['ok'], $description);
    }

    /**
     * @return array<string, array{0: int, 1: bool, 2: string}>
     */
    public static function upstreamStatuses(): array
    {
        return [
            'created' => [201, true, 'a 201 is not flattened to 200'],
            'accepted' => [202, true, 'a 202 is not flattened to 200'],
            'unprocessable' => [422, false, 'the real rejection status reaches the browser'],
            'server error' => [500, false, 'an upstream outage is a failure, not an empty success'],
        ];
    }

    /**
     * A body key named like one of the Adapter's failure markers is payload,
     * not a verdict.
     *
     * @dataProvider bodiesShapedLikeFailures
     */
    public function testASuccessCarryingAFailureShapedKeyIsStillASuccess(
        string $body,
        string $description
    ): void {
        $this->stageUpstream(200, $body);

        $decoded = json_decode($this->companyLookup()->search('no', 'x'), true);

        $this->assertTrue($decoded['ok'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bodiesShapedLikeFailures(): array
    {
        return [
            'http_status in payload' => ['{"http_status":200}', 'a payload field is not a verdict'],
            'error_code in payload' => ['{"error_code":"NONE"}', 'a payload field is not a verdict'],
        ];
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
            return $this->companyLookup()->search('no', 'acme');
        }
        if ($endpoint === 'get') {
            return $this->companyLookup()->get('lookup-1');
        }

        return $this->orderIntent()->place('{"gross_amount":"10.00"}');
    }

    public function testSearchAsksTheRegistryForTheServerSideLimitNotACallerSuppliedOne(): void
    {
        $this->companyLookup()->search('no', 'acme ltd');

        parse_str((string)parse_url($this->requestedUrl, PHP_URL_QUERY), $query);
        $this->assertSame('NO', $query['country']);
        $this->assertSame('acme ltd', $query['q']);
        $this->assertSame('50', $query['limit']);
        $this->assertSame('0', $query['offset']);
    }

    public function testCompanyDetailEncodesTheLookupIdIntoThePath(): void
    {
        $this->companyLookup()->get('NO/123 456');

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

    /**
     * Given a key that does not currently verify; When an intent is placed;
     * Then nothing is sent upstream at all.
     *
     * @dataProvider unverifiedKeyCategories
     */
    public function testOrderIntentIsRefusedRatherThanSentWithoutAMerchant(
        string $status,
        string $description
    ): void {
        $intent = $this->orderIntent($status);

        try {
            $intent->place('{"gross_amount":"10.00"}');
            $this->fail('an intent with no verified merchant should not have been sent');
        } catch (LocalizedException $e) {
            $this->assertSame('', $this->requestedBody, $description);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unverifiedKeyCategories(): array
    {
        return [
            'rejected key' => [ApiKeyStatus::INVALID_KEY, 'a wrong key never reaches the upstream call'],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, 'a transient blip does not degrade the request'],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, 'an outage does not degrade the request'],
            'not configured' => [ApiKeyStatus::NOT_CONFIGURED, 'an unconfigured store sends nothing'],
        ];
    }

    public function testOrderIntentRefusesAPayloadThatIsNotAnObject(): void
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);

        $this->orderIntent()->place('not json');
    }

    public function testAnUpstreamFailureIsRelayedAsANotOkEnvelopeRatherThanASuccess(): void
    {
        $this->stageUpstream(422, '{"error_code":"SCHEMA_ERROR"}');

        $decoded = json_decode($this->companyLookup()->search('no', 'x'), true);

        $this->assertFalse($decoded['ok']);
        $this->assertSame(422, $decoded['status']);
        $this->assertSame('SCHEMA_ERROR', $decoded['body']['error_code']);
    }
}
