<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Total\Invoice;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Total\Invoice\Surcharge;

/**
 * Regression coverage for ABN-443.
 *
 * The Two payment surcharge VAT is booked into the order's tax total at
 * quote/placement time. Magento's native invoice Tax collector then
 * propagates that order tax onto the invoice before this collector runs.
 * Therefore the surcharge VAT is ALREADY present in the invoice's
 * tax_amount/grand_total when collect() executes; this collector must add
 * only the surcharge NET to the grand total and must NOT touch tax_amount.
 *
 * Numbers mirror the production order #2000000014 that exposed the bug:
 *   net 58.09, VAT 21.5% = 12.48935, gross 70.58.
 *   Native invoice pre-state: grand 1071.48935, tax 12.48935.
 *   Correct result: grand 1129.57935, tax 12.48935.
 *   Buggy result:   grand 1142.06870, tax 24.97870  (VAT counted twice).
 */
class SurchargeTest extends TestCase
{
    private const NET = 58.09;
    private const TAX_RATE = 21.5;
    private const SURCHARGE_TAX = 12.48935; // round(58.09 * 0.215, 6)

    // Native-collected invoice pre-state (subtotal 1049 + shipping 10 + VAT).
    private const NATIVE_GRAND = 1071.48935;
    private const NATIVE_TAX = 12.48935;

    private function makeOrder(): Order
    {
        $order = new Order();
        $order->setData('two_surcharge_amount', self::NET);
        $order->setData('base_two_surcharge_amount', self::NET);
        $order->setData('two_surcharge_invoiced', 0.0);
        $order->setData('base_two_surcharge_invoiced', 0.0);
        $order->setData('two_surcharge_tax_amount', self::SURCHARGE_TAX);
        $order->setData('base_two_surcharge_tax_amount', self::SURCHARGE_TAX);
        $order->setData('two_surcharge_tax_rate', self::TAX_RATE);
        $order->setData('two_surcharge_description', 'Zakelijk op Rekening - 90 dagen');
        return $order;
    }

    private function makeInvoice(Order $order): Invoice
    {
        $invoice = new Invoice();
        $invoice->setOrder($order);
        // State left by Magento's native collectors (incl. surcharge VAT).
        $invoice->setData('grand_total', self::NATIVE_GRAND);
        $invoice->setData('base_grand_total', self::NATIVE_GRAND);
        $invoice->setData('tax_amount', self::NATIVE_TAX);
        $invoice->setData('base_tax_amount', self::NATIVE_TAX);
        return $invoice;
    }

    public function testAddsOnlyNetSurchargeToGrandTotal(): void
    {
        $order = $this->makeOrder();
        $invoice = $this->makeInvoice($order);

        (new Surcharge())->collect($invoice);

        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND + self::NET,
            (float)$invoice->getGrandTotal(),
            0.0001,
            'Invoice grand total must increase by the surcharge NET only; '
            . 'the VAT is already in the grand total via native tax propagation.'
        );
    }

    public function testDoesNotReAddSurchargeVatToTaxAmount(): void
    {
        $order = $this->makeOrder();
        $invoice = $this->makeInvoice($order);

        (new Surcharge())->collect($invoice);

        $this->assertEqualsWithDelta(
            self::NATIVE_TAX,
            (float)$invoice->getTaxAmount(),
            0.0001,
            'Invoice tax_amount must be unchanged: the surcharge VAT is already '
            . 'present from native propagation of order.tax_amount (ABN-443).'
        );
    }

    public function testStillRecordsSurchargeDescriptorFields(): void
    {
        $order = $this->makeOrder();
        $invoice = $this->makeInvoice($order);

        (new Surcharge())->collect($invoice);

        $this->assertEqualsWithDelta(self::NET, (float)$invoice->getTwoSurchargeAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            self::SURCHARGE_TAX,
            (float)$invoice->getTwoSurchargeTaxAmount(),
            0.0001
        );
        $this->assertSame('Zakelijk op Rekening - 90 dagen', $invoice->getTwoSurchargeDescription());
    }
}
