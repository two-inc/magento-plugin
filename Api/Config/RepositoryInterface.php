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
    /** Magento payment-method code (canonical, brand-independent). */
    public const CODE = 'two_payment';

    // Brand-bound values (PROVIDER, PROVIDER_FULL_NAME, PRODUCT_NAME,
    // URL_TEMPLATE, AVAILABLE_PAYMENT_TERMS, SURCHARGE_FIXED_MAX[_CURRENCY])
    // moved to Two\Gateway\Api\BrandRegistryInterface — inject the registry
    // and call its methods rather than re-introducing constants here.

    /** Payment Group */
    public const XML_PATH_ENABLED = 'payment/two_payment/active';
    public const XML_PATH_TITLE = 'payment/two_payment/title';
    public const XML_PATH_MODE = 'payment/two_payment/mode';
    public const XML_PATH_API_KEY = 'payment/two_payment/api_key';
    public const XML_PATH_DAYS_ON_INVOICE = 'payment/two_payment/days_on_invoice';
    public const XML_PATH_FULFILL_TRIGGER = 'payment/two_payment/fulfill_trigger';
    public const XML_PATH_FULFILL_ORDER_STATUS = 'payment/two_payment/fulfill_order_status';
    public const XML_PATH_ENABLE_COMPANY_SEARCH = 'payment/two_payment/enable_company_search';
    public const XML_PATH_ENABLE_ADDRESS_SEARCH = 'payment/two_payment/enable_address_search';
    public const XML_PATH_ENABLE_TAX_SUBTOTALS = 'payment/two_payment/enable_tax_subtotals';
    public const XML_PATH_ENABLE_ORDER_INTENT = 'payment/two_payment/enable_order_intent';
    public const XML_PATH_ENABLE_INVOICE_EMAILS = 'payment/two_payment/enable_invoice_emails';
    public const XML_PATH_ENABLE_DEPARTMENT_NAME = 'payment/two_payment/enable_department';
    public const XML_PATH_ENABLE_PROJECT_NAME = 'payment/two_payment/enable_project';
    public const XML_PATH_ENABLE_ORDER_NOTE = 'payment/two_payment/enable_order_note';
    public const XML_PATH_ENABLE_PO_NUMBER = 'payment/two_payment/enable_po_number';
    public const XML_PATH_PAYMENT_TERMS_TYPE = 'payment/two_payment/payment_terms_type';
    public const XML_PATH_PAYMENT_TERMS_DURATION_DAYS = 'payment/two_payment/payment_terms_duration_days';
    public const XML_PATH_PAYMENT_TERMS = 'payment/two_payment/payment_terms';
    public const XML_PATH_DEFAULT_PAYMENT_TERM = 'payment/two_payment/default_payment_term';
    public const XML_PATH_SURCHARGE_TYPE = 'payment/two_payment/surcharge_type';
    public const XML_PATH_SURCHARGE_DIFFERENTIAL = 'payment/two_payment/surcharge_differential';
    public const XML_PATH_SURCHARGE_LINE_DESCRIPTION = 'payment/two_payment/surcharge_line_description';
    public const XML_PATH_SURCHARGE_TAX_RATE = 'payment/two_payment/surcharge_tax_rate';
    public const XML_PATH_SURCHARGE_TAX_CLASS_ID = 'payment/two_payment/surcharge_tax_class';
    public const XML_PATH_SURCHARGE_FIXED_CURRENCY = 'payment/two_payment/surcharge_fixed_currency';
    public const XML_PATH_DEFAULT_PRODUCT_TAX_CLASS = 'tax/classes/default_product_tax_class';
    public const XML_PATH_VERSION = 'payment/two_payment/version';
    public const XML_PATH_DEBUG = 'payment/two_payment/debug';

    /** Brand-independent surcharge ceiling (percent). */
    public const SURCHARGE_PERCENTAGE_MAX = 100;

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
    public function isDebugMode(?int $storeId = null, ?string $scope = null): bool;

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
     * Check if invoice emails is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isInvoiceEmailsEnabled(?int $storeId = null): bool;

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

    /**
     * Get payment terms type
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getPaymentTermsType(?int $storeId = null): string;

    /**
     * Get payment terms duration days (custom term, 0 = not set)
     *
     * @param int|null $storeId
     *
     * @return int
     */
    public function getPaymentTermsDurationDays(?int $storeId = null): int;

    /**
     * Get selected payment terms from multiselect
     *
     * @param int|null $storeId
     *
     * @return array
     */
    public function getPaymentTerms(?int $storeId = null): array;

    /**
     * Get all buyer-facing terms (union of multiselect + custom duration)
     *
     * @param int|null $storeId
     *
     * @return array
     */
    public function getAllBuyerTerms(?int $storeId = null): array;

    /**
     * Get default payment term
     *
     * @param int|null $storeId
     *
     * @return int
     */
    public function getDefaultPaymentTerm(?int $storeId = null): int;

    /**
     * Get surcharge type
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getSurchargeType(?int $storeId = null): string;

    /**
     * Check if differential surcharge is enabled
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isSurchargeDifferential(?int $storeId = null): bool;

    /**
     * Get surcharge line item description
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getSurchargeLineDescription(?int $storeId = null): string;

    /**
     * Get surcharge tax rate (percentage)
     *
     * @param int|null $storeId
     *
     * @return float
     */
    public function getSurchargeTaxRate(?int $storeId = null): float;

    /**
     * Get the Product Tax Class id used to tax the surcharge via
     * Magento's tax rules engine (destination-aware, rule-driven,
     * additive multi-rate).
     *
     * Returns null when the merchant has not opted into engine-driven
     * surcharge tax (config unset, or explicitly set to the legacy
     * flat-rate option) — callers must then fall back to
     * getSurchargeTaxRate(). A value of 0 is a valid selection
     * ("None"): no tax rule can match class id 0, so the surcharge is
     * untaxed everywhere.
     *
     * @param int|null $storeId
     *
     * @return int|null
     */
    public function getSurchargeTaxClassId(?int $storeId = null): ?int;

    /**
     * Get surcharge config for a specific term
     *
     * @param int $days
     * @param int|null $storeId
     *
     * @return array{percentage: float, fixed: float, limit: float|null}
     */
    public function getSurchargeConfig(int $days, ?int $storeId = null): array;

    /**
     * Get the currency code in which surcharge fixed amounts were saved.
     *
     * Returns empty string if no currency was recorded (legacy data).
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getSurchargeFixedCurrency(?int $storeId = null): string;

    /**
     * Get the buyer surcharge rounding basis (none/up/down/standard).
     *
     * "none" means no rounding is applied to the buyer fee share line item.
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getSurchargeRoundingBasis(?int $storeId = null): string;

    /**
     * Get the increment the buyer surcharge line item is rounded to.
     *
     * Zero (or unset) means no step is configured.
     *
     * @param int|null $storeId
     *
     * @return float
     */
    public function getSurchargeRoundingStep(?int $storeId = null): float;
}
