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
use Two\Gateway\Service\Locale\AdminDecimalFormatter;

/**
 * Renders payment terms as individual checkboxes instead of a multiselect.
 *
 * Stores the selected terms as a comma-separated string at whatever config
 * path the field's `<config_path>` element in `system.xml` binds. The block
 * is path-agnostic — it reads the value via `$element->getValue()` and the
 * System Config framework supplies the bound element. Brand overlays vary
 * the path via their own `system.xml` without needing to touch this block.
 */
class PaymentTermsCheckboxes extends Field
{
    /** @var string */
    protected $_template = 'Two_Gateway::system/config/field/payment-terms-checkboxes.phtml';

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var AdminDecimalFormatter */
    private $decimalFormatter;

    public function __construct(
        Context $context,
        BrandRegistryInterface $brandRegistry,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        AdminDecimalFormatter $decimalFormatter,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->brandRegistry = $brandRegistry;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->decimalFormatter = $decimalFormatter;
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->setData('element', $element);
        return $this->_toHtml();
    }

    /**
     * Get available payment terms from the constant.
     */
    public function getAvailableTerms(): array
    {
        return $this->brandRegistry->getAvailablePaymentTerms();
    }

    /**
     * Get the currently selected terms (from saved config).
     */
    public function getSelectedTerms(): array
    {
        $element = $this->getData('element');
        $value = $element ? (string)$element->getValue() : '';
        $selected = array_values(array_filter(array_map('intval', explode(',', $value))));
        if (count($selected) === 0) {
            // Nothing stored: prepopulate the shortest available term so the form
            // never loads with no selection (a selection is mandatory on save).
            $available = array_map('intval', $this->getAvailableTerms());
            sort($available);
            if (count($available) > 0) {
                $selected = [$available[0]];
            }
        }
        return $selected;
    }

    /**
     * Get the HTML field name for the checkboxes (array format).
     */
    public function getFieldName(): string
    {
        $element = $this->getData('element');
        return $element ? $element->getName() . '[]' : '';
    }

    /**
     * Get the element HTML ID (for "Use Default" checkbox targeting).
     */
    public function getElementId(): string
    {
        $element = $this->getData('element');
        return $element ? $element->getHtmlId() : '';
    }

    /**
     * Whether to render an inline merchant-fee span beside each
     * checkbox. Brand overlays return false to hide.
     */
    public function showInlineFees(): bool
    {
        return $this->brandRegistry->getInlineTermFees();
    }

    /**
     * Admin URL the inline-fees JS hits to fetch merchant fees.
     */
    public function getFeesUrl(): string
    {
        return $this->getUrl('two/config/fees');
    }

    /**
     * Current Configuration scope (default / websites / stores).
     */
    public function getScope(): string
    {
        $element = $this->getData('element');
        if ($element) {
            $form = $element->getForm();
            if ($form) {
                $scope = (string)$form->getScope();
                if ($scope !== '') {
                    return $scope;
                }
            }
        }
        return 'default';
    }

    public function getScopeId(): int
    {
        $element = $this->getData('element');
        if ($element) {
            $form = $element->getForm();
            if ($form) {
                return (int)$form->getScopeId();
            }
        }
        return 0;
    }

    /**
     * Base currency code of the active scope. The Fees controller
     * returns amounts in the merchant's contractual currency; JS
     * appends a degraded-currency suffix when they differ.
     */
    /**
     * Decimal separator for the active admin locale, emitted as a
     * data attribute on the container so the inline-fees JS can
     * render fetched amounts with the matching separator.
     */
    public function getDecimalSeparator(): string
    {
        return $this->decimalFormatter->getSeparator();
    }

    public function getBaseCurrency(): string
    {
        try {
            $scope = $this->getScope();
            $scopeId = $this->getScopeId();
            if ($scopeId > 0) {
                if ($scope === 'stores') {
                    return (string)$this->storeManager->getStore($scopeId)->getBaseCurrencyCode();
                }
                if ($scope === 'websites') {
                    return (string)$this->storeManager->getWebsite($scopeId)->getBaseCurrencyCode();
                }
            }
            return (string)($this->scopeConfig->getValue('currency/options/base') ?: 'EUR');
        } catch (\Exception $e) {
            return '';
        }
    }
}
