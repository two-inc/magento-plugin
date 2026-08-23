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
 * Renders the API-key verification result in Stores Configuration, and
 * carries the wiring (endpoint URL, target field id, active scope) the
 * live re-verification JS binds to.
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
     * Html id of the API key input this panel reports on. This renderer is
     * the `api_key_check` sibling of that field, so its own element id
     * minus the suffix names it.
     */
    public function getApiKeyFieldId(): string
    {
        $element = $this->getData('element');
        $id = $element ? (string)$element->getHtmlId() : '';
        return preg_replace('/_check$/', '', $id) ?? '';
    }

    /**
     * Current Configuration scope (default / websites / stores).
     */
    public function getScope(): string
    {
        $element = $this->getData('element');
        $form = $element ? $element->getForm() : null;
        $scope = $form ? (string)$form->getScope() : '';
        return $scope !== '' ? $scope : 'default';
    }

    public function getScopeId(): int
    {
        $element = $this->getData('element');
        $form = $element ? $element->getForm() : null;
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
        $this->setData('element', $element);
        return $this->_toHtml();
    }
}
