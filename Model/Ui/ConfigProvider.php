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
use Two\Gateway\Service\Api\Adapter;
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
     * ConfigProvider constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Adapter $adapter
     * @param Two $two
     * @param AssetRepository $assetRepository
     */
    public function __construct(
        ConfigRepository $configRepository,
        Adapter $adapter,
        Two $two,
        AssetRepository $assetRepository
    ) {
        $this->configRepository = $configRepository;
        $this->adapter = $adapter;
        $this->two = $two;
        $this->assetRepository = $assetRepository;
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
            'invoiceType' => 'FUNDED_INVOICE',
            'weightUnit' => $this->configRepository->getWeightUnit(),
            'merchant' => $merchant,
        ];

        $provider = $this->configRepository::PROVIDER;
        $tryAgainLater = __('Please try again later.');
        $soleTraderaccountCouldNotBeVerified = __('Your sole trader account could not be verified.');
        $paymentTerms = __("%1 terms and conditions", $this->configRepository::PROVIDER);
        $paymentTermsLink = $this->configRepository->getCheckoutPageUrl() . '/terms';

        return [
            'payment' => [
                ConfigRepository::CODE => [
                    'checkoutApiUrl' => $this->configRepository->getCheckoutApiUrl(),
                    'checkoutPageUrl' => $this->configRepository->getCheckoutPageUrl(),
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
}
