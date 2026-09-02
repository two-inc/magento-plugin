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
     * The flag at the token's own scope, falling back to the default scope it
     * would otherwise have inherited from.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function browserFlag(array $rows, string $code, string $scope, int $scopeId): bool
    {
        $default = null;
        foreach ($rows as $row) {
            if ($this->keyOf($row) !== self::BROWSER_KEY || $this->codeOf($row) !== $code) {
                continue;
            }
            if ((string)$row['scope'] === $scope && (int)$row['scope_id'] === $scopeId) {
                return (bool)(int)$row['value'];
            }
            if ((string)$row['scope'] === 'default') {
                $default = (bool)(int)$row['value'];
            }
        }

        return $default ?? false;
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
