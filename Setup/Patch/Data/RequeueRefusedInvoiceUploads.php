<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Two\Gateway\Service\Invoice\UploadService;

/**
 * Puts self-invoice uploads that failed only because the upload request was
 * sent with the wrong HTTP method back in the upload queue (TWO-26150).
 *
 * That request was refused with a 405 before anything was uploaded, so these
 * orders hold no upload at Two and retrying cannot duplicate one. The cron
 * picks up UPLOADING orders and re-checks the merchant's self-invoice setting
 * before it uploads. Any other failure is left as it is. Idempotent: a second
 * run finds no matching rows.
 */
class RequeueRefusedInvoiceUploads implements DataPatchInterface
{
    /** What UploadService records when the upload request is refused with a bare 405. */
    public const REFUSED_ERROR = 'Failed to request upload URL (HTTP 405)';

    private const CHUNK_SIZE = 500;

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritDoc
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('sales_order');
        $connection->startSetup();
        // A plain select is a non-locking read, so finding the rows takes no
        // locks across the order table; only the matched rows are then
        // locked, by primary key, a chunk at a time.
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($table, ['entity_id'])
                ->where('two_invoice_upload_status = ?', UploadService::STATUS_FAILED)
                ->where('two_invoice_upload_error = ?', self::REFUSED_ERROR)
                ->where("two_invoice_id IS NOT NULL AND two_invoice_id <> ''")
        );
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $connection->update(
                $table,
                [
                    'two_invoice_upload_status' => UploadService::STATUS_UPLOADING,
                    'two_invoice_upload_error' => null,
                ],
                [
                    'entity_id IN (?)' => $chunk,
                    'two_invoice_upload_status = ?' => UploadService::STATUS_FAILED,
                    'two_invoice_upload_error = ?' => self::REFUSED_ERROR,
                ]
            );
        }
        $connection->endSetup();

        return $this;
    }

    /**
     * @return array
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function getAliases(): array
    {
        return [];
    }
}
