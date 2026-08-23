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
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Store\Model\ScopeInterface;
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

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        ApiKeyStatus $apiKeyStatus,
        ApiKeyStatusMessage $statusMessage,
        ?AbstractResource $resource = null,
        $resourceCollection = null,
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
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException when the submitted key is rejected upstream.
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

        $result = $this->apiKeyStatus->verifyCandidate($candidate, $this->resolveStoreId());

        // ONLY a definitive upstream rejection aborts the save. An unreachable
        // or erroring service cannot be told apart from a bad key, and blocking
        // on it would stop a merchant configuring their first key during an
        // outage — a worse failure than accepting a key we could not confirm.
        if ($result['status'] === ApiKeyStatus::INVALID_KEY) {
            throw new LocalizedException($this->statusMessage->describe($result)['message']);
        }

        parent::beforeSave();
    }

    /**
     * Store scope of the field being saved. Website scope is not mapped to
     * its default store: the store id only selects which environment the
     * candidate is verified against, and the website's own environment
     * override is not reachable without a StoreManager hop this does not
     * otherwise need.
     *
     * Both spellings of store scope are accepted — the config layer uses the
     * plural form on save and the singular one when reading values back.
     */
    private function resolveStoreId(): ?int
    {
        $scope = (string)$this->getScope();
        if ($scope !== ScopeInterface::SCOPE_STORES && $scope !== ScopeInterface::SCOPE_STORE) {
            return null;
        }
        $scopeId = (int)$this->getScopeId();

        return $scopeId > 0 ? $scopeId : null;
    }
}
