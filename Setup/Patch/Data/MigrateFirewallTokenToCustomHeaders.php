<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * ABN-490: carries a configured firewall token onto the custom-header table
 * that replaced it, as one `X-WAF-TOKEN` row, then deletes the retired rows.
 * Without this a merchant whose network gates on that header silently stops
 * sending it after upgrade and every call to the API is refused.
 *
 * Brand-agnostic (payment codes come from the rows actually present) and
 * scope-complete (each stored scope/scope_id row migrates at its own scope, so
 * inheritance is unchanged). Idempotent: a re-run finds no retired rows and
 * writes nothing. A scope that already has a custom-header table keeps it —
 * the admin's own list is never overwritten.
 */
class MigrateFirewallTokenToCustomHeaders implements DataPatchInterface
{
    private const TOKEN_KEY = 'firewall_token';
    private const BROWSER_KEY = 'firewall_token_browser';
    private const HEADERS_KEY = 'custom_headers';
    private const HEADER_NAME = 'X-WAF-TOKEN';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    /**
     * @var array<int, int>|null store id => website id, read once
     */
    private $storeWebsites;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $rows = $this->storedRows();
        $touched = false;

        foreach ($rows as $row) {
            if ($this->keyOf($row) !== self::TOKEN_KEY) {
                continue;
            }

            $token = trim((string)$row['value']);
            $code = $this->codeOf($row);
            $scope = (string)$row['scope'];
            $scopeId = (int)$row['scope_id'];

            if ($token !== '' && !$this->hasCustomHeaders($rows, $code, $scope, $scopeId)) {
                $this->configWriter->save(
                    $this->path($code, self::HEADERS_KEY),
                    $this->encodeSingleRow($token, $this->browserFlag($rows, $code, $scope, $scopeId)),
                    $scope,
                    $scopeId
                );
            }
        }

        foreach ($rows as $row) {
            if (in_array($this->keyOf($row), [self::TOKEN_KEY, self::BROWSER_KEY], true)) {
                $this->configWriter->delete((string)$row['path'], (string)$row['scope'], (int)$row['scope_id']);
                $touched = true;
            }
        }

        if ($touched) {
            $this->cacheTypeList->invalidate('config');
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * The flag the retired field pair resolved to beside this token, walked
     * down the same scope chain config inheritance uses — a store-scoped
     * token whose flag was only ever ticked on its website must keep it.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function browserFlag(array $rows, string $code, string $scope, int $scopeId): bool
    {
        foreach ($this->scopeChain($scope, $scopeId) as [$chainScope, $chainScopeId]) {
            foreach ($rows as $row) {
                if ($this->keyOf($row) === self::BROWSER_KEY
                    && $this->codeOf($row) === $code
                    && (string)$row['scope'] === $chainScope
                    && (int)$row['scope_id'] === $chainScopeId
                ) {
                    return (bool)(int)$row['value'];
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, array{0: string, 1: int}> nearest scope first
     */
    private function scopeChain(string $scope, int $scopeId): array
    {
        if ($scope === 'stores') {
            return [['stores', $scopeId], ['websites', $this->websiteOfStore($scopeId)], ['default', 0]];
        }

        if ($scope === 'websites') {
            return [['websites', $scopeId], ['default', 0]];
        }

        return [['default', 0]];
    }

    private function websiteOfStore(int $storeId): int
    {
        if ($this->storeWebsites === null) {
            $connection = $this->moduleDataSetup->getConnection();
            $select = $connection->select()
                ->from($this->moduleDataSetup->getTable('store'), ['store_id', 'website_id']);

            $this->storeWebsites = [];
            foreach ($connection->fetchAll($select) as $row) {
                $this->storeWebsites[(int)$row['store_id']] = (int)$row['website_id'];
            }
        }

        // The admin website (0) matches no stored override, so an unknown
        // store falls through to the default scope.
        return $this->storeWebsites[$storeId] ?? 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function hasCustomHeaders(array $rows, string $code, string $scope, int $scopeId): bool
    {
        foreach ($rows as $row) {
            if ($this->keyOf($row) === self::HEADERS_KEY
                && $this->codeOf($row) === $code
                && (string)$row['scope'] === $scope
                && (int)$row['scope_id'] === $scopeId
                && trim((string)$row['value']) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    private function encodeSingleRow(string $token, bool $sendFromBrowser): string
    {
        return (string)json_encode([
            '_1' => [
                'name' => self::HEADER_NAME,
                'value' => $token,
                'send_from_browser' => $sendFromBrowser ? '1' : '',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function keyOf(array $row): string
    {
        $segments = explode('/', (string)$row['path']);

        return count($segments) === 3 && $segments[0] === 'payment' ? $segments[2] : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function codeOf(array $row): string
    {
        return explode('/', (string)$row['path'])[1] ?? '';
    }

    private function path(string $code, string $key): string
    {
        return 'payment/' . $code . '/' . $key;
    }

    /**
     * Every row this patch reads or rewrites, in one query.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storedRows(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
            ->where('path LIKE ?', 'payment/%/' . self::TOKEN_KEY . '%')
            ->orWhere('path LIKE ?', 'payment/%/' . self::HEADERS_KEY);

        return $connection->fetchAll($select);
    }

    /**
     * @return array
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }
}
