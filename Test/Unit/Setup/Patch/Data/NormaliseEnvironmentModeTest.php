<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Setup\Patch\Data\NormaliseEnvironmentMode;

/**
 * Stored Environment rows are rewritten to the canonical set at their own
 * scope (TWO-26604), and only on Two's payment codes.
 */
class NormaliseEnvironmentModeTest extends TestCase
{
    /** @var RemoveConnection */
    private $connection;

    /** @var array<int, array> */
    private $writes = [];

    private function apply(array $rows, string $brandCode = 'two_payment'): void
    {
        $this->connection = new RemoveConnection();
        $this->connection->rows = $rows;
        $writes = &$this->writes;
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(function ($path, $value, $scope, $scopeId) use (&$writes) {
            $writes[] = ['save', $path, $value, (string)$scope, (int)$scopeId];
        });
        $writer->method('delete')->willReturnCallback(function ($path, $scope, $scopeId) use (&$writes) {
            $writes[] = ['delete', $path, (string)$scope, (int)$scopeId];
        });
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getCode')->willReturn($brandCode);

        (new NormaliseEnvironmentMode(
            $this->connection->setup(),
            $writer,
            $this->createMock(TypeListInterface::class),
            $brand
        ))->apply();
    }

    /**
     * @dataProvider rowCases
     */
    public function testEachRowIsRewrittenAtItsOwnScope(?string $stored, ?array $expected, string $description): void
    {
        $this->apply([['scope' => 'stores', 'scope_id' => 3, 'path' => 'payment/two_payment/mode', 'value' => $stored]]);

        $this->assertSame($expected === null ? [] : [$expected], $this->writes, $description);
    }

    public static function rowCases(): array
    {
        $path = 'payment/two_payment/mode';
        return [
            ['production', null, 'production is left alone'],
            ['sandbox', null, 'sandbox is left alone'],
            ['staging', null, 'staging is left alone'],
            ['PROD', ['save', $path, 'production', 'stores', 3], 'upper-case alias to production'],
            ['Staging', ['save', $path, 'staging', 'stores', 3], 'staging in any case kept'],
            ['development', ['save', $path, 'sandbox', 'stores', 3], 'development to sandbox'],
            ['Demo', ['save', $path, 'sandbox', 'stores', 3], 'any other value to sandbox'],
            ['', ['delete', $path, 'stores', 3], 'an empty row is deleted so the scope inherits'],
            [null, ['delete', $path, 'stores', 3], 'a null row is deleted so the scope inherits'],
        ];
    }

    public function testOnlyTwoPaymentCodesAreQueried(): void
    {
        $this->apply([], 'brandpay');

        $this->assertSame(
            [['path IN (?)', ['payment/two_payment/mode', 'payment/brandpay/mode']]],
            $this->connection->recordedWheres
        );
    }
}
