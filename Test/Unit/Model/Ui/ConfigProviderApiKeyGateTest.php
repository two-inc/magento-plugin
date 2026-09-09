<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Ui;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Repository as ConfigRepositoryImpl;
use Two\Gateway\Model\Two;
use Two\Gateway\Model\Ui\CheckoutTileCopy;
use Two\Gateway\Model\Ui\ConfigProvider;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

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
    private function build(ApiKeyStatus $apiKeyStatus): ConfigProvider
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
            'two' => $two,
            'assetRepository' => $this->createMock(AssetRepository::class),
            'checkoutSession' => $checkoutSession,
            'storeManager' => $this->storeManager(),
            'supportedCompanyTypes' => $this->createMock(SupportedCompanyTypes::class),
            'checkoutTileCopy' => $this->createMock(CheckoutTileCopy::class),
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

            public function getStatus(?int $storeId = null): array
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
        $config = $this->build($this->statusService($status, $code, ['id' => 'abc-123']))->getConfig();

        $this->assertSame($emitted, $config !== [], $description);
        $this->assertSame(
            $emitted ? 'two_payment' : null,
            self::resolveActiveTwoBrandCode($config),
            $description
        );
    }

    /**
     * The withholding half of the pair above, stated as the thing it protects:
     * a rejected key must not leave a brand code for company search to mount
     * behind.
     */
    public function testARejectedKeyLeavesNothingForCompanySearchToMountBehind(): void
    {
        $config = $this->build($this->statusService(ApiKeyStatus::INVALID_KEY, 401))->getConfig();

        $this->assertSame([], $config);
        $this->assertNull(self::resolveActiveTwoBrandCode($config));
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
     * A fall-through carries no merchant record, and the browser's api-client
     * params omit the short name rather than sending "undefined".
     */
    public function testAFallThroughEmitsTheSubtreeWithNoMerchantRecord(): void
    {
        $config = $this->build($this->statusService(ApiKeyStatus::UNREACHABLE))->getConfig();

        $this->assertSame('two_payment', self::resolveActiveTwoBrandCode($config));
        $this->assertNull($config['payment']['two_payment']['orderIntentConfig']['merchant']);
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
