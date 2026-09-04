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
 * Deletes stored `skip_confirm_token_check` rows (TWO-25386 follow-up):
 * the admin field is removed — Controller\Payment\Confirm never consumed
 * it, so the toggle had nothing left to control. A stored value that
 * survives the field's removal is an orphaned core_config_data row,
 * harmless but pointless to keep.
 *
 * Brand-agnostic (every payment code's row matches, same shape as
 * RenameSkipConfirmTokenCheck) and idempotent: a second run finds no
 * matching rows and deletes nothing.
 */
class RemoveSkipConfirmTokenCheck implements DataPatchInterface
{
    private const KEY = 'skip_confirm_token_check';

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
        foreach ($this->storedRows() as $row) {
            $this->configWriter->delete((string)$row['path'], (string)$row['scope'], (int)$row['scope_id']);
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
    private function storedRows(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path'])
            ->where('path LIKE ?', 'payment/%/' . self::KEY);

        return array_values(array_filter($connection->fetchAll($select), static function ($row) {
            // LIKE treats `_` as a wildcard, so re-check the shape exactly.
            $segments = explode('/', (string)$row['path']);
            return count($segments) === 3 && $segments[0] === 'payment' && $segments[2] === self::KEY;
        }));
    }

    /**
     * @return array
     */
    public static function getDependencies(): array
    {
        return [RenameSkipConfirmTokenCheck::class];
    }

    /**
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }
}
