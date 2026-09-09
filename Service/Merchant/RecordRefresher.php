<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Merchant;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;

/**
 * Maps Magento config scopes onto merchant-record cache identities.
 *
 * The record is cached per (mode, API key), while the admin saves and
 * presses buttons at a default / website / store scope. This is the one
 * place that translation happens: a scope governs an identity when a store
 * view under it reads the API key set at that scope.
 *
 * It also owns the scope walk the FX rate cron shares — both caches are keyed
 * on (mode, API key), so one walk serves both. The walk hands back the mode
 * and key it read, so no caller resolves them a second time.
 */
class RecordRefresher
{
    /**
     * @var RecordProvider
     */
    private $recordProvider;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    public function __construct(
        RecordProvider $recordProvider,
        StoreManagerInterface $storeManager,
        ConfigRepository $configRepository,
        LogRepository $logRepository
    ) {
        $this->recordProvider = $recordProvider;
        $this->storeManager = $storeManager;
        $this->configRepository = $configRepository;
        $this->logRepository = $logRepository;
    }

    /**
     * The hourly cron: refreshes every identity whose record is MAX_AGE old
     * or missing, and records that the schedule ran for the rest.
     */
    public function refreshDue(): void
    {
        $identities = $this->distinctScopes($this->storeScopes());
        $due = [];
        foreach ($identities as $identity) {
            $this->recordProvider->noteScheduledRun($identity['mode'], $identity['api_key']);
            if ($this->recordProvider->isDue($identity['mode'], $identity['api_key'])) {
                $due[] = $identity;
            }
        }
        // TEMP(live-verify)
        $this->logRepository->addDebugLog('LIVEVERIFY RecordRefresher: scheduled run', [
            'identities' => count($identities),
            'due' => count($due),
        ]);
        $this->refreshWithin($due, INF);
    }

    /**
     * Refreshes identities in order until the wall-clock budget is spent; the
     * first is always attempted. A throwing identity is logged and counts as
     * failed, never as aborting the rest.
     *
     * @param array<int,array{mode: string, api_key: string, store_id: int|null}> $identities
     * @param float $budgetSeconds INF for no budget
     * @return array{records: array<int,array<string,mixed>|null>, skipped: int} a record or null per attempt
     */
    public function refreshWithin(array $identities, float $budgetSeconds): array
    {
        $started = microtime(true);
        $records = [];
        foreach ($identities as $index => $identity) {
            if ($index > 0 && microtime(true) - $started >= $budgetSeconds) {
                break;
            }
            try {
                $records[] = $this->recordProvider->refresh(
                    $identity['mode'],
                    $identity['api_key'],
                    $identity['store_id']
                );
            } catch (\Throwable $e) {
                $this->logRepository->addErrorLog(
                    'RecordRefresher: refresh threw, treating as failed',
                    ['error' => $e->getMessage()]
                );
                $records[] = null;
            }
        }

        return ['records' => $records, 'skipped' => count($identities) - count($records)];
    }

    /**
     * The cache identities whose values a save or button press at this scope
     * governs: every distinct (mode, key) read by a store view under the
     * scope whose API key is the one set at the scope. A store view with its
     * own key is another merchant and is not touched; the default scope's own
     * read point counts as a store view under it.
     *
     * @param string $scope default, websites or stores
     * @return array<int,array{mode: string, api_key: string, store_id: int|null}>
     * @throws NoSuchEntityException the website or store view no longer exists
     * @throws LocalizedException nothing under the scope reads a key set there; the message says why
     */
    public function governedIdentities(string $scope, int $scopeId): array
    {
        if ($scope !== ScopeInterface::SCOPE_WEBSITES && $scope !== ScopeInterface::SCOPE_STORES) {
            $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        }
        $points = $this->readPointsUnder($scope, $scopeId);
        if ($points === []) {
            throw new LocalizedException(
                __('This website has no store view, so nothing reads the API key set here.')
            );
        }
        $apiKey = $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            ? $this->apiKeyAt(null)
            : $this->configRepository->getApiKey($scopeId, $scope);
        if ($apiKey === '') {
            throw new LocalizedException(
                __('No API key is set at this scope, so there is no merchant profile to refresh.')
            );
        }

        $inheriting = array_values(array_filter($points, function (?int $storeId) use ($apiKey): bool {
            return $this->apiKeyAt($storeId) === $apiKey;
        }));
        if ($inheriting === []) {
            throw new LocalizedException(
                __('Every store view in this website has its own API key, so nothing reads the one set here.')
            );
        }

        return $this->distinctScopes($inheriting);
    }

    /**
     * One identity per distinct (mode, API key), in the order the given scopes
     * read them, carrying the scope it was read at.
     *
     * @param array<int,int|null> $scopes
     * @return array<int,array{mode: string, api_key: string, store_id: int|null}>
     */
    public function distinctScopes(array $scopes): array
    {
        $seen = [];
        $distinct = [];
        foreach ($scopes as $storeId) {
            $apiKey = $this->apiKeyAt($storeId);
            if ($apiKey === '') {
                continue;
            }
            $mode = $this->modeAt($storeId);
            $slot = $mode . "\0" . $apiKey;
            if (isset($seen[$slot])) {
                continue;
            }
            $seen[$slot] = true;
            $distinct[] = ['mode' => $mode, 'api_key' => $apiKey, 'store_id' => $storeId];
        }

        return $distinct;
    }

    /**
     * Default scope (null) plus every store view: API keys are store-scoped,
     * so each scope may resolve a different key or environment.
     *
     * @return array<int,int|null>
     */
    public function storeScopes(): array
    {
        $scopes = [null];
        foreach ($this->storeManager->getStores() as $store) {
            $scopes[] = (int)$store->getId();
        }

        return $scopes;
    }

    /** A null point is the default scope read explicitly, not the area-dependent current store. */
    private function apiKeyAt(?int $storeId): string
    {
        return $storeId === null
            ? $this->configRepository->getApiKey(null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            : $this->configRepository->getApiKey($storeId);
    }

    private function modeAt(?int $storeId): string
    {
        return $storeId === null
            ? $this->configRepository->getMode(null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            : $this->configRepository->getMode($storeId);
    }

    /**
     * The runtime read points whose config resolves through this scope.
     *
     * @return array<int,int|null>
     * @throws NoSuchEntityException
     */
    private function readPointsUnder(string $scope, int $scopeId): array
    {
        if ($scope === ScopeInterface::SCOPE_STORES) {
            if ($scopeId <= 0) {
                throw NoSuchEntityException::singleField('store_id', $scopeId);
            }
            return [(int)$this->storeManager->getStore($scopeId)->getId()];
        }
        if ($scope === ScopeInterface::SCOPE_WEBSITES) {
            if ($scopeId <= 0) {
                throw NoSuchEntityException::singleField('website_id', $scopeId);
            }
            $websiteId = (int)$this->storeManager->getWebsite($scopeId)->getId();
            $points = [];
            foreach ($this->storeManager->getStores() as $store) {
                if ((int)$store->getWebsiteId() === $websiteId) {
                    $points[] = (int)$store->getId();
                }
            }
            return $points;
        }

        return $this->storeScopes();
    }
}
