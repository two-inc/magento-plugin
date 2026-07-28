<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Patch\Data\CollapseAddressSearchToggle;

/**
 * TWO-25202: the patch must preserve every merchant's *effective*
 * address-lookup behaviour when the old
 * `enable_company_search && enable_address_search` conjunction collapses
 * into `enable_address_search` alone — at default, website and store
 * scope, and idempotently across re-runs.
 */
class CollapseAddressSearchToggleTest extends TestCase
{
    private const COMPANY_PATH = 'payment/two_payment/enable_company_search';
    private const ADDRESS_PATH = 'payment/two_payment/enable_address_search';

    /** @var CollapseConnection */
    private $connection;

    /** @var array<int, array{0: string, 1: string, 2: string, 3: int}> recorded writer saves */
    private $saves = [];

    /** @var TypeListInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cacheTypeList;

    /**
     * @param array<int, array{scope: string, scope_id: int, path: string, value: string}> $rows
     */
    private function buildPatch(array $rows, bool $xmlDefaultsOn = true): CollapseAddressSearchToggle
    {
        $this->connection = new CollapseConnection();
        $this->connection->rows = $rows;

        $connection = $this->connection;
        $moduleDataSetup = new class ($connection) implements ModuleDataSetupInterface {
            /** @var CollapseConnection */
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

        // Default-scope fallback when a key has no row in the walked
        // chain: etc/config.xml ships 1 for both.
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($xmlDefaultsOn);

        $saves = &$this->saves;
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(
            function ($path, $value, $scope, $scopeId) use (&$saves) {
                $saves[] = [$path, (string)$value, (string)$scope, (int)$scopeId];
                return null;
            }
        );

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);
        $storeManager->method('getStores')->willReturn([$store]);

        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        return new CollapseAddressSearchToggle(
            $moduleDataSetup,
            $scopeConfig,
            $writer,
            $storeManager,
            $this->cacheTypeList
        );
    }

    private static function row(string $scope, int $scopeId, string $path, string $value): array
    {
        return ['scope' => $scope, 'scope_id' => $scopeId, 'path' => $path, 'value' => $value];
    }

    public function testNothingIsWrittenWhenTheOldAndWasAlreadyOnEverywhere(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::COMPANY_PATH, '1'),
            self::row('default', 0, self::ADDRESS_PATH, '1'),
        ]);

        $this->cacheTypeList->expects($this->never())->method('invalidate');
        $patch->apply();

        $this->assertSame([], $this->saves);
    }

    public function testCompanySearchOffAtDefaultPinsAddressSearchOffOnceAtDefault(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::COMPANY_PATH, '0'),
            self::row('default', 0, self::ADDRESS_PATH, '1'),
        ]);

        $this->cacheTypeList->expects($this->once())->method('invalidate')->with('config');
        $patch->apply();

        // One write only: the website and store scopes inherit the new 0,
        // so no redundant explicit rows are created.
        $this->assertSame(
            [[self::ADDRESS_PATH, '0', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]],
            $this->saves
        );
    }

    public function testCompanySearchOffAtWebsiteScopeOnlyPinsThatWebsite(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::COMPANY_PATH, '1'),
            self::row('default', 0, self::ADDRESS_PATH, '1'),
            self::row('websites', 1, self::COMPANY_PATH, '0'),
        ]);

        $patch->apply();

        // Website scope loses address lookup; the store under it inherits
        // the pinned 0, so it needs no row of its own.
        $this->assertSame(
            [[self::ADDRESS_PATH, '0', ScopeInterface::SCOPE_WEBSITES, 1]],
            $this->saves
        );
    }

    public function testCompanySearchOffAtStoreScopeOnlyPinsThatStore(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::COMPANY_PATH, '1'),
            self::row('default', 0, self::ADDRESS_PATH, '1'),
            self::row('stores', 1, self::COMPANY_PATH, '0'),
        ]);

        $patch->apply();

        $this->assertSame(
            [[self::ADDRESS_PATH, '0', ScopeInterface::SCOPE_STORES, 1]],
            $this->saves
        );
    }

    public function testStoreScopeReEnableSurvivesADefaultLevelPin(): void
    {
        // Old effective: default 0 && 1 = OFF, store 1 && 1 = ON. The
        // store must keep address lookup, so an explicit 1 stays untouched
        // while the default is pinned off.
        $patch = $this->buildPatch([
            self::row('default', 0, self::COMPANY_PATH, '0'),
            self::row('default', 0, self::ADDRESS_PATH, '1'),
            self::row('stores', 1, self::COMPANY_PATH, '1'),
            self::row('stores', 1, self::ADDRESS_PATH, '1'),
        ]);

        $patch->apply();

        $this->assertSame(
            [[self::ADDRESS_PATH, '0', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]],
            $this->saves
        );
    }

    public function testRerunAfterCollapseWritesNothing(): void
    {
        // State the first run leaves behind.
        $patch = $this->buildPatch([
            self::row('default', 0, self::COMPANY_PATH, '0'),
            self::row('default', 0, self::ADDRESS_PATH, '0'),
        ]);

        $patch->apply();

        $this->assertSame([], $this->saves);
    }

    public function testNeverTurnsAddressSearchOn(): void
    {
        // Old effective: OFF (address stored 0), company on. The patch must
        // not "restore" address lookup anywhere.
        $patch = $this->buildPatch([
            self::row('default', 0, self::COMPANY_PATH, '1'),
            self::row('default', 0, self::ADDRESS_PATH, '0'),
        ]);

        $patch->apply();

        // Nothing to write (the stored 0 already equals the collapsed
        // value), and in particular no "restore to 1" write anywhere.
        $this->assertSame([], $this->saves);
        $this->assertSame([], array_filter($this->saves, static function ($save) {
            return $save[1] !== '0';
        }));
    }

    public function testMigratesEveryBrandCodePresentInConfig(): void
    {
        $patch = $this->buildPatch([
            self::row('default', 0, self::COMPANY_PATH, '0'),
            self::row('default', 0, self::ADDRESS_PATH, '1'),
            self::row('default', 0, 'payment/two_abn_payment/enable_company_search', '0'),
            self::row('default', 0, 'payment/two_abn_payment/enable_address_search', '1'),
        ]);

        $patch->apply();

        $paths = array_column($this->saves, 0);
        $this->assertContains(self::ADDRESS_PATH, $paths);
        $this->assertContains('payment/two_abn_payment/enable_address_search', $paths);
    }

    public function testQueriesTheCoreConfigDataTableWithThePrefix(): void
    {
        $patch = $this->buildPatch([]);

        $patch->apply();

        $this->assertSame('prefix_core_config_data', $this->connection->queriedTable);
    }
}

/**
 * Minimal scripted stand-in for Magento's DB adapter, covering only what
 * the patch touches: a select()->from()->where()->orWhere() chain
 * consumed by fetchAll(), plus start/endSetup().
 */
class CollapseConnection
{
    /** @var array<int, array<string, mixed>> core_config_data rows to return */
    public $rows = [];

    /** @var string|null */
    public $queriedTable;

    public function startSetup(): void
    {
    }

    public function endSetup(): void
    {
    }

    public function select(): CollapseSelect
    {
        return new CollapseSelect();
    }

    /**
     * @param CollapseSelect $select
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll($select): array
    {
        $this->queriedTable = $select->table;

        return $this->rows;
    }
}

/**
 * Records the from/where chain so CollapseConnection::fetchAll() can
 * report which table was queried.
 */
class CollapseSelect
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

    public function orWhere($condition, $value = null): self
    {
        $this->wheres[] = [$condition, $value];

        return $this;
    }
}
