<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Ui;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Repository as ConfigRepositoryImpl;
use Two\Gateway\Model\Two;
use Two\Gateway\Model\Ui\AnchorOnlyHtmlEscaper;
use Two\Gateway\Model\Ui\CheckoutTileCopy;
use Two\Gateway\Model\Ui\ConfigProvider;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;
use Two\Gateway\Service\Merchant\SettingsProvider;

/**
 * The about control is one control: when it is withheld, no key of its
 * subtree ships a value to the storefront renderer.
 */
class ConfigProviderAboutControlTest extends TestCase
{
    private const ICON_URL = 'https://shop.example/static/Two_Gateway/images/question.svg';

    /**
     * @return array<string, array{0:bool,1:string,2:string}>
     */
    public static function aboutControlRows(): array
    {
        return [
            'control visible' => [
                true, self::ICON_URL,
                'a rendering control is fed the icon asset',
            ],
            'control withheld' => [
                false, '',
                'a withheld control ships no icon asset either',
            ],
        ];
    }

    /**
     * @dataProvider aboutControlRows
     */
    public function testEveryAboutKeyFollowsTheControlsVisibility(
        bool $visible,
        string $expectedIconUrl,
        string $description
    ): void {
        $config = $this->build($visible)->getConfig()['payment']['two_payment'];

        $this->assertSame($visible, $config['showAboutLink'], $description);
        $this->assertSame($expectedIconUrl, $config['aboutIconUrl'], $description);
    }

    private function build(bool $aboutLinkVisible): ConfigProvider
    {
        $reflection = new \ReflectionClass(ConfigProvider::class);
        $provider = $reflection->newInstanceWithoutConstructor();

        $configRepository = $this->createMock(ConfigRepositoryImpl::class);
        $configRepository->method('getApiKey')->willReturn('test-api-key');
        $configRepository->method('getBrand')->willReturn('');
        $configRepository->method('getBrandVersion')->willReturn('');
        $configRepository->method('getCheckoutPageUrl')->willReturn('https://checkout.example');
        $configRepository->method('getCustomHeaders')->willReturn([]);
        $configRepository->method('getBrowserCustomHeaders')->willReturn([]);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');
        $brandRegistry->method('getProviderFullName')->willReturn('Acme Pay Ltd');
        $brandRegistry->method('getAboutUrl')->willReturn('');

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

        $assetRepository = $this->createMock(AssetRepository::class);
        $assetRepository->method('getUrl')->willReturn(self::ICON_URL);

        $checkoutTileCopy = $this->createMock(CheckoutTileCopy::class);
        $checkoutTileCopy->method('isAboutLinkVisible')->willReturn($aboutLinkVisible);

        $properties = [
            'code' => 'two_payment',
            'configRepository' => $configRepository,
            'brandRegistry' => $brandRegistry,
            'apiKeyStatus' => $apiKeyStatus,
            'settingsProvider' => new SettingsProvider($this->createMock(RecordProvider::class)),
            'two' => $two,
            'assetRepository' => $assetRepository,
            'checkoutSession' => $checkoutSession,
            'storeManager' => $this->storeManager(),
            'supportedCompanyTypes' => $this->createMock(SupportedCompanyTypes::class),
            'checkoutTileCopy' => $checkoutTileCopy,
            'htmlEscaper' => new AnchorOnlyHtmlEscaper(),
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
