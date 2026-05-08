<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Migrates merchant configuration written under the legacy ABN namespace
 * onto the canonical Two namespace.
 *
 * On ABN-fork installs prior to ABN-392 the plugin stored config under
 * `payment/abn_payment/*` (active flag, mode, API key, surcharge config,
 * payment terms, etc.). The unified plugin reads everything under
 * `payment/two_payment/*` regardless of brand, so this patch rewrites
 * existing rows in core_config_data on the next setup:upgrade run.
 *
 * Properties:
 *  - Idempotent: Magento tracks applied patches by class name, so this
 *    runs once and is skipped on subsequent setup:upgrade invocations.
 *  - Two-install safe: the WHERE clause matches no rows on Two-only
 *    installs, so the UPDATE is a no-op.
 *  - Encryption safe: the encrypted value column is untouched. Magento
 *    encryption is keyed on the install-wide crypt key, not on path.
 *  - Multi-scope safe: core_config_data rows carry their own scope
 *    (default/website/store) tags, which the path rewrite does not
 *    disturb.
 *  - Atomic: single multi-row UPDATE.
 */
class MigrateAbnConfigPaths implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $connection->update(
            $table,
            ['path' => new \Zend_Db_Expr(
                "REPLACE(path, 'payment/abn_payment/', 'payment/two_payment/')"
            )],
            "path LIKE 'payment/abn_payment/%'"
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
