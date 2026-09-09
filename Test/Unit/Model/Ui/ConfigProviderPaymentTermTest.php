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
/**
 * The published term seam: `defaultPaymentTerm` / `selectedPaymentTerm` are
 * what the renderer preselects a chip from, so a day count published here is
 * offered to the buyer even when the offered set is empty (ABN-544).
 */
class ConfigProviderPaymentTermTest extends TestCase
{
    private function build(ApiKeyStatus $apiKeyStatus, ?int $defaultTerm): ConfigProvider
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
        $configRepository->method('getDefaultPaymentTerm')->willReturn($defaultTerm);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');
        $brandRegistry->method('getProviderFullName')->willReturn('Acme Pay Ltd');
        $brandRegistry->method('getAboutUrl')->willReturn('');

        // The real settings provider over a mocked record fetch, so the
        // identity fall-back is the shipped derivation.
        $recordProvider = $this->createMock(RecordProvider::class);
        $recordProvider->method('getRecord')->willReturn(null);

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
     * @dataProvider publishedTerms
     */
    public function testThePublishedTermSeam(
        ?int $defaultTerm,
        int $sessionTerm,
        int $expectedDefault,
        int $expectedSelected,
        string $case
    ): void {
        $provider = $this->build($this->statusService(ApiKeyStatus::OK, 200, []), $defaultTerm);
        $subtree = $this->publish($provider, $sessionTerm);

        $this->assertSame($expectedDefault, $subtree['defaultPaymentTerm'], $case);
        $this->assertSame($expectedSelected, $subtree['selectedPaymentTerm'], $case);
    }

    /**
     * @return array<int,array{0:int|null,1:int,2:int,3:int,4:string}>
     */
    public static function publishedTerms(): array
    {
        return [
            [30, 0, 30, 30, 'an offered default is published as its day count'],
            [30, 45, 30, 45, 'a session selection wins for the selected term'],
            [null, 0, 0, 0, 'no offered term publishes no day count'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function publish(ConfigProvider $provider, int $sessionTerm): array
    {
        $session = (new \ReflectionClass(ConfigProvider::class))->getProperty('checkoutSession')
            ->getValue($provider);
        $session->setTwoSelectedTerm($sessionTerm);

        return $provider->getConfig()['payment']['two_payment'];
    }
}
