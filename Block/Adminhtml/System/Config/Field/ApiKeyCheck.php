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
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * Renders the `api_key` field itself with its live verification result
 * inline (icon + status), matching woocommerce-plugin's and
 * prestashop-plugin's pattern — there is no separate "check" button
 * field.
 *
 * Verification at page load is deliberately a LIVE check
 * (ApiKeyStatus::refresh) rather than a cached read: an admin on this page
 * is asking about the key in front of them right now. refresh() also
 * writes its result forward into the shared cache, so a key corrected on
 * this page takes effect at checkout immediately instead of after the
 * cache TTL expires.
 *
 * Wording comes from {@see ApiKeyStatusMessage}, shared with the live
 * verification endpoint and the save-time guard.
 */
class ApiKeyCheck extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Two_Gateway::system/config/field/apikey.phtml';

    /**
     * @var ApiKeyStatus
     */
    private $apiKeyStatus;

    /**
     * @var ApiKeyStatusMessage
     */
    private $statusMessage;

    /**
     * @param ApiKeyStatus $apiKeyStatus
     * @param ApiKeyStatusMessage $statusMessage
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        ApiKeyStatus $apiKeyStatus,
        ApiKeyStatusMessage $statusMessage,
        Context $context,
        array $data = []
    ) {
        $this->apiKeyStatus = $apiKeyStatus;
        $this->statusMessage = $statusMessage;
        parent::__construct($context, $data);
    }

    /**
     * Verification outcome for the stored API key as
     * ['message' => Phrase, 'status' => 'success'|'warning'|'error'],
     * plus 'merchant_id' and 'merchant_short_name' on success only.
     *
     * @return array
     */
    public function getApiKeyStatus(): array
    {
        return $this->statusMessage->describe($this->apiKeyStatus->refresh());
    }

    /**
     * Endpoint the live verification JS posts a candidate key to.
     */
    public function getVerifyUrl(): string
    {
        return $this->getUrl('two/config/verifyApiKey');
    }

    /**
     * The actual obscure `<input>` markup Magento would have rendered for
     * this field absent this renderer — the verification panel is
     * additional markup around it, not a replacement for it.
     */
    public function getInputHtml(): string
    {
        $element = $this->getData('element');
        return $element ? (string)$element->getElementHtml() : '';
    }

    /**
     * Html id of the API key input this panel reports on — this renderer
     * decorates that field directly.
     */
    public function getApiKeyFieldId(): string
    {
        $element = $this->getData('element');
        return $element ? (string)$element->getHtmlId() : '';
    }

    /**
     * Current Configuration scope (default / websites / stores).
     *
     * Not the element's own form — `addField()` sets that to the fieldset,
     * which carries no scope. The config Form BLOCK is what the renderer
     * itself is bound to (`AbstractForm::setForm($this)` at render time),
     * so `$this->getForm()` is the one with `getScope()`/`getScopeId()`.
     */
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
    public function _getElementHtml(AbstractElement $element)
    {
        $this->setData('element', $element);
        return $this->_toHtml();
    }
}
