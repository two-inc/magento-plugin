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
        // The proportional default is the surcharge net Magento's native tax
        // collector has ALREADY refunded VAT for on this credit memo (it
        // prorates order tax by subtotal). Compute it regardless of any
        // override so we can reconcile the tax line to what's actually
        // refunded. Keep 6dp internally (a 2dp round here previously lost up
        // to half a cent and defeated the Total\Surcharge precision fix).
        $orderSubtotal = (float)$order->getSubtotal();
        $cmSubtotal = (float)$creditmemo->getSubtotal();
        $proportion = $orderSubtotal > 0 ? $cmSubtotal / $orderSubtotal : 0.0;
        $defaultNet = round($orderSurcharge * $proportion, 6);

        // Phase 5 plugin sets `two_surcharge_amount` directly on the creditmemo
        // from request data. hasData() distinguishes "explicit merchant
        // override" (including 0) from "never set, use proportional default".
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
        $baseDefaultNet = round($defaultNet / $rate, 6);

        // Tax delta: native already refunded VAT on the proportional default
        // surcharge net, so adjust the tax line ONLY for the difference an
        // override introduces. This is exactly zero on the non-override path,
        // preserving the #201 de-dup guarantee (surcharge VAT counted once);
        // when the merchant edits the surcharge it moves the Tax line to the
        // VAT on the surcharge actually refunded (refunded net × rate).
        $taxDelta = round(($amount - $defaultNet) * ($taxRatePercent / 100), 6);
        $baseTaxDelta = round(($baseAmount - $baseDefaultNet) * ($taxRatePercent / 100), 6);

        $creditmemo->setTwoSurchargeAmount($amount);
        $creditmemo->setBaseTwoSurchargeAmount($baseAmount);
        $creditmemo->setTwoSurchargeTaxAmount($taxAmount);
        $creditmemo->setBaseTwoSurchargeTaxAmount($baseTaxAmount);
        $creditmemo->setTwoSurchargeDescription((string)$order->getTwoSurchargeDescription());
        $creditmemo->setTwoSurchargeTaxRate($taxRatePercent);

        // Grand total gets the surcharge net plus the tax delta. The base
        // surcharge VAT is already in tax_amount via Magento's native tax
        // propagation (re-adding the full VAT was the ABN-443 double-count);
        // we only move the Tax line and grand total by the override delta so
        // both stay consistent with the surcharge actually refunded.
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
}
