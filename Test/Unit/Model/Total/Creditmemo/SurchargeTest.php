<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Total\Creditmemo;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Total\Creditmemo\Surcharge;

/**
 * Regression coverage for ABN-443 on the refund path.
 *
 * Same root cause as the invoice collector: the surcharge VAT is already
 * carried in the credit-memo's tax_amount/grand_total (Magento propagates the
 * order/invoice tax onto the credit memo natively) before this collector
 * runs. Re-adding it here inflates the credit-memo grand total past the
 * order's paid total, so Magento's CreditmemoService rejects the refund with
 * "The most money available to refund is ...". This collector must add only
 * the surcharge NET to the grand total and must not touch tax_amount.
 *
 * Full proportional refund of production order #2000000014:
 *   net 58.09, VAT 21.5% = 12.48935.
 *   Native credit-memo pre-state: grand 1071.48935, tax 12.48935.
 *   Correct result: grand 1129.57935, tax 12.48935.
 *   Buggy result:   grand 1142.06870, tax 24.97870  (VAT counted twice).
 */
class SurchargeTest extends TestCase
{
    private const NET = 58.09;
    private const TAX_RATE = 21.5;
    private const SURCHARGE_TAX = 12.48935; // round(58.09 * 0.215, 6)
    private const SUBTOTAL = 1049.0;

    private const NATIVE_GRAND = 1071.48935;
    private const NATIVE_TAX = 12.48935;

    private function makeOrder(): Order
    {
        $order = new Order();
        $order->setData('two_surcharge_amount', self::NET);
        $order->setData('base_two_surcharge_amount', self::NET);
        $order->setData('two_surcharge_refunded', 0.0);
        $order->setData('base_two_surcharge_refunded', 0.0);
        $order->setData('two_surcharge_tax_rate', self::TAX_RATE);
        $order->setData('two_surcharge_description', 'Zakelijk op Rekening - 90 dagen');
        $order->setData('subtotal', self::SUBTOTAL);
        $order->setData('base_to_order_rate', 1.0);
        return $order;
    }

    private function makeCreditmemo(Order $order): Creditmemo
    {
        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        // Full refund: credit-memo subtotal == order subtotal -> proportion 1.0.
        $creditmemo->setData('subtotal', self::SUBTOTAL);
        // State left by Magento's native collectors (incl. surcharge VAT).
        $creditmemo->setData('grand_total', self::NATIVE_GRAND);
        $creditmemo->setData('base_grand_total', self::NATIVE_GRAND);
        $creditmemo->setData('tax_amount', self::NATIVE_TAX);
        $creditmemo->setData('base_tax_amount', self::NATIVE_TAX);
        return $creditmemo;
    }

    public function testAddsOnlyNetSurchargeToGrandTotal(): void
    {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order);

        (new Surcharge())->collect($creditmemo);

        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND + self::NET,
            (float)$creditmemo->getGrandTotal(),
            0.0001,
            'Credit-memo grand total must increase by the surcharge NET only; '
            . 're-adding the VAT pushes the refund past the order paid total (ABN-443).'
        );
    }

    public function testDoesNotReAddSurchargeVatToTaxAmount(): void
    {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order);

        (new Surcharge())->collect($creditmemo);

        $this->assertEqualsWithDelta(
            self::NATIVE_TAX,
            (float)$creditmemo->getTaxAmount(),
            0.0001,
            'Credit-memo tax_amount must be unchanged: the surcharge VAT is already '
            . 'present from native tax propagation (ABN-443).'
        );
    }

    public function testStillRecordsSurchargeDescriptorFields(): void
    {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order);

        (new Surcharge())->collect($creditmemo);

        $this->assertEqualsWithDelta(self::NET, (float)$creditmemo->getTwoSurchargeAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            self::SURCHARGE_TAX,
            (float)$creditmemo->getTwoSurchargeTaxAmount(),
            0.0001
        );
        $this->assertSame(
            'Zakelijk op Rekening - 90 dagen',
            $creditmemo->getTwoSurchargeDescription()
        );
    }

    /**
     * Guards that the fix only de-duplicates the surcharge VAT and never
     * disturbs legitimate line-item VAT: when the native tax total carries
     * both a line-item VAT component and the surcharge VAT, collect() must
     * leave tax_amount exactly as-is (line VAT intact, surcharge VAT not
     * re-added) and add only the surcharge net to the grand total.
     */
    public function testPreservesLineItemVatAndDoesNotReAddSurchargeVat(): void
    {
        $order = $this->makeOrder();

        $lineItemVat = 200.0;
        $nativeTax = $lineItemVat + self::SURCHARGE_TAX; // line VAT + surcharge VAT, both via native propagation
        $nativeGrand = 1000.0 + $nativeTax;              // goods + all VAT; surcharge net not yet added

        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setData('subtotal', self::SUBTOTAL);
        $creditmemo->setData('grand_total', $nativeGrand);
        $creditmemo->setData('base_grand_total', $nativeGrand);
        $creditmemo->setData('tax_amount', $nativeTax);
        $creditmemo->setData('base_tax_amount', $nativeTax);

        (new Surcharge())->collect($creditmemo);

        $this->assertEqualsWithDelta(
            $nativeGrand + self::NET,
            (float)$creditmemo->getGrandTotal(),
            0.0001,
            'Grand total must grow by the surcharge net only.'
        );
        $this->assertEqualsWithDelta(
            $nativeTax,
            (float)$creditmemo->getTaxAmount(),
            0.0001,
            'tax_amount must be untouched: line-item VAT preserved AND surcharge VAT not re-added.'
        );
    }
}
