<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\UrlCookie;

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
     * @var AssetRepository
     */
    private $assetRepository;

    /**
     * ConfigProvider constructor.
     *
     * @param ConfigRepository $configRepository
     * @param AssetRepository $assetRepository
     */
    public function __construct(
        ConfigRepository $configRepository,
        AssetRepository $assetRepository
    ) {
        $this->configRepository = $configRepository;
        $this->assetRepository = $assetRepository;
    }

    /**
     * Retrieve assoc array of checkout configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        $orderIntentConfig = [
            'extensionPlatformName' => $this->configRepository->getExtensionPlatformName(),
            'extensionDBVersion' => $this->configRepository->getExtensionDBVersion(),
            'invoiceType' => 'FUNDED_INVOICE',
            'merchantShortName' => $this->configRepository->getMerchantShortName(),
            'weightUnit' => $this->configRepository->getWeightUnit(),
        ];

        $provider = $this->configRepository::PROVIDER;
        $tryAgainLater = __('Please try again later.');
        $soleTraderaccountCouldNotBeVerified = __('Your sole trader account could not be verified.');
        $paymentTerms = __("Payment Terms");
        $paymentTermsLink = $this->configRepository->getCheckoutPageUrl() . '/terms';

        return [
            'payment' => [
                ConfigRepository::CODE => [
                    'checkoutApiUrl' => $this->configRepository->getCheckoutApiUrl(),
                    'checkoutPageUrl' => $this->configRepository->getCheckoutPageUrl(),
                    'redirectUrlCookieCode' => UrlCookie::COOKIE_NAME,
                    'isOrderIntentEnabled' => $this->configRepository->isOrderIntentEnabled(),
                    'orderIntentConfig' => $orderIntentConfig,
                    'isCompanySearchEnabled' => $this->configRepository->isCompanySearchEnabled(),
                    'isAddressSearchEnabled' => $this->configRepository->isAddressSearchEnabled(),
                    'companySearchLimit' => 50,
                    'supportedCountryCodes' => ['no', 'gb', 'se', 'nl'],
                    'isDepartmentFieldEnabled' => $this->configRepository->isDepartmentEnabled(),
                    'isProjectFieldEnabled' => $this->configRepository->isProjectEnabled(),
                    'isOrderNoteFieldEnabled' => $this->configRepository->isOrderNoteEnabled(),
                    'isPONumberFieldEnabled' => $this->configRepository->isPONumberEnabled(),
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
                    'paymentTermsMessage' => __(
                        'By checking this box, I confirm that I have read and agree to the %1.',
                        sprintf('<a href="%s" target="_blank">%s</a>', $paymentTermsLink, $paymentTerms)
                    ),
                    'termsNotAcceptedMessage' => __('You must first accept the payment terms.'),
                    'soleTraderErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $provider,
                        $soleTraderaccountCouldNotBeVerified
                    ),
                ],
            ],
        ];
    }
}
