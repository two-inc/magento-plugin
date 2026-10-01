<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Invoice\UploadService;
use Two\Gateway\Setup\Patch\Data\RequeueRefusedInvoiceUploads;

/**
 * TWO-26150: only uploads whose upload request was refused with a 405 go back
 * in the queue, and the error the patch matches is the one UploadService
 * actually records for that refusal.
 */
class RequeueRefusedInvoiceUploadsTest extends TestCase
{
    public function testRequeuesOnlyUploadsRefusedWith405(): void
    {
        $connection = new class {
            /** @var array<int, array{0: string, 1: array, 2: array}> */
            public $updates = [];

            public function startSetup(): void
            {
            }

            public function endSetup(): void
            {
            }

            public function update($table, array $bind, $where): int
            {
                $this->updates[] = [$table, $bind, $where];
                return 0;
            }
        };
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

        $this->assertSame([[
            'prefix_sales_order',
            ['two_invoice_upload_status' => 'UPLOADING', 'two_invoice_upload_error' => null],
            [
                'two_invoice_upload_status = ?' => 'FAILED',
                'two_invoice_upload_error = ?' => 'Failed to request upload URL (HTTP 405)',
                "two_invoice_id IS NOT NULL AND two_invoice_id <> ''",
            ],
        ]], $connection->updates);
    }

    public function testMatchesTheErrorUploadServiceRecordsForA405(): void
    {
        $service = (new \ReflectionClass(UploadService::class))->newInstanceWithoutConstructor();
        $parse = new \ReflectionMethod(UploadService::class, 'parseSignedUrlError');
        $parse->setAccessible(true);

        $this->assertSame(RequeueRefusedInvoiceUploads::REFUSED_ERROR, $parse->invoke($service, ['http_status' => 405], 405));
    }
}
