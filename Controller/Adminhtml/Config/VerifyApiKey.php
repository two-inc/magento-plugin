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
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\AdminScope;
use Two\Gateway\Model\Config\EnvironmentMode;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\ApiKeyStatusMessage;
use Two\Gateway\Service\Merchant\CredentialsSave;

/**
 * AJAX endpoint behind the admin settings page's live API-key check.
 *
 * Verifies a CANDIDATE key that has not been saved, so an admin learns the
 * key is wrong before the section save rather than after it. The candidate
 * is never echoed back and never cached. On success the response carries the
 * merchant id and short name the key resolves to, for display only.
 *
 * With `stored` set and no key typed, it verifies the key saved for the scope
 * instead, so switching the environment shows whether the saved key works
 * there (TWO-27303). That key is read here and never leaves the server.
 *
 * `key_inherit` and `mode_inherit` say the field's "Use Default" box is
 * ticked. The check then uses the parent scope's key or environment, as the
 * save does, not the one saved at this scope.
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

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var CredentialsSave
     */
    private $credentialsSave;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        ApiKeyStatus $apiKeyStatus,
        ApiKeyStatusMessage $statusMessage,
        ConfigRepository $configRepository,
        CredentialsSave $credentialsSave
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->statusMessage = $statusMessage;
        $this->configRepository = $configRepository;
        $this->credentialsSave = $credentialsSave;
    }

    /**
     * @return ResponseInterface|Json|ResultInterface
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $request = $this->getRequest();
        [$scopeId, $scope] = AdminScope::fromScope(
            (string)$request->getParam('scope', 'default'),
            $request->getParam('scopeId', 0)
        );
        // At the default scope there is no parent, so no box to tick.
        $parent = $this->credentialsSave->parentScope($scope, $scopeId);
        $keyInherited = $parent !== null && $request->getParam('key_inherit');
        $modeInherited = $parent !== null && $request->getParam('mode_inherit');
        $apiKey = trim((string)$request->getParam('api_key', ''));
        // An inherited key is the parent's, never the field's.
        $stored = $keyInherited || ($apiKey === '' && $request->getParam('stored'));
        if ($stored) {
            // The key the save would keep, read here: inherited, or saved at this scope.
            $apiKey = trim($keyInherited
                ? $this->configRepository->getApiKey(self::configId($parent[1]), $parent[0])
                : $this->configRepository->getApiKey($scopeId, $scope));
            if ($apiKey === '') {
                return $result->setData(['skipped' => true]);
            }
        } elseif (strlen($apiKey) < self::MIN_KEY_LENGTH) {
            return $result->setData(['skipped' => true]);
        }
        $mode = $modeInherited
            ? $this->configRepository->getStoredMode(self::configId($parent[1]), $parent[0])
            : $this->postedMode($scopeId, $scope);
        $status = $this->apiKeyStatus->verifyCandidate($apiKey, $scopeId, $mode, $scope);
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

    /**
     * @param int|string|null $id
     */
    private static function configId($id): ?int
    {
        return $id === null ? null : (int)$id;
    }

    /**
     * The environment chosen on the form, which may not be saved yet
     * (TWO-27303). Switching environment and pasting that environment's key
     * is one admin action, so checking against the saved environment rejects
     * a good key. Only a mode the dropdown offers at this scope, which is
     * what the save accepts, is used; otherwise the saved environment applies.
     */
    private function postedMode(?int $scopeId, string $scope): ?string
    {
        $mode = $this->getRequest()->getParam('mode');
        $stored = $this->configRepository->getStoredMode($scopeId, $scope);

        return EnvironmentMode::isSaveAllowed($mode, $stored) ? $mode : null;
    }
}
