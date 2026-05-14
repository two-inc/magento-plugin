<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Two\Gateway\Service\Order as OrderService;

/**
 * Compose Refund Service
 */
class ComposeRefund extends OrderService
{
    /**
     * Compose request body for two refund order
     *
     * @param Creditmemo $creditmemo
     * @param float $amount
     * @param Order $order
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(Creditmemo $creditmemo, float $amount, Order $order): array
    {
        $lineItems = array_values($this->getLineItemsCreditmemo($order, $creditmemo));
        // Use creditmemo->getGrandTotal() rather than re-summing line items.
        // It's the canonical post-collector refund value Magento records
        // and avoids per-line 2dp-rounding drift that re-summing would
        // accumulate (worst case ~N * 0.005). Line items now do carry the
        // adjustment via an OTHER-type line, so amount and
        // sum(line_items.gross_amount) reconcile to within rounding —
        // but grand_total remains the source of truth.
        $result = [
            'amount' => $this->roundAmt((float)$creditmemo->getGrandTotal()),
            'currency' => $order->getOrderCurrencyCode(),
            'line_items' => $lineItems,
        ];
        $taxSubtotals = $this->getTaxSubtotals($lineItems);
        if ($taxSubtotals) {
            $result['tax_subtotals'] = $taxSubtotals;
        }
        return $result;
    }

    /**
     * Get line items from creditmemo
     *
     * @param Order $order
     * @param Creditmemo $creditmemo
     * @return array
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function getLineItemsCreditmemo(Order $order, Creditmemo $creditmemo): array
    {
        $items = [];
        foreach ($creditmemo->getItems() as $item) {
            $orderItem = $this->getOrderItem((int)$item->getOrderItemId());
            if (!$item->getRowTotal() || !$product = $this->getProduct($order, $orderItem)) {
                continue;
            }

            // Part of the item line that is shipped.
            $part = $item->getQty() / $orderItem->getQtyOrdered();

            $grossAmount = $this->roundAmt($this->getGrossAmountItem($orderItem) * $part);
            $netAmount = $this->roundAmt($this->getNetAmountItem($orderItem) * $part);
            $taxAmount = $grossAmount - $netAmount;

            $items[$orderItem->getItemId()] = [
                'order_item_id' => $item->getOrderItemId(),
                'name' => $item->getName(),
                'description' => $item->getName(),
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
                'tax_amount' => $taxAmount,
                'discount_amount' => $this->roundAmt($this->getDiscountAmountItem($orderItem) * $part),
                'unit_price' => $this->roundAmt($this->getUnitPriceItem($orderItem), 6),
                'tax_rate' => $this->roundAmt(($orderItem->getTaxPercent() / 100), 6),
                'tax_class_name' => 'VAT ' . $this->roundAmt($orderItem->getTaxPercent()) . '%',
                'quantity' => $item->getQty(),
                'quantity_unit' => $this->configRepository->getWeightUnit((int)$order->getStoreId()),
                'image_url' => $this->getProductImageUrl($product),
                'product_page_url' => $product->getProductUrl(),
                'type' => $orderItem->getIsVirtual() ? 'DIGITAL' : 'PHYSICAL',
                'details' => [
                    'barcodes' => [
                        [
                            'type' => 'SKU',
                            'value' => $item->getSku(),
                        ],
                    ],
                    'categories' => $this->getCategories($product->getCategoryIds()),
                ]
            ];
        }

        if ((float)$creditmemo->getTwoSurchargeAmount() > 0) {
            // Match ComposeOrder's component-rounding pattern (each
            // value rounded independently to 2dp from the 6dp stamped
            // source, gross computed from the unrounded sum). Pre-
            // rounding net and tax then summing would diverge from
            // ComposeOrder by a cent at half-cent boundaries — the
            // resulting refund line gross would mismatch the order
            // line gross and Two would reject the refund. See internal ticket.
            $netAmountRaw = (float)$creditmemo->getTwoSurchargeAmount();
            $taxAmountRaw = (float)$creditmemo->getTwoSurchargeTaxAmount();
            $netAmount = $this->roundAmt($netAmountRaw);
            $taxAmount = $this->roundAmt($taxAmountRaw);
            $grossAmount = $this->roundAmt($netAmountRaw + $taxAmountRaw);
            $taxRatePercent = (float)$creditmemo->getTwoSurchargeTaxRate();
            $description = (string)$creditmemo->getTwoSurchargeDescription() ?: (string)__('Payment terms fee');

            // order_item_id 'surcharge' must match ComposeOrder so Two's API
            // allocates the refund to the BUYER_FEE line on the original order.
            $items['surcharge'] = [
                'order_item_id'   => 'surcharge',
                'name'            => $description,
                'description'     => $description,
                'type'            => 'BUYER_FEE',
                'image_url'       => '',
                'product_page_url' => '',
                'gross_amount'    => $grossAmount,
                'net_amount'      => $netAmount,
                'tax_amount'      => $taxAmount,
                'discount_amount' => '0.00',
                'unit_price'      => $this->roundAmt($netAmountRaw, 6),
                'tax_rate'        => $this->roundAmt($taxRatePercent / 100, 6),
                'tax_class_name'  => 'VAT ' . $this->roundAmt($taxRatePercent) . '%',
                'quantity'        => 1,
                'quantity_unit'   => 'sc',
            ];
        }

        // OTHER-type adjustment line. Magento's "Adjustment
        // Refund" and "Adjustment Fee" admin inputs feed
        // creditmemo.grand_total but had no representation in line_items
        // pre-fix, leaving payload.amount and
        // sum(line_items.gross_amount) divergent. Combine as a single
        // signed line: positive = extra refund to customer, negative =
        // fee withheld.
        //
        // tax_rate '0.00' here is a CONSERVATIVE DEFAULT, not a
        // verified fact. Magento has no adjustment_tax accessor;
        // Total\Tax ignores the adjustment fields; Total\Grand adds
        // adjustment_positive - adjustment_negative straight into
        // grand_total without tax decomposition. The same admin field
        // is used for two different intents that Magento can't
        // distinguish:
        //
        //   (a) tax-exempt amounts (gift-card refunds, goodwill
        //       credits, write-offs) — 0% is exactly right;
        //   (b) tax-inclusive partial refunds (typical in EU stores
        //       with tax-inclusive pricing) where the merchant
        //       implicitly meant the value to include VAT — 0%
        //       under-declares recoverable VAT.
        //
        // We default to (a) because under-declaration is recoverable
        // in accounting reconciliation, whereas over-declaration
        // (claiming VAT recovery on amounts that may not have been
        // taxed) is a tax-authority risk. Merchants whose adjustment
        // usage is systematically (b) should track the VAT portion
        // separately; revisit with a config knob if that becomes a
        // pattern.
        //
        // Threshold uses the pre-format magnitude (not a post-format
        // string compare against "0.00") to avoid number_format
        // returning "-0.00" for inputs in (-0.005, 0).
        $adjustmentNet = (float)$creditmemo->getAdjustmentPositive()
            - (float)$creditmemo->getAdjustmentNegative();
        if (abs($adjustmentNet) >= 0.005) {
            $gross = $this->roundAmt($adjustmentNet);
            $items['adjustment'] = [
                'order_item_id'   => 'adjustment',
                'name'            => 'Adjustment',
                'description'     => 'Adjustment',
                'type'            => 'OTHER',
                'image_url'       => '',
                'product_page_url' => '',
                'gross_amount'    => $gross,
                'net_amount'      => $gross,
                'tax_amount'      => '0.00',
                'discount_amount' => '0.00',
                'unit_price'      => $this->roundAmt($adjustmentNet, 6),
                'tax_rate'        => '0.00',
                'tax_class_name'  => 'VAT 0%',
                'quantity'        => 1,
                'quantity_unit'   => 'sc',
            ];
        }

        if (!$order->getIsVirtual() && $creditmemo->getShippingAmount()) {

            $grossAmount = $this->roundAmt($this->getGrossAmountShipping($creditmemo));
            $netAmount = $this->roundAmt($this->getNetAmountShipping($creditmemo));
            $taxAmount = $grossAmount - $netAmount;

            $items['shipping'] = [
                'name' => 'Shipping - ' . $order->getShippingDescription(),
                'description' => '',
                'type' => 'SHIPPING_FEE',
                'image_url' => '',
                'product_page_url' => '',
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
                'tax_amount' => $taxAmount,
                'discount_amount' => $this->roundAmt($this->getDiscountAmountShipping($creditmemo)),
                'unit_price' => $this->roundAmt($this->getUnitPriceShipping($creditmemo), 6),
                'tax_rate' => $this->roundAmt($this->getTaxRateShipping($creditmemo), 6),
                'tax_class_name' => 'VAT ' . $this->roundAmt($this->getTaxRateShipping($creditmemo) * 100) . '%',
                'quantity' => 1,
                'quantity_unit' => 'sc',
            ];
        }

        return $items;
    }
}
