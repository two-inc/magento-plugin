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
 * Clears a stored checkout title that is exactly one of the English titles
 * this module once shipped as its default (TWO-27108).
 *
 * Saving the section stores every field, so a shop that never chose a title
 * still holds the English default it was showing, in every store view. With
 * it cleared, an empty title shows the translated default for each store
 * view. A title the merchant chose, even one differing only in case or
 * spacing, is kept, and the subtitle is never touched.
 */
class ClearEnglishTitleDefault implements DataPatchInterface
{
    private const PATH = 'payment/two_payment/title';

    /**
     * Every default title the module's config.xml has carried, as the sha256
     * of the exact text: the values are matched, never shown, and as literals
     * the vanilla brand name would trip the debrand guard. The texts are in
     * the comments, and the patch's test pins each one to its hash.
     */
    public const OLD_DEFAULT_HASHES = [
        // Two
        '94a72c074cfe574742c9e99e863322f73feff82981d065ff65a0308f44f19f62',
        // Business invoice in 30 days
        'd617370208f63822481d000b603c942d4b4a4faa453441bbb3441559f42d8515',
        // Business invoice in 14 days
        '615dec0de579b75c7e970f42b17669f604f324db5ebb1756ac1cbec488282e78',
        // Business invoice - 30 days
        '0d801cd50d630193ca01d376206b22b8a6f5d8d7df0dd7b2d9d5a6782bf485b0',
        // Two - Buy Now Pay Later on Invoice Terms
        'a2509b916c542f94f4231e78f5d2d434840fbbb6cfdac5823c3b1e8a3dee555c',
    ];

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
        $deleted = false;
        foreach ($this->storedDefaults() as $row) {
            $this->configWriter->delete(self::PATH, (string)$row['scope'], (int)$row['scope_id']);
            $deleted = true;
        }
        if ($deleted) {
            $this->cacheTypeList->invalidate('config');
        }
        $this->moduleDataSetup->getConnection()->endSetup();
        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedDefaults(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
            ->where('path = ?', self::PATH);

        // Compared here, byte for byte: a database collation may match case-insensitively.
        return array_values(array_filter($connection->fetchAll($select), static function ($row): bool {
            return ($row['path'] ?? null) === self::PATH
                && is_string($row['value'] ?? null)
                && in_array(hash('sha256', $row['value']), self::OLD_DEFAULT_HASHES, true);
        }));
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
