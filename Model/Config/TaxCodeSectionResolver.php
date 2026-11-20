<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Config\Backend\TaxCodeMap as TaxCodeMapBackend;
use Two\Gateway\Service\Api\TaxCodes;
use Two\Gateway\Service\Merchant\ApiKeyStatus;
use Two\Gateway\Service\Merchant\RecordProvider;

/**
 * Gathers the facts TaxCodeSection::state() decides on for one admin scope
 * (TWO-26153). Admin only. The merchant country is the merchant record's for
 * the scope's API key and environment, the record checkout reads, and render
 * and save use the same one. Nothing here writes the record, the key verdict
 * or the saved mapping.
 */
class TaxCodeSectionResolver
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var RecordProvider
     */
    private $recordProvider;

    /**
     * @var ApiKeyStatus
     */
    private $apiKeyStatus;

    /**
     * @var TaxCodes
     */
    private $taxCodes;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        ConfigRepository $configRepository,
        RecordProvider $recordProvider,
        ApiKeyStatus $apiKeyStatus,
        TaxCodes $taxCodes,
        StoreManagerInterface $storeManager
    ) {
        $this->configRepository = $configRepository;
        $this->recordProvider = $recordProvider;
        $this->apiKeyStatus = $apiKeyStatus;
        $this->taxCodes = $taxCodes;
        $this->storeManager = $storeManager;
    }

    /**
     * @param int|null $scopeId as AdminScope resolves it
     * @param string $scope as AdminScope resolves it
     * @param mixed $saved the mapping stored at that scope, as the form or the save reads it
     * @return array TaxCodeSection::state()
     */
    public function resolve(?int $scopeId, string $scope, $saved): array
    {
        $facts = [
            'has_key' => $this->configRepository->getApiKey($scopeId, $scope) !== '',
            'readable' => true,
            'entries' => [],
            'country' => '',
            'rejected' => false,
            'profile_http' => 0,
            'list' => null,
            'list_http' => 0,
            'scope_differs' => false,
        ];
        if (!$facts['has_key']) {
            return TaxCodeSection::state($facts);
        }
        $map = TaxCodeMapBackend::read($saved);
        $facts['readable'] = $map !== null;
        $facts['entries'] = $map ?? [];
        $facts['scope_differs'] = $this->scopesBelowDiffer($scopeId, $scope);

        $record = $this->recordProvider->getRecord($scopeId, $scope);
        $country = is_string($record['country_code'] ?? null) ? strtoupper(trim($record['country_code'])) : '';
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $verdict = $this->apiKeyStatus->getStatus($scopeId, $scope);
            $facts['rejected'] = $verdict['status'] === ApiKeyStatus::INVALID_KEY;
            $facts['profile_http'] = $verdict['status'] === ApiKeyStatus::OK ? 0 : (int)($verdict['code'] ?? 0);
            return TaxCodeSection::state($facts);
        }
        $facts['country'] = $country;
        $failedStatus = 0;
        $facts['list'] = $this->taxCodes->getList($country, $scopeId, $scope, $failedStatus);
        $facts['list_http'] = $failedStatus;

        return TaxCodeSection::state($facts);
    }

    /**
     * Whether a scope below this one carries another API key or environment:
     * at the default scope any website or store view, at a website scope its
     * own store views. Such a scope inherits this map while its merchant, and
     * its country's codes, may differ from what this scope shows.
     */
    private function scopesBelowDiffer(?int $scopeId, string $scope): bool
    {
        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $scope !== ScopeInterface::SCOPE_WEBSITE) {
            return false;
        }
        $key = $this->configRepository->getApiKey($scopeId, $scope);
        $mode = $this->configRepository->getMode($scopeId, $scope);
        $below = [];
        if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            foreach ($this->storeManager->getWebsites() as $website) {
                $below[] = [(int)$website->getId(), ScopeInterface::SCOPE_WEBSITE];
            }
        }
        foreach ($this->storeManager->getStores() as $store) {
            if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT || (int)$store->getWebsiteId() === $scopeId) {
                $below[] = [(int)$store->getId(), ScopeInterface::SCOPE_STORE];
            }
        }
        foreach ($below as [$id, $type]) {
            if ($this->configRepository->getApiKey($id, $type) !== $key
                || $this->configRepository->getMode($id, $type) !== $mode
            ) {
                return true;
            }
        }

        return false;
    }
}
