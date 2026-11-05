<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Merchant;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
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
     * @var array<string, string> outcome per scope and config group, for this request
     */
    private $decided = [];

    public function __construct(
        ApiKeyStatus $apiKeyStatus,
        ApiKeyStatusMessage $statusMessage,
        MessageManager $messageManager,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
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
     */
    public function decide(
        string $keyPath,
        string $modePath,
        string $scope,
        $scopeId,
        string $submittedKey,
        ?string $submittedMode
    ): string {
        $memo = $scope . '|' . (string)$scopeId . '|' . $keyPath;
        if (!isset($this->decided[$memo])) {
            $this->decided[$memo] = $this->judge($keyPath, $modePath, $scope, $scopeId, $submittedKey, $submittedMode);
        }

        return $this->decided[$memo];
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
        ?string $submittedMode
    ): string {
        $scopeCode = $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $scopeId;
        $storedRaw = $this->scopeConfig->getValue($keyPath, $scope, $scopeCode);
        // Trimmed: a key stored before keys were trimmed on save may carry stray whitespace.
        $storedKey = is_string($storedRaw) && $storedRaw !== '' ? trim((string)$this->encryptor->decrypt($storedRaw)) : '';
        $storedModeRaw = $this->scopeConfig->getValue($modePath, $scope, $scopeCode);
        $storedMode = is_scalar($storedModeRaw) ? (string)$storedModeRaw : '';
        $typed = self::keepsStoredKey(trim($submittedKey)) ? '' : trim($submittedKey);
        $key = $typed !== '' ? $typed : $storedKey;

        if ($submittedMode !== null && !EnvironmentMode::isSaveAllowed($submittedMode, $storedMode)) {
            // The environment field reports its own refusal.
            if ($typed !== '') {
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
