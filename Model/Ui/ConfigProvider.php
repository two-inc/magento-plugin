<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use ABN\Gateway\Service\UrlCookie;
use ABN\Gateway\Service\Api\Adapter;
use ABN\Gateway\Model\Two;

/**
 * Ui Config Provider
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

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

    public function __construct(
        ConfigRepository $configRepository,
        Adapter $adapter,
        Two $two,
        AssetRepository $assetRepository,
        CheckoutSession $checkoutSession,
        StoreManagerInterface $storeManager
    ) {
        $this->configRepository = $configRepository;
        $this->adapter = $adapter;
        $this->two = $two;
        $this->assetRepository = $assetRepository;
        $this->checkoutSession = $checkoutSession;
        $this->storeManager = $storeManager;
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

        return [
            'payment' => [
                ConfigRepository::CODE => [
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
                    'supportedCountryCodes' => ['no', 'gb', 'se', 'nl'],
                    'isDepartmentFieldEnabled' => $this->configRepository->isDepartmentEnabled(),
                    'isProjectFieldEnabled' => $this->configRepository->isProjectEnabled(),
                    'isOrderNoteFieldEnabled' => $this->configRepository->isOrderNoteEnabled(),
                    'isPONumberFieldEnabled' => $this->configRepository->isPONumberEnabled(),
                    'availableBuyerTerms' => $this->configRepository->getAllBuyerTerms(),
                    'defaultPaymentTerm' => $this->configRepository->getDefaultPaymentTerm(),
                    'selectedPaymentTerm' => (int)$this->checkoutSession->getTwoSelectedTerm()
                        ?: $this->configRepository->getDefaultPaymentTerm(),
                    'currencySymbol' => $this->getCurrencySymbol(),
                    'surchargeDescription' => $this->configRepository->getSurchargeLineDescription(),
                    'isPaymentTermsEnabled' => true,
                    'orderIntentApprovedMessage' => __(
                        'Your invoice purchase with %1 is likely to be accepted subject to additional checks.',
                        $this->configRepository::PRODUCT_NAME
                    ),
                    'orderIntentDeclinedMessage' => __(
                        'Your invoice purchase with %1 has been declined.',
                        $this->configRepository::PRODUCT_NAME
                    ),
                    'generalErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $this->configRepository::PRODUCT_NAME,
                        $tryAgainLater
                    ),
                    'invalidEmailListMessage' => __('Please ensure that your invoice email address list only contains valid email addresses separated by commas.'),
                    'paymentTermsMessage' => __(
                        'I accept the %1 and authorize %2 to process my data automatically.',
                        sprintf('<a href="%s" target="_blank">%s</a>', $paymentTermsLink, $paymentTerms),
                        $this->configRepository::PROVIDER_FULL_NAME
                    ),
                    'termsNotAcceptedMessage' => __('You must accept %1 to place order.', $paymentTerms),
                    'soleTraderErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $this->configRepository::PRODUCT_NAME,
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
     * Build query string with brand parameters.
     *
     * @return string e.g. "?brand=achterafbetalen&brandVersion=qa" or ""
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
