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
 * The checkout config subtree is published to every buyer on every checkout
 * render, so the browser toggle is the only thing standing between a
 * configured firewall token and public disclosure.
 */
class ConfigProviderFirewallTokenExposureTest extends TestCase
{
    private const TOKEN = 'waf-token-value';

    /**
     * Given a configured token; When the browser toggle decides; Then the
     * token reaches the page only with the toggle on.
     *
     * @dataProvider browserToggle
     */
    public function testTheBrowserToggleDecidesWhetherTheTokenIsPublished(
        bool $sentFromBrowser,
        string $expected,
        string $description
    ): void {
        $config = $this->build($sentFromBrowser)->getConfig();

        $this->assertSame($expected, $config['payment']['two_payment']['firewallToken'], $description);
    }

    /**
     * @return array<string, array{0: bool, 1: string, 2: string}>
     */
    public static function browserToggle(): array
    {
        return [
            'browser calls enabled' => [true, self::TOKEN, 'the one browser-direct call needs the header'],
            'default off' => [false, '', 'the token stays server-side'],
        ];
    }

    /**
     * Not just the one key: nothing else in the published subtree may carry
     * the token either.
     */
    public function testTheTokenAppearsNowhereInThePublishedSubtreeWhenTheToggleIsOff(): void
    {
        $config = $this->build(false)->getConfig();

        $this->assertStringNotContainsString(self::TOKEN, (string)json_encode($config));
    }

    private function build(bool $sentFromBrowser): ConfigProvider
    {
        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        $configRepository = $this->createMock(ConfigRepositoryImpl::class);
        $configRepository->method('getApiKey')->willReturn('test-api-key');
        $configRepository->method('getBrand')->willReturn('');
        $configRepository->method('getBrandVersion')->willReturn('');
        $configRepository->method('getCheckoutPageUrl')->willReturn('https://checkout.example');
        $configRepository->method('getFirewallToken')->willReturn(self::TOKEN);
        $configRepository->method('isFirewallTokenSentFromBrowser')->willReturn($sentFromBrowser);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');
        $brandRegistry->method('getProviderFullName')->willReturn('Acme Pay Ltd');
        $brandRegistry->method('getCheckoutSubtitle')->willReturn('');

        $two = $this->createMock(Two::class);
        $two->method('getMinimumOrderVisibility')->willReturn(['minimums' => [], 'unresolved' => false]);

        $quote = $this->createMock(\Magento\Quote\Model\Quote::class);
        $quote->method('getBillingAddress')
            ->willReturn($this->createMock(\Magento\Quote\Model\Quote\Address::class));
        $checkoutSession = new CheckoutSession();
        $checkoutSession->setQuote($quote);

        $apiKeyStatus = $this->createMock(ApiKeyStatus::class);
        $apiKeyStatus->method('getStatus')->willReturn([
            'status' => ApiKeyStatus::OK,
            'code' => 200,
            'merchant' => ['id' => 'abc-123', 'short_name' => 'acme'],
        ]);

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
}
