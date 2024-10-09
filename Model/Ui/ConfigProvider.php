<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use ABN\Gateway\Service\UrlCookie;

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

        $tryAgainLater = __('Please try again later.');
        $soleTraderaccountCouldNotBeVerified = __('Your sole trader account could not be verified.');
        // Set isTermsAndConditionsEnabled based on provider
        $paymentTerms = __("terms and conditions of %1", $this->configRepository::PRODUCT_NAME);
        $paymentTermsLink = $this->configRepository->getCheckoutPageUrl() . '/terms';
        $paymentTermsEmail = $this->configRepository::PAYMENT_TERMS_EMAIL;

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
                        $this->configRepository::PRODUCT_NAME
                    ),
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
                    'paymentTermsMessage' => __(
                        'I have filled in all the details truthfully and accept to pay the invoice in 30 days. '.
                        'I agree to the %1. ' .
                        'You hereby give permission to %2 to decide on the basis ' .
                        'of automated processing of (personal) data whether you can use %3. ' .
                        'You can withdraw this permission by sending an e-mail to %4.',
                        sprintf('<a href="%s" target="_blank">%s</a>', $paymentTermsLink, $paymentTerms),
                        $this->configRepository::PROVIDER_FULL_NAME,
                        $this->configRepository::PRODUCT_NAME,
                        sprintf('<a href="mailto:%s">%s</a>', $paymentTermsEmail, $paymentTermsEmail)
                    ),
                    'termsNotAcceptedMessage' => __('You must first accept the payment terms.'),
                    'soleTraderErrorMessage' => __(
                        'Something went wrong with your request to %1. %2',
                        $this->configRepository::PRODUCT_NAME,
                        $soleTraderaccountCouldNotBeVerified
                    ),
                ],
            ],
        ];
    }
}
