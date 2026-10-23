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
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Model\Config\EnvironmentMode;

/**
 * Rewrites stored Environment rows to `production`, `sandbox` or `staging`
 * at every scope (TWO-26604). `development` and anything else unrecognised
 * resolved to a host that does not exist and becomes `sandbox`; an empty row
 * is deleted, so the scope inherits again and ends on the config.xml default.
 *
 * Only the Two payment codes are touched (`two_payment` and the installed
 * brand's own), since `payment/<code>/mode` is a common key other payment
 * methods use too. Idempotent: canonical rows are left alone.
 */
class NormaliseEnvironmentMode implements DataPatchInterface
{
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
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        BrandRegistryInterface $brandRegistry
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->brandRegistry = $brandRegistry;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $wrote = false;
        foreach ($this->storedRows() as $row) {
            $path = (string)$row['path'];
            $scope = (string)$row['scope'];
            $scopeId = (int)$row['scope_id'];
            $stored = $row['value'];
            if ($stored === null || trim((string)$stored) === '') {
                $this->configWriter->delete($path, $scope, $scopeId);
                $wrote = true;
                continue;
            }
            $target = EnvironmentMode::migrate($stored);
            if ($target !== $stored) {
                $this->configWriter->save($path, $target, $scope, $scopeId);
                $wrote = true;
            }
        }
        if ($wrote) {
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
        $paths = array_values(array_unique(array_map(static function (string $code): string {
            return 'payment/' . $code . '/mode';
        }, ['two_payment', $this->brandRegistry->getCode()])));
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
            ->where('path IN (?)', $paths);
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
