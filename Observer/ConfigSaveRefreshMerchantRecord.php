<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Merchant\RecordRefresher;

/**
 * Refreshes the cached merchant record when a General section save changes
 * the API key or the environment, for every cache identity the saved scope
 * governs.
 */
class ConfigSaveRefreshMerchantRecord implements ObserverInterface
{
    /** Bounds only the start of an identity, so a save costs this plus one identity's two calls. */
    private const INLINE_BUDGET_SECONDS = 15.0;

    /**
     * @var RecordRefresher
     */
    private $recordRefresher;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LogRepository
     */
    private $logRepository;

    public function __construct(
        RecordRefresher $recordRefresher,
        StoreManagerInterface $storeManager,
        LogRepository $logRepository
    ) {
        $this->recordRefresher = $recordRefresher;
        $this->storeManager = $storeManager;
        $this->logRepository = $logRepository;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $changedPaths = $event->getData('changed_paths');
        if (is_array($changedPaths) && !$this->changesCredentials($changedPaths)) {
            return;
        }
        try {
            // The event carries the scope as posted (code or id); the store manager resolves either.
            $store = (string)$event->getData('store');
            $website = (string)$event->getData('website');
            if ($store !== '') {
                $scope = ScopeInterface::SCOPE_STORES;
                $scopeId = (int)$this->storeManager->getStore($store)->getId();
            } elseif ($website !== '') {
                $scope = ScopeInterface::SCOPE_WEBSITES;
                $scopeId = (int)$this->storeManager->getWebsite($website)->getId();
            } else {
                $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
                $scopeId = 0;
            }
            $identities = $this->recordRefresher->governedIdentities($scope, $scopeId);
            // TEMP(live-verify)
            $this->logRepository->addDebugLog('LIVEVERIFY ConfigSave: credentials changed', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'identities' => count($identities),
            ]);
        } catch (LocalizedException $e) {
            $this->logRepository->addDebugLog(
                'ConfigSaveRefreshMerchantRecord: nothing to refresh',
                ['reason' => $e->getMessage()]
            );
            return;
        }

        $outcome = $this->recordRefresher->refreshWithin($identities, self::INLINE_BUDGET_SECONDS);
        if ($outcome['skipped'] > 0) {
            $this->logRepository->addDebugLog(
                'ConfigSaveRefreshMerchantRecord: left merchant profiles to the scheduled refresh',
                ['skipped' => $outcome['skipped']]
            );
        }
    }

    /**
     * Whether the API key or the environment is among the saved paths — the
     * section carries other fields, and a no-change save reports none.
     *
     * @param array<int,string> $changedPaths
     */
    private function changesCredentials(array $changedPaths): bool
    {
        foreach ($changedPaths as $path) {
            $slash = strrpos((string)$path, '/');
            $field = $slash === false ? (string)$path : substr((string)$path, $slash + 1);
            if ($field === 'api_key' || $field === 'mode') {
                return true;
            }
        }

        return false;
    }
}
