<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Ui;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\Repository as ConfigRepositoryImpl;
use Two\Gateway\Model\Config\Source\PaymentTermsType;
use Two\Gateway\Model\Two;
use Two\Gateway\Model\Ui\AnchorOnlyHtmlEscaper;
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
 * Two::isAvailable() withholds on (a rejected key and no key),
 * or an outage leaves the method offered with no config to render it.
 */
/**
 * The published term seam: `defaultPaymentTerm` / `selectedPaymentTerm` are
 * what the renderer preselects a chip from, so a day count published here is
 * offered to the buyer even when the offered set is empty.
 */
class ConfigProviderPaymentTermTest extends TestCase
{
    protected function tearDown(): void
    {
        Phrase::setRenderer(null);
    }

    private function build(
        ApiKeyStatus $apiKeyStatus,
        ?int $defaultTerm,
        string $termsType = PaymentTermsType::STANDARD,
        string $providerFullName = 'Acme Pay Ltd'
    ): ConfigProvider {
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
        $configRepository->method('getPaymentTermsType')->willReturn($termsType);

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Acme Pay');
        $brandRegistry->method('getProviderFullName')->willReturn($providerFullName);
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
     * The chip text depends on the term type, so the type has to reach the
     * browser: an end-of-month term falls due that many days after the end of
     * the month.
     *
     * @dataProvider publishedTermsTypes
     */
    public function testThePublishedTermsType(string $termsType, bool $expected, string $case): void
    {
        $provider = $this->build($this->statusService(ApiKeyStatus::OK, 200, []), 30, $termsType);

        $this->assertSame($expected, $this->publish($provider, 0)['isEndOfMonthTerms'], $case);
    }

    /**
     * @return array<int,array{0:string,1:bool,2:string}>
     */
    public static function publishedTermsTypes(): array
    {
        return [
            [PaymentTermsType::END_OF_MONTH, true, 'a legacy end-of-month shop publishes the flag'],
            [PaymentTermsType::STANDARD, false, 'a standard shop does not'],
            ['', false, 'an unset row reads as standard'],
            ['END_OF_MONTH', false, 'the comparison is exact, so an upper-case row is not end of month'],
        ];
    }

    /**
     * Given an admin-supplied translation carrying markup; when the consent
     * sentence is published; then only the terms link survives.
     *
     * @dataProvider consentSentenceRows
     */
    public function testTheConsentSentenceCarriesOnlyItsOwnLink(
        string $sentence,
        string $termsText,
        string $providerFullName,
        string $expected,
        string $case
    ): void {
        Phrase::setRenderer(self::rendererTranslating([
            'I accept the %1 and authorize %2 to process my data automatically.' => $sentence,
            'payment terms' => $termsText,
        ]));

        $provider = $this->build($this->statusService(ApiKeyStatus::OK, 200, []), 30, PaymentTermsType::STANDARD, $providerFullName);

        $this->assertSame($expected, $this->publish($provider, 0)['paymentTermsMessage'], $case);
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function consentSentenceRows(): array
    {
        $sentence = 'I accept the %1 and authorize %2 to process my data automatically.';
        $link = '<a href="https://checkout.example/terms" target="_blank" rel="noopener">';

        return [
            'markup in the sentence translation' => [
                $sentence . '<img src=x onerror=alert(1)>',
                'payment terms',
                'Acme Pay Ltd',
                'I accept the ' . $link . 'payment terms</a> and authorize Acme Pay Ltd'
                    . ' to process my data automatically.',
                'a translated sentence cannot add a tag of its own',
            ],
            'markup in the link-text translation' => [
                $sentence,
                '</a><img src=x onerror=alert(1)>',
                'Acme Pay Ltd',
                'I accept the ' . $link . '</a> and authorize Acme Pay Ltd'
                    . ' to process my data automatically.',
                'a translated link text cannot close the anchor and open its own markup',
            ],
            'markup in the provider name' => [
                $sentence,
                'payment terms',
                '<b>Acme</b> & Pay Ltd',
                'I accept the ' . $link . 'payment terms</a> and authorize Acme &amp; Pay Ltd'
                    . ' to process my data automatically.',
                'the provider name is text, and an ampersand in it is entity-encoded rather than refused',
            ],
        ];
    }

    /** @param array<string,string> $translations */
    private static function rendererTranslating(array $translations): object
    {
        return new class ($translations) implements \Magento\Framework\Phrase\RendererInterface {
            /** @param array<string,string> $translations */
            public function __construct(private array $translations)
            {
            }

            public function render(array $source, array $arguments): string
            {
                $text = $this->translations[$source[0]] ?? $source[0];
                foreach ($arguments as $index => $value) {
                    $text = str_replace('%' . ($index + 1), (string)$value, $text);
                }

                return $text;
            }
        };
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
