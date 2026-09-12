<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Patch\Data\RemoveSkipConfirmTokenCheck;
use Two\Gateway\Setup\Patch\Data\RenameSkipConfirmTokenCheck;

/**
 * The removed admin field's stored rows are deleted, at whatever scope
 * and under whatever brand code they were saved, and a re-run is a no-op.
 */
class RemoveSkipConfirmTokenCheckTest extends TestCase
{
    private const PATH = 'payment/two_payment/skip_confirm_token_check';

    /** @var RemoveConnection */
    private $connection;

    /** @var array<int, array{0: string, 1: string, 2: int}> */
    private $deletes = [];

    /** @var TypeListInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cacheTypeList;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildPatch(array $rows): RemoveSkipConfirmTokenCheck
    {
        $this->connection = new RemoveConnection();
        $this->connection->rows = $rows;

        $connection = $this->connection;
        $moduleDataSetup = new class ($connection) implements ModuleDataSetupInterface {
            /** @var RemoveConnection */
            private $connection;

            public function __construct($connection)
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

        $deletes = &$this->deletes;
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('delete')->willReturnCallback(
            function ($path, $scope, $scopeId) use (&$deletes) {
                $deletes[] = [$path, (string)$scope, (int)$scopeId];
                return null;
            }
        );

        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        return new RemoveSkipConfirmTokenCheck($moduleDataSetup, $writer, $this->cacheTypeList);
    }

    private static function row(string $scope, int $scopeId, string $path): array
    {
        return ['scope' => $scope, 'scope_id' => $scopeId, 'path' => $path];
    }

    public function testAStoredValueIsDeletedAndInvalidatesConfigCache(): void
    {
        $patch = $this->buildPatch([self::row('default', 0, self::PATH)]);

        $this->cacheTypeList->expects($this->once())->method('invalidate')->with('config');
        $patch->apply();

        $this->assertSame([[self::PATH, 'default', 0]], $this->deletes);
    }

    /**
     * @dataProvider scopedRowsProvider
     */
    public function testEveryStoredScopeIsDeletedAtItsOwnScope(string $scope, int $scopeId, string $case): void
    {
        $patch = $this->buildPatch([self::row($scope, $scopeId, self::PATH)]);

        $patch->apply();

        $this->assertSame([[self::PATH, $scope, $scopeId]], $this->deletes, $case);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public function scopedRowsProvider(): array
    {
        return [
            'default scope' => ['default', 0, 'default-scope override'],
            'website scope' => ['websites', 2, 'per-website override'],
            'store scope' => ['stores', 3, 'per-store override'],
        ];
    }

    public function testEveryBrandCodePresentInConfigIsDeleted(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::PATH),
            self::row('default', 0, 'payment/two_abn_payment/skip_confirm_token_check'),
        ]);

        $patch->apply();

        $this->assertSame(
            [self::PATH, 'payment/two_abn_payment/skip_confirm_token_check'],
            array_column($this->deletes, 0)
        );
    }

    public function testRerunAfterRemovalDeletesNothing(): void
    {
        $patch = $this->buildPatch([]);

        $this->cacheTypeList->expects($this->never())->method('invalidate');
        $patch->apply();

        $this->assertSame([], $this->deletes);
    }

    public function testUnrelatedPathsMatchedOnlyByTheLikeWildcardAreIgnored(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, 'payment/two_payment/skipXconfirm_token_check'),
            self::row('default', 0, 'payment/two_payment/enable_company_search'),
        ]);

        $patch->apply();

        $this->assertSame([], $this->deletes);
    }

    public function testQueriesTheCoreConfigDataTableWithThePrefix(): void
    {
        $patch = $this->buildPatch([]);

        $patch->apply();

        $this->assertSame('prefix_core_config_data', $this->connection->queriedTable);
    }

    public function testTheStoredRowsAreSelectedByTheRetiredKeyShape(): void
    {
        $patch = $this->buildPatch([]);

        $patch->apply();

        $this->assertSame(
            [['path LIKE ?', 'payment/%/skip_confirm_token_check']],
            $this->connection->recordedWheres
        );
    }

    public function testDependsOnTheRenamePatch(): void
    {
        $this->assertSame([RenameSkipConfirmTokenCheck::class], RemoveSkipConfirmTokenCheck::getDependencies());
    }

    public function testGetAliasesIsEmpty(): void
    {
        $patch = $this->buildPatch([]);

        $this->assertSame([], $patch->getAliases());
    }
}

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

    /** @var array<int, array{0: string, 1: mixed}> */
    public $recordedWheres = [];

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
        $this->recordedWheres = $select->wheres;

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
