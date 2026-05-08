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
 * Migrates the Magento payment method code from the legacy ABN
 * namespace (abn_payment) to the canonical Two namespace
 * (two_payment).
 *
 * Touches:
 *  - sales_order_payment (method column)  — historical orders
 *  - quote_payment        (method column) — in-flight checkouts
 *
 * Properties:
 *  - Idempotent: Magento tracks applied patches by class name.
 *  - Two-install safe: WHERE clauses match no rows on installs where
 *    abn_payment was never a registered method.
 *  - Order-data safe: rename only, no deletion.
 *
 * Runs alongside MigrateAbnConfigPaths and MigrateAbnOrderStatuses
 * during a single setup:upgrade. The three patches are independent
 * (no inter-dependencies); they all migrate ABN namespacing onto
 * the canonical Two namespace.
 */
class MigrateAbnPaymentMethod implements DataPatchInterface
{
    private const OLD_METHOD_CODE = 'abn_payment';
    private const NEW_METHOD_CODE = 'two_payment';

    private const PAYMENT_TABLES = [
        'sales_order_payment',
        'quote_payment',
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
            foreach (self::PAYMENT_TABLES as $table) {
                $resolvedTable = $this->moduleDataSetup->getTable($table);
                $connection->update(
                    $resolvedTable,
                    ['method' => self::NEW_METHOD_CODE],
                    ['method = ?' => self::OLD_METHOD_CODE]
                );
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
