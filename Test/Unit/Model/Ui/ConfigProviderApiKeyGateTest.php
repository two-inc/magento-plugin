<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Ui;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Config\Repository as ConfigRepositoryImpl;
use Two\Gateway\Model\Two;
use Two\Gateway\Model\Ui\CheckoutTileCopy;
use Two\Gateway\Model\Ui\ConfigProvider;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * The checkout-config subtree is the gate the company-search control AND the
 * payment renderer sit behind.
 *
 * `js/model/brand-config.js::getActiveTwoBrandCode()` finds the active
 * Two-family brand by scanning `window.checkoutConfig.payment` for a
 * subtree carrying a truthy `redirectUrlCookieCode`, and both mount only
 * when that resolves. It must therefore withhold on exactly the verdicts
 * Two::isAvailable() withholds on (ABN-533) — a rejected key and no key —
 * or an outage leaves the method offered with no config to render it.
 */
class ConfigProviderApiKeyGateTest extends TestCase
{
    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $logRepository;

    /**
     * @param array<string,mixed>|null $merchantRecord what the never-expiring record holds
     */
    private function build(ApiKeyStatus $apiKeyStatus, ?array $merchantRecord = null): ConfigProvider
    {
        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        // The concrete repository, not the interface: getBrand() and
        // getBrandVersion() are declared on the implementation only, and
        // getConfig() reaches both through buildBrandQueryString().
        $configRepository = $this->createMock(ConfigRepositoryImpl::class);
        $configRepository->method('getApiKey')->willReturn('test-api-key');
        $configRepository->method('getBrand')->willReturn('');
        $configRepository->method('getBrandVersion')->willReturn('');
        $configRepository->method('getCheckoutPageUrl')->willReturn('https://checkout.example');

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');
        $brandRegistry->method('getProviderFullName')->willReturn('Acme Pay Ltd');
        $brandRegistry->method('getAboutUrl')->willReturn('');

        // The real settings provider over a mocked record fetch, so the
        // identity fall-back is the shipped derivation.
        $recordProvider = $this->createMock(RecordProvider::class);
        $recordProvider->method('getRecord')->willReturn($merchantRecord);

        $two = $this->createMock(Two::class);
        $two->method('getMinimumOrderVisibility')->willReturn(['minimums' => [], 'unresolved' => false]);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getBillingAddress')
            ->willReturn($this->createMock(\Magento\Quote\Model\Quote\Address::class));
        // The checkout session is a magic data bag in the test harness, so its
        // accessors are populated rather than mocked.
        $checkoutSession = new CheckoutSession();
        $checkoutSession->setQuote($quote);

        $properties = [
            'code' => 'two_payment',
            'configRepository' => $configRepository,
            'brandRegistry' => $brandRegistry,
            'apiKeyStatus' => $apiKeyStatus,
            'settingsProvider' => new SettingsProvider($recordProvider),
            'two' => $two,
            'assetRepository' => $this->createMock(AssetRepository::class),
            'checkoutSession' => $checkoutSession,
            'storeManager' => $this->storeManager(),
            'supportedCompanyTypes' => $this->createMock(SupportedCompanyTypes::class),
            'checkoutTileCopy' => $this->createMock(CheckoutTileCopy::class),
            'logRepository' => $this->logRepository ?? $this->createMock(LogRepository::class),
        ];
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($provider, $value);
        }

        return $provider;
    }

    /**
     * @return StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private function storeManager()
    {
        $currency = $this->createMock(\Magento\Directory\Model\Currency::class);
        $currency->method('getCurrencySymbol')->willReturn('kr');

        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->method('getCurrentCurrency')->willReturn($currency);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    /**
     * The real ApiKeyStatus over a stubbed verdict — only getStatus() is
     * overridden — so the gate runs the production predicate and cannot pass
     * by re-stating the rule in the test.
     */
    private function statusService(string $status, ?int $code = null, ?array $merchant = null): ApiKeyStatus
    {
        return new class (['status' => $status, 'code' => $code, 'merchant' => $merchant]) extends ApiKeyStatus {
            /** @var array{status: string, code: int|null, merchant: array<string,mixed>|null} */
            private $verdict;

            /** @param array{status: string, code: int|null, merchant: array<string,mixed>|null} $verdict */
            public function __construct(array $verdict)
            {
                $this->verdict = $verdict;
            }

            public function getStatus(?int $storeId = null, ?string $scope = null): array
            {
                return $this->verdict;
            }
        };
    }

    /**
     * Asserted through a PHP mirror of the JS consumer, so this fails if the
     * emitted shape stops matching what getActiveTwoBrandCode() looks for,
     * not merely if a key is renamed.
     *
     * @dataProvider verdictCategories
     */
    public function testTheSubtreeIsWithheldOnlyOnADefinitiveRejection(
        string $status,
        ?int $code,
        bool $emitted,
        string $description
    ): void {
        $merchant = $status === ApiKeyStatus::OK ? ['id' => 'abc-123'] : null;
        $config = $this->build($this->statusService($status, $code, $merchant))->getConfig();

        $this->assertSame($emitted, $config !== [], $description);
        $this->assertSame(
            $emitted ? 'two_payment' : null,
            self::resolveActiveTwoBrandCode($config),
            $description
        );
    }

    /**
     * PHP mirror of `js/model/brand-config.js::getActiveTwoBrandCode()` — the
     * gate the company-search widget and the payment renderer both mount
     * behind. Asserting through it means these tests fail if the emitted
     * shape stops matching what that function looks for, not merely if a
     * particular key changes name.
     *
     * @param array<string,mixed> $checkoutConfig
     */
    private static function resolveActiveTwoBrandCode(array $checkoutConfig): ?string
    {
        foreach (($checkoutConfig['payment'] ?? []) as $code => $subtree) {
            if (is_array($subtree) && !empty($subtree['redirectUrlCookieCode'])) {
                return (string)$code;
            }
        }
        return null;
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: bool, 3: string}>
     */
    public static function verdictCategories(): array
    {
        return [
            'ok' => [ApiKeyStatus::OK, 200, true,
                'a verifying key emits the subtree'],
            'rejected key' => [ApiKeyStatus::INVALID_KEY, 401, false,
                'Two rejected the key, so nothing can mount'],
            'not configured' => [ApiKeyStatus::NOT_CONFIGURED, null, false,
                'there is no key to mount against'],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, 503, true,
                'the method stays offered, so its renderer needs its config'],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, null, true,
                'an outage must not leave the method offered with no config'],
            'other error' => [ApiKeyStatus::ERROR, 404, true,
                'a non-2xx that is not a 401/403 is not a rejection'],
            'malformed response' => [ApiKeyStatus::MALFORMED_RESPONSE, null, true,
                'an unreadable answer is about the service, not the key'],
        ];
    }

    /**
     * The verdict carries no merchant on a fall-through, so the identity the
     * browser is handed comes from the never-expiring record instead. With
     * neither it is null, and the api-client params omit the short name
     * rather than sending "undefined".
     *
     * @dataProvider fallThroughIdentitySources
     * @param array<string,mixed>|null $record
     * @param array<string,string|null>|null $expected
     */
    public function testAFallThroughRelaysTheRecordsIdentity(
        ?array $record,
        ?array $expected,
        string $description
    ): void {
        $config = $this->build($this->statusService(ApiKeyStatus::UNREACHABLE), $record)->getConfig();

        $this->assertSame('two_payment', self::resolveActiveTwoBrandCode($config), $description);
        $this->assertSame(
            $expected,
            $config['payment']['two_payment']['orderIntentConfig']['merchant'],
            $description
        );
    }

    /** With both sources resolvable the verdict wins, and only on a success has it one. */
    public function testTheVerdictsOwnMerchantWinsOverTheRecord(): void
    {
        $record = ['id' => 'from-record', 'short_name' => 'record-name'];

        $verified = $this->build(
            $this->statusService(ApiKeyStatus::OK, 200, ['id' => 'from-verdict', 'short_name' => 'verdict-name']),
            $record
        )->getConfig();
        $this->assertSame(
            ['id' => 'from-verdict', 'short_name' => 'verdict-name'],
            $verified['payment']['two_payment']['orderIntentConfig']['merchant']
        );

        $fallThrough = $this->build($this->statusService(ApiKeyStatus::UNREACHABLE), $record)->getConfig();
        $this->assertSame(
            ['id' => 'from-record', 'short_name' => 'record-name'],
            $fallThrough['payment']['two_payment']['orderIntentConfig']['merchant']
        );
    }

    /**
     * @return array<string, array{0: array<string,mixed>|null, 1: array<string,string|null>|null, 2: string}>
     */
    public static function fallThroughIdentitySources(): array
    {
        return [
            'record resolved' => [
                ['id' => 'abc-123', 'short_name' => 'example'],
                ['id' => 'abc-123', 'short_name' => 'example'],
                'last-known-good identity reaches the browser through an outage',
            ],
            'record has no short name' => [
                ['id' => 'abc-123'],
                ['id' => 'abc-123', 'short_name' => null],
                'an absent short name is null, not the string "undefined"',
            ],
            'nothing ever resolved' => [
                null,
                null,
                'a shop with no record has no identity to relay',
            ],
        ];
    }

    /**
     * ABN-518: the category and HTTP status, never a response body.
     *
     * @dataProvider failureCategories
     */
    public function testEveryVerificationFailureIsLogged(string $status, ?int $code): void
    {
        $this->logRepository = $this->createMock(LogRepository::class);
        $this->logRepository->expects($this->once())->method('addDebugLog')
            ->with(
                'two_payment withheld from checkout: API key verification failed',
                ['status' => $status, 'http_status' => $code]
            );

        $this->build($this->statusService($status, $code))->getConfig();
    }

    public function testNothingIsLoggedWhenTheKeyVerifies(): void
    {
        $this->logRepository = $this->createMock(LogRepository::class);
        $this->logRepository->expects($this->never())->method('addDebugLog');

        $this->build($this->statusService(ApiKeyStatus::OK, 200, ['id' => 'abc-123']))->getConfig();
    }

    public function testTheSubtreeAndItsSentinelArePresentOnSuccess(): void
    {
        $merchant = ['id' => 'abc-123', 'short_name' => 'acme'];
        $config = $this->build($this->statusService(ApiKeyStatus::OK, 200, $merchant))->getConfig();

        $this->assertArrayHasKey('payment', $config);
        $this->assertArrayHasKey('two_payment', $config['payment']);
        $this->assertNotEmpty($config['payment']['two_payment']['redirectUrlCookieCode']);
        // And through the gate as its JS consumer reads it.
        $this->assertSame('two_payment', self::resolveActiveTwoBrandCode($config));
    }

    /**
     * The verdict's `merchant` is the whole verify_api_key body. Only the
     * identity reaches the page — the merchant's commercial fields are not the
     * browser's business, and one shape for this key means the fall-through and
     * the success case cannot be told apart by a consumer.
     */
    public function testOnlyTheMerchantIdentityReachesThePage(): void
    {
        $verdictBody = [
            'id' => 'abc-123',
            'short_name' => 'acme',
            'available_terms' => [14, 30],
            'min_order_amount' => ['amount' => '250.00', 'currency' => 'EUR'],
            'invoice_distributed_by_merchant' => true,
        ];

        $config = $this->build($this->statusService(ApiKeyStatus::OK, 200, $verdictBody))->getConfig();

        $this->assertSame(
            ['id' => 'abc-123', 'short_name' => 'acme'],
            $config['payment']['two_payment']['orderIntentConfig']['merchant']
        );
        $this->assertStringNotContainsString(
            'min_order_amount',
            (string)json_encode($config),
            'the merchant record\'s commercial fields must not reach the page'
        );
    }

    /**
     * The verdict is served from a cache whose only structural guarantee is a
     * `status` key, so an unreadable entry must degrade rather than fatal out
     * of a checkout render.
     *
     * @dataProvider unreadableVerdictMerchants
     * @param mixed $merchant
     */
    public function testAnUnreadableVerdictMerchantDegradesToTheRecord($merchant, string $description): void
    {
        $service = $this->createMock(ApiKeyStatus::class);
        $service->method('getStatus')->willReturn(
            ['status' => ApiKeyStatus::OK, 'code' => 200, 'merchant' => $merchant]
        );
        $service->method('isDefinitiveFailure')->willReturn(false);

        $config = $this->build($service, ['id' => 'from-record'])->getConfig();

        $this->assertSame(
            ['id' => 'from-record', 'short_name' => null],
            $config['payment']['two_payment']['orderIntentConfig']['merchant'],
            $description
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function unreadableVerdictMerchants(): array
    {
        return [
            'a string' => ['not-an-array', 'a scalar cache entry must not fatal'],
            'a bool' => [false, 'nor a bool'],
            'an int' => [0, 'nor an int'],
            'an array with no id' => [['short_name' => 'acme'], 'an array naming no merchant resolves nothing'],
        ];
    }

    public function testTheCachedVerificationSuppliesTheMerchantRecord(): void
    {
        // The verify call used to be made inline here on every checkout
        // render. It now comes from the shared cached status, so the merchant
        // payload the renderer needs is unchanged while the round-trip is not
        // repeated per render.
        $merchant = ['id' => 'abc-123', 'short_name' => 'acme'];
        $apiKeyStatus = $this->statusService(ApiKeyStatus::OK, 200, $merchant);

        $config = $this->build($apiKeyStatus)->getConfig();

        $this->assertSame(
            $merchant,
            $config['payment']['two_payment']['orderIntentConfig']['merchant']
        );
    }
}
