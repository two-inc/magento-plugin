<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

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

    public function __construct(
        Context $context,
        BrandRegistryInterface $brandRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->brandRegistry = $brandRegistry;
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
