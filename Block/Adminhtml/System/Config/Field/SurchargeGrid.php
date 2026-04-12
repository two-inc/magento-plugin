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
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

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

    /** @var string */
    private $scope = 'default';

    /** @var int */
    private $scopeId = 0;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->scopeConfig = $scopeConfig;
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
        $selected = $this->getConfigValue(ConfigRepository::XML_PATH_PAYMENT_TERMS);
        $terms = array_filter(array_map('intval', explode(',', (string)$selected)));

        $custom = (int)$this->getConfigValue(ConfigRepository::XML_PATH_PAYMENT_TERMS_DURATION_DAYS);
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
        $path = sprintf('payment/two_payment/surcharge_%d_%s', $days, $field);
        $value = $this->getConfigValue($path);
        return $value !== null ? (string)$value : '';
    }

    /**
     * Get the default payment term (for differential mode highlighting).
     */
    public function getDefaultTerm(): int
    {
        return (int)$this->getConfigValue(ConfigRepository::XML_PATH_DEFAULT_PAYMENT_TERM);
    }

    /**
     * Get the surcharge type (none, percentage, fixed, fixed_and_percentage).
     */
    public function getSurchargeType(): string
    {
        return (string)$this->getConfigValue(ConfigRepository::XML_PATH_SURCHARGE_TYPE);
    }

    public function getMaxFixed(): int
    {
        return ConfigRepository::SURCHARGE_FIXED_MAX;
    }

    public function getMaxPercentage(): int
    {
        return ConfigRepository::SURCHARGE_PERCENTAGE_MAX;
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
        $path = sprintf('payment/two_payment/surcharge_%d_%s', $days, $field);
        // Check if a value exists at this specific scope
        $value = $this->scopeConfig->getValue($path, $this->scope, $this->scopeId);
        $defaultValue = $this->scopeConfig->getValue($path);
        // If the scope-specific value equals the default, it's likely inherited
        // (Magento doesn't expose "is this overridden" directly for system config)
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
        return ConfigRepository::AVAILABLE_PAYMENT_TERMS;
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
}
