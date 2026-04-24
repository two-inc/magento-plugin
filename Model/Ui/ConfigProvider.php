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
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\UrlCookie;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\SurchargeCalculator;
use Two\Gateway\Model\Two;

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
     * @var SurchargeCalculator
     */
    private $surchargeCalculator;

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
        SurchargeCalculator $surchargeCalculator,
        StoreManagerInterface $storeManager
    ) {
        $this->configRepository = $configRepository;
        $this->adapter = $adapter;
        $this->two = $two;
        $this->assetRepository = $assetRepository;
        $this->checkoutSession = $checkoutSession;
        $this->surchargeCalculator = $surchargeCalculator;
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

        $provider = $this->configRepository::PROVIDER;
        $tryAgainLater = __('Please try again later.');
        $soleTraderaccountCouldNotBeVerified = __('Your sole trader account could not be verified.');
        $paymentTerms = __("%1 terms and conditions", $this->configRepository::PROVIDER);
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
                    'termSurcharges' => $this->getTermSurcharges(),
                    'currencySymbol' => $this->getCurrencySymbol(),
                    'surchargeDescription' => $this->configRepository->getSurchargeLineDescription(),
                    'isPaymentTermsEnabled' => true,
                    'redirectMessage' => __(
                        'You will be redirected to %1 when you place order.',
                        $provider
                    ),
                    'orderIntentApprovedMessage' => __(
                        'Your invoice purchase with %1 is likely to be accepted subject to additional checks.',
                        $provider
                    ),
                    'orderIntentDeclinedMessage' => __('Your invoice purchase with %1 has been declined.', $provider),
                    'generalErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $provider,
                        $tryAgainLater
                    ),
                    'invalidEmailListMessage' => __('Please ensure that your invoice email address list only contains valid email addresses separated by commas.'),
                    'paymentTermsMessage' => __(
                        'By checking this box, I confirm that I have read and agree to %1.',
                        sprintf('<a href="%s" target="_blank">%s</a>', $paymentTermsLink, $paymentTerms)
                    ),
                    'termsNotAcceptedMessage' => __('You must accept %1 to place order.', $paymentTerms),
                    'soleTraderErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $provider,
                        $soleTraderaccountCouldNotBeVerified
                    ),
                ],
            ],
        ];
    }

    /**
     * Compute surcharges for each available term using the current quote.
     *
     * @return array<int, float> days => surcharge amount
     */
    private function getTermSurcharges(): array
    {
        $terms = $this->configRepository->getAllBuyerTerms();
        $surcharges = [];

        try {
            $quote = $this->checkoutSession->getQuote();
            // Subtract any existing surcharge to avoid circular base
            $existingSurcharge = (float)$this->checkoutSession->getTwoSurchargeGross();
            $grandTotal = (float)$quote->getGrandTotal() - $existingSurcharge;
            $currency = $quote->getQuoteCurrencyCode()
                ?: $this->storeManager->getStore()->getBaseCurrencyCode();

            $store = $this->storeManager->getStore();
            $country = $store->getConfig('general/country/default') ?: 'NO';
            $billing = $quote->getBillingAddress();
            $shipping = $quote->getShippingAddress();
            if ($billing && $billing->getCountryId()) {
                $country = $billing->getCountryId();
            } elseif ($shipping && $shipping->getCountryId()) {
                $country = $shipping->getCountryId();
            }

            foreach ($terms as $days) {
                try {
                    $result = $this->surchargeCalculator->calculate(
                        $grandTotal,
                        $days,
                        $country,
                        $currency
                    );
                    $net = $result['amount'];
                    $tax = round($net * ($result['tax_rate'] / 100), 2);
                    $surcharges[$days] = $net;
                } catch (\Exception $e) {
                    $surcharges[$days] = 0.0;
                }
            }
        } catch (\Exception $e) {
            foreach ($terms as $days) {
                $surcharges[$days] = 0.0;
            }
        }

        return $surcharges;
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
     * @return string e.g. "?brand=x&brandVersion=qa" or ""
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
