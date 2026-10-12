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
        $facts['scope_differs'] = $this->websitesDiffer($scopeId, $scope);

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
     * At the default scope, whether a website carries another API key or
     * environment, so its merchant, and its country's codes, may differ from
     * what this scope shows. A website or store view scope has one key.
     */
    private function websitesDiffer(?int $scopeId, string $scope): bool
    {
        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            return false;
        }
        $key = $this->configRepository->getApiKey($scopeId, $scope);
        $mode = $this->configRepository->getMode($scopeId, $scope);
        foreach ($this->storeManager->getWebsites() as $website) {
            $id = (int)$website->getId();
            if ($this->configRepository->getApiKey($id, ScopeInterface::SCOPE_WEBSITE) !== $key
                || $this->configRepository->getMode($id, ScopeInterface::SCOPE_WEBSITE) !== $mode
            ) {
                return true;
            }
        }

        return false;
    }
}
