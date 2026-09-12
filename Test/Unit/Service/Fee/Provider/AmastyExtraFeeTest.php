<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Fee\Provider;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Fee\Provider\AmastyExtraFee;

/**
 * Amasty Extra Fee runs its own credit-memo total collector, so claiming its
 * fee as a real line keeps it out of the residual that
 * Model\Total\Creditmemo\OtherCharges offers the merchant to refund.
 */
class AmastyExtraFeeTest extends TestCase
{
    private const FEE_ROW = [
        'total_amount' => '5.9900',
        'tax_amount' => '1.1980',
        'fee_label' => 'Recycling levy',
        'fee_option_label' => 'Additional fee',
        'fee_id' => '1',
        'option_id' => '1',
    ];

    /** @var AdapterInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $connection;

    private AmastyExtraFee $provider;

    /** @var array<int, string> */
    private array $queries = [];

    /** @var array<int, array> */
    private array $binds = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(static fn ($identifier) => '`' . $identifier . '`');
        $this->connection->method('isTableExists')->willReturn(true);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn ($table) => $table);

        $this->provider = new AmastyExtraFee($resourceConnection);
    }

    private function expectRows(array $rows): void
    {
        $this->connection->method('fetchAll')
            ->willReturnCallback(function ($sql, $bind = []) use ($rows) {
                $this->queries[] = $sql;
                $this->binds[] = $bind;

                return $rows;
            });
    }

    private function entity(string $kind, ?int $id)
    {
        $entity = ['order' => new OrderModel(), 'invoice' => new Invoice(), 'creditmemo' => new Creditmemo()][$kind]
            ?? new \stdClass();
        if ($entity instanceof \stdClass) {
            return $entity;
        }
        $entity->setData('id', $id);
        $entity->setData('entity_id', $id);

        return $entity;
    }

    /**
     * @dataProvider entityTableProvider
     */
    public function testEachEntityKindIsReadFromItsOwnAmastyTable(
        string $kind,
        string $expectedTable,
        string $expectedColumn,
        string $description
    ): void {
        $this->expectRows([self::FEE_ROW]);

        $lines = $this->provider->getFeeLines($this->entity($kind, 63));

        $this->assertCount(1, $lines, $description);
        $this->assertStringContainsString('`' . $expectedTable . '`', $this->queries[0], $description);
        $this->assertStringContainsString($expectedColumn . ' = :entity_id', $this->queries[0], $description);
        $this->assertSame(['entity_id' => 63], $this->binds[0], $description);
    }

    public static function entityTableProvider(): array
    {
        return [
            ['order', 'amasty_extrafee_order', 'order_id', 'an order reads the order fee table'],
            ['invoice', 'amasty_extrafee_invoice', 'invoice_id', 'an invoice reads the invoice fee table'],
            [
                'creditmemo',
                'amasty_extrafee_creditmemo',
                'creditmemo_id',
                'a credit memo reads the credit memo fee table',
            ],
        ];
    }

    /**
     * @dataProvider feeRowProvider
     */
    public function testAFeeRowBecomesALineCarryingAmastysOwnAmounts(
        array $rows,
        array $expectedLines,
        string $description
    ): void {
        $this->expectRows($rows);

        $lines = $this->provider->getFeeLines($this->entity('order', 63));

        $this->assertCount(count($expectedLines), $lines, $description);
        foreach ($expectedLines as $index => $expected) {
            foreach ($expected as $key => $value) {
                $this->assertSame($value, $lines[$index][$key], $description . ' — ' . $key);
            }
        }
    }

    public static function feeRowProvider(): array
    {
        return [
            [
                [self::FEE_ROW],
                [[
                    'order_item_id' => 'amasty_extrafee_1_1',
                    'name' => 'Recycling levy Additional fee',
                    'type' => 'OTHER',
                    'gross_amount' => '7.19',
                    'net_amount' => '5.99',
                    'tax_amount' => '1.20',
                    'tax_rate' => '0.200000',
                    'tax_class_name' => 'VAT 20.00%',
                    'quantity' => 1,
                ]],
                'a taxed fee carries its own measured rate',
            ],
            [
                [['total_amount' => '4.0000', 'tax_amount' => '0.0000', 'fee_id' => '2', 'option_id' => '3']],
                [[
                    'order_item_id' => 'amasty_extrafee_2_3',
                    'gross_amount' => '4.00',
                    'net_amount' => '4.00',
                    'tax_amount' => '0.00',
                    'tax_rate' => '0.000000',
                    'tax_class_name' => 'VAT 0.00%',
                ]],
                'an untaxed fee declares 0%, not a guessed rate',
            ],
            [
                [
                    ['total_amount' => '0.0000', 'tax_amount' => '0.0000', 'fee_id' => '1', 'option_id' => '0'],
                    self::FEE_ROW,
                ],
                [['order_item_id' => 'amasty_extrafee_1_1', 'gross_amount' => '7.19']],
                'the unselected zero-amount option is not a line',
            ],
            [
                [
                    self::FEE_ROW,
                    ['total_amount' => '2.5000', 'tax_amount' => '0.5000', 'fee_id' => '9', 'option_id' => '4'],
                ],
                [
                    ['order_item_id' => 'amasty_extrafee_1_1'],
                    ['order_item_id' => 'amasty_extrafee_9_4', 'gross_amount' => '3.00'],
                ],
                'every selected fee gets its own line',
            ],
            [
                [['total_amount' => '6.0000', 'tax_amount' => '1.2000', 'fee_id' => '1', 'option_id' => '1']],
                [['name' => 'Other charges', 'description' => 'Other charges']],
                'an unlabelled fee still names itself',
            ],
        ];
    }

    /**
     * @dataProvider nothingToClaimProvider
     */
    public function testNothingIsClaimedWhenThereIsNoAmastyFeeToRead(
        string $kind,
        ?int $id,
        bool $tableExists,
        string $description
    ): void {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($i) => '`' . $i . '`');
        $connection->method('isTableExists')->willReturn($tableExists);
        $connection->method('fetchAll')->willReturn([self::FEE_ROW]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(static fn ($t) => $t);

        $provider = new AmastyExtraFee($resourceConnection);

        $this->assertSame([], $provider->getFeeLines($this->entity($kind, $id)), $description);
    }

    public static function nothingToClaimProvider(): array
    {
        return [
            ['order', 63, false, 'Amasty is not installed on this store'],
            ['order', null, true, 'the entity has not been saved yet'],
            ['other', 63, true, 'the entity is not an order, invoice or credit memo'],
        ];
    }
}
