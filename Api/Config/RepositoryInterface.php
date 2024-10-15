<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api\Config;

/**
 * Config Repository Interface
 */
interface RepositoryInterface
{
    /** Provider specific config */
    public const CODE = 'two_payment';
    public const PROVIDER = 'Two';
    public const PAYMENT_TERMS = 'https://www.two.inc/terms-privacy';
    public const URL_TEMPLATE = 'https://%s.two.inc';

    /** Payment Group */
    public const XML_PATH_ENABLED = 'payment/two_payment/active';
    public const XML_PATH_TITLE = 'payment/two_payment/title';
    public const XML_PATH_MODE = 'payment/two_payment/mode';
    public const XML_PATH_MERCHANT_SHORT_NAME = 'payment/two_payment/merchant_short_name';
    public const XML_PATH_API_KEY = 'payment/two_payment/api_key';
    public const XML_PATH_DAYS_ON_INVOICE = 'payment/two_payment/days_on_invoice';
    public const XML_PATH_FULFILL_TRIGGER = 'payment/two_payment/fulfill_trigger';
    public const XML_PATH_FULFILL_ORDER_STATUS = 'payment/two_payment/fulfill_order_status';
    public const XML_PATH_ENABLE_COMPANY_SEARCH = 'payment/two_payment/enable_company_search';
    public const XML_PATH_ENABLE_ADDRESS_SEARCH = 'payment/two_payment/enable_address_search';
    public const XML_PATH_ENABLE_TAX_SUBTOTALS = 'payment/two_payment/enable_tax_subtotals';
    public const XML_PATH_ENABLE_ORDER_INTENT = 'payment/two_payment/enable_order_intent';
    public const XML_PATH_ENABLE_DEPARTMENT_NAME = 'payment/two_payment/enable_department';
    public const XML_PATH_ENABLE_PROJECT_NAME = 'payment/two_payment/enable_project';
    public const XML_PATH_ENABLE_ORDER_NOTE = 'payment/two_payment/enable_order_note';
    public const XML_PATH_ENABLE_PO_NUMBER = 'payment/two_payment/enable_po_number';
    public const XML_PATH_ENABLE_TWO_LINK = 'payment/two_payment/enable_two_link';
    public const XML_PATH_VERSION = 'payment/two_payment/version';
    public const XML_PATH_DEBUG = 'payment/two_payment/debug';

    /** Weight unit */
    public const XML_PATH_WEIGHT_UNIT = 'general/locale/weight_unit';

    /**
     * Check if payment method is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isActive(?int $storeId = null): bool;

    /**
     * Get mode
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getMode(?int $storeId = null): string;

    /**
     * Get merchant short name
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getMerchantShortName(?int $storeId = null): string;

    /**
     * Get API key
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getApiKey(?int $storeId = null): string;

    /**
     * Check if debug mode is enabled
     *
     * @param int|null $storeId
     * @param string|null $scope
     *
     * @return bool
     */
    public function isDebugMode(int $storeId = null, ?string $scope = null): bool;

    /**
     * Get invoice due in days
     *
     * @param int|null $storeId
     *
     * @return int
     */
    public function getDueInDays(?int $storeId = null): int;

    /**
     * Get Fulfill Trigger (invoice or shipment or complete)
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getFulfillTrigger(?int $storeId = null): string;

    /**
     * Get Fulfill Order Status
     *
     * @param int|null $storeId
     *
     * @return array
     */
    public function getFulfillOrderStatusList(?int $storeId = null): array;

    /**
     * Check if company name autocomplete is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isCompanySearchEnabled(?int $storeId = null): bool;

    /**
     * Check if order intent is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isOrderIntentEnabled(?int $storeId = null): bool;

    /**
     * Check if tax subtotals is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isTaxSubtotalsEnabled(?int $storeId = null): bool;

    /**
     * Check if department is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isDepartmentEnabled(?int $storeId = null): bool;

    /**
     * Check if order note is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isOrderNoteEnabled(?int $storeId = null): bool;

    /**
     * Check if project is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isProjectEnabled(?int $storeId = null): bool;

    /**
     * Check if PO number is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isPONumberEnabled(?int $storeId = null): bool;

    /**
     * Check if TWO link is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isTwoLinkEnabled(?int $storeId = null): bool;

    /**
     * Get weight unit
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getWeightUnit(?int $storeId = null): string;

    /**
     * Get route url
     *
     * @param string $route
     * @param array|null $params
     *
     * @return string
     */
    public function getUrls(string $route, ?array $params = []): string;

    /**
     * Get search host url
     *
     * @return array
     */
    public function getSearchHostUrl(): string;

    /**
     * Get checkout API url
     *
     * @return string
     */
    public function getCheckoutApiUrl(): string;

    /**
     * Get checkout page url
     *
     * @return string
     */
    public function getCheckoutPageUrl(): string;

    /**
     * Get Magento version
     *
     * @return string
     */
    public function getMagentoVersion(): string;

    /**
     * Get extension platform name
     *
     * @return string|null
     */
    public function getExtensionPlatformName(): ?string;

    /**
     * Get extension version
     *
     * @return string|null
     */
    public function getExtensionDBVersion(): ?string;

    /**
     * Add version data in url
     *
     * @param string $url
     * @return string
     */
    public function addVersionDataInURL(string $url): string;

    /**
     * Check if address autocomplete is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isAddressSearchEnabled(?int $storeId = null): bool;
}
