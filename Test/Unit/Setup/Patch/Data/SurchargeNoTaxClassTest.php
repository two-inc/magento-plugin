<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\SurchargeTaxCalculator;
use Two\Gateway\Setup\Patch\Data\SurchargeNoTaxClass;

/**
 * Coverage for the always-zero tax class provisioning patch,
 * specifically the name-collision guard: a pre-existing merchant class
 * with the same name must never be silently treated as the safe
 * "no tax" class when it has Tax Rules attached.
 */
class SurchargeNoTaxClassTest extends TestCase
{
    /** @var FakeConnection */
    private $connection;

    /** @var LogRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $log;

    /** @var SurchargeNoTaxClass */
    private $patch;

    protected function setUp(): void
    {
        $this->connection = new FakeConnection();
        $this->log = $this->createMock(LogRepository::class);

        $connection = $this->connection;
        $moduleDataSetup = new class($connection) implements ModuleDataSetupInterface {
            /** @var FakeConnection */
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

        $this->patch = new SurchargeNoTaxClass($moduleDataSetup, $this->log);
    }

    public function testCreatesClassWhenNameIsFree(): void
    {
        $this->connection->existingClassId = false;
        $this->log->expects($this->never())->method('addErrorLog');

        $this->patch->apply();

        $this->assertCount(1, $this->connection->inserts);
        [$table, $data] = $this->connection->inserts[0];
        $this->assertSame('prefix_tax_class', $table);
        $this->assertSame(SurchargeTaxCalculator::NO_TAX_CLASS_NAME, $data['class_name']);
        $this->assertSame('PRODUCT', $data['class_type']);
        // No rule-count probe needed when the class did not pre-exist.
        $this->assertNull($this->connection->ruleCountQueryClassId);
    }

    public function testIdempotentRerunWithRuleFreeExistingClassIsSilent(): void
    {
        // Normal second run of this patch: our own class exists, no
        // rules attached — nothing inserted, nothing logged.
        $this->connection->existingClassId = '17';
        $this->connection->attachedRuleCount = 0;
        $this->log->expects($this->never())->method('addErrorLog');

        $this->patch->apply();

        $this->assertCount(0, $this->connection->inserts);
        $this->assertSame('17', $this->connection->ruleCountQueryClassId);
    }

    public function testCollisionWithRuleBearingClassLogsLoudlyAndDoesNotInsert(): void
    {
        // A merchant already had a Product Tax Class with this exact
        // name AND Tax Rules attached to it: reusing it would silently
        // break the "guaranteed untaxed" promise. Expect a loud error
        // log and no insert.
        $this->connection->existingClassId = '17';
        $this->connection->attachedRuleCount = 2;
        $this->log->expects($this->once())->method('addErrorLog')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('already exists'),
                    $this->stringContains('Tax Rule'),
                    $this->stringContains('CANNOT guarantee an untaxed')
                ),
                $this->callback(function ($context) {
                    return $context['class_id'] === '17' && $context['attached_rule_count'] === 2;
                })
            );

        $this->patch->apply();

        $this->assertCount(0, $this->connection->inserts);
    }

    public function testRuleCountProbeTargetsTaxCalculationTable(): void
    {
        $this->connection->existingClassId = '17';
        $this->connection->attachedRuleCount = 1;

        $this->patch->apply();

        $this->assertSame('prefix_tax_calculation', $this->connection->ruleCountQueryTable);
    }
}

/**
 * Minimal scripted stand-in for Magento's DB adapter, covering only
 * what the patch touches: select()->from()->where() chains consumed by
 * fetchOne(), plus insert() and start/endSetup().
 */
class FakeConnection
{
    /** @var string|false class_id returned for the tax_class existence lookup */
    public $existingClassId = false;

    /** @var int rule count returned for the tax_calculation probe */
    public $attachedRuleCount = 0;

    /** @var array<int, array{0: string, 1: array}> recorded insert() calls */
    public $inserts = [];

    /** @var string|null class_id the rule-count probe filtered on */
    public $ruleCountQueryClassId;

    /** @var string|null table the rule-count probe selected from */
    public $ruleCountQueryTable;

    public function startSetup(): void
    {
    }

    public function endSetup(): void
    {
    }

    public function select(): FakeSelect
    {
        return new FakeSelect();
    }

    /**
     * @param FakeSelect $select
     * @return string|int|false
     */
    public function fetchOne($select)
    {
        if ($select->table === 'prefix_tax_class') {
            return $this->existingClassId;
        }
        if ($select->table === 'prefix_tax_calculation') {
            $this->ruleCountQueryTable = $select->table;
            $this->ruleCountQueryClassId = $select->wheres['product_tax_class_id = ?'] ?? null;
            return $this->attachedRuleCount;
        }
        return false;
    }

    public function insert(string $table, array $data): void
    {
        $this->inserts[] = [$table, $data];
    }
}

/**
 * Records the from/where chain so FakeConnection::fetchOne() can
 * dispatch on the queried table and inspect bound values.
 */
class FakeSelect
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
