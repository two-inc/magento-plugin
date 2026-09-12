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
 * Carries stored values of the superseded `skip_confirm_*_check` admin toggle
 * onto `skip_confirm_token_check`. Without this a merchant who had the toggle
 * on silently reverts to the etc/config.xml default (0) after upgrade.
 *
 * The retired key is matched by shape rather than spelled out, because the word
 * it contained is the reason for the rename.
 *
 * Brand-agnostic (payment codes come from the rows actually present) and
 * scope-complete (every stored scope/scope_id row moves as-is, so inheritance
 * is unchanged). Idempotent: a second run finds no superseded rows and writes
 * nothing.
 */
class RenameSkipConfirmTokenCheck implements DataPatchInterface
{
    private const KEY_PREFIX = 'skip_confirm_';
    private const KEY_SUFFIX = '_check';
    private const NEW_KEY = self::KEY_PREFIX . 'token' . self::KEY_SUFFIX;

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

        $moved = false;
        foreach ($this->supersededRows() as $row) {
            $scope = (string)$row['scope'];
            $scopeId = (int)$row['scope_id'];
            [, $code] = explode('/', (string)$row['path']);

            $this->configWriter->save(
                'payment/' . $code . '/' . self::NEW_KEY,
                (string)$row['value'],
                $scope,
                $scopeId
            );
            $this->configWriter->delete((string)$row['path'], $scope, $scopeId);
            $moved = true;
        }

        if ($moved) {
            $this->cacheTypeList->invalidate('config');
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function supersededRows(): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from($this->moduleDataSetup->getTable('core_config_data'), ['scope', 'scope_id', 'path', 'value'])
            ->where('path LIKE ?', 'payment/%/' . self::KEY_PREFIX . '%' . self::KEY_SUFFIX);

        return array_values(array_filter($connection->fetchAll($select), static function ($row) {
            // LIKE treats `_` as a wildcard, so re-check the shape exactly.
            $segments = explode('/', (string)$row['path']);
            if (count($segments) !== 3 || $segments[0] !== 'payment') {
                return false;
            }
            $key = $segments[2];

            return $key !== self::NEW_KEY
                && strpos($key, self::KEY_PREFIX) === 0
                && substr($key, -strlen(self::KEY_SUFFIX)) === self::KEY_SUFFIX;
        }));
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases()
    {
        return [];
    }
}
