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
 * render, so the per-row browser tick is the only thing standing between a
 * configured header and public disclosure.
 */
class ConfigProviderCustomHeaderExposureTest extends TestCase
{
    private const SERVER_ONLY_VALUE = 'server-only-header-value';

    /**
     * Given headers the merchant ticked for the browser; When the checkout
     * config is built; Then exactly those reach the page.
     */
    public function testOnlyTheTickedHeadersArePublished(): void
    {
        $ticked = ['X-WAF-TOKEN' => 'waf-token-value'];

        $config = $this->build($ticked)->getConfig();

        $this->assertSame($ticked, $config['payment']['two_payment']['customHeaders']);
    }

    public function testNoTickedHeadersPublishesAnEmptyMap(): void
    {
        $config = $this->build([])->getConfig();

        $this->assertSame([], $config['payment']['two_payment']['customHeaders']);
    }

    /**
     * Not just the one key: nothing else in the published subtree may carry a
     * header the merchant kept server-side.
     */
    public function testAnUntickedHeaderAppearsNowhereInThePublishedSubtree(): void
    {
        $config = $this->build([])->getConfig();

        $this->assertStringNotContainsString(self::SERVER_ONLY_VALUE, (string)json_encode($config));
    }

    /**
     * @param array<string, string> $browserHeaders
     */
    private function build(array $browserHeaders): ConfigProvider
    {
        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        $configRepository = $this->createMock(ConfigRepositoryImpl::class);
        $configRepository->method('getApiKey')->willReturn('test-api-key');
        $configRepository->method('getBrand')->willReturn('');
        $configRepository->method('getBrandVersion')->willReturn('');
        $configRepository->method('getCheckoutPageUrl')->willReturn('https://checkout.example');
        $configRepository->method('getCustomHeaders')
            ->willReturn($browserHeaders + ['X-Internal' => self::SERVER_ONLY_VALUE]);
        $configRepository->method('getBrowserCustomHeaders')->willReturn($browserHeaders);

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
