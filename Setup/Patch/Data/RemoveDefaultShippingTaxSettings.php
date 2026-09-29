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
 * Deletes stored rows of the removed "Default shipping tax class" and
 * deprecated flat "Default shipping tax rate" fields (TWO-26073): the
 * shipping tax fallback now reads Magento core's tax/classes/shipping_tax_class.
 *
 * Brand-agnostic (every payment code's row matches) and idempotent: a
 * second run finds no matching rows and deletes nothing.
 */
class RemoveDefaultShippingTaxSettings implements DataPatchInterface
{
    public const KEYS = ['default_shipping_tax_class', 'default_shipping_tax_rate'];

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
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $deleted = false;
        foreach (self::KEYS as $key) {
            foreach ($this->storedRows($key) as $row) {
                $this->configWriter->delete((string)$row['path'], (string)$row['scope'], (int)$row['scope_id']);
                $deleted = true;
            }
        }

        if ($deleted) {
            $this->cacheTypeList->invalidate('config');
        }

        $connection->endSetup();

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedRows(string $key): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path'])
            ->where('path LIKE ?', 'payment/%/' . $key);

        return array_values(array_filter($connection->fetchAll($select), static function ($row) use ($key) {
            // LIKE treats `_` as a wildcard, so re-check the shape exactly.
            $segments = explode('/', (string)$row['path']);
            return count($segments) === 3 && $segments[0] === 'payment' && $segments[2] === $key;
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
