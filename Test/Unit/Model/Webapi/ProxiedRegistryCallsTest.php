<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Webapi;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\ApiTranslator\NullApiTranslator;
use Two\Gateway\Model\Webapi\CompanyLookup;
use Two\Gateway\Model\Webapi\OrderIntent;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SupportedCountriesProvider;
use Two\Gateway\Service\Order\BuyerCountryResolver;
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
        // Keyed by store so a proxied call proves WHICH store's key it
        // authenticated with, not merely that it sent one.
        $this->configRepository->method('getApiKey')->willReturnCallback(
            static fn(?int $storeId = null) => $storeId === null ? 'merchant-key' : 'store-' . $storeId . '-key'
        );
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

    /**
     * @param array<string,mixed>|null $merchantRecord null for a merchant whose
     *     record carries no buyer-country allowlist.
     */
    private function orderIntent(
        string $status = ApiKeyStatus::OK,
        ?int $storeId = null,
        ?array $merchantRecord = null,
        ?string $buyerCountry = null
    ): OrderIntent {
        // Real provider over a mocked record fetch: the tri-state derivation
        // the gate depends on is the shipped one, not a mock's.
        $recordProvider = $this->createMock(RecordProvider::class);
        $recordProvider->method('getRecord')->willReturn($merchantRecord);

        return new OrderIntent(
            $this->adapter(),
            $this->apiKeyStatus($status),
            $this->rateLimiter(),
            $this->logRepository(),
            $this->checkoutSession($storeId, $buyerCountry),
            new BuyerCountryResolver(),
            new SupportedCountriesProvider($recordProvider)
        );
    }

    private function companyLookup(string $status = ApiKeyStatus::OK, ?int $storeId = null): CompanyLookup
    {
        return new CompanyLookup(
            $this->adapter(),
            $this->apiKeyStatus($status),
            $this->rateLimiter(),
            $this->logRepository(),
            $this->checkoutSession($storeId)
        );
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

    private function checkoutSession(?int $storeId = null, ?string $buyerCountry = null): CheckoutSession
    {
        $session = new CheckoutSession();
        if ($storeId !== null || $buyerCountry !== null) {
            $quote = $this->createMock(Quote::class);
            $quote->method('getStoreId')->willReturn($storeId);
            if ($buyerCountry !== null) {
                $address = $this->createMock(Address::class);
                $address->method('getCountryId')->willReturn($buyerCountry);
                $quote->method('getBillingAddress')->willReturn($address);
            }
            $session->setData('quote', $quote);
        }

        return $session;
    }

    /** @var array<int,array{0: string, 1: mixed}> */
    private $errorLog = [];

    /** @var array<int,array{0: string, 1: mixed}> */
    private $log = [];

    private function logRepository(): LogRepository
    {
        $log = $this->createMock(LogRepository::class);
        $log->method('addErrorLog')->willReturnCallback(
            function ($type, $data) {
                $this->errorLog[] = [$type, $data];
                return null;
            }
        );
        $log->method('addLog')->willReturnCallback(
            function ($type, $data) {
                $this->log[] = [$type, $data];
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
     * Given a merchant record and a quote country; When an intent is placed;
     * Then it reaches upstream only while the allowlist admits that country.
     *
     * @param array<string,mixed>|null $record
     * @dataProvider intentCountryCases
     */
    public function testOrderIntentIsRefusedUnlessTheAllowlistAdmitsTheQuoteCountry(
        ?array $record,
        ?string $buyerCountry,
        bool $sent,
        string $description
    ): void {
        $decoded = json_decode(
            $this->orderIntent(ApiKeyStatus::OK, 1, $record, $buyerCountry)
                ->place('{"gross_amount":"10.00"}'),
            true
        );

        if ($sent) {
            $this->assertNotSame('', $this->requestedBody, $description);
            $this->assertTrue($decoded['ok'], $description);
            $this->assertSame([], $this->log, $description);
            return;
        }

        $this->assertSame('', $this->requestedBody, $description);
        $this->assertFalse($decoded['ok'], $description);
        $this->assertSame(403, $decoded['status'], $description);
    }

    /**
     * @return array<string, array{0: array<string,mixed>|null, 1: ?string, 2: bool, 3: string}>
     */
    public static function intentCountryCases(): array
    {
        return [
            'field absent' =>
                [['min_order_amount' => 100], 'DE', true, 'an old API restricts nothing'],
            'no record at all' =>
                [null, 'DE', true, 'a failed merchant fetch must not refuse the intent'],
            'field null' =>
                [['supported_buyer_countries' => null], 'GB', false, 'a present null admits no country'],
            'field empty' =>
                [['supported_buyer_countries' => []], 'GB', false, 'a present empty list admits no country'],
            'field malformed' =>
                [['supported_buyer_countries' => 'GB'], 'GB', false, 'a malformed value is refused, not parsed'],
            'listed country' =>
                [['supported_buyer_countries' => ['GB']], 'GB', true, 'a listed country is admitted'],
            'unlisted country' =>
                [['supported_buyer_countries' => ['GB']], 'DE', false, 'an unlisted country is refused'],
            'restricted, no country on the quote' =>
                [['supported_buyer_countries' => ['GB']], null, false,
                    'a restricted merchant withholds rather than guess'],
            'unrestricted, no country on the quote' =>
                [['min_order_amount' => 100], null, true,
                    'an unjudgeable quote is only refused when the merchant restricts'],
        ];
    }

    public function testAnIntentRefusedOnCountryIsLoggedWithTheStateThatCausedIt(): void
    {
        $this->orderIntent(ApiKeyStatus::OK, 1, ['supported_buyer_countries' => []], 'GB')
            ->place('{"gross_amount":"10.00"}');

        $this->assertCount(1, $this->log);
        $this->assertStringContainsString('buyer country not supported', $this->log[0][0]);
        $this->assertSame(
            [
                'merchant_id' => 'merchant-uuid',
                'country' => 'GB',
                'restriction' => SupportedCountriesProvider::STATE_EMPTY,
            ],
            $this->log[0][1]
        );
    }

    /**
     * The buyer names their own country in the payload; the gate must read the
     * server's quote instead.
     */
    public function testTheGateIgnoresTheCountryThePayloadClaims(): void
    {
        $decoded = json_decode(
            $this->orderIntent(ApiKeyStatus::OK, 1, ['supported_buyer_countries' => ['GB']], 'DE')
                ->place((string)json_encode([
                    'gross_amount' => '10.00',
                    'buyer' => ['company' => ['country_prefix' => 'GB']],
                ])),
            true
        );

        $this->assertSame('', $this->requestedBody);
        $this->assertSame(403, $decoded['status']);
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
            'json list' => ['[1,2]', 400, 'a list is not an order intent, however well-formed'],
            'no fields' => ['{}', 400, 'a body with no fields is not an order intent'],
            'oversized' => [
                '{"pad":"' . str_repeat('x', 262144) . '"}',
                413,
                'an unbounded body from an anonymous caller is capped',
            ],
        ];
    }

    /**
     * Given a search the registry could not act on; When it is submitted; Then
     * it is refused here rather than relayed upstream under the merchant's key.
     *
     * @dataProvider unusableSearchInputs
     */
    public function testCompanySearchRefusesAnInputTheRegistryCannotActOn(
        string $country,
        string $query,
        string $description
    ): void {
        $decoded = json_decode($this->companyLookup()->search($country, $query), true);

        $this->assertSame('', $this->requestedUrl, $description);
        $this->assertSame(400, $decoded['status'], $description);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unusableSearchInputs(): array
    {
        return [
            'no country' => ['', 'acme', 'a missing country is refused'],
            'alpha-3 country' => ['nor', 'acme', 'the registry endpoints take ISO-2 only'],
            'single letter country' => ['n', 'acme', 'a truncated code is refused'],
            'non-alpha country' => ['N1', 'acme', 'a code with a digit is not a country'],
            'no query' => ['no', '', 'an empty term searches for nothing'],
            'whitespace query' => ['no', "  \t ", 'a whitespace term searches for nothing'],
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

    /**
     * Given an upstream failure body; When it would reach an anonymous caller;
     * Then the merchant's log keeps the real body and the caller gets only the
     * generic refusal — the status still carries the pass/fail verdict.
     *
     * @dataProvider upstreamFailures
     */
    public function testAnUpstreamFailureBodyIsLoggedRatherThanRelayedToAnAnonymousCaller(
        int $status,
        string $body,
        string $internalDetail,
        string $description
    ): void {
        $this->stageUpstream($status, $body);

        $answer = $this->companyLookup()->search('no', 'x');
        $decoded = json_decode($answer, true);

        $this->assertFalse($decoded['ok'], $description);
        $this->assertSame($status, $decoded['status'], $description);
        $this->assertSame('PROXY_REFUSED', $decoded['body']['error_code'], $description);
        $this->assertStringNotContainsString($internalDetail, $answer, $description);
        $this->assertCount(1, $this->errorLog, $description);
        $this->assertStringContainsString('[upstream-failure]', $this->errorLog[0][0], $description);
        $this->assertStringContainsString(
            $internalDetail,
            (string)json_encode($this->errorLog[0][1]),
            $description
        );
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: string}>
     */
    public static function upstreamFailures(): array
    {
        return [
            'server error' => [
                500,
                '{"error_message":"stack trace in two-internal-app.py"}',
                'two-internal-app.py',
                'an upstream outage body is not relayed',
            ],
            'bad gateway' => [
                502,
                '{"error_code":"SCHEMA_ERROR","error_message":"upstream pg.internal.example down"}',
                'pg.internal.example',
                'a 5xx is generic even when its body is shaped like a buyer-actionable one',
            ],
            'no allowlisted field' => [
                400,
                '{"detail":"merchant acme-internal-7f3a rejected"}',
                'acme-internal-7f3a',
                'a 4xx carrying nothing a buyer can act on falls back to the generic refusal',
            ],
        ];
    }

    /**
     * Given a 4xx the buyer can act on; When it is proxied; Then the fields the
     * tile renders reach it and every other key of the body is dropped.
     *
     * @dataProvider buyerActionable4xx
     *
     * @param array<string,mixed> $expected
     */
    public function testABuyerActionable4xxRelaysOnlyTheAllowlistedFields(
        int $status,
        string $body,
        array $expected,
        string $description
    ): void {
        $this->stageUpstream($status, $body);

        $answer = $this->orderIntent()->place('{"gross_amount":"10.00"}');
        $decoded = json_decode($answer, true);

        $this->assertFalse($decoded['ok'], $description);
        $this->assertSame($status, $decoded['status'], $description);
        $this->assertSame($expected, $decoded['body'], $description);
        $this->assertCount(1, $this->errorLog, $description);
        $this->assertStringContainsString('[upstream-failure]', $this->errorLog[0][0], $description);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: array<string,mixed>, 3: string}>
     */
    public static function buyerActionable4xx(): array
    {
        return [
            'schema error' => [
                422,
                '{"error_code":"SCHEMA_ERROR","error_json":[{"msg":"org number is invalid"}]}',
                ['error_code' => 'SCHEMA_ERROR', 'error_json' => [['msg' => 'org number is invalid']]],
                'the per-field errors the tile renders survive the proxy',
            ],
            'missing field' => [
                400,
                '{"error_code":"JSON_MISSING_FIELD","error_details":"buyer.company.organization_number"}',
                ['error_code' => 'JSON_MISSING_FIELD', 'error_details' => 'buyer.company.organization_number'],
                'the field name the buyer must fill survives the proxy',
            ],
            'order invalid' => [
                400,
                '{"error_code":"ORDER_INVALID","error_message":"Order rejected","error_details":"amount too low"}',
                [
                    'error_code' => 'ORDER_INVALID',
                    'error_message' => 'Order rejected',
                    'error_details' => 'amount too low',
                ],
                'both halves of the composed message survive the proxy',
            ],
            'extra keys scrubbed' => [
                404,
                '{"error_code":"MERCHANT_NOT_FOUND_ERROR","error_message":"Unknown merchant",'
                    . '"http_status":404,"trace_id":"two-internal-app-7f3a","upstream_host":"pg.internal.example"}',
                ['error_code' => 'MERCHANT_NOT_FOUND_ERROR', 'error_message' => 'Unknown merchant'],
                'internal detail alongside an actionable code is dropped',
            ],
        ];
    }

    /**
     * Given no HTTP exchange completed at all; When the envelope is built; Then
     * the caller gets the generic refusal, not the transport detail.
     */
    public function testATransportFailureIsGenericRatherThanTreatedAsA4xx(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('post')->willThrowException(
            new \RuntimeException('cURL error 6: Could not resolve host: api.internal.example')
        );

        $decoded = json_decode($this->orderIntent()->place('{"gross_amount":"10.00"}'), true);

        $this->assertSame(0, $decoded['status']);
        $this->assertSame('PROXY_REFUSED', $decoded['body']['error_code']);
        $this->assertArrayNotHasKey('error_details', $decoded['body']);
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
            $this->logRepository(),
            $this->checkoutSession(),
            new BuyerCountryResolver(),
            new SupportedCountriesProvider($this->createMock(RecordProvider::class))
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

    /**
     * Given a buyer shopping in a non-default store; When a call is proxied;
     * Then it authenticates with that store's key, not the default scope's.
     *
     * @dataProvider proxiedEndpoints
     */
    public function testAProxiedCallAuthenticatesWithTheStoreTheBuyerIsShoppingIn(
        string $endpoint,
        string $description
    ): void {
        $this->invokeInStore($endpoint, 7);

        $this->assertSame('store-7-key', $this->headers['X-API-Key'] ?? null, $description);
    }

    /**
     * Given a call with no loadable quote; When it is proxied; Then it falls
     * back to the default scope rather than failing.
     *
     * @dataProvider proxiedEndpoints
     */
    public function testAStorelessCallFallsBackToTheDefaultScope(
        string $endpoint,
        string $description
    ): void {
        $this->invoke($endpoint);

        $this->assertSame('merchant-key', $this->headers['X-API-Key'] ?? null, $description);
    }

    private function invokeInStore(string $endpoint, int $storeId): string
    {
        if ($endpoint === 'search') {
            return $this->companyLookup(ApiKeyStatus::OK, $storeId)->search('no', 'acme');
        }
        if ($endpoint === 'get') {
            return $this->companyLookup(ApiKeyStatus::OK, $storeId)->get('lookup-1');
        }

        return $this->orderIntent(ApiKeyStatus::OK, $storeId)->place('{"gross_amount":"10.00"}');
    }
}
