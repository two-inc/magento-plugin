<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Block\Adminhtml\System\Config\Button;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the Diagnostics "Refresh merchant profile" button, which refetches
 * the cached merchant record for the scope being edited.
 */
class RefreshMerchantRecord extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Two_Gateway::system/config/button/refresh-merchant-record.phtml';

    public function getRefreshUrl(): string
    {
        return $this->getUrl('two/config/refreshMerchantRecord');
    }

    /** Read off the config form block, not the element's fieldset form, which has no scope. */
    public function getScope(): string
    {
        $form = $this->getForm();
        $scope = $form ? (string)$form->getScope() : '';

        return $scope !== '' ? $scope : 'default';
    }

    public function getScopeId(): int
    {
        $form = $this->getForm();

        return $form ? (int)$form->getScopeId() : 0;
    }

    /**
     * @inheritDoc
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * @inheritDoc
     */
    public function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }
}
