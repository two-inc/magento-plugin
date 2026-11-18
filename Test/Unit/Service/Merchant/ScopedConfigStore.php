<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Config storage with Magento's scope fallback: a store view reads its own
 * row, then its website's, then Default, then the module's shipped defaults;
 * a website reads its own, then Default. A store-scope read with no id reads
 * the current store, and with no current store (the admin) it reads Default.
 * Both spellings of each scope name resolve, as Magento's do. Scope codes are
 * numeric ids, as the code under test passes them; Magento also resolves
 * string codes. Stores 1 and 2 are in website 1, store 3 in website 2.
 */
final class ScopedConfigStore implements ScopeConfigInterface
{
    public const STORE_WEBSITE = [1 => 1, 2 => 1, 3 => 2];

    /**
     * @param array{default?: array<string, mixed>, websites?: array<int, array<string, mixed>>,
     *     stores?: array<int, array<string, mixed>>} $rows saved config path => value per scope
     * @param array<string, mixed> $shippedDefaults etc/config.xml values, under every scope
     * @param int|null $currentStoreId the store a store-scope read with no id resolves to
     */
    public function __construct(
        private array $rows,
        private readonly array $shippedDefaults = [],
        private readonly ?int $currentStoreId = null
    ) {
    }

    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        foreach ($this->chain((string)$scopeType, $scopeCode) as $layer) {
            if ($layer !== null && array_key_exists($path, $layer)) {
                return $layer[$path];
            }
        }

        return null;
    }

    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return (bool)$this->getValue($path, $scopeType, $scopeCode);
    }

    /** Writes a row, as saving a field does. $scope is 'default', 'websites' or 'stores'. */
    public function set(string $scope, int $scopeId, string $path, $value): void
    {
        if ($scope === 'default') {
            $this->rows['default'][$path] = $value;
        } else {
            $this->rows[$scope][$scopeId][$path] = $value;
        }
    }

    /** Removes a row, as ticking "Use Default" on a field does. */
    public function delete(string $scope, int $scopeId, string $path): void
    {
        if ($scope === 'default') {
            unset($this->rows['default'][$path]);
        } else {
            unset($this->rows[$scope][$scopeId][$path]);
        }
    }

    /** @return array the saved rows, without the shipped defaults */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<array<string, mixed>|null> the rows a read at this scope falls back through, nearest first */
    private function chain(string $scopeType, $scopeCode): array
    {
        $default = [$this->rows['default'] ?? null, $this->shippedDefaults];
        if ($scopeType === 'websites' || $scopeType === 'website') {
            return [$scopeCode === null ? null : ($this->rows['websites'][(int)$scopeCode] ?? null), ...$default];
        }
        if ($scopeType === 'stores' || $scopeType === 'store') {
            $storeId = $scopeCode === null ? $this->currentStoreId : (int)$scopeCode;
            if ($storeId === null) {
                return $default;
            }
            return [
                $this->rows['stores'][$storeId] ?? null,
                $this->rows['websites'][self::STORE_WEBSITE[$storeId]] ?? null,
                ...$default,
            ];
        }

        return $default;
    }
}
