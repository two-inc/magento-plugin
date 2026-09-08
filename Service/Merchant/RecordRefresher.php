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
 * It also owns the scope walk the FX rate cron shares. The walk takes the
 * caller's cache identity, because the two caches are keyed differently:
 * the merchant record on mode + API key, the FX rate table on the API key
 * alone. Walking one with the other's identity either misses a scope or
 * refreshes the same entry twice. The walk hands back the key it read, so
 * no caller resolves it a second time.
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
        $identities = $this->identitiesAt($this->distinctScopes($this->recordIdentity(), $this->storeScopes()));
        $due = [];
        foreach ($identities as $identity) {
            $this->recordProvider->noteScheduledRun($identity['mode'], $identity['api_key']);
            if ($this->recordProvider->isDue($identity['mode'], $identity['api_key'])) {
                $due[] = $identity;
            }
        }
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

        return $this->identitiesAt($this->distinctScopes($this->recordIdentity(), $inheriting));
    }

    /**
     * One point per distinct cache identity, in the order given, carrying the
     * API key that identity was computed from.
     *
     * @param callable(int|null, string): string $identity
     * @param array<int,int|null> $scopes
     * @return array<int,array{store_id: int|null, api_key: string}>
     */
    public function distinctScopes(callable $identity, array $scopes): array
    {
        $seen = [];
        $distinct = [];
        foreach ($scopes as $storeId) {
            $apiKey = $this->apiKeyAt($storeId);
            if ($apiKey === '') {
                continue;
            }
            $key = $identity($storeId, $apiKey);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $distinct[] = ['store_id' => $storeId, 'api_key' => $apiKey];
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

    /**
     * Mode + API key: one key can name a sandbox merchant on one store view
     * and a production one on another.
     *
     * @return callable(int|null, string): string
     */
    public function recordIdentity(): callable
    {
        return function (?int $storeId, string $apiKey): string {
            return hash('sha256', $this->modeAt($storeId) . "\0" . $apiKey);
        };
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

    /**
     * @param array<int,array{store_id: int|null, api_key: string}> $points one per distinct record identity
     * @return array<int,array{mode: string, api_key: string, store_id: int|null}>
     */
    private function identitiesAt(array $points): array
    {
        $identities = [];
        foreach ($points as $point) {
            $identities[] = [
                'mode' => $this->modeAt($point['store_id']),
                'api_key' => $point['api_key'],
                'store_id' => $point['store_id'],
            ];
        }

        return $identities;
    }
}
