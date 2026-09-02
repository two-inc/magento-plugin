<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * The custom outbound HTTP header table: any number of admin-named headers,
 * each optionally also sent on the one call the browser makes directly to the
 * API.
 */
class CustomHeaders extends AbstractFieldArray
{
    /**
     * @var CustomHeaderBrowserCheckbox|null
     */
    private $browserCheckbox;

    /**
     * @inheritDoc
     */
    protected function _prepareToRender()
    {
        $this->addColumn('name', ['label' => __('Header name'), 'class' => 'input-text']);
        $this->addColumn('value', ['label' => __('Header value'), 'class' => 'input-text']);
        $this->addColumn('send_from_browser', [
            'label' => __('Also send from browser'),
            'renderer' => $this->browserCheckbox(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add header');
    }

    private function browserCheckbox(): CustomHeaderBrowserCheckbox
    {
        if ($this->browserCheckbox === null) {
            /** @var CustomHeaderBrowserCheckbox $block */
            $block = $this->getLayout()->createBlock(CustomHeaderBrowserCheckbox::class);
            $this->browserCheckbox = $block;
        }

        return $this->browserCheckbox;
    }
}
