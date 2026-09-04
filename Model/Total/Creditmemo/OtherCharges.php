<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two as TwoPayment;
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

        // By instance, not code: a brand overlay extends Two under its own.
        if (!$this->isTwoOrder($order)) {
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

        // Entitlement is CUMULATIVE, so a share an earlier memo could not take
        // is still recoverable here rather than stranded, and the last memo
        // lands on the whole charge exactly with no rounding residue.
        [$refundedCharge, $refundedSubtotal] = $this->priorRefunds($creditmemo, $order);
        $share = min(1.0, ($refundedSubtotal + (float)$creditmemo->getSubtotal()) / $orderSubtotal);
        $net = round($feeNet * $share - $refundedCharge, 6);
        if ($net <= 0) {
            return $this;
        }

        // What core already granted THIS charge, read the way ComposeRefund
        // reads it so the two cannot disagree about the rate.
        $granted = $this->grantedFeeTax($creditmemo);
        if ($granted < -0.005) {
            // Another total's shortfall is not this charge's to pay.
            $this->logRepository->addDebugLog(
                'OtherChargesDeferred',
                sprintf('Memo tax is short by %.4F against its own lines. Deferred.', -$granted)
            );

            return $this;
        }
        $granted = max(0.0, $granted);

        // base_to_order_rate = order-currency units per 1 base-currency unit.
        $fxRate = (float)$order->getBaseToOrderRate();
        if ($fxRate <= 0) {
            // Assuming 1.0 would over-refund the base amounts.
            return $this;
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
        if ($feeRate > 0) {
            $net = min(
                $net,
                ($granted + $taxHeadroom) / $feeRate,
                ($payable + $granted) / (1 + $feeRate)
            );
        } else {
            $net = min($net, $payable);
        }
        $net = round($net, 6);

        $taxDelta = round($feeRate * $net - $granted, 6);
        if ($taxDelta < -0.005) {
            $this->logRepository->addDebugLog(
                'OtherChargesDeferred',
                sprintf('Granted VAT %.4F exceeds the share of %.4F net. Deferred.', $granted, $net)
            );

            return $this;
        }
        if ($net <= 0) {
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

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function isTwoOrder($order): bool
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }

        try {
            return $payment->getMethodInstance() instanceof TwoPayment;
        } catch (\Throwable $e) {
            // getMethodInstance() throws for a method no longer installed.
            return false;
        }
    }

    /**
     * From the saved memos, not a running column, so a re-collect on this
     * memo cannot compound.
     *
     * @param Creditmemo $creditmemo
     * @param \Magento\Sales\Model\Order $order
     * @return array{0: float, 1: float} charge already refunded, subtotal already refunded
     */
    private function priorRefunds(Creditmemo $creditmemo, $order): array
    {
        $collection = $order->getCreditmemosCollection();
        if (!$collection) {
            return [0.0, 0.0];
        }

        $charge = 0.0;
        $subtotal = 0.0;
        foreach ($collection as $existing) {
            if ($existing->getId() && (int)$existing->getId() !== (int)$creditmemo->getId()) {
                $charge += (float)$existing->getTwoOtherChargesAmount();
                $subtotal += (float)$existing->getSubtotal();
            }
        }

        return [$charge, $subtotal];
    }
}
