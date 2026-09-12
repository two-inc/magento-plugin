<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Fee\Provider;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Two\Gateway\Api\Fee\FeeLineProviderInterface;

/**
 * Itemizes Amasty "Extra Fee" charges from that module's own tables.
 *
 * Amasty runs its own credit-memo total collector, so claiming the fee here
 * keeps it out of the residual Model\Total\Creditmemo\OtherCharges offers the
 * merchant to refund — a fee its owner already accounts for (ABN-554).
 *
 * Reads the tables, not Amasty's classes, so an install without the module
 * still compiles.
 */
class AmastyExtraFee implements FeeLineProviderInterface
{
    private const TABLES = [
        'order' => ['amasty_extrafee_order', 'order_id'],
        'invoice' => ['amasty_extrafee_invoice', 'invoice_id'],
        'creditmemo' => ['amasty_extrafee_creditmemo', 'creditmemo_id'],
    ];

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @inheritDoc
     */
    public function getFeeLines($entity): array
    {
        $key = $this->entityKey($entity);
        if ($key === null || !$entity->getId()) {
            return [];
        }

        [$table, $column] = self::TABLES[$key];
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);
        if (!$connection->isTableExists($tableName)) {
            return [];
        }

        // Table and column are both from self::TABLES, never from input.
        $rows = $connection->fetchAll(
            sprintf(
                'SELECT * FROM %s WHERE %s = :entity_id',
                $connection->quoteIdentifier($tableName),
                $column
            ),
            ['entity_id' => (int)$entity->getId()]
        );

        $lines = [];
        foreach ($rows as $row) {
            $line = $this->toLine($row);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param mixed $entity
     * @return string|null
     */
    private function entityKey($entity): ?string
    {
        if ($entity instanceof Invoice) {
            return 'invoice';
        }
        if ($entity instanceof Creditmemo) {
            return 'creditmemo';
        }
        if ($entity instanceof OrderModel) {
            return 'order';
        }

        return null;
    }

    /**
     * @param array $row
     * @return array|null
     */
    private function toLine(array $row): ?array
    {
        $net = (float)($row['total_amount'] ?? 0);
        $tax = (float)($row['tax_amount'] ?? 0);
        if ($net <= 0 && $tax <= 0) {
            return null;
        }

        // Amasty's 4dp amounts, not the 2dp this line declares: the quotient
        // of two rounded amounts is not the rate that was applied.
        $rate = $net > 0 ? $tax / $net : 0.0;
        $name = trim(($row['fee_label'] ?? '') . ' ' . ($row['fee_option_label'] ?? ''));

        return [
            'order_item_id' => sprintf(
                'amasty_extrafee_%d_%d',
                (int)($row['fee_id'] ?? 0),
                (int)($row['option_id'] ?? 0)
            ),
            'name' => $name !== '' ? $name : (string)__('Other charges'),
            'description' => $name !== '' ? $name : (string)__('Other charges'),
            'type' => 'OTHER',
            'image_url' => '',
            'product_page_url' => '',
            'gross_amount' => $this->amt($net + $tax),
            'net_amount' => $this->amt($net),
            'tax_amount' => $this->amt($tax),
            'discount_amount' => '0.00',
            'tax_rate' => $this->amt($rate, 6),
            'tax_class_name' => 'VAT ' . $this->amt($rate * 100) . '%',
            'unit_price' => $this->amt($net, 6),
            'quantity' => 1,
            'quantity_unit' => 'sc',
        ];
    }

    /**
     * @param float $value
     * @param int $dp
     * @return string
     */
    private function amt(float $value, int $dp = 2): string
    {
        return number_format($value, $dp, '.', '');
    }
}
