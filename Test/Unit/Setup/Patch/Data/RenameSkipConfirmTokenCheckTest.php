<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Patch\Data\RenameSkipConfirmTokenCheck;

/**
 * The renamed admin toggle must keep every merchant's stored value: each
 * superseded row moves to the new key at its own scope, the old row goes, and
 * a re-run is a no-op.
 *
 * The retired key is exercised by its shape, which is what the patch matches on
 * — the word it spelled is the reason for the rename.
 */
class RenameSkipConfirmTokenCheckTest extends TestCase
{
    private const OLD_PATH = 'payment/two_payment/skip_confirm_retired_check';
    private const NEW_PATH = 'payment/two_payment/skip_confirm_token_check';

    /** @var RenameConnection */
    private $connection;

    /** @var array<int, array{0: string, 1: string, 2: string, 3: int}> */
    private $saves = [];

    /** @var array<int, array{0: string, 1: string, 2: int}> */
    private $deletes = [];

    /** @var TypeListInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cacheTypeList;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildPatch(array $rows): RenameSkipConfirmTokenCheck
    {
        $this->connection = new RenameConnection();
        $this->connection->rows = $rows;

        $connection = $this->connection;
        $moduleDataSetup = new class ($connection) implements ModuleDataSetupInterface {
            /** @var RenameConnection */
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

        $saves = &$this->saves;
        $deletes = &$this->deletes;
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(
            function ($path, $value, $scope, $scopeId) use (&$saves) {
                $saves[] = [$path, (string)$value, (string)$scope, (int)$scopeId];
                return null;
            }
        );
        $writer->method('delete')->willReturnCallback(
            function ($path, $scope, $scopeId) use (&$deletes) {
                $deletes[] = [$path, (string)$scope, (int)$scopeId];
                return null;
            }
        );

        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        return new RenameSkipConfirmTokenCheck($moduleDataSetup, $writer, $this->cacheTypeList);
    }

    private static function row(string $scope, int $scopeId, string $path, string $value): array
    {
        return ['scope' => $scope, 'scope_id' => $scopeId, 'path' => $path, 'value' => $value];
    }

    public function testAStoredValueMovesToTheNewKeyAndTheOldRowGoes(): void
    {
        $patch = $this->buildPatch([self::row('default', 0, self::OLD_PATH, '1')]);

        $this->cacheTypeList->expects($this->once())->method('invalidate')->with('config');
        $patch->apply();

        $this->assertSame([[self::NEW_PATH, '1', 'default', 0]], $this->saves);
        $this->assertSame([[self::OLD_PATH, 'default', 0]], $this->deletes);
    }

    /**
     * @dataProvider scopedRowsProvider
     */
    public function testEveryStoredScopeMovesAtItsOwnScope(string $scope, int $scopeId, string $case): void
    {
        $patch = $this->buildPatch([self::row($scope, $scopeId, self::OLD_PATH, '1')]);

        $patch->apply();

        $this->assertSame([[self::NEW_PATH, '1', $scope, $scopeId]], $this->saves, $case);
        $this->assertSame([[self::OLD_PATH, $scope, $scopeId]], $this->deletes, $case);
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

    public function testAnOffValueIsCarriedForwardRatherThanDropped(): void
    {
        $patch = $this->buildPatch([self::row('stores', 1, self::OLD_PATH, '0')]);

        $patch->apply();

        $this->assertSame([[self::NEW_PATH, '0', 'stores', 1]], $this->saves);
    }

    public function testEveryBrandCodePresentInConfigMoves(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::OLD_PATH, '1'),
            self::row('default', 0, 'payment/two_abn_payment/skip_confirm_withdrawn_check', '1'),
        ]);

        $patch->apply();

        $this->assertSame(
            [self::NEW_PATH, 'payment/two_abn_payment/skip_confirm_token_check'],
            array_column($this->saves, 0)
        );
    }

    public function testRerunAfterTheRenameWritesNothing(): void
    {
        // State the first run leaves behind: only the new key is stored.
        $patch = $this->buildPatch([self::row('default', 0, self::NEW_PATH, '1')]);

        $this->cacheTypeList->expects($this->never())->method('invalidate');
        $patch->apply();

        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->deletes);
    }

    public function testUnrelatedPathsMatchedOnlyByTheLikeWildcardAreIgnored(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, 'payment/two_payment/skipXconfirm_somethingXcheck', '1'),
            self::row('default', 0, 'payment/two_payment/enable_company_search', '1'),
        ]);

        $patch->apply();

        $this->assertSame([], $this->saves);
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
            [['path LIKE ?', 'payment/%/skip_confirm_%_check']],
            $this->connection->recordedWheres
        );
    }

    public function testGetDependenciesAndAliasesAreEmpty(): void
    {
        $patch = $this->buildPatch([]);

        $this->assertSame([], RenameSkipConfirmTokenCheck::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }
}

/**
 * Minimal scripted stand-in for Magento's DB adapter, covering only the
 * select()->from()->where() chain the patch consumes via fetchAll().
 */
class RenameConnection
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

    public function select(): RenameSelect
    {
        return new RenameSelect();
    }

    /**
     * @param RenameSelect $select
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
 * Records the from/where chain so RenameConnection::fetchAll() can report
 * which table was queried.
 */
class RenameSelect
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
