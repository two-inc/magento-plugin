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
use Two\Gateway\Model\Config\Backend\CustomHeaders as CustomHeadersBackend;

/**
 * ABN-490: carries a configured firewall token onto the custom-header table
 * that replaced it, as one `X-WAF-TOKEN` row, then deletes the retired rows.
 * Without this a merchant whose network gates on that header silently stops
 * sending it after upgrade and every call to the API is refused.
 */
class MigrateFirewallTokenToCustomHeaders implements DataPatchInterface
{
    private const TOKEN_KEY = 'firewall_token';
    private const BROWSER_KEY = 'firewall_token_browser';
    private const HEADERS_KEY = 'custom_headers';
    private const HEADER_NAME = 'X-WAF-TOKEN';

    /** Doubled twice over: once for PHP, once for MySQL's own LIKE parser. */
    private const LIKE_PATH = "path LIKE ? ESCAPE '\\\\'";

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

        foreach ($this->candidateScopes($rows) as [$code, $scope, $scopeId]) {
            $chain = $this->scopeChain($scope, $scopeId);
            $resolved = $this->resolvePair($rows, $code, $chain);

            // A scope resolving to what it would inherit anyway needs no row of
            // its own: writing one would turn inheritance into an override.
            if ($resolved['token'] === '' || $resolved === $this->resolvePair($rows, $code, array_slice($chain, 1))) {
                continue;
            }

            if ($this->hasCustomHeaders($rows, $code, $scope, $scopeId)) {
                continue;
            }

            // A token the new table cannot carry is left behind rather than
            // written: the read path would drop it anyway, and a stored row
            // the entry gate refuses makes the whole section unsavable over
            // something the admin never typed.
            $encoded = CustomHeadersBackend::isSendableValue($resolved['token'])
                ? json_encode($this->singleRow($resolved['token'], $resolved['browser']))
                : false;

            if ($encoded !== false) {
                $this->configWriter->save($this->path($code, self::HEADERS_KEY), $encoded, $scope, $scopeId);
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
     * Every scope that could resolve differently from its parent — the two
     * retired fields were independently scopeable, so a tick could sit at a
     * narrower scope than the token it applied to.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function candidateScopes(array $rows): array
    {
        $scopes = [];
        foreach ($rows as $row) {
            if (!in_array($this->keyOf($row), [self::TOKEN_KEY, self::BROWSER_KEY], true)) {
                continue;
            }

            $candidate = [$this->codeOf($row), (string)$row['scope'], (int)$row['scope_id']];
            $scopes[implode('/', $candidate)] = $candidate;
        }

        return array_values($scopes);
    }

    /**
     * What the retired field pair resolved to at one scope, each field walked
     * down the chain config inheritance uses.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array{0: string, 1: int}> $chain
     * @return array{token: string, browser: bool}
     */
    private function resolvePair(array $rows, string $code, array $chain): array
    {
        return [
            'token' => trim((string)$this->resolve($rows, $code, self::TOKEN_KEY, $chain)),
            'browser' => (bool)(int)$this->resolve($rows, $code, self::BROWSER_KEY, $chain),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array{0: string, 1: int}> $chain
     * @return string|null null when no scope in the chain stores the key
     */
    private function resolve(array $rows, string $code, string $key, array $chain): ?string
    {
        foreach ($chain as [$chainScope, $chainScopeId]) {
            foreach ($rows as $row) {
                if ($this->keyOf($row) === $key
                    && $this->codeOf($row) === $code
                    && (string)$row['scope'] === $chainScope
                    && (int)$row['scope_id'] === $chainScopeId
                ) {
                    return (string)$row['value'];
                }
            }
        }

        return null;
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

    /**
     * @return array<string, array<string, string>>
     */
    private function singleRow(string $token, bool $sendFromBrowser): array
    {
        return [
            '_1' => [
                'name' => self::HEADER_NAME,
                'value' => $token,
                'send_from_browser' => $sendFromBrowser ? '1' : '',
            ],
        ];
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
     * The brand code is the only wildcard: `payment/%/<key>` with the key's own
     * underscore escaped, so the pattern cannot widen to a neighbouring field.
     * Same escaping as Setup\Uninstall.
     */
    private static function like(string $key): string
    {
        return 'payment/%/' . str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $key);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedRows(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
            ->where(self::LIKE_PATH, self::like(self::TOKEN_KEY) . '%')
            ->orWhere(self::LIKE_PATH, self::like(self::HEADERS_KEY));

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
