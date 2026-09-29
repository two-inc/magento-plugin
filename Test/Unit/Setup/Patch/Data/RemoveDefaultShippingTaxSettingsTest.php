<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
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
        $connection = new RemoveConnection();
        $connection->rows = array_map(static fn ($r) => ['scope' => $r[0], 'scope_id' => $r[1], 'path' => $r[2]], $stored);

        $deletes = [];
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('delete')->willReturnCallback(
            function ($path, $scope, $scopeId) use (&$deletes) {
                $deletes[] = [$path, $scope, $scopeId];
            }
        );
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects($expectedDeletes ? $this->once() : $this->never())->method('invalidate')->with('config');

        (new RemoveDefaultShippingTaxSettings($connection->setup(), $writer, $cache))->apply();

        $this->assertSame($expectedDeletes, $deletes, $case);
        $this->assertSame(
            [['path LIKE ?', 'payment/%/default_shipping_tax_class'], ['path LIKE ?', 'payment/%/default_shipping_tax_rate']],
            $connection->recordedWheres,
            $case
        );
        $this->assertSame('prefix_core_config_data', $connection->queriedTable, $case);
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
}
