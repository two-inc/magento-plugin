<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\UrlCookie;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Api\SupportedCompanyTypes;
use Two\Gateway\Model\Two;

/**
 * Ui Config Provider.
 *
 * Populates `window.checkoutConfig.payment[<code>]` with the runtime
 * config the gateway_method renderer needs. The `$code` constructor
 * argument decides which subtree of `payment` gets populated, so
 * brand-overlay packages can declare a
 * virtualType of this class with `code='acme_payment'` and a
 * brand-bound BrandRegistryInterface to expose their own subtree
 * without re-implementing the body of getConfig().
 *
 * The Two-branded binding defaults to ConfigRepository::CODE
 * ('two_payment') so existing installs keep their current behaviour
 * without an etc/di.xml change.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /** @var string */
    private $code;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /**
     * @var Two
     */
    private $two;

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var AssetRepository
     */
    private $assetRepository;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var SupportedCompanyTypes
     */
    private $supportedCompanyTypes;

    /**
     * @param string $code Payment-method code (overlay-specific). Defaults
     *                     to the Two-branded value for backward
     *                     compatibility with installs that don't override.
     */
    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        Adapter $adapter,
        Two $two,
        AssetRepository $assetRepository,
        CheckoutSession $checkoutSession,
        StoreManagerInterface $storeManager,
        SupportedCompanyTypes $supportedCompanyTypes,
        ?string $code = null
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->adapter = $adapter;
        $this->two = $two;
        $this->assetRepository = $assetRepository;
        $this->checkoutSession = $checkoutSession;
        $this->storeManager = $storeManager;
        $this->supportedCompanyTypes = $supportedCompanyTypes;
        $this->code = $code ?? $brandRegistry->getCode();
    }

    /**
     * Registry answer for the quote's current billing country, keyed by
     * lowercased ISO code — the renderer's warm-start memo entry. Empty
     * when the quote has no billing country yet; fail-soft (the service
     * resolves registry errors to an empty type list, which the renderer
     * treats as business-only checkout).
     *
     * @return array<string,string[]>
     */
    private function getSupportedCompanyTypesSeed(): array
    {
        $country = (string)$this->checkoutSession->getQuote()->getBillingAddress()->getCountryId();
        if ($country === '') {
            return [];
        }
        return [strtolower($country) => $this->supportedCompanyTypes->getForCountry($country)];
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        $merchant = null;
        if ($this->configRepository->getApiKey()) {
            $merchant = $this->adapter->execute('/v1/merchant/verify_api_key', [], 'GET');
        }
        $orderIntentConfig = [
            'extensionPlatformName' => $this->configRepository->getExtensionPlatformName(),
            'extensionDBVersion' => $this->configRepository->getExtensionDBVersion(),
            'weightUnit' => $this->configRepository->getWeightUnit(),
            'merchant' => $merchant,
        ];

        $tryAgainLater = __('Please try again later.');
        $soleTraderaccountCouldNotBeVerified = __('Your sole trader account could not be verified.');
        $paymentTerms = __("payment terms");
        $brandParams = $this->buildBrandQueryString();
        $paymentTermsLink = $this->configRepository->getCheckoutPageUrl() . '/terms' . $brandParams;
        $minimumOrder = $this->two->getMinimumOrderVisibility($this->checkoutSession->getQuote());

        return [
            'payment' => [
                $this->code => [
                    'checkoutApiUrl' => $this->configRepository->getCheckoutApiUrl(),
                    'checkoutPageUrl' => $this->configRepository->getCheckoutPageUrl(),
                    'brand' => $this->configRepository->getBrand(),
                    'brandVersion' => $this->configRepository->getBrandVersion(),
                    'redirectUrlCookieCode' => UrlCookie::COOKIE_NAME,
                    'isOrderIntentEnabled' => $this->configRepository->isOrderIntentEnabled(),
                    'isInvoiceEmailsEnabled' => $this->configRepository->isInvoiceEmailsEnabled(),
                    'orderIntentConfig' => $orderIntentConfig,
                    'isCompanySearchEnabled' => $this->configRepository->isCompanySearchEnabled(),
                    'isAddressSearchEnabled' => $this->configRepository->isAddressSearchEnabled(),
                    'companySearchLimit' => 50,
                    // Warm-start seed for the renderer's per-country
                    // supported-company-types memo: the quote's current
                    // billing country resolved server-side (the merchant
                    // API key never reaches the browser). Other countries
                    // are fetched live via GET /V1/two/supported-company-types
                    // as the buyer edits the billing address.
                    'supportedCompanyTypes' => $this->getSupportedCompanyTypesSeed(),
                    'isDepartmentFieldEnabled' => $this->configRepository->isDepartmentEnabled(),
                    'isProjectFieldEnabled' => $this->configRepository->isProjectEnabled(),
                    'isOrderNoteFieldEnabled' => $this->configRepository->isOrderNoteEnabled(),
                    'isPONumberFieldEnabled' => $this->configRepository->isPONumberEnabled(),
                    'availableBuyerTerms' => $this->configRepository->getAllBuyerTerms(),
                    'defaultPaymentTerm' => $this->configRepository->getDefaultPaymentTerm(),
                    'selectedPaymentTerm' => (int)$this->checkoutSession->getTwoSelectedTerm()
                        ?: $this->configRepository->getDefaultPaymentTerm(),
                    'currencySymbol' => $this->getCurrencySymbol(),
                    // Server-resolved minimum-order constraints in the display
                    // currency, for the renderer's client-side visibility gate
                    // (hide below min; on Amasty, where isAvailable offers the
                    // method unconditionally, this also drives showing above it).
                    // minimumOrderUnresolved is true when an active minimum could
                    // not be projected into the display currency (missing FX
                    // rate) → the renderer hides, matching the server gate's
                    // fail-closed stance rather than failing open.
                    'minimumOrder' => $minimumOrder['minimums'],
                    'minimumOrderUnresolved' => $minimumOrder['unresolved'],
                    'subtitleHtml' => $this->getSubtitleHtml(),
                    'surchargeDescription' => $this->configRepository->getSurchargeLineDescription(),
                    'isPaymentTermsEnabled' => true,
                    'orderIntentApprovedMessage' => __(
                        'Your invoice purchase with %1 is likely to be accepted subject to additional checks.',
                        $this->brandRegistry->getProductName()
                    ),
                    'orderIntentDeclinedMessage' => __(
                        'Your invoice purchase with %1 has been declined.',
                        $this->brandRegistry->getProductName()
                    ),
                    'generalErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $this->brandRegistry->getProductName(),
                        $tryAgainLater
                    ),
                    'invalidEmailListMessage' => __('Please ensure that your invoice email address list only contains valid email addresses separated by commas.'),
                    'paymentTermsMessage' => __(
                        'I accept the %1 and authorize %2 to process my data automatically.',
                        sprintf('<a href="%s" target="_blank">%s</a>', $paymentTermsLink, $paymentTerms),
                        $this->brandRegistry->getProviderFullName()
                    ),
                    'termsNotAcceptedMessage' => __('You must accept %1 to place order.', $paymentTerms),
                    'soleTraderErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $this->brandRegistry->getProductName(),
                        $soleTraderaccountCouldNotBeVerified
                    ),
                ],
            ],
        ];
    }

    /**
     * Get the currency symbol for the current store's display currency.
     */
    private function getCurrencySymbol(): string
    {
        try {
            $store = $this->storeManager->getStore();
            return $store->getCurrentCurrency()->getCurrencySymbol() ?: $store->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Resolve the brand's checkout subtitle for the storefront renderer.
     *
     * The string is brand data (BrandRegistryInterface::getCheckoutSubtitle,
     * sourced from brand.xml). The vanilla Two brand returns '' → no
     * subtitle. We only pass a non-empty key to the translator, so an
     * unmapped locale falls back to the (brand-owned) source key rather
     * than ever leaking a vanilla key. May contain HTML (e.g. a link);
     * the KO template binds it via `html:`.
     */
    private function getSubtitleHtml(): string
    {
        $key = $this->brandRegistry->getCheckoutSubtitle();
        return $key === '' ? '' : (string)__($key);
    }

    /**
     * Build query string with brand parameters.
     *
     * @return string e.g. "?brand=<tag>&brandVersion=qa" or ""
     *                where <tag> comes from BrandRegistryInterface::getBrandTag().
     */
    private function buildBrandQueryString(): string
    {
        $params = [];
        $brand = $this->configRepository->getBrand();
        if ($brand !== '') {
            $params['brand'] = $brand;
        }
        $brandVersion = $this->configRepository->getBrandVersion();
        if ($brandVersion !== '') {
            $params['brandVersion'] = $brandVersion;
        }
        return $params ? '?' . http_build_query($params) : '';
    }
}
