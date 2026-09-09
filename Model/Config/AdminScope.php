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

/**
 * The scope an admin config form is editing, as the (id, scope type) pair the config
 * repository reads with. The API key is website-scoped, so a website read through one of
 * its stores answers for whichever child was picked, and flattened to a store id answers
 * for the default scope's merchant (ABN-530).
 */
class AdminScope
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(StoreManagerInterface $storeManager)
    {
        $this->storeManager = $storeManager;
    }

    /**
     * @param mixed $scopeId
     * @return array{int|null, string}
     */
    public static function fromScope(?string $scope, $scopeId): array
    {
        $id = (int)$scopeId;
        if ($scope === ScopeInterface::SCOPE_STORES || $scope === ScopeInterface::SCOPE_STORE) {
            return $id > 0 ? [$id, ScopeInterface::SCOPE_STORE] : self::defaultScope();
        }
        if ($scope === ScopeInterface::SCOPE_WEBSITES || $scope === ScopeInterface::SCOPE_WEBSITE) {
            return $id > 0 ? [$id, ScopeInterface::SCOPE_WEBSITE] : self::defaultScope();
        }

        return self::defaultScope();
    }

    /**
     * From the store/website codes a config form carries in its URL.
     *
     * @param mixed $storeCode
     * @param mixed $websiteCode
     * @return array{int|null, string}
     */
    public function fromCodes($storeCode, $websiteCode): array
    {
        try {
            if ($storeCode !== null && $storeCode !== '') {
                return self::fromScope(
                    ScopeInterface::SCOPE_STORE,
                    $this->storeManager->getStore($storeCode)->getId()
                );
            }
            if ($websiteCode !== null && $websiteCode !== '') {
                return self::fromScope(
                    ScopeInterface::SCOPE_WEBSITE,
                    $this->storeManager->getWebsite($websiteCode)->getId()
                );
            }
        } catch (\Exception $e) {
            return self::defaultScope();
        }

        return self::defaultScope();
    }

    /** Whether a scope type carries a store id the API adapter can use for its headers. */
    public static function isStoreScope(?string $scope): bool
    {
        return $scope === null
            || $scope === ScopeInterface::SCOPE_STORE
            || $scope === ScopeInterface::SCOPE_STORES;
    }

    /** @return array{null, string} */
    private static function defaultScope(): array
    {
        return [null, ScopeConfigInterface::SCOPE_TYPE_DEFAULT];
    }
}
