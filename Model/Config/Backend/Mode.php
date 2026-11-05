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
use Magento\Framework\Exception\LocalizedException;
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
        if (!CredentialsSave::writesEnvironment($this->pairOutcome((string)$this->getValue(), false))) {
            $this->_dataSaveAllowed = false;
            return $this;
        }
        return parent::beforeSave();
    }

    /**
     * "Use Default" on this field deletes its row, without beforeSave(). The
     * pair rule judges that change too, and only an exception stops the
     * delete: it rolls back the whole save of the page, which is the price of
     * never leaving a pair Two did not accept (TWO-27108).
     *
     * @return $this
     * @throws LocalizedException when the stored pair is kept
     */
    public function beforeDelete()
    {
        if (CredentialsSave::keepsPair($this->pairOutcome(null, true))) {
            throw new LocalizedException(__(
                'Nothing on this page was saved: the API key and environment are saved together, and this change would leave a key that does not work with its environment.'
            ));
        }
        return parent::beforeDelete();
    }

    /**
     * @param string|null $mode the environment posted, null when inherited
     */
    private function pairOutcome(?string $mode, bool $inherited): string
    {
        $key = $this->getFieldsetDataValue('api_key');

        return $this->credentialsSave->decide(
            ApiKey::siblingPath((string)$this->getPath(), 'api_key'),
            (string)$this->getPath(),
            (string)($this->getScope() ?: 'default'),
            $this->getScopeId(),
            is_string($key) ? $key : '',
            $mode,
            CredentialsSave::isInherited($this, 'api_key'),
            $inherited
        );
    }
}
