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
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Two\Gateway\Service\Merchant\CredentialsSave;

/**
 * Save-time guard on the API key field (TWO-25503, TWO-27108).
 *
 * The key and the environment beside it are saved as one pair or not at all,
 * by the rule in {@see CredentialsSave}: a mistyped, rejected or
 * wrong-environment key cannot take a working integration offline, and a key
 * Two could not judge never replaces one that last verified. An empty field
 * keeps the stored key, which never goes back to the browser.
 */
class ApiKey extends Encrypted
{
    /**
     * @var CredentialsSave
     */
    private $credentialsSave;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        CredentialsSave $credentialsSave,
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
        $this->credentialsSave = $credentialsSave;
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $candidate = trim((string)$this->getValue());

        // A blank submission keeps the stored key (TWO-27108): the field renders
        // empty, so the key never goes back to the browser. An obscured
        // placeholder (all asterisks), from a page rendered before that, means
        // the same. The environment beside it decides whether the stored key
        // is checked against a new environment.
        if (CredentialsSave::keepsStoredKey($candidate)) {
            $this->_dataSaveAllowed = false;
            return;
        }

        // A field-level skip, not an exception: an exception rolls back the whole section.
        $outcome = $this->credentialsSave->decide(
            (string)$this->getPath(),
            self::siblingPath((string)$this->getPath(), 'mode'),
            (string)($this->getScope() ?: 'default'),
            $this->getScopeId(),
            $candidate,
            $this->submittedMode()
        );
        if (!CredentialsSave::writesKey($outcome)) {
            $this->_dataSaveAllowed = false;
            return;
        }

        $this->setValue($candidate);
        parent::beforeSave();
    }

    /** The path of another field in this key's group. */
    public static function siblingPath(string $path, string $field): string
    {
        $slash = strrpos($path, '/');

        return ($slash === false ? '' : substr($path, 0, $slash + 1)) . $field;
    }

    /**
     * The `mode` value being saved alongside this key, or NULL when the same
     * request is not setting one.
     *
     * Switching environment and pasting that environment's key is ONE admin
     * action, and the committed mode is still the old one while this runs:
     * verifying against it rejects a perfectly good key. `mode` is a sibling
     * field in the same group, so the submitted value is on this model.
     */
    private function submittedMode(): ?string
    {
        $mode = $this->getFieldsetDataValue('mode');

        return is_string($mode) && $mode !== '' ? $mode : null;
    }
}
