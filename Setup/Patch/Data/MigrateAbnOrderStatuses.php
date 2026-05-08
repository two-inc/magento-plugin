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
 * Migrates order-status codes from the legacy ABN namespace to the
 * canonical Two namespace.
 *
 * Touches every Magento table that records the status code as a string:
 *
 *  - sales_order_status            (the status registry)
 *  - sales_order_status_state      (state→status mapping)
 *  - sales_order                   (per-order current status)
 *  - sales_order_grid              (denormalised status for admin grid)
 *  - sales_order_status_history    (history rows)
 *
 * Mappings:
 *   abn_new             → two_new
 *   abn_failed          → two_failed
 *   pending_abn_payment → pending_two_payment
 *
 * Properties:
 *  - Idempotent: Magento tracks applied patches by class name. Runs
 *    once per merchant install.
 *  - Two-install safe: WHERE clauses match no rows on installs that
 *    never carried abn_* status codes.
 *  - Order-data safe: only renames, never deletes. Existing orders
 *    keep their state and history; only the status string changes.
 *  - Transactional: all 5 tables × 3 mappings run inside a single
 *    transaction so any single-statement failure rolls back the
 *    entire migration. setup:upgrade can then be re-run cleanly.
 *
 * Runs BEFORE Two\Gateway\Setup\Patch\Data\OrderStatuses (which
 * declares this class in its own getDependencies()) so that, on
 * ABN merchants, the status registry rows (sales_order_status) carry
 * the canonical codes by the time OrderStatuses' insert-or-skip guard
 * runs. OrderStatuses then sees the rows already present and does
 * nothing.
 */
class MigrateAbnOrderStatuses implements DataPatchInterface
{
    private const STATUS_MAPPINGS = [
        'abn_new' => 'two_new',
        'abn_failed' => 'two_failed',
        'pending_abn_payment' => 'pending_two_payment',
    ];

    private const STATUS_TABLES = [
        // table => [columns to rewrite]
        'sales_order_status' => ['status'],
        'sales_order_status_state' => ['status'],
        'sales_order' => ['status'],
        'sales_order_grid' => ['status'],
        'sales_order_status_history' => ['status'],
    ];

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
        $connection->beginTransaction();
        try {
            foreach (self::STATUS_TABLES as $table => $columns) {
                $resolvedTable = $this->moduleDataSetup->getTable($table);
                foreach ($columns as $column) {
                    foreach (self::STATUS_MAPPINGS as $oldCode => $newCode) {
                        $connection->update(
                            $resolvedTable,
                            [$column => $newCode],
                            [$column . ' = ?' => $oldCode]
                        );
                    }
                }
            }
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
