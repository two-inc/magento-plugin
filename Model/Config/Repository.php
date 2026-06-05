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
use Magento\Tax\Model\Calculation as TaxCalculation;
use Two\Gateway\Api\BrandRegistryInterface;
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
     * @var TaxCalculation
     */
    private $taxCalculation;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /**
     * @var string|null Optional explicit override. Null = resolve
     *                  lazily from BrandRegistryInterface::getCode().
     *                  Kept as a ctor arg for unit-test injection and
     *                  the rare caller that explicitly targets a
     *                  non-active brand's CCD subtree.
     */
    private $code;

    /**
     * @param ?string $code Payment-method code. Null (the shipped
     *                      default) defers to the brand registry —
     *                      every `payment/<code>/<key>` path is
     *                      built against the active brand resolved
     *                      from brand.xml at request time. ABN and
     *                      future overlays no longer need a virtualType
     *                      of this Repository.
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        UrlInterface $urlBuilder,
        ProductMetadataInterface $productMetadata,
        TaxCalculation $taxCalculation,
        BrandRegistryInterface $brandRegistry,
        ?string $code = null
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->urlBuilder = $urlBuilder;
        $this->productMetadata = $productMetadata;
        $this->taxCalculation = $taxCalculation;
        $this->brandRegistry = $brandRegistry;
        $this->code = $code;
    }

    /**
     * Active payment-method code. Lazily resolved from the brand
     * registry so swapping the brand (single-overlay invariant) is
     * enough — no virtualType-per-brand needed.
     */
    private function code(): string
    {
        return $this->code ?? $this->brandRegistry->getCode();
    }

    /**
     * Build a brand-aware `payment/<code>/<key>` config path.
     */
    private function path(string $key): string
    {
        return 'payment/' . $this->code() . '/' . $key;
    }

    /**
     * @inheritDoc
     */
    public function isActive(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('active'), $storeId);
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
        return (string)$this->encryptor->decrypt($this->getConfig($this->path('api_key'), $storeId));
    }

    /**
     * @inheritDoc
     */
    public function isDebugMode(?int $storeId = null, ?string $scope = null): bool
    {
        $scope = $scope ?? ScopeInterface::SCOPE_STORE;
        return $this->isSetFlag(
            $this->path('debug'),
            $storeId,
            $scope
        );
    }

    /**
     * @inheritDoc
     */
    public function getDueInDays(?int $storeId = null): int
    {
        return (int)$this->getConfig($this->path('days_on_invoice'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getFulfillTrigger(?int $storeId = null): string
    {
        return (string)$this->getConfig($this->path('fulfill_trigger'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getFulfillOrderStatusList(?int $storeId = null): array
    {
        return explode(',', (string)$this->getConfig($this->path('fulfill_order_status'), $storeId));
    }

    /**
     * @inheritDoc
     */
    public function isCompanySearchEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('enable_company_search'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isOrderIntentEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('enable_order_intent'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isInvoiceEmailsEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('enable_invoice_emails'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isTaxSubtotalsEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('enable_tax_subtotals'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isDepartmentEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('enable_department'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isProjectEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('enable_project'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isOrderNoteEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('enable_order_note'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function isPONumberEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('enable_po_number'), $storeId);
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
        return (string)$this->getConfig($this->path('mode'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getCheckoutApiUrl(?string $mode = null): string
    {
        if ($this->isDeveloperMode()) {
            $envUrl = getenv('TWO_API_BASE_URL');
            if ($envUrl !== false && $envUrl !== '') {
                return $envUrl;
            }
        }
        $mode = $mode ?: $this->getMode();
        $prefix = $mode == 'production' ? 'api' : ('api.' . $mode);
        return sprintf($this->brandRegistry->getCheckoutUrlTemplate(), $prefix);
    }

    /**
     * @inheritDoc
     */
    public function getCheckoutPageUrl(?string $mode = null): string
    {
        if ($this->isDeveloperMode()) {
            $envUrl = getenv('TWO_CHECKOUT_BASE_URL');
            if ($envUrl !== false && $envUrl !== '') {
                return $envUrl;
            }
        }
        $mode = $mode ?: $this->getMode();
        $prefix = $mode == 'production' ? 'checkout' : ('checkout.' . $mode);
        return sprintf($this->brandRegistry->getCheckoutUrlTemplate(), $prefix);
    }

    /**
     * Check if Magento is in developer mode.
     *
     * Reads from app/etc/env.php directly to avoid State DI injection
     *. Equivalent to State::getMode() === MODE_DEVELOPER.
     *
     * @return bool
     */
    protected function isDeveloperMode(): bool
    {
        if (defined('BP')) {
            $envFile = BP . '/app/etc/env.php';
            if (file_exists($envFile)) {
                $env = include $envFile;
                return ($env['MAGE_MODE'] ?? '') === 'developer';
            }
        }
        return false;
    }

    /**
     * Get brand identifier for checkout page URL decoration.
     *
     * Returns empty in production so URLs stay clean — the checkout
     * domain itself conveys the brand there. Only emitted in non-prod
     * modes where domains are shared across brands.
     *
     * @return string
     */
    public function getBrand(): string
    {
        if ($this->getMode() === 'production') {
            return '';
        }
        // Dev-loop override: developers can route a non-prod build to a
        // specific brand sub-stack via TWO_BRAND. Sanitise — the value
        // goes straight into a query string emitted to the buyer
        // browser, so a typo like "TWO_BRAND=foo bar" must not slip
        // through.
        $envBrand = getenv("TWO_BRAND");
        if ($envBrand !== false && $envBrand !== "" && preg_match("/^[a-z0-9-]+$/i", $envBrand)) {
            return $envBrand;
        }
        return $this->brandRegistry->getBrandTag();
    }

    /**
     * Get brand version for checkout page URL decoration.
     *
     * Resolved by Makefile: 'qa' for @two.inc gcloud users, empty otherwise.
     * Overridable via TWO_BRAND_VERSION in .env.local. Returns empty in
     * production — see getBrand().
     *
     * @return string
     */
    public function getBrandVersion(): string
    {
        if ($this->getMode() === 'production') {
            return '';
        }
        $envVersion = getenv('TWO_BRAND_VERSION');
        if ($envVersion !== false && $envVersion !== '') {
            return $envVersion;
        }
        return '';
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
            'client_v' => $this->getConfig($this->path('version'))
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
        return $this->isSetFlag($this->path('enable_company_search'), $storeId) &&
            $this->isSetFlag($this->path('enable_address_search'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getPaymentTermsType(?int $storeId = null): string
    {
        return (string)$this->getConfig($this->path('payment_terms_type'), $storeId) ?: 'standard';
    }

    /**
     * @inheritDoc
     */
    public function getPaymentTermsDurationDays(?int $storeId = null): int
    {
        return (int)$this->getConfig($this->path('payment_terms_duration_days'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getPaymentTerms(?int $storeId = null): array
    {
        $value = (string)$this->getConfig($this->path('payment_terms'), $storeId);
        if ($value === '') {
            return [];
        }
        return array_map('intval', explode(',', $value));
    }

    /**
     * @inheritDoc
     */
    public function getAllBuyerTerms(?int $storeId = null): array
    {
        $terms = $this->getPaymentTerms($storeId);
        $custom = $this->getPaymentTermsDurationDays($storeId);
        if ($custom > 0) {
            $terms[] = $custom;
        }
        $terms = array_unique($terms);
        sort($terms);
        return $terms;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultPaymentTerm(?int $storeId = null): int
    {
        $terms = $this->getAllBuyerTerms($storeId);
        $default = (int)$this->getConfig($this->path('default_payment_term'), $storeId);
        // Only honour the configured default if it's actually an available
        // buyer term. Otherwise fall back to the lowest available term so the
        // buyer always lands on a real, selectable term — in particular a
        // single available term is always the default (and thus preselected),
        // even if a stale default_payment_term points elsewhere (ABN-439).
        if ($default > 0 && in_array($default, $terms, true)) {
            return $default;
        }
        return $terms ? min($terms) : 30;
    }

    /**
     * @inheritDoc
     */
    public function getSurchargeType(?int $storeId = null): string
    {
        return (string)$this->getConfig($this->path('surcharge_type'), $storeId) ?: 'none';
    }

    /**
     * @inheritDoc
     */
    public function isSurchargeDifferential(?int $storeId = null): bool
    {
        return $this->isSetFlag($this->path('surcharge_differential'), $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getSurchargeLineDescription(?int $storeId = null): string
    {
        return (string)$this->getConfig($this->path('surcharge_line_description'), $storeId)
            ?: 'Payment terms fee - %1 days';
    }

    /**
     * @inheritDoc
     */
    public function getSurchargeTaxRate(?int $storeId = null): float
    {
        $configured = $this->getConfig($this->path('surcharge_tax_rate'), $storeId);
        if ($configured !== null && $configured !== '') {
            return (float)$configured;
        }
        return $this->getDefaultTaxRate($storeId);
    }

    /**
     * Look up the store's default tax rate from Magento's tax rules.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultTaxRate(?int $storeId = null): float
    {
        $productTaxClassId = (int)$this->getConfig(self::XML_PATH_DEFAULT_PRODUCT_TAX_CLASS, $storeId);
        if ($productTaxClassId <= 0) {
            return 0.0;
        }
        $request = $this->taxCalculation->getRateRequest(null, null, null, $storeId);
        $request->setProductClassId($productTaxClassId);
        return (float)$this->taxCalculation->getRate($request);
    }

    /**
     * @inheritDoc
     */
    public function getSurchargeConfig(int $days, ?int $storeId = null): array
    {
        $prefix = sprintf('payment/%s/surcharge_%d_', $this->code(), $days);
        $limitValue = $this->getConfig($prefix . 'limit', $storeId);
        return [
            'percentage' => (float)$this->getConfig($prefix . 'percentage', $storeId),
            'fixed' => (float)$this->getConfig($prefix . 'fixed', $storeId),
            'limit' => $limitValue !== null ? (float)$limitValue : null,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getSurchargeFixedCurrency(?int $storeId = null): string
    {
        return (string)$this->getConfig($this->path('surcharge_fixed_currency'), $storeId);
    }
}
