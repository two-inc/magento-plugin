<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Model\Config\EnvironmentMode;
use Two\Gateway\Service\Merchant\CredentialsSave;

/**
 * Refuses an Environment the dropdown does not offer (TWO-26604):
 * production and sandbox always, staging only on Two's infrastructure or
 * when staging is already stored at the scope being saved.
 *
 * A field-level skip, not an exception, as the WooCommerce plugin refuses
 * it: the stored value stays, every sibling field still saves, and the
 * merchant is told why. An exception would roll back the whole section.
 *
 * The environment and the API key are saved as one pair or not at all
 * (TWO-27108, {@see CredentialsSave}): a new environment is written only when
 * the key it will be used with is accepted, or could not be judged and the
 * stored pair is not known good.
 */
class Mode extends Value
{
    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @var CredentialsSave
     */
    private $credentialsSave;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        MessageManager $messageManager,
        CredentialsSave $credentialsSave,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
        $this->messageManager = $messageManager;
        $this->credentialsSave = $credentialsSave;
    }

    /**
     * @return $this
     */
    public function beforeSave()
    {
        // "Use Default" retires the row rather than storing anything.
        if ($this->getData('inherit') || $this->getValue() === null) {
            return parent::beforeSave();
        }
        if (!EnvironmentMode::isSaveAllowed($this->getValue(), $this->getOldValue())) {
            $this->_dataSaveAllowed = false;
            $this->messageManager->addErrorMessage(__('Select a valid environment.'));
            return $this;
        }
        $key = $this->getFieldsetDataValue('api_key');
        $outcome = $this->credentialsSave->decide(
            ApiKey::siblingPath((string)$this->getPath(), 'api_key'),
            (string)$this->getPath(),
            (string)($this->getScope() ?: 'default'),
            $this->getScopeId(),
            is_string($key) ? $key : '',
            (string)$this->getValue()
        );
        if (!CredentialsSave::writesEnvironment($outcome)) {
            $this->_dataSaveAllowed = false;
            return $this;
        }
        return parent::beforeSave();
    }
}
