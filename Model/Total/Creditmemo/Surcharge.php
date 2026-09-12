<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;

/**
 * Creditmemo total collector for the Two surcharge.
 *
 * Default behaviour: refund the surcharge proportionally to the items being
 * refunded (creditmemo subtotal / order subtotal). When the merchant types an
 * explicit value into the creditmemo override field, that value is pre-set on
 * the creditmemo before collectTotals runs and we honour it here.
 *
 * The override path is what allows the surcharge to be refunded in full on a
 * creditmemo with no items at all: zero items, the whole surcharge typed into
 * the override input.
 */
class Surcharge extends AbstractTotal
{
    /**
     * @inheritDoc
     */
    public function collect(Creditmemo $creditmemo): self
    {
        $order = $creditmemo->getOrder();

        $orderSurcharge = (float)$order->getTwoSurchargeAmount();
        if ($orderSurcharge <= 0) {
            return $this;
        }

        $alreadyRefunded = (float)$order->getTwoSurchargeRefunded();
        $maxRefundable = $orderSurcharge - $alreadyRefunded;
        if ($maxRefundable <= 0) {
            return $this;
        }

        $baseOrderSurcharge = (float)$order->getBaseTwoSurchargeAmount();
        $baseAlreadyRefunded = (float)$order->getBaseTwoSurchargeRefunded();
        $baseMaxRefundable = $baseOrderSurcharge - $baseAlreadyRefunded;

        // The default refund is proportional to the items on this memo. 6dp
        // deliberately — a 2dp round here loses up to half a cent.
        $orderSubtotal = (float)$order->getSubtotal();
        $cmSubtotal = (float)$creditmemo->getSubtotal();
        $proportion = $orderSubtotal > 0 ? $cmSubtotal / $orderSubtotal : 0.0;
        $defaultNet = round($orderSurcharge * $proportion, 6);

        // CreditmemoFeeOverride sets `two_surcharge_amount` directly on the
        // creditmemo from request data. hasData() distinguishes "explicit
        // merchant override" (including 0) from "never set, use proportional
        // default". Admin input arrives at locale precision, often 2dp but
        // potentially finer than the 6dp we keep internally, hence the round.
        $hasOverride = $creditmemo->hasData('two_surcharge_amount')
            && $creditmemo->getData('two_surcharge_amount') !== null
            && $creditmemo->getData('two_surcharge_amount') !== '';
        $amount = $hasOverride
            ? round((float)$creditmemo->getData('two_surcharge_amount'), 6)
            : $defaultNet;

        // Nothing refunded and nothing native assumed → no surcharge in play.
        if ($amount <= 0 && $defaultNet <= 0) {
            return $this;
        }

        $amount = max(0.0, min($amount, $maxRefundable));

        $taxRatePercent = (float)$order->getTwoSurchargeTaxRate();
        $taxAmount = round($amount * ($taxRatePercent / 100), 6);

        // base_to_order_rate = order-currency units per 1 base-currency unit.
        // Convert order → base by dividing.
        $rate = (float)$order->getBaseToOrderRate();
        if ($rate <= 0) {
            // Fallback: derive from the persisted surcharge columns. Mind
            // the direction — orderSurcharge / baseOrderSurcharge gives
            // order/base (matches base_to_order_rate semantics above).
            $rate = $baseOrderSurcharge > 0 ? $orderSurcharge / $baseOrderSurcharge : 1.0;
        }
        $baseAmount = max(0.0, min(round($amount / $rate, 6), $baseMaxRefundable));
        $baseTaxAmount = round($taxAmount / $rate, 6);

        // The surcharge VAT core already granted this memo, measured rather
        // than assumed: its credit-memo tax collector hands the last memo the
        // order's whole remaining tax allowance, but gives an earlier one only
        // the tax its item and shipping rows carry, which no surcharge VAT
        // reaches (ABN-560). Capped by the VAT still-refundable surcharge
        // carries, because that allowance also covers other charges.
        $grantedTax = $this->grantedSurchargeTax(
            $creditmemo,
            round($maxRefundable * ($taxRatePercent / 100), 6),
            base: false
        );
        $baseGrantedTax = $this->grantedSurchargeTax(
            $creditmemo,
            round($baseMaxRefundable * ($taxRatePercent / 100), 6),
            base: true
        );

        // Move the Tax line to the VAT on the surcharge this memo actually
        // refunds. Zero wherever core granted exactly that, preserving the
        // de-dup guarantee from magento-plugin PR #201 (surcharge VAT counted
        // once). Each leg takes the VAT on its own clamped net — a base net a
        // ceiling cut back must not keep the order-currency VAT.
        $taxDelta = round($taxAmount - $grantedTax, 6);
        $baseTaxDelta = round(round($baseAmount * ($taxRatePercent / 100), 6) - $baseGrantedTax, 6);

        $creditmemo->setTwoSurchargeAmount($amount);
        $creditmemo->setBaseTwoSurchargeAmount($baseAmount);
        $creditmemo->setTwoSurchargeTaxAmount($taxAmount);
        $creditmemo->setBaseTwoSurchargeTaxAmount($baseTaxAmount);
        $creditmemo->setTwoSurchargeDescription((string)$order->getTwoSurchargeDescription());
        $creditmemo->setTwoSurchargeTaxRate($taxRatePercent);

        // Grand total gets the surcharge net plus the tax delta. Adding the
        // whole surcharge VAT here was the surcharge-VAT double-count; only
        // the delta moves, so the Tax line and grand total both end on the
        // surcharge this memo actually refunds.
        $creditmemo->setGrandTotal((float)$creditmemo->getGrandTotal() + $amount + $taxDelta);
        $creditmemo->setBaseGrandTotal((float)$creditmemo->getBaseGrandTotal() + $baseAmount + $baseTaxDelta);
        $creditmemo->setTaxAmount((float)$creditmemo->getTaxAmount() + $taxDelta);
        $creditmemo->setBaseTaxAmount((float)$creditmemo->getBaseTaxAmount() + $baseTaxDelta);

        // NOTE: do NOT mutate $order->setTwoSurchargeRefunded here. collect()
        // runs on prepareCreditmemo and again on save/register — mutating the
        // order in-place would double-count. The bump is performed by
        // Observer\CreditmemoSurchargeRunningTotal on save_after, gated by
        // is-new so retries / re-saves don't compound.

        return $this;
    }

    /**
     * The surcharge VAT core granted this memo: the memo tax no line it
     * itemizes carries, read the way Creditmemo\OtherCharges reads its own
     * grant so the two cannot disagree, bounded by $ceiling and never
     * negative — another total's shortfall is not the surcharge's to pay.
     *
     * @param Creditmemo $creditmemo
     * @param float $ceiling
     * @param bool $base
     * @return float
     */
    private function grantedSurchargeTax(Creditmemo $creditmemo, float $ceiling, bool $base): float
    {
        $itemised = $base
            ? (float)$creditmemo->getBaseShippingTaxAmount()
            : (float)$creditmemo->getShippingTaxAmount();

        foreach ($creditmemo->getAllItems() as $item) {
            $itemised += $base ? (float)$item->getBaseTaxAmount() : (float)$item->getTaxAmount();
        }

        $memoTax = $base ? (float)$creditmemo->getBaseTaxAmount() : (float)$creditmemo->getTaxAmount();

        return max(0.0, min(round($memoTax - $itemised, 6), $ceiling));
    }
}
