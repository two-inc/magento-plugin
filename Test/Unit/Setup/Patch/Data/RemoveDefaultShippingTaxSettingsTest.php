<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Setup\Patch\Data\RemoveDefaultShippingTaxSettings;

/**
 * The removed shipping tax fields' stored rows are deleted at every scope
 * and brand code they were saved under; anything else is left alone.
 */
class RemoveDefaultShippingTaxSettingsTest extends TestCase
{
    /**
     * @param array<int, array{0: string, 1: int, 2: string}> $stored [scope, scope_id, path]
     * @param array<int, array{0: string, 1: string, 2: int}> $expectedDeletes [path, scope, scope_id]
     * @dataProvider cases
     */
    public function testApply(array $stored, array $expectedDeletes, string $case): void
    {
        $rows = array_map(static fn ($r) => ['scope' => $r[0], 'scope_id' => $r[1], 'path' => $r[2]], $stored);
        $connection = new class ($rows) {
            public array $wheres = [];
            public array $tables = [];

            public function __construct(private array $rows)
            {
            }

            public function startSetup(): void
            {
            }

            public function endSetup(): void
            {
            }

            public function select(): object
            {
                $connection = $this;
                return new class ($connection) {
                    public function __construct(private object $connection)
                    {
                    }

                    public function from($table, $columns = '*'): self
                    {
                        $this->connection->tables[] = $table;
                        return $this;
                    }

                    public function where($condition, $value = null): self
                    {
                        $this->connection->wheres[] = [$condition, $value];
                        return $this;
                    }
                };
            }

            public function fetchAll($select): array
            {
                return $this->rows;
            }
        };
        $setup = new class ($connection) implements ModuleDataSetupInterface {
            public function __construct(private object $connection)
            {
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

        $deletes = [];
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('delete')->willReturnCallback(
            function ($path, $scope, $scopeId) use (&$deletes) {
                $deletes[] = [$path, $scope, $scopeId];
            }
        );
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects($expectedDeletes ? $this->once() : $this->never())->method('invalidate')->with('config');

        (new RemoveDefaultShippingTaxSettings($setup, $writer, $cache))->apply();

        $this->assertSame($expectedDeletes, $deletes, $case);
        $this->assertSame(
            [['path LIKE ?', 'payment/%/default_shipping_tax_class'], ['path LIKE ?', 'payment/%/default_shipping_tax_rate']],
            $connection->wheres,
            $case
        );
        $this->assertSame(['prefix_core_config_data', 'prefix_core_config_data'], $connection->tables, $case);
    }

    public static function cases(): array
    {
        $class = 'payment/two_payment/default_shipping_tax_class';
        $rate = 'payment/two_payment/default_shipping_tax_rate';
        return [
            [[['default', 0, $class]], [[$class, 'default', 0]], 'class row at default scope'],
            [[['stores', 3, $rate]], [[$rate, 'stores', 3]], 'flat rate row at store scope'],
            [[['websites', 2, $class], ['default', 0, $rate]], [[$class, 'websites', 2], [$rate, 'default', 0]], 'both fields'],
            [[['default', 0, 'payment/acme_payment/default_shipping_tax_rate']], [['payment/acme_payment/default_shipping_tax_rate', 'default', 0]], 'brand code row'],
            [[['default', 0, 'payment/two_payment/defaultXshipping_tax_rate'], ['default', 0, 'tax/classes/shipping_tax_class']], [], 'LIKE-wildcard lookalike and the core path are kept'],
            [[], [], 're-run after removal deletes nothing'],
        ];
    }

    public function testIsIndependentAndUnaliased(): void
    {
        $this->assertSame([], RemoveDefaultShippingTaxSettings::getDependencies());
        $this->assertSame([], (new \ReflectionClass(RemoveDefaultShippingTaxSettings::class))
            ->newInstanceWithoutConstructor()->getAliases());
    }
}
