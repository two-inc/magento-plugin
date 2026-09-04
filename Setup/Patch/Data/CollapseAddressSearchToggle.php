<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * TWO-25202: address lookup used to require BOTH `enable_company_search`
 * and `enable_address_search` to be on (the AND lived in
 * Model\Config\Repository::isAddressSearchEnabled). It now reads
 * `enable_address_search` alone, so this patch folds the old conjunction
 * into that one key: wherever the old AND resolved to OFF, the new single
 * key is pinned to 0 at that scope. Every merchant's effective
 * address-lookup behaviour is therefore identical before and after.
 *
 * `enable_company_search` is NOT touched or removed — it keeps its own
 * separate job (placing the company-search control).
 *
 * TWO-25326 later reinstated a conjunction, but only for the payment-tile
 * picker and only client-side (gateway_method.js::addressLookup()). This
 * patch and the server-side single-key read it migrated to are unaffected:
 * the config semantics it converted merchants onto still hold.
 *
 * Scope-aware: default, website and store scopes are walked parent-first
 * and a row is only written where the inherited new value would differ
 * from the old effective AND, so no needless explicit rows appear in
 * core_config_data.
 *
 * Brand-agnostic: the codes to migrate are discovered from the
 * `payment/<code>/enable_*_search` rows actually present in
 * core_config_data. A code with no stored rows cannot need a write —
 * both keys default to 1 (etc/config.xml), so the old AND was already
 * ON — which is why untouched installs are unaffected.
 *
 * Idempotent: a second run re-derives the AND from the already-collapsed
 * values (address 0 => AND 0 => desired 0 => no change) and writes
 * nothing. Only ever writes 0, never 1.
 */
class CollapseAddressSearchToggle implements DataPatchInterface
{
    private const COMPANY_KEY = 'enable_company_search';
    private const ADDRESS_KEY = 'enable_address_search';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        StoreManagerInterface $storeManager,
        TypeListInterface $cacheTypeList
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->storeManager = $storeManager;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $wrote = false;
        foreach ($this->discoverStoredRows() as $code => $stored) {
            $wrote = $this->collapseForCode((string)$code, $stored) || $wrote;
        }

        if ($wrote) {
            // The rows were written behind the config cache; invalidate so
            // the storefront reads the collapsed values immediately.
            $this->cacheTypeList->invalidate('config');
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Explicit core_config_data rows for both keys, keyed
     * [code][scope][scopeId][key] => bool.
     *
     * @return array<string, array>
     */
    private function discoverStoredRows(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
            ->where('path LIKE ?', 'payment/%/' . self::COMPANY_KEY)
            ->orWhere('path LIKE ?', 'payment/%/' . self::ADDRESS_KEY);

        $stored = [];
        foreach ($connection->fetchAll($select) as $row) {
            $segments = explode('/', (string)$row['path']);
            if (count($segments) !== 3) {
                continue;
            }
            [, $code, $key] = $segments;
            $stored[$code][(string)$row['scope']][(int)$row['scope_id']][$key] = (bool)(int)$row['value'];
        }

        return $stored;
    }

    /**
     * Collapse the old AND into `enable_address_search` for one payment code.
     *
     * @param array $stored [scope][scopeId][key] => bool
     * @return bool whether anything was written
     */
    private function collapseForCode(string $code, array $stored): bool
    {
        $addressPath = 'payment/' . $code . '/' . self::ADDRESS_KEY;
        // Fallback for a key with no row in the walked chain: the
        // default-scope effective value (etc/config.xml merged with any
        // default row — the latter is matched by $stored first anyway).
        $fallback = [
            self::COMPANY_KEY => $this->scopeConfig->isSetFlag(
                'payment/' . $code . '/' . self::COMPANY_KEY,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            ),
            self::ADDRESS_KEY => $this->scopeConfig->isSetFlag(
                $addressPath,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            ),
        ];

        $resolve = static function (array $chain, string $key) use ($stored, $fallback): bool {
            foreach ($chain as [$scope, $scopeId]) {
                if (isset($stored[$scope][$scopeId][$key])) {
                    return $stored[$scope][$scopeId][$key];
                }
            }
            return $fallback[$key];
        };

        $wrote = false;
        // Effective post-patch address-search value per scope, so a child
        // scope knows what it now inherits.
        $newDefault = null;
        $newWebsite = [];

        // ── default scope ────────────────────────────────────────────
        $chain = [[ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]];
        $desired = $resolve($chain, self::COMPANY_KEY) && $resolve($chain, self::ADDRESS_KEY);
        if ($resolve($chain, self::ADDRESS_KEY) !== $desired) {
            $this->pinOff($addressPath, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0, $desired);
            $wrote = true;
        }
        $newDefault = $desired;

        // ── website scopes ───────────────────────────────────────────
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            $chain = [
                [ScopeInterface::SCOPE_WEBSITES, $websiteId],
                [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
            ];
            $desired = $resolve($chain, self::COMPANY_KEY) && $resolve($chain, self::ADDRESS_KEY);
            $inherited = $stored[ScopeInterface::SCOPE_WEBSITES][$websiteId][self::ADDRESS_KEY] ?? $newDefault;
            if ($inherited !== $desired) {
                $this->pinOff($addressPath, ScopeInterface::SCOPE_WEBSITES, $websiteId, $desired);
                $wrote = true;
            }
            $newWebsite[$websiteId] = $desired;
        }

        // ── store scopes ─────────────────────────────────────────────
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $websiteId = (int)$store->getWebsiteId();
            $chain = [
                [ScopeInterface::SCOPE_STORES, $storeId],
                [ScopeInterface::SCOPE_WEBSITES, $websiteId],
                [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
            ];
            $desired = $resolve($chain, self::COMPANY_KEY) && $resolve($chain, self::ADDRESS_KEY);
            $inherited = $stored[ScopeInterface::SCOPE_STORES][$storeId][self::ADDRESS_KEY]
                ?? ($newWebsite[$websiteId] ?? $newDefault);
            if ($inherited !== $desired) {
                $this->pinOff($addressPath, ScopeInterface::SCOPE_STORES, $storeId, $desired);
                $wrote = true;
            }
        }

        return $wrote;
    }

    /**
     * Write the collapsed value. $desired is always false here — the new
     * single key can only ever lose truth relative to the old AND, never
     * gain it — so the patch can never switch address lookup ON for a
     * merchant who had it off.
     */
    private function pinOff(string $path, string $scope, int $scopeId, bool $desired): void
    {
        if ($desired) {
            return;
        }
        $this->configWriter->save($path, '0', $scope, $scopeId);
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases()
    {
        return [];
    }
}
