<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Two\Gateway\Api\Config\RepositoryInterface;

/**
 * Config Repository
 */
class Repository implements RepositoryInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    /**
     * @var EncryptorInterface
     */
    private $encryptor;
    /**
     * @var UrlInterface
     */
    private $urlBuilder;
    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param UrlInterface $urlBuilder
     * @param ProductMetadataInterface $productMetadata
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        UrlInterface $urlBuilder,
        ProductMetadataInterface $productMetadata
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->urlBuilder = $urlBuilder;
        $this->productMetadata = $productMetadata;
    }

    /**
     * @inheritDoc
     */
    public function isActive(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLED, $storeId);
    }

    /**
     * Retrieve config flag by path, storeId and scope
     *
     * @param string $path
     * @param int|null $storeId
     * @param string|null $scope
     * @return bool
     */
    private function isSetFlag(string $path, ?int $storeId = null, ?string $scope = null): bool
    {
        if (empty($scope)) {
            $scope = ScopeInterface::SCOPE_STORE;
        }

        return $this->scopeConfig->isSetFlag($path, $scope, $storeId);
    }

    /**
     * Retrieve config value
     *
     * @param string $configPath
     * @param int|null $storeId
     * @return mixed
     */
    private function getConfig(string $configPath, ?int $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $configPath,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritDoc
     */
    public function getApiKey(?int $storeId = null): string
    {
        return (string)$this->encryptor->decrypt($this->getConfig(self::XML_PATH_API_KEY, $storeId));
    }

    /**
     * @inheritDoc
     */
    public function isDebugMode(int $storeId = null, ?string $scope = null): bool
    {
        $scope = $scope ?? ScopeInterface::SCOPE_STORE;
        return $this->isSetFlag(
            self::XML_PATH_DEBUG,
            $storeId,
            $scope
        );
    }

    /**
     * @inheritDoc
     */
    public function getDueInDays(?int $storeId = null): int
    {
        return (int)$this->getConfig(self::XML_PATH_DAYS_ON_INVOICE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getFulfillTrigger(?int $storeId = null): string
    {
        return (string)$this->getConfig(self::XML_PATH_FULFILL_TRIGGER, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getFulfillOrderStatusList(?int $storeId = null): array
    {
        return explode(',', (string)$this->getConfig(self::XML_PATH_FULFILL_ORDER_STATUS, $storeId));
    }

    /**
     * @inheritDoc
     */
    public function isCompanySearchEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_COMPANY_SEARCH, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isOrderIntentEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_ORDER_INTENT, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isInvoiceEmailsEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_INVOICE_EMAILS, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isTaxSubtotalsEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_TAX_SUBTOTALS, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isDepartmentEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_DEPARTMENT_NAME, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isProjectEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_PROJECT_NAME, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isOrderNoteEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_ORDER_NOTE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isPONumberEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_PO_NUMBER, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getWeightUnit(?int $storeId = null): string
    {
        return $this->getConfig(self::XML_PATH_WEIGHT_UNIT, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getUrls(string $route, ?array $params = []): string
    {
        return $this->urlBuilder->getUrl($route, $params);
    }

    /**
     * @inheritDoc
     */
    public function getMode(?int $storeId = null): string
    {
        return (string)$this->getConfig(self::XML_PATH_MODE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getCheckoutApiUrl(?string $mode = null): string
    {
        $mode = $mode ?: $this->getMode();
        $prefix = $mode == 'production' ? 'api' : ('api.' . $mode);
        return sprintf(self::URL_TEMPLATE, $prefix);
    }

    /**
     * @inheritDoc
     */
    public function getCheckoutPageUrl(?string $mode = null): string
    {
        $mode = $mode ?: $this->getMode();
        $prefix = $mode == 'production' ? 'checkout' : ('checkout.' . $mode);
        return sprintf(self::URL_TEMPLATE, $prefix);
    }

    /**
     * @inheritDoc
     */
    public function getMagentoVersion(): string
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * @inheritDoc
     */
    public function getExtensionPlatformName(): ?string
    {
        $versionData = $this->getExtensionVersionData();
        if (isset($versionData['client'])) {
            return $versionData['client'];
        }

        return null;
    }

    /**
     * Returns extension version Array
     *
     * @return array
     */
    private function getExtensionVersionData(): array
    {
        return [
            'client' => 'Magento',
            'client_v' => $this->getConfig(self::XML_PATH_VERSION)
        ];
    }

    /**
     * @inheritDoc
     */
    public function getExtensionDBVersion(): ?string
    {
        $versionData = $this->getExtensionVersionData();
        if (isset($versionData['client_v'])) {
            return $versionData['client_v'];
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function addVersionDataInURL(string $url): string
    {
        $queryString = $this->getExtensionVersionData();
        if (!empty($queryString)) {
            if (strpos($url, '?') !== false) {
                $url = sprintf('%s&%s', $url, http_build_query($queryString));
            } else {
                $url = sprintf('%s?%s', $url, http_build_query($queryString));
            }
        }

        return $url;
    }

    /**
     * @inheritDoc
     */
    public function isAddressSearchEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag(self::XML_PATH_ENABLE_COMPANY_SEARCH, $storeId) &&
            $this->isSetFlag(self::XML_PATH_ENABLE_ADDRESS_SEARCH, $storeId);
    }
}
