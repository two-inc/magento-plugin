<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Minimal scripted stand-in for Magento's DB adapter, covering only the
 * select()->from()->where() chain the patch consumes via fetchAll().
 */
class RemoveConnection
{
    /** @var array<int, array<string, mixed>> core_config_data rows to return */
    public $rows = [];

    /** @var string|null */
    public $queriedTable;

    /** @var array<int, array{0: string, 1: mixed}> every select's where() calls, in order */
    public $recordedWheres = [];

    /**
     * A module data setup over this connection, prefixing table names with `prefix_`.
     */
    public function setup(): ModuleDataSetupInterface
    {
        return new class ($this) implements ModuleDataSetupInterface {
            /** @var RemoveConnection */
            private $connection;

            public function __construct(RemoveConnection $connection)
            {
                $this->connection = $connection;
            }

            public function getConnection()
            {
                return $this->connection;
            }

            public function getTable($tableName)
            {
                return 'prefix_' . $tableName;
            }
        };
    }

    public function startSetup(): void
    {
    }

    public function endSetup(): void
    {
    }

    public function select(): RemoveSelect
    {
        return new RemoveSelect();
    }

    /**
     * @param RemoveSelect $select
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll($select): array
    {
        $this->queriedTable = $select->table;
        $this->recordedWheres = array_merge($this->recordedWheres, $select->wheres);

        return $this->rows;
    }
}

/**
 * Records the from/where chain so RemoveConnection::fetchAll() can report
 * which table was queried.
 */
class RemoveSelect
{
    /** @var string|null */
    public $table;

    /** @var array<int, array{0: string, 1: mixed}> */
    public $wheres = [];

    public function from($table, $columns = '*'): self
    {
        $this->table = $table;

        return $this;
    }

    public function where($condition, $value = null): self
    {
        $this->wheres[] = [$condition, $value];

        return $this;
    }
}
