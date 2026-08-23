<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Fee\Provider;

use Magento\Sales\Model\Order as OrderModel;
use Two\Gateway\Api\Fee\FeeLineProviderInterface;

/**
 * Itemizes Amasty's "Extra Fee" module (amasty/module-extra-fee) total.
 *
 * ComposeOrder hands this provider $payment->getOrder() from inside
 * Order::place() - which Magento\Sales\Model\Service\OrderService::place()
 * calls BEFORE OrderRepositoryInterface::save() persists the order. So
 * this $entity has no entity_id yet: there is no order row in the
 * database for anyone, Amasty included, to load by id. What it does have
 * is a quote_id, because the quote has already been converted.
 *
 * Amasty's quote-side total collector (Amasty\Extrafee\Model\Quote\Fee)
 * runs during quote totals collection, well before order placement, and
 * persists one row per selected fee option to its own quote-fee table.
 * AmastyExtraFeeQuoteReader reads that table by quote_id - the same
 * lookup Amasty's own OrderRepository plugin uses, for the same reason:
 * quote_id is the only field connecting this in-memory order back to
 * Amasty's data before a database row exists to load by entity_id.
 *
 * Amasty's quote-side total collector aggregates every selected fee
 * option into ONE combined amount/tax before it ever reaches an order,
 * so this always emits at most one line - there is no per-option
 * breakdown to itemize even if we wanted one.
 */
class AmastyExtraFee implements FeeLineProviderInterface
{
    private AmastyExtraFeeQuoteReader $quoteReader;

    public function __construct(AmastyExtraFeeQuoteReader $quoteReader)
    {
        $this->quoteReader = $quoteReader;
    }

    /**
     * @param OrderModel|OrderModel\Invoice|OrderModel\Creditmemo $entity
     * @return array[]
     */
    public function getFeeLines($entity): array
    {
        if (!$entity instanceof OrderModel) {
            return [];
        }

        $quoteId = (int)$entity->getQuoteId();
        if ($quoteId <= 0) {
            return [];
        }

        $fee = $this->quoteReader->getFeeByQuoteId($quoteId);
        if ($fee === null || $fee['net_amount'] <= 0.0) {
            return [];
        }

        $netAmount = $fee['net_amount'];
        $taxAmount = $fee['tax_amount'];
        $grossAmount = $netAmount + $taxAmount;
        $taxRate = $taxAmount > 0.0 ? $taxAmount / $netAmount : 0.0;

        return [[
            'order_item_id' => 'amasty_extrafee',
            'name' => (string)__('Amasty Fee'),
            'description' => (string)__('Amasty Fee'),
            'type' => 'OTHER',
            'image_url' => '',
            'product_page_url' => '',
            'gross_amount' => $this->roundAmt($grossAmount),
            'net_amount' => $this->roundAmt($netAmount),
            'tax_amount' => $this->roundAmt($taxAmount),
            'discount_amount' => '0.00',
            'tax_rate' => $this->roundAmt($taxRate, 6),
            'tax_class_name' => 'VAT ' . $this->roundAmt($taxRate * 100) . '%',
            'unit_price' => $this->roundAmt($netAmount, 6),
            'quantity' => 1,
            'quantity_unit' => 'sc',
        ]];
    }

    private function roundAmt(float $amt, int $dp = 2): string
    {
        return number_format($amt, $dp, '.', '');
    }
}
