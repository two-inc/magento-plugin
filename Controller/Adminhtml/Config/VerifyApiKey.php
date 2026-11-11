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
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * AJAX endpoint behind the admin settings page's live API-key check.
 *
 * Verifies a CANDIDATE key that has not been saved, so an admin learns the
 * key is wrong before the section save rather than after it. The candidate
 * is never echoed back and never cached. On success the response carries the
 * merchant id and short name the key resolves to, for display only.
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

        [$scopeId, $scope] = AdminScope::fromScope(
            (string)$this->getRequest()->getParam('scope', 'default'),
            $this->getRequest()->getParam('scopeId', 0)
        );
        $status = $this->apiKeyStatus->verifyCandidate($apiKey, $scopeId, null, $scope);
        $described = $this->statusMessage->describe($status);

        $data = [
            'verified' => $status['status'] === ApiKeyStatus::OK,
            'status' => $described['status'],
            'message' => (string)$described['message'],
            // Only a key Two actually rejected should clear the merchant
            // details on screen; an outage or timeout judged nothing about it.
            'definitive' => $status['status'] === ApiKeyStatus::INVALID_KEY
                || $status['status'] === ApiKeyStatus::NOT_CONFIGURED,
        ];
        // The merchant the candidate resolves to, so the settings page can show
        // it straight away. Nothing is saved here; the section save does that.
        if (isset($described['merchant_id'])) {
            $data['merchant_id'] = $described['merchant_id'];
            $data['merchant_short_name'] = $described['merchant_short_name'] ?? '';
        }

        return $result->setData($data);
    }
}
