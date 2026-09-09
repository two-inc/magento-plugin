<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;

/**
 * Save-time guard on the API key field (TWO-25503).
 *
 * Verifies the submitted key before it replaces the stored one, so a
 * mistyped or wrong-environment key cannot silently take a working
 * integration offline until someone notices checkout is gone.
 */
class ApiKey extends Encrypted
{
    /**
     * @var ApiKeyStatus
     */
    private $apiKeyStatus;

    /**
     * @var ApiKeyStatusMessage
     */
    private $statusMessage;

    /**
     * @var MessageManager
     */
    private $messageManager;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        ApiKeyStatus $apiKeyStatus,
        ApiKeyStatusMessage $statusMessage,
        MessageManager $messageManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $encryptor,
            $resource,
            $resourceCollection,
            $data
        );
        $this->apiKeyStatus = $apiKeyStatus;
        $this->statusMessage = $statusMessage;
        $this->messageManager = $messageManager;
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $candidate = (string)$this->getValue();

        // The obscured placeholder (all asterisks) and a blank submission both
        // mean the stored key is not being changed, so there is nothing to verify.
        if ($candidate === '' || preg_match('/^\*+$/', $candidate)) {
            parent::beforeSave();
            return;
        }

        [$scopeId, $scope] = AdminScope::fromScope((string)$this->getScope(), $this->getScopeId());
        $result = $this->apiKeyStatus->verifyCandidate(
            $candidate,
            $scopeId,
            $this->submittedMode(),
            $scope
        );

        // ONLY a definitive upstream rejection stops the key being written. An
        // unreachable or erroring service cannot be told apart from a bad key,
        // and blocking on it would stop a merchant configuring their first key
        // during an outage — a worse failure than accepting a key we could not
        // confirm.
        //
        // A field-level skip, not an exception: an exception rolls back the whole section (ABN-495).
        if ($result['status'] === ApiKeyStatus::INVALID_KEY) {
            $this->_dataSaveAllowed = false;
            $this->messageManager->addErrorMessage($this->statusMessage->describe($result)['message']);

            return;
        }

        parent::beforeSave();
    }

    /**
     * The `mode` value being saved alongside this key, or NULL when the same
     * request is not setting one.
     *
     * Switching environment and pasting that environment's key is ONE admin
     * action, and the committed mode is still the old one while this runs —
     * verifying against it rejects a perfectly good key and fails the whole
     * section save. `mode` is a sibling field in the same group
     * (two_general/general), so the submitted value is on this model.
     */
    private function submittedMode(): ?string
    {
        $mode = $this->getFieldsetDataValue('mode');

        return is_string($mode) && $mode !== '' ? $mode : null;
    }
}
