<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the deprecated custom term as keep-or-remove, so the only edit the merchant is
 * offered is the only one the save accepts (ABN-522).
 */
class PaymentTermsCustomDays extends Field
{
    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $stored = trim((string)$element->getValue());
        $options = $stored === ''
            ? [['', __('Remove')]]
            : [[$stored, __('%1 days', $stored)], ['', __('Remove')]];

        $optionsHtml = '';
        foreach ($options as [$value, $label]) {
            $optionsHtml .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->escapeHtmlAttr($value),
                $value === $stored ? ' selected="selected"' : '',
                $this->escapeHtml((string)$label)
            );
        }

        return sprintf(
            '<select id="%s" name="%s" class="select"%s>%s</select>',
            $this->escapeHtmlAttr((string)$element->getHtmlId()),
            $this->escapeHtmlAttr((string)$element->getName()),
            $element->getDisabled() ? ' disabled="disabled"' : '',
            $optionsHtml
        );
    }
}
