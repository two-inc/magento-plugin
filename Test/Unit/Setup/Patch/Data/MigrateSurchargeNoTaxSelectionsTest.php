<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;
use Two\Gateway\Setup\Patch\Data\MigrateSurchargeNoTaxSelections;

/**
 * Coverage for the retirement migration: stored selections of the
 * plugin-provisioned "no tax" Product Tax Class must be repointed at
 * core "None" ('0') at every scope and for every brand code, and the
 * tax_class row itself must never be deleted.
 */
class MigrateSurchargeNoTaxSelectionsTest extends TestCase
{
    /** @var MigrationFakeConnection */
    private $connection;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $log;

    /** @var ReinitableConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $reinitableConfig;

    /** @var MigrateSurchargeNoTaxSelections */
    private $patch;

    protected function setUp(): void
    {
        $this->connection = new MigrationFakeConnection();
        $this->log = $this->createMock(LogRepository::class);
        $this->reinitableConfig = $this->createMock(ReinitableConfigInterface::class);

        $connection = $this->connection;
        $moduleDataSetup = new class($connection) implements ModuleDataSetupInterface {
            /** @var MigrationFakeConnection */
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

        $this->patch = new MigrateSurchargeNoTaxSelections(
            $moduleDataSetup,
            $this->log,
            $this->reinitableConfig
        );
    }

    public function testRepointsStoredSelectionsAtCoreNone(): void
    {
        $this->connection->existingClassId = '17';
        $this->connection->updatedRows = 3;

        $this->patch->apply();

        $this->assertCount(1, $this->connection->updates);
        [$table, $bind, $where] = $this->connection->updates[0];
        $this->assertSame('prefix_core_config_data', $table);
        $this->assertSame(['value' => '0'], $bind);
        // Exact pattern, not just "contains %": a looser 'payment/%' would
        // rewrite every payment config row in the table.
        $this->assertSame('payment/%/surcharge\_tax\_class', $where['path LIKE ?']);
        $this->assertSame('17', $where['value = ?']);
    }

    /**
     * The class id arrives from fetchOne() and may be an int on some adapters.
     * It must be bound as a string, because core_config_data.value is a text
     * column and a numeric bind would rely on MySQL's coercion.
     */
    public function testClassIdIsBoundAsAStringEvenWhenTheAdapterReturnsAnInt(): void
    {
        $this->connection->existingClassId = 17;

        $this->patch->apply();

        [, , $where] = $this->connection->updates[0];
        $this->assertSame('17', $where['value = ?']);
    }

    public function testConfigCacheIsReinitialisedAfterTheRewrite(): void
    {
        $this->connection->existingClassId = '17';
        $this->reinitableConfig->expects($this->once())->method('reinit');

        $this->patch->apply();
    }

    public function testMatchesEveryBrandCodeAndScopeViaPathWildcard(): void
    {
        // Brand overlays save the same field under their own payment
        // method code, and the same path exists at default / website /
        // store scope. A path wildcard with no scope filter is what
        // makes the migration scope- and brand-agnostic; a literal
        // `payment/two_payment/...` path or a scope predicate would
        // leave overlay or store-scoped rows behind.
        $this->connection->existingClassId = '17';

        $this->patch->apply();

        [, , $where] = $this->connection->updates[0];
        $this->assertStringStartsWith('payment/%/', $where['path LIKE ?']);
        $this->assertArrayNotHasKey('scope = ?', $where);
        $this->assertArrayNotHasKey('scope_id = ?', $where);
    }

    public function testNoOpWhenProvisionedClassWasNeverPresent(): void
    {
        // Fresh installs (and installs where the class was already
        // removed by hand) must not have their config touched at all.
        $this->connection->existingClassId = false;

        $this->patch->apply();

        $this->assertSame([], $this->connection->updates);
    }

    public function testLooksUpTheProvisionedClassByNameAndProductType(): void
    {
        $this->connection->existingClassId = '17';

        $this->patch->apply();

        $this->assertSame(
            SurchargeTaxCalculator::NO_TAX_CLASS_NAME,
            $this->connection->classLookupWheres['class_name = ?'] ?? null
        );
        $this->assertSame('PRODUCT', $this->connection->classLookupWheres['class_type = ?'] ?? null);
    }

    public function testNeverDeletesTheTaxClassRow(): void
    {
        // Deliberate: a product's tax class is an EAV attribute value
        // with no foreign key, so deleting the row would silently
        // orphan any product assigned to it.
        $this->connection->existingClassId = '17';

        $this->patch->apply();

        $this->assertSame(0, $this->connection->deleteCalls);
    }

    public function testLogsMigratedRowCountAndAttachedRuleCount(): void
    {
        $this->connection->existingClassId = '17';
        $this->connection->updatedRows = 2;
        $this->connection->attachedRuleCount = 1;

        $this->log->expects($this->once())->method('addDebugLog')
            ->with(
                $this->stringContains(SurchargeTaxCalculator::NO_TAX_CLASS_NAME),
                $this->callback(function ($context) {
                    return $context['tax_class_id'] === 17
                        && $context['migrated_config_rows'] === 2
                        && $context['attached_tax_rule_count'] === 1;
                })
            );

        $this->patch->apply();
    }
}

/**
 * Minimal scripted stand-in for Magento's DB adapter, covering only
 * what the migration touches: select()->from()->where() chains consumed
 * by fetchOne(), update(), and start/endSetup(). delete() exists purely
 * so the "never deletes" assertion can fail if the patch ever calls it.
 */
class MigrationFakeConnection
{
    /** @var string|int|false class_id returned for the tax_class lookup */
    public $existingClassId = false;

    /** @var int rows reported affected by update() */
    public $updatedRows = 0;

    /** @var int rule count returned for the tax_calculation probe */
    public $attachedRuleCount = 0;

    /** @var array<int, array{0: string, 1: array, 2: array}> recorded update() calls */
    public $updates = [];

    /** @var int */
    public $deleteCalls = 0;

    /** @var array<string, mixed> where chain of the tax_class lookup */
    public $classLookupWheres = [];

    public function startSetup(): void
    {
    }

    public function endSetup(): void
    {
    }

    public function select(): MigrationFakeSelect
    {
        return new MigrationFakeSelect();
    }

    /**
     * @param MigrationFakeSelect $select
     * @return string|int|false
     */
    public function fetchOne($select)
    {
        if ($select->table === 'prefix_tax_class') {
            $this->classLookupWheres = $select->wheres;
            return $this->existingClassId;
        }
        if ($select->table === 'prefix_tax_calculation') {
            return $this->attachedRuleCount;
        }
        return false;
    }

    public function update(string $table, array $bind, array $where): int
    {
        $this->updates[] = [$table, $bind, $where];
        return $this->updatedRows;
    }

    public function delete(string $table, array $where = []): int
    {
        $this->deleteCalls++;
        return 0;
    }
}

/**
 * Records the from/where chain so MigrationFakeConnection::fetchOne()
 * can dispatch on the queried table and inspect bound values.
 */
class MigrationFakeSelect
{
    /** @var string|null */
    public $table;

    /** @var string|array|null */
    public $columns;

    /** @var array<string, mixed> condition => bound value */
    public $wheres = [];

    public function from($table, $columns = '*'): self
    {
        $this->table = $table;
        $this->columns = $columns;
        return $this;
    }

    public function where(string $condition, $value = null): self
    {
        $this->wheres[$condition] = $value;
        return $this;
    }
}
