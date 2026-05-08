<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\DB\Sql\Expression;
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
 *    installs, so the migration is a no-op.
 *  - Collision-safe: when both namespaces are populated at the same
 *    scope (rare — seen on partial re-deploys or hand-rolled imports),
 *    the canonical `payment/two_payment/*` row is preferred and the
 *    legacy `payment/abn_payment/*` sibling is dropped before the
 *    rename. This protects merchant-set canonical values from being
 *    clobbered by stale legacy rows, and avoids violating the UNIQUE
 *    INDEX on (scope, scope_id, path).
 *  - Encryption safe (stock Magento): the encrypted value column is
 *    untouched. Magento's stock Encryptor keys on the install-wide crypt
 *    key, not on path. Custom encryptor modules that derive a per-path
 *    subkey are out of scope.
 *  - Multi-scope safe: core_config_data rows carry their own scope tags
 *    (default/websites/stores), which the path rewrite does not disturb.
 *  - Transactional: the destination-clear + path-rewrite run inside a
 *    single transaction so a partial failure rolls back cleanly and
 *    `setup:upgrade` can be re-run.
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

        $connection->beginTransaction();
        try {
            // Defensive: drop any legacy `payment/abn_payment/*` rows
            // whose canonical sibling already exists at the same scope.
            // A merchant whose DB has BOTH namespaces populated (rare —
            // seen on partial re-deploys and hand-rolled imports) would
            // otherwise hit the UNIQUE INDEX (scope, scope_id, path) on
            // the rename. Prefer the canonical row: it represents the
            // merchant's current intent under the unified plugin, while
            // the legacy row is residue from the pre-ABN-392 namespace.
            $connection->query(
                "DELETE legacy FROM {$table} legacy"
                . " INNER JOIN {$table} canonical"
                . "   ON canonical.scope = legacy.scope"
                . "  AND canonical.scope_id = legacy.scope_id"
                . "  AND canonical.path = REPLACE(legacy.path, 'payment/abn_payment/', 'payment/two_payment/')"
                . " WHERE legacy.path LIKE 'payment/abn_payment/%'"
            );

            $connection->update(
                $table,
                ['path' => new Expression(
                    "REPLACE(path, 'payment/abn_payment/', 'payment/two_payment/')"
                )],
                "path LIKE 'payment/abn_payment/%'"
            );

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

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
