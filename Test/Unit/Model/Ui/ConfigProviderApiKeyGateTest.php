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
use Two\Gateway\Model\Ui\ConfigProvider;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\Merchant\ApiKeyStatus;

/**
 * The checkout-config subtree is the gate the company-search control sits
 * behind.
 *
 * `js/model/brand-config.js::getActiveTwoBrandCode()` finds the active
 * Two-family brand by scanning `window.checkoutConfig.payment` for a
 * subtree carrying a truthy `redirectUrlCookieCode`, and the address
 * block's company-search widget mounts only when that resolves. So
 * withholding the subtree on a verification failure is what stops company
 * search rendering on a broken integration — the same job the sibling
 * plugins do by withholding their client-side bootstrap object.
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
        $brandRegistry->method('getCheckoutSubtitle')->willReturn('');

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

    private function statusService(string $status, ?int $code = null, ?array $merchant = null): ApiKeyStatus
    {
        $service = $this->createMock(ApiKeyStatus::class);
        $service->method('getStatus')->willReturn(
            ['status' => $status, 'code' => $code, 'merchant' => $merchant]
        );
        return $service;
    }

    /**
     * @dataProvider failureCategories
     */
    public function testNoConfigSubtreeIsEmittedOnAnyVerificationFailure(string $status, ?int $code): void
    {
        $config = $this->build($this->statusService($status, $code))->getConfig();

        $this->assertSame([], $config);
    }

    /**
     * The gate as its JS consumer actually reads it: no subtree anywhere in
     * `payment` carrying a truthy `redirectUrlCookieCode` means
     * getActiveTwoBrandCode() resolves to null and company search never
     * mounts.
     *
     * @dataProvider failureCategories
     */
    public function testTheCompanySearchSentinelIsAbsentOnAnyVerificationFailure(
        string $status,
        ?int $code
    ): void {
        $config = $this->build($this->statusService($status, $code))->getConfig();

        $this->assertNull(
            self::resolveActiveTwoBrandCode($config),
            'a resolvable brand code would let company search mount on a broken integration'
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
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function failureCategories(): array
    {
        return [
            'rejected key' => [ApiKeyStatus::INVALID_KEY, 401],
            'service error' => [ApiKeyStatus::SERVICE_ERROR, 503],
            'unreachable' => [ApiKeyStatus::UNREACHABLE, null],
            'other error' => [ApiKeyStatus::ERROR, 404],
            'malformed response' => [ApiKeyStatus::MALFORMED_RESPONSE, null],
            'not configured' => [ApiKeyStatus::NOT_CONFIGURED, null],
        ];
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
