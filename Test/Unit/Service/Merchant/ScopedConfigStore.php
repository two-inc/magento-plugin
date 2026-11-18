<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Merchant;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Config storage with Magento's scope fallback: a store view reads its own
 * row, then its website's, then Default; a website reads its own, then
 * Default. A store-scope read with no id reads the current store, and with no
 * current store (the admin) it reads Default. Both spellings of each scope
 * name resolve, as Magento's do. Stores 1 and 2 are in website 1, store 3 in
 * website 2.
 */
final class ScopedConfigStore implements ScopeConfigInterface
{
    public const STORE_WEBSITE = [1 => 1, 2 => 1, 3 => 2];

    /**
     * @param array{default?: array<string, mixed>, websites?: array<int, array<string, mixed>>,
     *     stores?: array<int, array<string, mixed>>} $rows config path => value per scope
     * @param int|null $currentStoreId the store a store-scope read with no id resolves to
     */
    public function __construct(
        private readonly array $rows,
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

    /** @return list<array<string, mixed>|null> the rows a read at this scope falls back through, nearest first */
    private function chain(string $scopeType, $scopeCode): array
    {
        $default = $this->rows['default'] ?? null;
        if ($scopeType === 'websites' || $scopeType === 'website') {
            return [$scopeCode === null ? null : ($this->rows['websites'][(int)$scopeCode] ?? null), $default];
        }
        if ($scopeType === 'stores' || $scopeType === 'store') {
            $storeId = $scopeCode === null ? $this->currentStoreId : (int)$scopeCode;
            if ($storeId === null) {
                return [$default];
            }
            return [
                $this->rows['stores'][$storeId] ?? null,
                $this->rows['websites'][self::STORE_WEBSITE[$storeId]] ?? null,
                $default,
            ];
        }

        return [$default];
    }
}
