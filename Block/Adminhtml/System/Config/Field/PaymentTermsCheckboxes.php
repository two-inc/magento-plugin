<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Renders payment terms as individual checkboxes instead of a multiselect.
 *
 * Stores the selected terms as a comma-separated string in the existing
 * config path (payment/two_payment/payment_terms).
 */
class PaymentTermsCheckboxes extends Field
{
    /** @var string */
    protected $_template = 'Two_Gateway::system/config/field/payment-terms-checkboxes.phtml';

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
        return ConfigRepository::AVAILABLE_PAYMENT_TERMS;
    }

    /**
     * Get the currently selected terms (from saved config).
     */
    public function getSelectedTerms(): array
    {
        $element = $this->getData('element');
        $value = $element ? (string)$element->getValue() : '';
        return array_filter(array_map('intval', explode(',', $value)));
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
}
