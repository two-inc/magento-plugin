<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Invoice\UploadService;
use Two\Gateway\Setup\Patch\Data\RequeueRefusedInvoiceUploads;

/**
 * TWO-26150: only uploads whose upload request was refused with a 405 go back
 * in the queue, and the error the patch matches is the one UploadService
 * actually records for that refusal.
 */
require_once __DIR__ . '/RemoveConnection.php';

class RequeueRefusedInvoiceUploadsTest extends TestCase
{
    /**
     * @return array<int, array{int, int[], string}> [matching rows, chunk sizes updated, description]
     */
    public static function backlogs(): array
    {
        return [
            [0, [], 'nothing refused: no update'],
            [3, [3], 'a small backlog: one update'],
            [1001, [500, 500, 1], 'a large backlog: updated by primary key in chunks of 500'],
        ];
    }

    #[DataProvider('backlogs')]
    public function testSelectsRefusedUploadsThenRequeuesThemByPrimaryKey(int $rows, array $chunks, string $description): void
    {
        $connection = new class {
            /** @var int[] */
            public $ids = [];
            /** @var RemoveSelect|null */
            public $select;
            /** @var array<int, array{0: string, 1: array, 2: array}> */
            public $updates = [];

            public function startSetup(): void
            {
            }

            public function endSetup(): void
            {
            }

            public function select(): RemoveSelect
            {
                return $this->select = new RemoveSelect();
            }

            public function fetchCol($select): array
            {
                return $this->ids;
            }

            public function update($table, array $bind, $where): int
            {
                $this->updates[] = [$table, $bind, $where];
                return count($where['entity_id IN (?)']);
            }
        };
        $connection->ids = $rows ? range(1, $rows) : [];
        $setup = new class ($connection) implements ModuleDataSetupInterface {
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

        (new RequeueRefusedInvoiceUploads($setup))->apply();

        $this->assertSame('prefix_sales_order', $connection->select->table, $description);
        $this->assertSame([
            ['two_invoice_upload_status = ?', 'FAILED'],
            ['two_invoice_upload_error = ?', 'Failed to request upload URL (HTTP 405)'],
            ["two_invoice_id IS NOT NULL AND two_invoice_id <> ''", null],
        ], $connection->select->wheres, $description);
        $this->assertSame($chunks, array_map(static fn (array $u): int => count($u[2]['entity_id IN (?)']), $connection->updates), $description);
        $this->assertSame($connection->ids, array_merge([], ...array_map(static fn (array $u): array => $u[2]['entity_id IN (?)'], $connection->updates)), $description);
        foreach ($connection->updates as [$table, $bind, $where]) {
            $this->assertSame('prefix_sales_order', $table, $description);
            $this->assertSame(['two_invoice_upload_status' => 'UPLOADING', 'two_invoice_upload_error' => null], $bind, $description);
            $this->assertSame('FAILED', $where['two_invoice_upload_status = ?'], $description);
        }
    }

    public function testMatchesTheErrorUploadServiceRecordsForA405(): void
    {
        $service = (new \ReflectionClass(UploadService::class))->newInstanceWithoutConstructor();
        $parse = new \ReflectionMethod(UploadService::class, 'parseSignedUrlError');
        $parse->setAccessible(true);

        $this->assertSame(RequeueRefusedInvoiceUploads::REFUSED_ERROR, $parse->invoke($service, ['http_status' => 405], 405));
    }
}
