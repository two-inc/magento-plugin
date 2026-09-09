<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Total\Creditmemo;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\OtherChargesResolver;

/**
 * Creditmemo total collector for a fee no sales document itemizes.
 *
 * A fee reaching the order's grand total through a totals collector rather
 * than a quote item belongs to no item and no shipping, so core's own
 * collectors never carry it onto a credit memo and the merchant cannot refund
 * it. This puts the order's residual back, prorated by refunded subtotal
 * share and capped by what earlier credit memos already took.
 *
 * Nothing here knows which extension the fee came from; the residual is
 * defined by what the grand total exceeds.
 */
class OtherCharges extends AbstractTotal
{
    private OtherChargesResolver $otherChargesResolver;

    private LogRepository $logRepository;

    public function __construct(
        OtherChargesResolver $otherChargesResolver,
        LogRepository $logRepository,
        array $data = []
    ) {
        parent::__construct($data);
        $this->otherChargesResolver = $otherChargesResolver;
        $this->logRepository = $logRepository;
    }

    /**
     * @inheritDoc
     */
    public function collect(Creditmemo $creditmemo): self
    {
        $order = $creditmemo->getOrder();
        if (!$order) {
            return $this;
        }

        if (!$this->otherChargesResolver->appliesTo($order)) {
            return $this;
        }

        $residual = $this->otherChargesResolver->forOrder($order);
        if (!$residual) {
            return $this;
        }

        $feeNet = (float)$residual['net_amount'];
        $feeTax = (float)$residual['tax_amount'];
        if ($feeNet <= 0) {
            return $this;
        }

        $orderSubtotal = (float)$order->getSubtotal();
        if ($orderSubtotal <= 0) {
            return $this;
        }

        // The rate getOtherChargesLineItem() already verified against the
        // order, not one re-derived from its own 2dp amounts.
        $feeRate = isset($residual['tax_rate'])
            ? (float)$residual['tax_rate']
            : $feeTax / $feeNet;

        [$refundedCharge, $refundedSubtotal] = $this->otherChargesResolver
            ->priorRefunds($order, $creditmemo);
        $remaining = round($feeNet - $refundedCharge, 6);

        // Plugin\Model\Sales\CreditmemoFeeOverride stamps the admin form's
        // value on the creditmemo from request data. hasData() distinguishes
        // an explicit merchant override (including 0) from "never set, prorate".
        $hasOverride = $creditmemo->hasData('two_other_charges_amount')
            && $creditmemo->getData('two_other_charges_amount') !== null
            && $creditmemo->getData('two_other_charges_amount') !== '';

        if ($hasOverride) {
            // The share is what the merchant typed, bounded only by what the
            // charge has left: refunding the whole charge on a memo carrying
            // no items is the point of the override.
            $requested = min(
                round((float)$creditmemo->getData('two_other_charges_amount'), 6),
                max(0.0, $remaining)
            );
        } else {
            // Entitlement is CUMULATIVE, so a share an earlier memo could not
            // take is still recoverable here rather than stranded, and the last
            // memo lands on the whole charge exactly with no rounding residue.
            $share = min(1.0, ($refundedSubtotal + (float)$creditmemo->getSubtotal()) / $orderSubtotal);
            $requested = round($feeNet * $share - $refundedCharge, 6);
        }

        if ($requested <= 0) {
            // An explicit zero is the merchant refunding none of the charge,
            // which no ceiling refused — so it is not an error.
            return $this;
        }

        // What core already granted THIS charge, read the way ComposeRefund
        // reads it so the two cannot disagree about the rate.
        $granted = $this->grantedFeeTax($creditmemo);
        if ($granted < -0.005) {
            // Another total's shortfall is not this charge's to pay.
            return $this->deferOrRefuse(
                $hasOverride,
                __(
                    'Other charges cannot be refunded on this credit memo: its tax is short by %1 '
                    . 'against its own lines.',
                    round(-$granted, 2)
                ),
                sprintf('Memo tax is short by %.4F against its own lines. Deferred.', -$granted)
            );
        }
        $granted = max(0.0, $granted);

        // base_to_order_rate = order-currency units per 1 base-currency unit.
        $fxRate = (float)$order->getBaseToOrderRate();
        if ($fxRate <= 0) {
            // Assuming 1.0 would over-refund the base amounts.
            return $this->deferOrRefuse(
                $hasOverride,
                __('Other charges cannot be refunded: the order has no usable currency conversion rate.'),
                null
            );
        }

        $taxAllowance = (float)$order->getTaxInvoiced() - (float)$order->getTaxRefunded();
        $invoice = $creditmemo->getInvoice();
        if ($invoice) {
            $taxAllowance = min($taxAllowance, (float)$invoice->getTaxAmount());
        }
        $taxHeadroom = $taxAllowance - (float)$creditmemo->getTaxAmount();

        // validateForRefund() bounds the base grand total.
        $payable = $fxRate * (
            min((float)$order->getBaseGrandTotal(), (float)$order->getBaseTotalPaid())
            - (float)$order->getBaseTotalRefunded()
            - (float)$creditmemo->getBaseGrandTotal()
        );

        // Solved, not clamped: scaling legs chosen separately loses the rate.
        $vatCeiling = $feeRate > 0 ? ($granted + $taxHeadroom) / $feeRate : INF;
        $payableCeiling = $feeRate > 0 ? ($payable + $granted) / (1 + $feeRate) : $payable;

        $net = round(min($requested, $vatCeiling, $payableCeiling), 6);

        // A ceiling the automatic proportion simply absorbs is an explicit
        // instruction refused, so the merchant is told which one bound it and
        // what they can type instead — rather than being handed a silent 0.00.
        if ($hasOverride && $requested - $net > 0.005) {
            throw new LocalizedException(
                $vatCeiling <= $payableCeiling
                    ? __(
                        'Other charges refund (%1) exceeds what the order\'s remaining VAT allowance '
                        . 'covers (%2).',
                        round($requested, 2),
                        round(max(0.0, $vatCeiling), 2)
                    )
                    : __(
                        'Other charges refund (%1) exceeds what is still refundable on this order (%2).',
                        round($requested, 2),
                        round(max(0.0, $payableCeiling), 2)
                    )
            );
        }

        $taxDelta = round($feeRate * $net - $granted, 6);
        if ($taxDelta < -0.005) {
            return $this->deferOrRefuse(
                $hasOverride,
                __(
                    'Other charges cannot be refunded on this credit memo: %1 of VAT is already '
                    . 'granted, more than %2 of net carries.',
                    round($granted, 2),
                    round($net, 2)
                ),
                sprintf('Granted VAT %.4F exceeds the share of %.4F net. Deferred.', $granted, $net)
            );
        }
        if ($net <= 0) {
            // Unreachable under an override: a requested amount above zero
            // clamped to zero trips the guard above first, which names the
            // ceiling rather than reporting the outcome.
            $this->logRepository->addDebugLog(
                'OtherChargesDeferred',
                sprintf('No ceiling leaves room for the charge (%.4F granted). Deferred.', $granted)
            );

            return $this;
        }
        $taxDelta = max(0.0, $taxDelta);

        $baseNet = round($net / $fxRate, 6);
        $baseTaxDelta = round($taxDelta / $fxRate, 6);

        $creditmemo->setTwoOtherChargesAmount($net);
        $creditmemo->setBaseTwoOtherChargesAmount($baseNet);
        $creditmemo->setTwoOtherChargesTaxAmount($taxDelta);
        $creditmemo->setBaseTwoOtherChargesTaxAmount($baseTaxDelta);

        $creditmemo->setGrandTotal((float)$creditmemo->getGrandTotal() + $net + $taxDelta);
        $creditmemo->setBaseGrandTotal((float)$creditmemo->getBaseGrandTotal() + $baseNet + $baseTaxDelta);
        $creditmemo->setTaxAmount((float)$creditmemo->getTaxAmount() + $taxDelta);
        $creditmemo->setBaseTaxAmount((float)$creditmemo->getBaseTaxAmount() + $baseTaxDelta);

        return $this;
    }

    /**
     * A ceiling the automatic proportion absorbs silently, the merchant's own
     * typed amount must not: an override reaching one is refused with the
     * reason, where the proration defers with a debug line.
     *
     * @param bool $hasOverride
     * @param Phrase $message
     * @param string|null $debug Nothing to log when the proration path is
     *                           deliberately silent about this case.
     * @return $this
     * @throws LocalizedException
     */
    private function deferOrRefuse(bool $hasOverride, Phrase $message, ?string $debug): self
    {
        if ($hasOverride) {
            throw new LocalizedException($message);
        }
        if ($debug !== null) {
            $this->logRepository->addDebugLog('OtherChargesDeferred', $debug);
        }

        return $this;
    }

    /**
     * The memo's tax that belongs to no line composition itemizes — what core
     * granted this charge. Read in ORDER currency, where ComposeRefund
     * evaluates its residual, so a converted-currency order cannot desync the
     * two. No product loads.
     *
     * @param Creditmemo $creditmemo
     * @return float
     */
    private function grantedFeeTax(Creditmemo $creditmemo): float
    {
        $itemised = (float)$creditmemo->getShippingTaxAmount()
            + (float)$creditmemo->getTwoSurchargeTaxAmount();

        foreach ($creditmemo->getAllItems() as $item) {
            $itemised += (float)$item->getTaxAmount();
        }

        return round((float)$creditmemo->getTaxAmount() - $itemised, 6);
    }

}
