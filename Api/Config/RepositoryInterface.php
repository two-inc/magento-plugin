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
    /**
     * Deprecated custom flat-rate field. Code-level name is
     * custom_surcharge_tax_rate; the persisted config key deliberately
     * stays `surcharge_tax_rate` (no data migration — pure BC).
     */
    public const XML_PATH_CUSTOM_SURCHARGE_TAX_RATE = 'payment/two_payment/surcharge_tax_rate';
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
     * Merchant-declared fallback shipping tax rate, as a percentage.
     *
     * Only consulted when Magento's tax engine declares no rate at all for a
     * taxed shipping line (TWO-25503). NULL when unset — the plugin refuses
     * the order rather than assuming a rate.
     *
     * @param int|null $storeId
     *
     * @return float|null
     */
    public function getDefaultShippingTaxRate(?int $storeId = null): ?float;

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
     * `enable_address_search` ANDed with `enable_company_search` (TWO-25503):
     * `enable_company_search` OFF relocates the company-search control to the
     * payment tile rather than disabling search, but it retires the
     * convenience this setting exists for, so autofill is forced off with it.
     * The stored value is also pinned off on save
     * (Model\Config\Backend\AddressSearchToggle) — this AND is belt-and-
     * suspenders for a row stored before the coupling existed.
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
     * Whether a term duration is one the merchant currently offers.
     *
     * Single owner of the availability check, shared by the chip-click
     * endpoint and final order composition (TWO-25503).
     *
     * @param int $termDays
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isBuyerTermAvailable(int $termDays, ?int $storeId = null): bool;

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
     * Get the custom (flat) surcharge tax rate percentage.
     *
     * DEPRECATED FIELD: initial attempt at tax support, superseded by
     * the tax-rule-based configurable selector (getSurchargeTaxClassId),
     * retained only for pre-existing merchants. The persisted config
     * key remains `surcharge_tax_rate` — only the code-level name was
     * renamed; migrating the core_config_data path would be pure risk
     * for zero benefit.
     *
     * @param int|null $storeId
     *
     * @return float
     */
    public function getCustomSurchargeTaxRate(?int $storeId = null): float;

    /**
     * Whether a custom (flat) surcharge tax rate value genuinely
     * exists in config. Existence check, not truthiness: a configured
     * rate of 0 / "0.00" is a real value and must return true. Gates
     * the deprecated "Custom" option in the surcharge tax treatment
     * selector — pre-existing merchants only.
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function hasCustomSurchargeTaxRate(?int $storeId = null): bool;

    /**
     * Get the Product Tax Class id used to tax the surcharge via
     * Magento's tax rules engine (destination-aware, rule-driven,
     * additive multi-rate).
     *
     * Returns null when the merchant has not opted into engine-driven
     * surcharge tax (config unset, or explicitly set to the deprecated
     * "custom" flat-rate treatment) — callers must then fall back to
     * getCustomSurchargeTaxRate(). A value of 0 is a valid selection
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

    /**
     * Optional merchant-entered site/vendor name for multi-site setups
     * (TWO-25386). Sent to the API on order creation as `vendor_name` so a
     * merchant running Two across several sites/stores that share one
     * merchant account can tell the orders apart. Empty string means unset.
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getVendorSiteName(?int $storeId = null): string;

    /**
     * Whether the buyer-facing "What is <Product>?" explainer link is
     * shown on the payment method tile at checkout (TWO-25386).
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isAboutLinkEnabled(?int $storeId = null): bool;

    /**
     * Whether the optional checkout field inputs (PO number, project,
     * department, order note, invoice email) render a hover tooltip with
     * the field's label (TWO-25386). Defaults to enabled — Magento already
     * rendered these unconditionally before this flag existed, so the
     * default preserves prior behaviour.
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isDisplayTooltipsEnabled(?int $storeId = null): bool;

    /**
     * Debug flag: skip the additional confirmation-callback check on
     * top of the mandatory order-reference match (TWO-25386). See
     * Controller\Payment\Confirm for why this is currently a documented
     * no-op on Magento — kept for admin-surface parity with the other
     * plugins pending a Magento-side signing mechanism to actually gate.
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isSkipConfirmNonceCheckEnabled(?int $storeId = null): bool;

    /**
     * Whether stored Two configuration (payment/two_payment/* and
     * payment/two_search/*) is deleted from core_config_data when the
     * module is uninstalled (TWO-25386). Module uninstall is the nearest
     * lifecycle event Magento offers for this.
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isClearSettingsOnUninstallEnabled(?int $storeId = null): bool;

    /**
     * Store-view-scoped payment method subtitle shown beneath the title
     * at checkout (TWO-25386). Empty string means unset — callers fall back
     * to the brand's default subtitle
     * (BrandRegistryInterface::getCheckoutSubtitle).
     *
     * @param int|null $storeId
     *
     * @return string
     */
    public function getSubtitle(?int $storeId = null): string;

    /**
     * Debug flag: skip TLS certificate verification on outbound API calls
     * (TWO-25386). Defaults to false (verification ON, secure). Unsafe for
     * production — intended only for corporate networks that terminate TLS
     * with a custom/self-signed certificate.
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isSslVerificationDisabled(?int $storeId = null): bool;
}
