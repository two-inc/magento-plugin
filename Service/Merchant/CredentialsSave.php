<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Merchant;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\EnvironmentMode;

/**
 * The admin save of the API key and environment (TWO-27108), the same rule as
 * the other plugins.
 *
 * The two are one pair: a key is only good against the environment it was
 * issued for, so they are written together or not at all. A submitted pair
 * replaces the stored one only when it is well formed and:
 * - Two accepts it, or
 * - Two could not judge it (no answer, a 5xx, a malformed reply) and the
 *   stored pair is not known good: its last definitive answer was not ok.
 * A rejected key is never stored, and a key Two could not judge never replaces
 * one that last verified. Every other field on the page still saves, and the
 * merchant is told why the pair was kept.
 *
 * The environment and key backend models each ask, in the same request, and
 * get one answer: the decision is made once per scope, and its message shown
 * once.
 *
 * "Use Default" on either half at a website or store view is a change of that
 * half to the parent scope's value, judged by the same rule. Core deletes an
 * inherited field's row without calling beforeSave(), and only an exception
 * from beforeDelete() stops that delete, so a pair that must be kept refuses
 * the whole save of the page rather than leave a pair Two never accepted.
 */
class CredentialsSave
{
    public const SAVED = 'saved';
    public const SAVED_UNVERIFIED = 'saved_unverified';
    public const UNCHANGED = 'unchanged';
    public const MISSING = 'missing';
    public const KEPT_ENVIRONMENT = 'kept_environment';
    public const KEPT_MALFORMED = 'kept_malformed';
    public const KEPT_REJECTED = 'kept_rejected';
    public const KEPT_UNVERIFIED = 'kept_unverified';

    /** Printable characters, no spaces or line breaks: what may be sent to Two as a key. */
    private const WELL_FORMED_KEY = '/^[\x21-\x7E]+\z/';

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

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var array<string, string> outcome per scope and config group, for this request
     */
    private $decided = [];

    public function __construct(
        ApiKeyStatus $apiKeyStatus,
        ApiKeyStatusMessage $statusMessage,
        MessageManager $messageManager,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        StoreManagerInterface $storeManager
    ) {
        $this->storeManager = $storeManager;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->statusMessage = $statusMessage;
        $this->messageManager = $messageManager;
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /** Whether the environment submitted with this outcome is written. */
    public static function writesEnvironment(string $outcome): bool
    {
        return in_array($outcome, [self::SAVED, self::SAVED_UNVERIFIED, self::UNCHANGED, self::MISSING], true);
    }

    /** Whether the stored pair is kept: neither half may be written or deleted. */
    public static function keepsPair(string $outcome): bool
    {
        return !self::writesEnvironment($outcome);
    }

    /**
     * Whether "Use Default" is ticked for a field of the config group a
     * backend model is saving: core passes the posted groups to every field.
     */
    public static function isInherited(DataObject $model, string $field): bool
    {
        $groups = $model->getData('groups');
        $groupId = $model->getData('group_id');

        return is_array($groups) && is_scalar($groupId)
            && !empty($groups[$groupId]['fields'][$field]['inherit']);
    }

    /** Whether a key typed with this outcome is written. */
    public static function writesKey(string $outcome): bool
    {
        return $outcome === self::SAVED || $outcome === self::SAVED_UNVERIFIED;
    }

    /** An empty field or an all-asterisk placeholder: the stored key is kept. */
    public static function keepsStoredKey(string $submitted): bool
    {
        return $submitted === '' || preg_match('/^\*+$/', $submitted) === 1;
    }

    /**
     * The outcome for the pair submitted at one scope.
     *
     * @param string $keyPath config path of the API key
     * @param string $modePath config path of the environment
     * @param string $scope the config layer's scope type
     * @param int|string|null $scopeId
     * @param string $submittedKey the typed key; empty or asterisks keep the stored key
     * @param string|null $submittedMode null when the form did not post one
     * @param bool $keyInherited "Use Default" ticked for the key
     * @param bool $modeInherited "Use Default" ticked for the environment
     */
    public function decide(
        string $keyPath,
        string $modePath,
        string $scope,
        $scopeId,
        string $submittedKey,
        ?string $submittedMode,
        bool $keyInherited = false,
        bool $modeInherited = false
    ): string {
        $memo = $scope . '|' . (string)$scopeId . '|' . $keyPath;
        if (!isset($this->decided[$memo])) {
            $this->decided[$memo] = $this->judge(
                $keyPath,
                $modePath,
                $scope,
                $scopeId,
                $submittedKey,
                $submittedMode,
                $keyInherited,
                $modeInherited
            );
        }

        return $this->decided[$memo];
    }

    /**
     * The value a field takes from the parent scope when "Use Default" is
     * ticked: a website's from the default scope, a store view's from its
     * website. Null at the default scope, which has no parent.
     *
     * @param int|string|null $scopeId
     * @return mixed
     */
    private function parentValue(string $path, string $scope, $scopeId)
    {
        if (in_array($scope, ['websites', 'website'], true)) {
            return $this->scopeConfig->getValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        }
        if (in_array($scope, ['stores', 'store'], true)) {
            $websiteId = $this->storeManager->getStore((int)$scopeId)->getWebsiteId();
            return $this->scopeConfig->getValue($path, 'websites', $websiteId);
        }

        return null;
    }

    /**
     * @param mixed $raw a stored, encrypted key
     */
    private function decryptKey($raw): string
    {
        // Trimmed: a key stored before keys were trimmed on save may carry stray whitespace.
        return is_string($raw) && $raw !== '' ? trim((string)$this->encryptor->decrypt($raw)) : '';
    }

    /**
     * @param int|string|null $scopeId
     */
    private function judge(
        string $keyPath,
        string $modePath,
        string $scope,
        $scopeId,
        string $submittedKey,
        ?string $submittedMode,
        bool $keyInherited,
        bool $modeInherited
    ): string {
        $scopeCode = $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $scopeId;
        $storedKey = $this->decryptKey($this->scopeConfig->getValue($keyPath, $scope, $scopeCode));
        $storedModeRaw = $this->scopeConfig->getValue($modePath, $scope, $scopeCode);
        $storedMode = is_scalar($storedModeRaw) ? (string)$storedModeRaw : '';
        // An inherited key is the parent's, never typed: Two judges it, not its form.
        $typed = $keyInherited || self::keepsStoredKey(trim($submittedKey)) ? '' : trim($submittedKey);
        if ($keyInherited) {
            $key = $this->decryptKey($this->parentValue($keyPath, $scope, $scopeId));
        } else {
            $key = $typed !== '' ? $typed : $storedKey;
        }
        if ($modeInherited) {
            $parentMode = $this->parentValue($modePath, $scope, $scopeId);
            $submittedMode = is_scalar($parentMode) ? (string)$parentMode : '';
        }

        // An inherited environment is the parent's, already stored: only a posted one is checked.
        if (!$modeInherited && $submittedMode !== null && !EnvironmentMode::isSaveAllowed($submittedMode, $storedMode)) {
            // The environment field reports its own refusal.
            if ($typed !== '' || $keyInherited) {
                $this->messageManager->addErrorMessage(
                    __('The API key was not changed either: it is saved together with its environment.')
                );
            }
            return self::KEPT_ENVIRONMENT;
        }
        if ($key === '') {
            // Nothing stored and nothing typed: no key to protect, so the environment still saves.
            $this->messageManager->addErrorMessage(__('Enter an API key.'));
            return self::MISSING;
        }
        // Only a typed key is checked for its form: a stored one is Two's to judge,
        // so an old stored key never blocks a change of environment.
        if ($typed !== '' && preg_match(self::WELL_FORMED_KEY, $typed) !== 1) {
            $this->messageManager->addErrorMessage(__(
                'The API key may only contain printable characters, with no spaces or line breaks. The previously saved key and environment were kept.'
            ));
            return self::KEPT_MALFORMED;
        }
        $mode = $submittedMode ?? $storedMode;
        if ($key === $storedKey && EnvironmentMode::normalise($mode) === EnvironmentMode::normalise($storedMode)) {
            return self::UNCHANGED;
        }

        [$adminScopeId, $adminScope] = AdminScope::fromScope($scope, $scopeId);
        $checked = $this->apiKeyStatus->verifyCandidate($key, $adminScopeId, $submittedMode, $adminScope);
        if ($checked['status'] === ApiKeyStatus::OK) {
            // Known good from now on, so a later outage cannot swap it out.
            $this->apiKeyStatus->recordDefinitive($key, $mode, ApiKeyStatus::OK);
            return self::SAVED;
        }
        $failure = (string)$this->statusMessage->describe($checked)['message'];
        if ($checked['status'] === ApiKeyStatus::INVALID_KEY) {
            $this->messageManager->addErrorMessage(
                $failure . ' ' . __('The previously saved key and environment were kept.')
            );
            return self::KEPT_REJECTED;
        }
        if ($storedKey !== ''
            && $this->apiKeyStatus->lastDefinitiveStatus($storedKey, $storedMode) === ApiKeyStatus::OK
        ) {
            $this->messageManager->addErrorMessage($failure . ' ' . __(
                'The previously saved key and environment were kept, because they last verified and the new ones could not be checked. Save again once the check succeeds.'
            ));
            return self::KEPT_UNVERIFIED;
        }
        // Nothing known good to protect: a first key during an outage still saves.
        $this->messageManager->addWarningMessage($failure);

        return self::SAVED_UNVERIFIED;
    }
}
