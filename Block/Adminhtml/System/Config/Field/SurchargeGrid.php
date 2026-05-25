<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\CurrencyRatesProviderInterface;

/**
 * Renders a grid of surcharge inputs (fixed, percentage, limit) per payment term.
 *
 * Replaces the individual per-term surcharge fields with a compact table.
 * Reads available terms from the multiselect + custom duration config,
 * and the limits from RepositoryInterface constants (fork-friendly).
 */
class SurchargeGrid extends Field
{
    /** @var string */
    protected $_template = 'Two_Gateway::system/config/field/surcharge-grid.phtml';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var CurrencyRatesProviderInterface */
    private $ratesProvider;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var string */
    private $scope = 'default';

    /** @var int */
    private $scopeId = 0;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        CurrencyRatesProviderInterface $ratesProvider,
        BrandRegistryInterface $brandRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->ratesProvider = $ratesProvider;
        $this->brandRegistry = $brandRegistry;
    }

    /**
     * Active payment-method code. Resolved at call time from the
     * brand registry so the same block works for every brand without
     * a per-brand DI rebinding.
     */
    private function methodCode(): string
    {
        return $this->brandRegistry->getCode();
    }

    /**
     * @inheritDoc
     */
    public function render(AbstractElement $element): string
    {
        $this->resolveScope($element);
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Get the sorted list of active payment terms (standard + custom).
     */
    public function getActiveTerms(): array
    {
        $selected = $this->getConfigValue($this->path('payment_terms'));
        $terms = array_filter(array_map('intval', explode(',', (string)$selected)));

        $custom = (int)$this->getConfigValue($this->path('payment_terms_duration_days'));
        if ($custom > 0) {
            $terms[] = $custom;
        }

        $terms = array_unique($terms);
        sort($terms);
        return array_values($terms);
    }

    /**
     * Get the saved surcharge value for a given term and field.
     */
    public function getSavedValue(int $days, string $field): string
    {
        $value = $this->getConfigValue($this->path(sprintf('surcharge_%d_%s', $days, $field)));
        return $value !== null ? (string)$value : '';
    }

    /**
     * Get the default payment term (for differential mode highlighting).
     */
    public function getDefaultTerm(): int
    {
        return (int)$this->getConfigValue($this->path('default_payment_term'));
    }

    /**
     * Get the surcharge type (none, percentage, fixed, fixed_and_percentage).
     */
    public function getSurchargeType(): string
    {
        return (string)$this->getConfigValue($this->path('surcharge_type'));
    }

    /**
     * Maximum fixed-amount surcharge in the merchant's base currency,
     * or null when the brand imposes no upper bound. The template and
     * JS treat null as "no max" and skip the range validation.
     */
    public function getMaxFixed(): ?int
    {
        $limit = $this->brandRegistry->getSurchargeFixedMax();
        if ($limit === null) {
            return null;
        }
        $limitAmount = (int)$limit['amount'];
        $limitCurrency = $limit['currency'];
        $baseCurrency = $this->getBaseCurrencyCode();

        if ($baseCurrency === $limitCurrency) {
            return $limitAmount;
        }

        $converted = $this->convertAmount((float)$limitAmount, $limitCurrency, $baseCurrency);
        return $converted > 0 ? (int)ceil($converted) : $limitAmount;
    }

    public function getMaxPercentage(): int
    {
        return ConfigRepository::SURCHARGE_PERCENTAGE_MAX;
    }

    /**
     * Get the base currency code for the current scope.
     */
    public function getBaseCurrencyCode(): string
    {
        if ($this->scope !== 'default' && $this->scopeId > 0) {
            try {
                if ($this->scope === 'stores') {
                    return $this->storeManager->getStore($this->scopeId)->getBaseCurrencyCode();
                }
                if ($this->scope === 'websites') {
                    return $this->storeManager->getWebsite($this->scopeId)->getBaseCurrencyCode();
                }
            } catch (\Exception $e) {
                // Fall through to default
            }
        }
        return (string)$this->scopeConfig->getValue('currency/options/base') ?: 'EUR';
    }

    /**
     * Get the base currency symbol (e.g. "€", "$") for the current scope.
     * Falls back to the currency code if the symbol isn't resolvable.
     */
    public function getBaseCurrencySymbol(): string
    {
        $code = $this->getBaseCurrencyCode();
        try {
            $store = ($this->scope === 'stores' && $this->scopeId > 0)
                ? $this->storeManager->getStore($this->scopeId)
                : $this->storeManager->getStore();
            $symbol = (string)$store->getBaseCurrency()->getCurrencySymbol();
            return $symbol !== '' ? $symbol : $code;
        } catch (\Exception $e) {
            return $code;
        }
    }

    /**
     * Fixed-fee limit label (e.g. "EUR 25" or "USD 28 (EUR 25)").
     * Empty when the brand imposes no upper bound — there is nothing
     * meaningful to display.
     */
    public function getFixedLimitLabel(): string
    {
        $limit = $this->brandRegistry->getSurchargeFixedMax();
        if ($limit === null) {
            return '';
        }
        $limitAmount = $limit['amount'];
        $limitCurrency = $limit['currency'];
        $baseCurrency = $this->getBaseCurrencyCode();

        if ($baseCurrency === $limitCurrency) {
            return $limitCurrency . ' ' . $limitAmount;
        }

        $converted = $this->convertAmount((float)$limitAmount, $limitCurrency, $baseCurrency);
        if ($converted > 0) {
            $precision = $this->getCurrencyPrecision($baseCurrency);
            $factor = pow(10, $precision);
            $convertedMax = ceil($converted * $factor) / $factor;
            $formatted = number_format($convertedMax, $precision, '.', '');
            return $baseCurrency . ' ' . $formatted . ' (' . $limitCurrency . ' ' . $limitAmount . ')';
        }

        return $limitCurrency . ' ' . $limitAmount;
    }

    /**
     * Get the percentage limit label, e.g. "100%".
     */
    public function getPercentageLimitLabel(): string
    {
        return ConfigRepository::SURCHARGE_PERCENTAGE_MAX . '%';
    }

    /**
     * Warning shown when the brand's fixed-fee limit currency differs
     * from the merchant's base currency and no FX rate is configured.
     * Empty when the brand has no limit at all (nothing to enforce).
     */
    public function getCurrencyWarning(): string
    {
        $limit = $this->brandRegistry->getSurchargeFixedMax();
        if ($limit === null) {
            return '';
        }
        $limitAmount = $limit['amount'];
        $limitCurrency = $limit['currency'];
        $baseCurrency = $this->getBaseCurrencyCode();

        if ($baseCurrency === $limitCurrency) {
            return '';
        }

        if ($this->hasExchangeRate($limitCurrency, $baseCurrency)) {
            return '';
        }

        return (string)__(
            'Warning: The fixed fee limit of %1 %2 cannot be enforced correctly because no exchange rate is '
            . 'configured from %3 to %4. Configure exchange rates in Stores → Currency → Currency Rates.',
            $limitCurrency,
            $limitAmount,
            $limitCurrency,
            $baseCurrency
        );
    }

    /**
     * Convert an amount from one currency to another. Rate lookup is routed
     * through the service contract so all cross-rates resolve via the base
     * currency's rate table.
     */
    private function convertAmount(float $amount, string $from, string $to): float
    {
        if ($from === $to) {
            return $amount;
        }
        $rate = $this->ratesProvider->getRate($from, $to, (int)$this->scopeId ?: null);
        return $rate !== null ? $amount * $rate : 0.0;
    }

    /**
     * Check if an exchange rate exists between the limit currency and base currency.
     */
    private function hasExchangeRate(string $from, string $to): bool
    {
        return $this->convertAmount(1.0, $from, $to) > 0;
    }

    /**
     * Get the number of decimal places for a currency (e.g. 2 for USD/EUR, 0 for JPY).
     */
    private function getCurrencyPrecision(string $code): int
    {
        try {
            $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
            $fmt->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $code);
            return (int)$fmt->getAttribute(\NumberFormatter::FRACTION_DIGITS);
        } catch (\Exception $e) {
            return 2;
        }
    }

    /**
     * Get the HTML field name for a surcharge input.
     *
     * Nests under the surcharge_grid field's value so the backend model receives it:
     * groups[payment_terms][fields][surcharge_grid][value][{days}][{field}]
     */
    public function getFieldName(int $days, string $field): string
    {
        return sprintf(
            'groups[payment_terms][fields][surcharge_grid][value][%d][%s]',
            $days,
            $field
        );
    }

    /**
     * Get the "inherit" checkbox name for scope override.
     */
    public function getInheritName(int $days, string $field): string
    {
        return sprintf(
            'groups[payment_terms][fields][surcharge_grid][inherit][%d][%s]',
            $days,
            $field
        );
    }

    /**
     * Check if a field is using the inherited (default/website) value at current scope.
     */
    public function isInherited(int $days, string $field): bool
    {
        if ($this->scope === 'default') {
            return false;
        }
        $path = $this->path(sprintf('surcharge_%d_%s', $days, $field));
        $value = $this->scopeConfig->getValue($path, $this->scope, $this->scopeId);
        $defaultValue = $this->scopeConfig->getValue($path);
        return $value === $defaultValue;
    }

    /**
     * Whether we're at a non-default scope (website or store).
     */
    public function isNonDefaultScope(): bool
    {
        return $this->scope !== 'default';
    }

    /**
     * Available term constants (for JS to know which terms are standard).
     */
    public function getAvailablePaymentTerms(): array
    {
        return $this->brandRegistry->getAvailablePaymentTerms();
    }

    /**
     * Admin URL the grid's JS hits to fetch merchant fees.
     */
    public function getFeesUrl(): string
    {
        return $this->getUrl('two/config/fees');
    }

    /**
     * Current scope for the Fees request, so the controller can resolve
     * the right API key when the merchant has per-scope credentials.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    public function getScopeId(): int
    {
        return $this->scopeId;
    }

    private function resolveScope(AbstractElement $element): void
    {
        $form = $element->getForm();
        if ($form) {
            $scope = (string)$form->getScope();
            $this->scope = ($scope !== '') ? $scope : 'default';
            $this->scopeId = (int)$form->getScopeId();
        }
    }

    private function getConfigValue(string $path)
    {
        if ($this->scope !== 'default') {
            return $this->scopeConfig->getValue($path, $this->scope, $this->scopeId);
        }
        return $this->scopeConfig->getValue($path);
    }

    /**
     * Build a fully-qualified config path under the active brand's
     * payment-method subtree (e.g. `payment/abn_payment/...` on an
     * ABN install). The brand code is resolved at call time from
     * BrandRegistryInterface, which routes through ActiveBrandResolver
     * to the active brand's brand.xml — no per-brand DI rebinding.
     */
    private function path(string $suffix): string
    {
        return 'payment/' . $this->methodCode() . '/' . $suffix;
    }
}
