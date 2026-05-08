<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Model\Total\Invoice;

use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Total\AbstractTotal;

/**
 * Invoice total collector for the Two payment-terms surcharge.
 *
 * Reads the order's persisted surcharge fields (set by the quote collector
 * via the conversion fieldset) and pours the remainder onto the invoice.
 * Math is partial-safe so a future partial-invoice flow needs no changes.
 */
class Surcharge extends AbstractTotal
{
    /**
     * @inheritDoc
     */
    public function collect(Invoice $invoice): self
    {
        $order = $invoice->getOrder();

        $orderSurcharge = (float)$order->getTwoSurchargeAmount();
        if ($orderSurcharge <= 0) {
            return $this;
        }

        $alreadyInvoiced = (float)$order->getTwoSurchargeInvoiced();
        $remaining = $orderSurcharge - $alreadyInvoiced;
        if ($remaining <= 0) {
            return $this;
        }

        $baseOrderSurcharge = (float)$order->getBaseTwoSurchargeAmount();
        $baseAlreadyInvoiced = (float)$order->getBaseTwoSurchargeInvoiced();
        $baseRemaining = $baseOrderSurcharge - $baseAlreadyInvoiced;

        $orderTax = (float)$order->getTwoSurchargeTaxAmount();
        $baseOrderTax = (float)$order->getBaseTwoSurchargeTaxAmount();
        // Tax follows the same proportion as the net amount remaining.
        $proportion = $orderSurcharge > 0 ? $remaining / $orderSurcharge : 1.0;
        $taxAmount = round($orderTax * $proportion, 6);
        $baseTaxAmount = round($baseOrderTax * $proportion, 6);

        $grossAmount = $remaining + $taxAmount;
        $baseGrossAmount = $baseRemaining + $baseTaxAmount;

        $invoice->setTwoSurchargeAmount($remaining);
        $invoice->setBaseTwoSurchargeAmount($baseRemaining);
        $invoice->setTwoSurchargeTaxAmount($taxAmount);
        $invoice->setBaseTwoSurchargeTaxAmount($baseTaxAmount);
        $invoice->setTwoSurchargeDescription((string)$order->getTwoSurchargeDescription());
        $invoice->setTwoSurchargeTaxRate((float)$order->getTwoSurchargeTaxRate());

        $invoice->setGrandTotal((float)$invoice->getGrandTotal() + $grossAmount);
        $invoice->setBaseGrandTotal((float)$invoice->getBaseGrandTotal() + $baseGrossAmount);
        $invoice->setTaxAmount((float)$invoice->getTaxAmount() + $taxAmount);
        $invoice->setBaseTaxAmount((float)$invoice->getBaseTaxAmount() + $baseTaxAmount);

        // NOTE: do NOT mutate $order->setTwoSurchargeInvoiced here. collect()
        // runs once on prepareInvoice and again on register() — mutating the
        // order's running total in collect() double-counts. The bump lives in
        // Observer\InvoiceSurchargeRunningTotal which fires on save_after with
        // an is-new guard, so it's idempotent across recollects + retries.

        return $this;
    }
}
