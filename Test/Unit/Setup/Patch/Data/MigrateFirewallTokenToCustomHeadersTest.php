<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Patch\Data\MigrateFirewallTokenToCustomHeaders;

/**
 * A merchant whose network gates on the retired firewall-token header keeps
 * sending it after upgrade: the stored token becomes one X-WAF-TOKEN row in
 * the table that replaced the field, at the scope it was configured on.
 */
class MigrateFirewallTokenToCustomHeadersTest extends TestCase
{
    private const TOKEN_PATH = 'payment/two_payment/firewall_token';
    private const BROWSER_PATH = 'payment/two_payment/firewall_token_browser';
    private const HEADERS_PATH = 'payment/two_payment/custom_headers';

    /** @var MigrateConnection */
    private $connection;

    /** @var array<int, array{0: string, 1: mixed, 2: string, 3: int}> */
    private $saves = [];

    /** @var array<int, array{0: string, 1: string, 2: int}> */
    private $deletes = [];

    /** @var TypeListInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cacheTypeList;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $storeRows
     */
    private function buildPatch(array $rows, array $storeRows = []): MigrateFirewallTokenToCustomHeaders
    {
        $this->connection = new MigrateConnection();
        $this->connection->rows = $rows;
        $this->connection->storeRows = $storeRows;

        $connection = $this->connection;
        $moduleDataSetup = new class ($connection) implements ModuleDataSetupInterface {
            /** @var MigrateConnection */
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
                $saves[] = [$path, $value, (string)$scope, (int)$scopeId];
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

        return new MigrateFirewallTokenToCustomHeaders($moduleDataSetup, $writer, $this->cacheTypeList);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function row(string $scope, int $scopeId, string $path, $value): array
    {
        return ['scope' => $scope, 'scope_id' => $scopeId, 'path' => $path, 'value' => $value];
    }

    /**
     * @param array<int, array<int, mixed>> $saves
     * @return array<string, mixed>
     */
    private static function decodeOnlySave(array $saves): array
    {
        self::assertCount(1, $saves);

        return json_decode((string)$saves[0][1], true);
    }

    /**
     * Given a token and whatever browser flag was stored beside it; When the
     * patch runs; Then one row carries both onto the new table.
     *
     * @dataProvider browserFlagRows
     *
     * @param array<int, array<string, mixed>> $flagRows
     */
    public function testTheTokenBecomesOneRowCarryingTheBrowserFlag(
        array $flagRows,
        string $expectedFlag,
        string $description
    ): void {
        $patch = $this->buildPatch(
            array_merge([self::row('default', 0, self::TOKEN_PATH, 'waf-token')], $flagRows)
        );

        $patch->apply();

        $this->assertSame(
            ['_1' => ['name' => 'X-WAF-TOKEN', 'value' => 'waf-token', 'send_from_browser' => $expectedFlag]],
            self::decodeOnlySave($this->saves),
            $description
        );
        $this->assertSame([self::HEADERS_PATH, 'default', 0], [
            $this->saves[0][0],
            $this->saves[0][2],
            $this->saves[0][3],
        ]);
    }

    /**
     * @return array<string, array{0: array<int, array<string, mixed>>, 1: string, 2: string}>
     */
    public static function browserFlagRows(): array
    {
        return [
            'flag on' => [
                [self::row('default', 0, self::BROWSER_PATH, '1')],
                '1',
                'a merchant who had the browser toggle on keeps it',
            ],
            'flag off' => [
                [self::row('default', 0, self::BROWSER_PATH, '0')],
                '',
                'the toggle off stays off',
            ],
            'flag never stored' => [
                [],
                '',
                'an unstored toggle was the shipped default, off',
            ],
        ];
    }

    /**
     * A token overridden per store inherits the flag from the default scope
     * unless that store overrode it too — the same value the old pair of
     * fields resolved to.
     *
     * @dataProvider scopedFlagResolution
     */
    public function testAScopedTokenResolvesTheFlagItWouldHaveInherited(
        array $flagRows,
        string $expectedFlag,
        string $description
    ): void {
        $patch = $this->buildPatch(
            array_merge([self::row('stores', 3, self::TOKEN_PATH, 'store-token')], $flagRows),
            [['store_id' => 3, 'website_id' => 2]]
        );

        $patch->apply();

        $this->assertSame($expectedFlag, self::decodeOnlySave($this->saves)['_1']['send_from_browser'], $description);
        $this->assertSame(['stores', 3], [$this->saves[0][2], $this->saves[0][3]]);
    }

    /**
     * @return array<string, array{0: array<int, array<string, mixed>>, 1: string, 2: string}>
     */
    public static function scopedFlagResolution(): array
    {
        return [
            'own scope wins' => [
                [
                    self::row('default', 0, self::BROWSER_PATH, '0'),
                    self::row('stores', 3, self::BROWSER_PATH, '1'),
                ],
                '1',
                'the store\'s own override decides',
            ],
            'inherits the default' => [
                [self::row('default', 0, self::BROWSER_PATH, '1')],
                '1',
                'with no override the store inherited the default scope',
            ],
            'the website beats the default' => [
                [
                    self::row('default', 0, self::BROWSER_PATH, '0'),
                    self::row('websites', 2, self::BROWSER_PATH, '1'),
                ],
                '1',
                "the store's own website is the next scope up, not the default",
            ],
            'the store beats its website' => [
                [
                    self::row('websites', 2, self::BROWSER_PATH, '1'),
                    self::row('stores', 3, self::BROWSER_PATH, '0'),
                ],
                '',
                'the nearest scope decides',
            ],
            'another website is not this one' => [
                [
                    self::row('default', 0, self::BROWSER_PATH, '0'),
                    self::row('websites', 9, self::BROWSER_PATH, '1'),
                ],
                '',
                'a flag on a website this store does not belong to is not inherited',
            ],
        ];
    }

    public function testEveryBrandCodeAndScopePresentIsMigrated(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::TOKEN_PATH, 'base-token'),
            self::row('websites', 2, 'payment/two_overlay_payment/firewall_token', 'overlay-token'),
        ]);

        $patch->apply();

        $this->assertSame(
            [
                [self::HEADERS_PATH, 'default', 0],
                ['payment/two_overlay_payment/custom_headers', 'websites', 2],
            ],
            array_map(static fn(array $save) => [$save[0], $save[2], $save[3]], $this->saves)
        );
    }

    public function testTheRetiredRowsAreDeletedAndTheConfigCacheInvalidated(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::TOKEN_PATH, 'waf-token'),
            self::row('default', 0, self::BROWSER_PATH, '1'),
        ]);

        $this->cacheTypeList->expects($this->once())->method('invalidate')->with('config');
        $patch->apply();

        $this->assertSame(
            [[self::TOKEN_PATH, 'default', 0], [self::BROWSER_PATH, 'default', 0]],
            $this->deletes
        );
    }

    public function testABlankTokenIsDroppedRatherThanMigratedAsAnEmptyHeader(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::TOKEN_PATH, '   '),
            self::row('default', 0, self::BROWSER_PATH, '1'),
        ]);

        $patch->apply();

        $this->assertSame([], $this->saves);
        $this->assertSame(
            [[self::TOKEN_PATH, 'default', 0], [self::BROWSER_PATH, 'default', 0]],
            $this->deletes,
            'the retired rows still go, there is just nothing to carry over'
        );
    }

    public function testAnExistingTableAtTheSameScopeIsNeverOverwritten(): void
    {
        $existing = '{"_1":{"name":"X-Mine","value":"keep","send_from_browser":""}}';
        $patch = $this->buildPatch([
            self::row('default', 0, self::TOKEN_PATH, 'waf-token'),
            self::row('default', 0, self::HEADERS_PATH, $existing),
        ]);

        $patch->apply();

        $this->assertSame([], $this->saves, "the admin's own list is authoritative");
    }

    public function testRerunAfterMigrationChangesNothing(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::HEADERS_PATH, '{"_1":{"name":"X-WAF-TOKEN","value":"a"}}'),
        ]);

        $this->cacheTypeList->expects($this->never())->method('invalidate');
        $patch->apply();

        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->deletes);
    }

    public function testUnrelatedPathsMatchedOnlyByTheLikeWildcardAreIgnored(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, 'payment/two_payment/firewallXtoken', 'x'),
            self::row('default', 0, 'payment/two_payment/two/firewall_token', 'x'),
            self::row('default', 0, 'payment/two_payment/enable_company_search', '1'),
        ]);

        $patch->apply();

        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->deletes);
    }

    public function testQueriesTheCoreConfigDataTableWithThePrefix(): void
    {
        $patch = $this->buildPatch([]);

        $patch->apply();

        $this->assertSame('prefix_core_config_data', $this->connection->queriedTable);
    }

    /**
     * The brand code is the only wildcard. An unescaped `_` in the key would
     * make `firewall_token` match a neighbouring field name too.
     */
    public function testTheQueryMatchesOnlyTheKeysThisPatchOwns(): void
    {
        $patch = $this->buildPatch([]);

        $patch->apply();

        $this->assertSame(
            [
                ["path LIKE ? ESCAPE '\\\\'", 'payment/%/firewall\_token%'],
                ["path LIKE ? ESCAPE '\\\\'", 'payment/%/custom\_headers'],
            ],
            $this->connection->recordedWheres
        );
    }

    public function testGetDependenciesAndAliasesAreEmpty(): void
    {
        $patch = $this->buildPatch([]);

        $this->assertSame([], MigrateFirewallTokenToCustomHeaders::getDependencies());
        $this->assertSame([], $patch->getAliases());
    }
}

/**
 * Minimal scripted stand-in for Magento's DB adapter, covering only the
 * select()->from()->where()->orWhere() chain the patch consumes via fetchAll().
 */
class MigrateConnection
{
    /** @var array<int, array<string, mixed>> core_config_data rows to return */
    public $rows = [];

    /** @var array<int, array<string, mixed>> store rows to return */
    public $storeRows = [];

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

    public function select(): MigrateSelect
    {
        return new MigrateSelect();
    }

    /**
     * @param MigrateSelect $select
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll($select): array
    {
        if ($select->table === 'prefix_store') {
            return $this->storeRows;
        }
        $this->queriedTable = $select->table;
        $this->recordedWheres = $select->wheres;

        return $this->rows;
    }
}

class MigrateSelect
{
    /** @var string|null */
    public $table;

    /** @var array<int, array{0: string, 1: mixed}> */
    public $wheres = [];

    /**
     * @param string $table
     * @param array<int, string> $columns
     */
    public function from($table, $columns = []): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * @param string $condition
     * @param mixed $value
     */
    public function where($condition, $value = null): self
    {
        $this->wheres[] = [$condition, $value];

        return $this;
    }

    /**
     * @param string $condition
     * @param mixed $value
     */
    public function orWhere($condition, $value = null): self
    {
        $this->wheres[] = [$condition, $value];

        return $this;
    }
}
