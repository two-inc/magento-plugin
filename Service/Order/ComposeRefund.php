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

        // Reconcile any known third-party fee (via a registered
        // FeeLineProviderInterface — a provider only needs to return a
        // line here if the fee it targets was actually refunded on this
        // credit memo, no proration is guessed at this call site) and,
        // failing that, any genuinely untaxed residual. See
        // Order::reconcileOtherCharges() docblock.
        $lineItems = $this->reconcileOtherCharges(
            $lineItems,
            $creditmemo,
            (float)$creditmemo->getGrandTotal() + $this->declaredTenders($creditmemo, $order),
            (float)$creditmemo->getTaxAmount()
        );
        $lineItems = $this->applyTaxCodes($lineItems, $order);
        $this->assertLinesCarryNetAndTax($lineItems, $order);

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
        $taxSubtotals = $this->getTaxSubtotals($lineItems, (int)$order->getStoreId());
        if ($taxSubtotals) {
            $result['tax_subtotals'] = $taxSubtotals;
        }
        // TWO-26309: what the credit memo returns to a tender (a gift card,
        // store credit) is refunded lines Two did not fund. It goes as the
        // prepaid part, up to the prepaid Two has fulfilled and not refunded;
        // the rest of the lines is the amount, so the two sum to the lines.
        if ($this->declaredTenders($order, $order) > 0) {
            $returned = (int)round($this->declaredTenders($creditmemo, $order) * 100);
            $grand = (int)round((float)$creditmemo->getGrandTotal() * 100);
            $prepaid = $this->refundPrepaid($order, $returned, $grand + $returned);
            $result['amount'] = $this->roundAmt(($grand + $returned - $prepaid) / 100);
            $result['prepaid_amount'] = $this->roundAmt($prepaid / 100);
        }
        return $result;
    }

    /**
     * Refuses a refund line sent as tax alone or as net alone (TWO-26638).
     *
     * Two refunds a line's tax only together with its net amount, so a line
     * whose net rounds to 0 while its tax does not is refused. A line with net
     * and no tax is refused when its rate is not 0% and the tax due at that
     * rate rounds to at least 0.01: a line at 0% (the adjustment line) and a
     * line too small to carry tax at its rate go out unchanged. The plugin
     * never fills in or recomputes the tax itself: how tax is levied is the
     * shop's decision. Amounts are read rounded as the payload sends them.
     *
     * Runs on every line the plugin composes, before the postprocessing hook
     * and before anything is sent, so the credit memo is refused unsaved.
     *
     * @param array $lineItems
     * @param Order $order
     * @return void
     * @throws LocalizedException naming the first refused line
     */
    public function assertLinesCarryNetAndTax(array $lineItems, Order $order): void
    {
        foreach ($lineItems as $line) {
            $net = round((float)($line['net_amount'] ?? 0), 2);
            $tax = round((float)($line['tax_amount'] ?? 0), 2);
            $rate = (float)($line['tax_rate'] ?? 0);
            $name = (string)($line['name'] ?? '');
            if ($net == 0.0 && $tax != 0.0) {
                $type = 'tax only';
                $phrase = __(
                    '"%1" cannot be refunded as tax alone: %2 refunds a line\'s tax only together with its net amount. To correct the tax charged, refund the whole order and rebook it with the correct tax rates.',
                    $name,
                    $this->brandRegistry->getProductName()
                );
            } elseif ($net != 0.0 && $tax == 0.0 && round(abs($net) * $rate, 2) != 0.0) {
                $type = 'net only';
                $phrase = __(
                    '"%1" has no tax entered: %2 refunds a line\'s net amount only together with its tax. Enter the tax refunded for this line as well.',
                    $name,
                    $this->brandRegistry->getProductName()
                );
            } else {
                continue;
            }
            $this->logRepository->addErrorLog('RefundLineRefused', sprintf(
                'Refund line refused (%s): line "%s", order %s, net %.2F, tax %.2F, rate %s',
                $type,
                $name,
                $order->getIncrementId(),
                $net,
                $tax,
                (string)($line['tax_rate'] ?? '')
            ));
            throw new LocalizedException($phrase);
        }
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
            // order_item_id is not interpreted by the API; matching
            // ComposeOrder keeps the fee line traceable across payloads.
            $items['surcharge'] = $this->getSurchargeLine(
                (float)$creditmemo->getTwoSurchargeAmount(),
                (float)$creditmemo->getTwoSurchargeTaxAmount(),
                (string)$creditmemo->getTwoSurchargeDescription(),
                (float)$creditmemo->getTwoSurchargeTaxRate()
            );
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
            // The order's rate, relayed: a refund does not re-check the charged tax.
            $taxRate = $this->getTaxRateShipping($creditmemo, false);

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
                'tax_rate' => $this->roundAmt($taxRate, 6),
                'tax_class_name' => 'VAT ' . $this->roundAmt($taxRate * 100) . '%',
                'quantity' => 1,
                'quantity_unit' => 'sc',
            ];
        }

        return $items;
    }
}
