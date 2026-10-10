<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order\ZeroTaxRates;
use Two\Gateway\Setup\Patch\Data\FanOutTaxCodeMap;

/**
 * TWO-26153: the upgrade carries each old class => code entry to all of that
 * class's rows (shared case table, row 18), at every scope and brand, once.
 */
class FanOutTaxCodeMapTest extends TestCase
{
    private const PATH = 'payment/two_payment/tax_code_map';

    /**
     * @dataProvider cases
     * @param array<int, array{0: string, 1: string, 2: int, 3: string}> $rows path, scope, scope id, stored value
     * @param array<int, array{0: string, 1: string, 2: string, 3: int}> $expected saves: path, value, scope, scope id
     */
    public function testTheOldMapIsFannedOutToEveryRowOfItsClass(array $rows, array $expected, string $description): void
    {
        $saves = [];
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(function ($path, $value, $scope, $scopeId) use (&$saves) {
            $saves[] = [$path, (string)$value, (string)$scope, (int)$scopeId];
        });
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects($expected ? $this->once() : $this->never())->method('invalidate')->with('config');
        $rates = $this->createMock(ZeroTaxRates::class);
        $rates->method('byClass')->willReturn([5 => [
            ['code' => 'ES-CANARIAS-0', 'country' => 'ES', 'postcode' => '35*'],
            ['code' => 'US-0', 'country' => 'US', 'postcode' => '*'],
        ]]);

        (new FanOutTaxCodeMap($this->moduleSetup($rows), $writer, $cache, $rates))->apply();

        $this->assertSame($expected, $saves, $description);
    }

    public static function cases(): array
    {
        $fanned = '{"5|exempt":"X","5|none":"X","5|rate:ES-CANARIAS-0":"X","5|rate:US-0":"X"}';
        return [
            'row 18' => [[[self::PATH, 'default', 0, '{"5":"X"}']], [[self::PATH, $fanned, 'default', 0]], 'upgrade fans the old code out to all of the class\'s rows'],
            'class without rates' => [[[self::PATH, 'default', 0, '{"0":"Y"}']], [[self::PATH, '{"0|exempt":"Y","0|none":"Y"}', 'default', 0]], 'a class whose rules use no 0% rate gets its exempt and no-rule rows'],
            'new rows kept' => [[[self::PATH, 'default', 0, '{"5":"X","5|none":"Z"}']], [[self::PATH, '{"5|exempt":"X","5|none":"Z","5|rate:ES-CANARIAS-0":"X","5|rate:US-0":"X"}', 'default', 0]], 'a row already in the new shape keeps its code'],
            'scope and brand' => [[['payment/two_abn_payment/tax_code_map', 'stores', 3, '{"0":"Y"}']], [['payment/two_abn_payment/tax_code_map', '{"0|exempt":"Y","0|none":"Y"}', 'stores', 3]], 'every brand and scope is rewritten in place'],
            'rerun' => [[[self::PATH, 'default', 0, $fanned]], [], 'a second run writes nothing'],
            'empty and junk' => [[[self::PATH, 'default', 0, ''], [self::PATH, 'websites', 1, 'not json']], [], 'nothing usable stored, nothing written'],
            'lookalike path' => [[['payment/two_payment/tax_code_mapX', 'default', 0, '{"5":"X"}']], [], 'a path the LIKE wildcard matches by accident is left alone'],
        ];
    }

    private function moduleSetup(array $rows): ModuleDataSetupInterface
    {
        $records = array_map(static fn (array $row) => ['path' => $row[0], 'scope' => $row[1], 'scope_id' => $row[2], 'value' => $row[3]], $rows);
        $connection = new class ($records) {
            /** @var array */
            private $records;

            public function __construct(array $records)
            {
                $this->records = $records;
            }

            public function startSetup(): void
            {
            }

            public function endSetup(): void
            {
            }

            public function select()
            {
                return new class {
                    public function from($table, $columns = '*')
                    {
                        return $this;
                    }

                    public function where($condition, $value = null)
                    {
                        return $this;
                    }
                };
            }

            public function fetchAll($select): array
            {
                return $this->records;
            }
        };

        return new class ($connection) implements ModuleDataSetupInterface {
            /** @var object */
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
                return $tableName;
            }
        };
    }
}
