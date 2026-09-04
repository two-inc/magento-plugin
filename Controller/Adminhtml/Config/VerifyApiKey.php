<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Adminhtml\Config;

use Magento\Backend\App\Action;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\ScopeInterface;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * AJAX endpoint behind the admin settings page's live API-key check.
 *
 * Verifies a CANDIDATE key that has not been saved, so an admin learns the
 * key is wrong before the section save rather than after it. The candidate
 * is never echoed back and never cached.
 */
class VerifyApiKey extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::config_sales';

    /**
     * A cheap "don't burn a round trip on a half-typed key" floor, not a
     * format contract — no key length is guaranteed by the API.
     */
    public const MIN_KEY_LENGTH = 20;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var ApiKeyStatus
     */
    private $apiKeyStatus;

    /**
     * @var ApiKeyStatusMessage
     */
    private $statusMessage;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        ApiKeyStatus $apiKeyStatus,
        ApiKeyStatusMessage $statusMessage
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->statusMessage = $statusMessage;
    }

    /**
     * @return ResponseInterface|Json|ResultInterface
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $apiKey = trim((string)$this->getRequest()->getParam('api_key', ''));
        if (strlen($apiKey) < self::MIN_KEY_LENGTH) {
            return $result->setData(['skipped' => true]);
        }

        $status = $this->apiKeyStatus->verifyCandidate($apiKey, $this->resolveStoreId());
        $described = $this->statusMessage->describe($status);

        return $result->setData([
            'verified' => $status['status'] === ApiKeyStatus::OK,
            'status' => $described['status'],
            'message' => (string)$described['message'],
        ]);
    }

    /**
     * The store id only selects which environment the candidate is verified
     * against — the key itself comes from the request, not from config — so
     * a website-scope check is left on the default scope's environment
     * rather than hopping to the website's default store.
     */
    private function resolveStoreId(): ?int
    {
        if ((string)$this->getRequest()->getParam('scope', 'default') !== ScopeInterface::SCOPE_STORES) {
            return null;
        }
        $scopeId = (int)$this->getRequest()->getParam('scopeId', 0);

        return $scopeId > 0 ? $scopeId : null;
    }
}
