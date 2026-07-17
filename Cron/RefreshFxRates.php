<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Service\Fx\RateTableProvider;

/**
 * Background refresh of the cached FX rate table (every 6 hours).
 *
 * Keeps checkout rate lookups off the fetch path: the read side serves
 * the cached table and only fetches itself when the table is missing or
 * this job has not kept it fresh. One refresh per distinct API key —
 * store scopes sharing a key share a cache entry, so refreshing it twice
 * would only duplicate the call.
 */
class RefreshFxRates
{
    /** @var RateTableProvider */
    private $rateTableProvider;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var ConfigRepository */
    private $configRepository;

    public function __construct(
        RateTableProvider $rateTableProvider,
        StoreManagerInterface $storeManager,
        ConfigRepository $configRepository
    ) {
        $this->rateTableProvider = $rateTableProvider;
        $this->storeManager = $storeManager;
        $this->configRepository = $configRepository;
    }

    public function execute(): void
    {
        $seen = [];
        foreach ($this->storeScopes() as $storeId) {
            $apiKey = (string)$this->configRepository->getApiKey($storeId);
            if ($apiKey === '') {
                continue;
            }
            $keyHash = hash('sha256', $apiKey);
            if (isset($seen[$keyHash])) {
                continue;
            }
            $seen[$keyHash] = true;
            $this->rateTableProvider->refresh($storeId);
        }
    }

    /**
     * Default scope plus every store view: API keys are store-scoped, so
     * each scope may resolve a different key (sandbox vs production, or a
     * different merchant per store).
     *
     * @return array<int,int|null>
     */
    private function storeScopes(): array
    {
        $scopes = [null];
        foreach ($this->storeManager->getStores() as $store) {
            $scopes[] = (int)$store->getId();
        }
        return $scopes;
    }
}
