<?php
/**
 * Stub for Magento\Framework\App\ResourceConnection and the slice of
 * Magento\Framework\DB\Adapter\AdapterInterface a fee-line provider reads.
 */
declare(strict_types=1);

namespace Magento\Framework\DB\Adapter {

    if (!interface_exists(AdapterInterface::class, false)) {
        interface AdapterInterface
        {
            public function isTableExists($tableName);

            public function quoteIdentifier($identifier);

            public function fetchAll($sql, $bind = []);
        }
    }
}

namespace Magento\Framework\App {

    use Magento\Framework\DB\Adapter\AdapterInterface;

    if (!class_exists(ResourceConnection::class, false)) {
        class ResourceConnection
        {
            public function getConnection($resourceName = 'default')
            {
                return null;
            }

            public function getTableName($modelEntity, $connectionName = 'default')
            {
                return $modelEntity;
            }
        }
    }
}
