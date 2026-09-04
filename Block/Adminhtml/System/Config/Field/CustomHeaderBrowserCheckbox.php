<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Field;

use Magento\Framework\View\Element\AbstractBlock;

/**
 * The "also send from browser" tick in one custom-header row. An unticked box
 * posts nothing, which the backend model reads as off.
 */
class CustomHeaderBrowserCheckbox extends AbstractBlock
{
    /**
     * @inheritDoc
     */
    protected function _toHtml()
    {
        // No `admin__control-checkbox` class: the admin theme hides that input
        // and draws its paired <label>, which a grid cell has not got. Name and
        // id carry array.phtml's `<%- _id %>` placeholder, so neither can be
        // entity-escaped.
        return sprintf(
            '<input type="checkbox" value="1" id="%s" name="%s"/>',
            $this->getInputId(),
            $this->getInputName()
        );
    }
}
