<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Two\Gateway\Model\Config\Backend\CustomHeaders as CustomHeadersBackend;

class CustomHeaders extends AbstractFieldArray
{
    /**
     * @inheritDoc
     */
    protected function _prepareToRender()
    {
        $this->addColumn('name', ['label' => __('Header name'), 'class' => 'input-text']);
        $this->addColumn('value', ['label' => __('Header value'), 'class' => 'input-text']);
        $this->addColumn('send_from_browser', [
            'label' => __('Also send from browser'),
            'renderer' => $this->getLayout()->createBlock(CustomHeaderBrowserCheckbox::class),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add header');
    }

    /**
     * Core runs the backend model's afterLoad() only for a value it read from
     * the database, so a value locked by `app:config:dump` arrives here as the
     * raw stored string and would render an empty table.
     *
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        if (!is_array($element->getValue())) {
            $element->setValue(array_map(
                [CustomHeadersBackend::class, 'normaliseRow'],
                CustomHeadersBackend::decode((string)$element->getValue())
            ));
        }

        return parent::_getElementHtml($element);
    }
}
