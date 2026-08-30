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
        // Mirrors Model\Config\Repository::addVersionDataInURL(): the client
        // identity rides on every outbound URL, which is how the proxied calls
        // keep reporting it now the browser no longer builds the query itself.
        $this->configRepository->method('addVersionDataInURL')->willReturnCallback(
            static fn(string $url) => $url
                . (strpos($url, '?') === false ? '?' : '&')
                . http_build_query(['client' => 'Magento', 'client_v' => '2.3.0+abc1234'])
        );
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
        return new OrderIntent(
            $this->adapter(),
            $this->apiKeyStatus($status),
            $this->rateLimiter(),
            $this->logRepository()
        );
    }

    private function companyLookup(string $status = ApiKeyStatus::OK): CompanyLookup
    {
        return new CompanyLookup($this->adapter(), $this->apiKeyStatus($status), $this->rateLimiter());
    }

    private function apiKeyStatus(string $status): ApiKeyStatus
    {
        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('getStatus')->willReturn([
            'status' => $status,
            'code' => 200,
            'merchant' => $status === ApiKeyStatus::OK
                ? ['id' => 'merchant-uuid', 'short_name' => 'acme']
                : null,
        ]);

        return $apiKeyStatus;
    }

    private function rateLimiter(): RateLimiter
    {
        return $this->createMock(RateLimiter::class);
    }

    /** @var array<int,array{0: string, 1: mixed}> */
    private $errorLog = [];

    private function logRepository(): LogRepository
    {
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(
            function ($type, $data) {
                $this->errorLog[] = [$type, $data];
                return null;
            }
        );

        return $log;
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

    private function invoke(string $endpoint, string $keyStatus = ApiKeyStatus::OK): string
    {
        if ($endpoint === 'search') {
            return $this->companyLookup($keyStatus)->search('no', 'acme');
        }
        if ($endpoint === 'get') {
            return $this->companyLookup($keyStatus)->get('lookup-1');
        }

        return $this->orderIntent($keyStatus)->place('{"gross_amount":"10.00"}');
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
            strtok($this->requestedUrl, '?')
        );
        parse_str((string)parse_url($this->requestedUrl, PHP_URL_QUERY), $query);
        $this->assertSame('acme', $query['merchant'] ?? null);
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
        $decoded = json_decode($this->orderIntent($status)->place('{"gross_amount":"10.00"}'), true);

        $this->assertSame('', $this->requestedBody, $description);
        $this->assertFalse($decoded['ok'], $description);
        $this->assertSame(503, $decoded['status'], $description);
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

    /**
     * Given a payload the route will not process; When it is posted; Then the
     * refusal comes back in the SAME envelope every other answer uses — the
     * browser reads pass/fail off one shape, and a raw webapi fault would
     * reach it as an unrendered generic error.
     *
     * @dataProvider unprocessablePayloads
     */
    public function testOrderIntentRefusesThroughTheEnvelopeContract(
        string $payload,
        int $status,
        string $description
    ): void {
        $decoded = json_decode($this->orderIntent()->place($payload), true);

        $this->assertSame('', $this->requestedBody, $description);
        $this->assertFalse($decoded['ok'], $description);
        $this->assertSame($status, $decoded['status'], $description);
        $this->assertSame('PROXY_REFUSED', $decoded['body']['error_code'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function unprocessablePayloads(): array
    {
        return [
            'not json' => ['not json', 400, 'an unparseable body is refused in-envelope'],
            'oversized' => [
                '{"pad":"' . str_repeat('x', 262144) . '"}',
                413,
                'an unbounded body from an anonymous caller is capped',
            ],
        ];
    }

    /**
     * Given a search term past any real company name; When it is searched;
     * Then nothing is relayed upstream under the merchant's key.
     */
    public function testCompanySearchCapsWhatAnAnonymousCallerCanRelayUpstream(): void
    {
        $decoded = json_decode($this->companyLookup()->search('no', str_repeat('x', 121)), true);

        $this->assertSame('', $this->requestedUrl);
        $this->assertFalse($decoded['ok']);
        $this->assertSame(400, $decoded['status']);
    }

    /**
     * Given a transport failure naming internal infrastructure; When it
     * reaches an anonymous caller; Then only a generic message does.
     */
    public function testATransportFailureIsNotRelayedVerbatimToAnAnonymousCaller(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('get')->willThrowException(
            new \RuntimeException('cURL error 6: Could not resolve host: api.internal.example')
        );

        $decoded = json_decode($this->companyLookup()->search('no', 'acme'), true);

        $this->assertSame(0, $decoded['status']);
        $this->assertStringNotContainsString('api.internal.example', (string)json_encode($decoded));
        $this->assertStringNotContainsString('cURL', (string)json_encode($decoded));
    }

    /**
     * Given each registry call; When it is proxied; Then the merchant it is
     * attributed to is the one the verified key resolves to, not one the
     * browser supplied — the browser no longer sends it at all.
     *
     * @dataProvider registryCalls
     */
    public function testRegistryCallsAreAttributedToTheServerResolvedMerchant(
        string $endpoint,
        string $description
    ): void {
        $this->invoke($endpoint);

        parse_str((string)parse_url($this->requestedUrl, PHP_URL_QUERY), $query);
        $this->assertSame('acme', $query['merchant'] ?? null, $description);
    }

    /**
     * @dataProvider registryCalls
     */
    public function testARegistryCallIsStillMadeWhenTheKeyDoesNotVerify(
        string $endpoint,
        string $description
    ): void {
        $this->invoke($endpoint, ApiKeyStatus::SERVICE_ERROR);

        parse_str((string)parse_url($this->requestedUrl, PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('merchant', $query, $description);
        $this->assertNotSame('', $this->requestedUrl, $description);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function registryCalls(): array
    {
        return [
            'company search' => ['search', 'search is attributed server-side'],
            'company by id' => ['get', 'detail is attributed server-side'],
        ];
    }

    public function testAnUpstreamFailureIsRelayedAsANotOkEnvelopeRatherThanASuccess(): void
    {
        $this->stageUpstream(422, '{"error_code":"SCHEMA_ERROR"}');

        $decoded = json_decode($this->companyLookup()->search('no', 'x'), true);

        $this->assertFalse($decoded['ok']);
        $this->assertSame(422, $decoded['status']);
        $this->assertSame('SCHEMA_ERROR', $decoded['body']['error_code']);
    }

    /**
     * Given each proxied call; When it reaches the API; Then it still reports
     * who is calling — the three params the browser used to put on the query
     * itself, now all resolved server-side.
     *
     * @dataProvider proxiedEndpoints
     */
    public function testEveryProxiedCallStillReportsTheClientAndMerchant(
        string $endpoint,
        string $description
    ): void {
        $this->invoke($endpoint);

        parse_str((string)parse_url($this->requestedUrl, PHP_URL_QUERY), $query);
        $this->assertSame('Magento', $query['client'] ?? null, $description);
        $this->assertSame('2.3.0+abc1234', $query['client_v'] ?? null, $description);
    }

    /**
     * Given a merchant whose record carries no short name; When the intent is
     * proxied; Then the key is absent rather than an explicit null — the
     * browser's optional chaining dropped it, and upstream reads the two apart.
     */
    public function testAnAbsentMerchantShortNameIsOmittedRatherThanSentAsNull(): void
    {
        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('getStatus')->willReturn([
            'status' => ApiKeyStatus::OK,
            'code' => 200,
            'merchant' => ['id' => 'merchant-uuid'],
        ]);

        (new OrderIntent(
            $this->adapter(),
            $apiKeyStatus,
            $this->rateLimiter(),
            $this->logRepository()
        ))->place('{"gross_amount":"10.00"}');

        $sent = json_decode($this->requestedBody, true);
        $this->assertArrayNotHasKey('merchant_short_name', $sent);
        $this->assertStringNotContainsString('merchant_short_name', $this->requestedBody);
    }

    /**
     * Given a cart whose intent body is past the cap; When it is refused; Then
     * the merchant has a log line to diagnose it from, and the buyer is told
     * the size is the problem rather than the payload being invalid.
     */
    public function testAnOversizeIntentIsLoggedAndNamedAsOversizeToTheBuyer(): void
    {
        $decoded = json_decode($this->orderIntent()->place(str_repeat('x', 300000)), true);

        $this->assertSame(413, $decoded['status']);
        $this->assertStringContainsString('too large', $decoded['body']['error_message']);
        $this->assertCount(1, $this->errorLog);
        $this->assertStringContainsString('[order-intent-oversize]', $this->errorLog[0][0]);
        $this->assertStringContainsString('bytes=300000', $this->errorLog[0][0]);
        $this->assertSame('', $this->requestedUrl, 'nothing was sent upstream');
    }

    /**
     * A multi-byte company name is 120 characters, not 120 bytes.
     */
    public function testTheQueryCapCountsCharactersNotBytes(): void
    {
        $decoded = json_decode($this->companyLookup()->search('no', str_repeat('æ', 120)), true);

        $this->assertTrue($decoded['ok'], 'a 120-character term is within the cap');

        $overLong = json_decode($this->companyLookup()->search('no', str_repeat('æ', 121)), true);
        $this->assertSame(400, $overLong['status']);
    }
}
