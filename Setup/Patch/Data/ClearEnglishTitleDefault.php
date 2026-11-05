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

    /** Every default title the module's config.xml has carried. */
    public const OLD_DEFAULTS = [
        'Two',
        'Business invoice in 30 days',
        'Business invoice in 14 days',
        'Business invoice - 30 days',
        'Two - Buy Now Pay Later on Invoice Terms',
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
                && in_array($row['value'] ?? null, self::OLD_DEFAULTS, true);
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
