<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Model\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;

/**
 * Creditmemo total collector for the Two surcharge.
 *
 * Default behaviour: refund the surcharge proportionally to the items being
 * refunded (creditmemo subtotal / order subtotal). When the merchant types an
 * explicit value into the creditmemo override field (Phase 5), that value is
 * pre-set on the creditmemo before collectTotals runs and we honour it here.
 *
 * The override path covers Doug's "valued buyer refuses surcharge" case:
 * a creditmemo with zero items but the full surcharge in the override input.
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

        // Phase 5 plugin sets `two_surcharge_amount` directly on the
        // creditmemo from request data. hasData() distinguishes "explicit
        // merchant override" (including 0) from "never set, use default".
        // Normalise to 6dp on entry — admin input is parsed by
        // CreditmemoSurchargeOverride at locale precision (often 2dp,
        // potentially more) and we keep 6dp internally so the refund
        // line gross matches what ComposeOrder declared at placement.
        // See Model/Total/Surcharge for the 6dp invariant rationale.
        if ($creditmemo->hasData('two_surcharge_amount')
            && $creditmemo->getData('two_surcharge_amount') !== null
            && $creditmemo->getData('two_surcharge_amount') !== ''
        ) {
            $amount = round((float)$creditmemo->getData('two_surcharge_amount'), 6);
        } else {
            // Proportional refund — keep at 6dp internally. Pre-ABN-379
            // this rounded at 2dp, losing up to half a cent that defeated
            // the Total\Surcharge fix for the dominant (partial-refund)
            // case.
            $orderSubtotal = (float)$order->getSubtotal();
            $cmSubtotal = (float)$creditmemo->getSubtotal();
            $proportion = $orderSubtotal > 0 ? $cmSubtotal / $orderSubtotal : 0.0;
            $amount = round($orderSurcharge * $proportion, 6);
        }

        if ($amount <= 0) {
            return $this;
        }

        $amount = min($amount, $maxRefundable);

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
        $baseAmount = round($amount / $rate, 6);
        $baseAmount = min($baseAmount, $baseMaxRefundable);
        $baseTaxAmount = round($taxAmount / $rate, 6);

        $grossAmount = round($amount + $taxAmount, 6);
        $baseGrossAmount = round($baseAmount + $baseTaxAmount, 6);

        $creditmemo->setTwoSurchargeAmount($amount);
        $creditmemo->setBaseTwoSurchargeAmount($baseAmount);
        $creditmemo->setTwoSurchargeTaxAmount($taxAmount);
        $creditmemo->setBaseTwoSurchargeTaxAmount($baseTaxAmount);
        $creditmemo->setTwoSurchargeDescription((string)$order->getTwoSurchargeDescription());
        $creditmemo->setTwoSurchargeTaxRate($taxRatePercent);

        $creditmemo->setGrandTotal((float)$creditmemo->getGrandTotal() + $grossAmount);
        $creditmemo->setBaseGrandTotal((float)$creditmemo->getBaseGrandTotal() + $baseGrossAmount);
        $creditmemo->setTaxAmount((float)$creditmemo->getTaxAmount() + $taxAmount);
        $creditmemo->setBaseTaxAmount((float)$creditmemo->getBaseTaxAmount() + $baseTaxAmount);

        // NOTE: do NOT mutate $order->setTwoSurchargeRefunded here. collect()
        // runs on prepareCreditmemo and again on save/register — mutating the
        // order in-place would double-count. The bump is performed by
        // Observer\CreditmemoSurchargeRunningTotal on save_after, gated by
        // is-new so retries / re-saves don't compound.

        return $this;
    }
}
