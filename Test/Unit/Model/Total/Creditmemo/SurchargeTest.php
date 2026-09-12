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
 * Regression coverage for the surcharge-VAT double-count on refunds.
 *
 * Same root cause as the invoice collector: the surcharge VAT is already
 * carried in the credit-memo's tax_amount/grand_total (Magento propagates the
 * order/invoice tax onto the credit memo natively) before this collector
 * runs. Re-adding it here inflates the credit-memo grand total past the
 * order's paid total, so Magento's CreditmemoService rejects the refund with
 * "The most money available to refund is ...". This collector must add only
 * the surcharge NET to the grand total and must not touch tax_amount.
 *
 * Full proportional refund of a fully invoiced order:
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
        $order->setData('two_surcharge_description', 'Business Invoice - 90 days');
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
            . 're-adding the VAT pushes the refund past the order paid total (the surcharge-VAT double-count bug).'
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
            . 'present from native tax propagation (the surcharge-VAT double-count bug).'
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
            'Business Invoice - 90 days',
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

    /**
     * When the merchant edits the surcharge refund down, the refunded surcharge
     * VAT must follow (refunded net x rate) — both the Tax line and the grand
     * total. Mirrors the canonical example: order €1000 goods + €100 surcharge,
     * all @21% (full tax €231). Native refunds the full surcharge VAT (€21) by
     * default; halving the surcharge to €50 must drop the refunded surcharge
     * VAT to €10.50, so Tax €231 -> €220.50 and Grand €1331 -> €1270.50.
     */
    public function testTaxTracksSurchargeOverrideDownward(): void
    {
        $order = new Order();
        $order->setData('two_surcharge_amount', 100.0);
        $order->setData('base_two_surcharge_amount', 100.0);
        $order->setData('two_surcharge_refunded', 0.0);
        $order->setData('base_two_surcharge_refunded', 0.0);
        $order->setData('two_surcharge_tax_rate', 21.0);
        $order->setData('two_surcharge_description', 'Surcharge');
        $order->setData('subtotal', 1000.0);
        $order->setData('base_to_order_rate', 1.0);

        // Native credit-memo state for a FULL refund: goods 1000 + tax 231
        // (210 goods VAT + 21 surcharge VAT). Surcharge net not yet added.
        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setData('subtotal', 1000.0);
        $creditmemo->setData('grand_total', 1231.0);
        $creditmemo->setData('base_grand_total', 1231.0);
        $creditmemo->setData('tax_amount', 231.0);
        $creditmemo->setData('base_tax_amount', 231.0);

        // Merchant overrides the refunded surcharge to half (€50).
        $creditmemo->setData('two_surcharge_amount', 50.0);

        (new Surcharge())->collect($creditmemo);

        $this->assertEqualsWithDelta(
            220.5,
            (float)$creditmemo->getTaxAmount(),
            0.0001,
            'Tax must drop to 220.50 (210 goods VAT + 10.50 surcharge VAT on the €50 refunded).'
        );
        $this->assertEqualsWithDelta(
            1270.5,
            (float)$creditmemo->getGrandTotal(),
            0.0001,
            'Grand total must be 1270.50 (1000 goods + 220.50 tax + 50 surcharge net).'
        );
    }

    /**
     * The proportional (non-override) path must remain a no-op on tax — the
     * double-count guarantee of magento-plugin PR #201. Refunded net
     * equals the proportional default, so the delta is zero and native
     * tax stands.
     */
    public function testProportionalRefundDoesNotAdjustTax(): void
    {
        $order = $this->makeOrder();

        $nativeTax = self::SURCHARGE_TAX; // native already carries the full surcharge VAT
        $nativeGrand = 1000.0 + $nativeTax;

        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setData('subtotal', self::SUBTOTAL);  // full refund => proportion 1.0
        $creditmemo->setData('grand_total', $nativeGrand);
        $creditmemo->setData('base_grand_total', $nativeGrand);
        $creditmemo->setData('tax_amount', $nativeTax);
        $creditmemo->setData('base_tax_amount', $nativeTax);

        (new Surcharge())->collect($creditmemo);

        $this->assertEqualsWithDelta(
            $nativeTax,
            (float)$creditmemo->getTaxAmount(),
            0.0001,
            'No override => proportional default => zero tax delta.'
        );
    }

    /**
     * A memo raised after an earlier one refunded part of the surcharge.
     *
     * The order: merchandise 74.00 + 14.80 VAT, surcharge 13.00 + 2.60 VAT at
     * 20%, fully invoiced, so the order's invoiced tax is 17.40. An earlier
     * offline memo refunded 1.01 of surcharge net and its 0.20 of VAT and
     * nothing else.
     *
     * Core hands the remaining memo the order's invoiced tax less the tax
     * already refunded and puts the merchandise share on the memo's item rows,
     * so the surcharge VAT this collector may still adjust is the VAT on the
     * surcharge that is left — not on the whole order surcharge (ABN-560).
     *
     * @dataProvider priorRefundCases
     */
    public function testTaxBaselineIsTheSurchargeStillRefundable(
        float $priorSurchargeRefunded,
        float $priorTaxRefunded,
        ?float $override,
        float $expectedItemTax,
        float $expectedTaxTotal,
        float $expectedGrandTotal,
        string $case
    ): void {
        $order = new Order();
        $order->setData('two_surcharge_amount', 13.0);
        $order->setData('base_two_surcharge_amount', 13.0);
        $order->setData('two_surcharge_refunded', $priorSurchargeRefunded);
        $order->setData('base_two_surcharge_refunded', $priorSurchargeRefunded);
        $order->setData('two_surcharge_tax_rate', 20.0);
        $order->setData('two_surcharge_description', 'Surcharge');
        $order->setData('subtotal', 74.0);
        $order->setData('tax_invoiced', 17.4);
        $order->setData('tax_refunded', $priorTaxRefunded);
        $order->setData('base_to_order_rate', 1.0);

        // Core's own figures, per Magento\Sales\Model\Order\Creditmemo\
        // Total\Tax: a memo taking every remaining item gets the order's
        // invoiced tax less the tax already refunded, and each item row keeps
        // its own share of it.
        $nativeTax = 17.4 - $priorTaxRefunded;
        $item = new \Magento\Framework\DataObject();
        $item->setTaxAmount(14.8);
        $item->setBaseTaxAmount(14.8);

        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setData('subtotal', 74.0);
        $creditmemo->setData('all_items', [$item]);
        $creditmemo->setData('shipping_tax_amount', 0.0);
        $creditmemo->setData('tax_amount', $nativeTax);
        $creditmemo->setData('base_tax_amount', $nativeTax);
        $creditmemo->setData('grand_total', 74.0 + $nativeTax);
        $creditmemo->setData('base_grand_total', 74.0 + $nativeTax);
        if ($override !== null) {
            $creditmemo->setData('two_surcharge_amount', $override);
        }

        (new Surcharge())->collect($creditmemo);

        $this->assertEqualsWithDelta(
            $expectedItemTax,
            (float)$item->getTaxAmount(),
            0.0001,
            $case . ': the merchandise row tax is core\'s and must be left alone.'
        );
        $this->assertEqualsWithDelta(
            $expectedTaxTotal,
            (float)$creditmemo->getTaxAmount(),
            0.0001,
            $case . ': the refund tax total must agree with the memo\'s own rows.'
        );
        $this->assertEqualsWithDelta(
            $expectedGrandTotal,
            (float)$creditmemo->getGrandTotal(),
            0.0001,
            $case . ': the grand total must carry that same tax.'
        );

        // What Total\Creditmemo\OtherCharges reads as the VAT core granted a
        // fee no line itemizes, and refuses the refund over when negative.
        $unattributed = (float)$creditmemo->getTaxAmount()
            - (float)$item->getTaxAmount()
            - (float)$creditmemo->getTwoSurchargeTaxAmount();
        $this->assertGreaterThan(
            -0.005,
            $unattributed,
            $case . ': no tax shortfall against the memo\'s own lines.'
        );
    }

    /**
     * @return array<int, array<int, float|string|null>>
     */
    public static function priorRefundCases(): array
    {
        return [
            // prior net, prior tax, override, item tax, tax total, grand total
            [0.0, 0.0, null, 14.8, 17.4, 104.4, 'nothing refunded yet, whole surcharge prorated'],
            [1.01, 0.2, null, 14.8, 17.2, 103.19, 'remaining surcharge prorated after a fee-only memo'],
            [1.01, 0.2, 0.0, 14.8, 14.802, 88.802, 'merchandise isolated, surcharge refund zeroed'],
            [1.01, 0.2, 11.99, 14.8, 17.2, 103.19, 'the whole remaining surcharge typed in'],
            [1.01, 0.2, 6.0, 14.8, 16.002, 96.002, 'part of the remaining surcharge typed in'],
        ];
    }

    /**
     * Every memo in a sequence must carry the VAT on the surcharge it refunds.
     *
     * The order: merchandise 74.00 + 14.80 VAT, surcharge 13.00 + 2.60 VAT at
     * 20%, fully invoiced, so its invoiced tax is 17.40. Each case walks its
     * memos in order, advancing the order's refunded columns the way core and
     * Observer\CreditmemoSurchargeRunningTotal advance them, and asserts each
     * memo's own row tax, tax total and grand total. An aggregate that only
     * balances once the last memo lands is what shipped.
     *
     * @param array<int, array<string, float|bool|null>> $memos
     * @param array<int, array<string, float>> $expected
     * @dataProvider memoSequenceCases
     */
    public function testEveryMemoCarriesTheVatOnTheSurchargeItRefunds(
        float $priorTaxRefunded,
        array $memos,
        array $expected,
        string $case
    ): void {
        $order = new Order();
        $order->setData('two_surcharge_amount', 13.0);
        $order->setData('base_two_surcharge_amount', 13.0);
        $order->setData('two_surcharge_refunded', 0.0);
        $order->setData('base_two_surcharge_refunded', 0.0);
        $order->setData('two_surcharge_tax_rate', 20.0);
        $order->setData('two_surcharge_description', 'Surcharge');
        $order->setData('subtotal', 74.0);
        $order->setData('tax_invoiced', 17.4);
        $order->setData('tax_refunded', $priorTaxRefunded);
        $order->setData('base_to_order_rate', 1.0);

        foreach ($memos as $index => $spec) {
            $creditmemo = $this->nativeCreditmemo($order, $spec);

            (new Surcharge())->collect($creditmemo);

            $want = $expected[$index];
            $label = sprintf('%s, memo %d', $case, $index + 1);

            $rowTax = 0.0;
            foreach ($creditmemo->getAllItems() as $item) {
                $rowTax += (float)$item->getTaxAmount();
            }
            $this->assertEqualsWithDelta(
                $want['row_tax'],
                $rowTax,
                0.0001,
                $label . ': the merchandise row tax is core\'s and must be left alone.'
            );
            $this->assertEqualsWithDelta(
                $want['surcharge_net'],
                (float)$creditmemo->getTwoSurchargeAmount(),
                0.0001,
                $label . ': the surcharge net refunded on this memo.'
            );
            $this->assertEqualsWithDelta(
                $want['tax_total'],
                (float)$creditmemo->getTaxAmount(),
                0.0001,
                $label . ': the tax total must cover the surcharge this memo refunds.'
            );
            $this->assertEqualsWithDelta(
                $want['grand_total'],
                (float)$creditmemo->getGrandTotal(),
                0.0001,
                $label . ': the grand total must carry that same tax.'
            );
            // The order is in its base currency, so both legs land together.
            $this->assertEqualsWithDelta(
                $want['tax_total'],
                (float)$creditmemo->getBaseTaxAmount(),
                0.0001,
                $label . ': the base tax total must move with the order-currency one.'
            );
            $this->assertEqualsWithDelta(
                $want['grand_total'],
                (float)$creditmemo->getBaseGrandTotal(),
                0.0001,
                $label . ': the base grand total must move with the order-currency one.'
            );

            // What Total\Creditmemo\OtherCharges reads as the VAT core granted
            // a fee no line itemizes, and refuses the refund over when negative.
            $unattributed = (float)$creditmemo->getTaxAmount()
                - $rowTax
                - (float)$creditmemo->getTwoSurchargeTaxAmount();
            $this->assertEqualsWithDelta(
                $want['unattributed'],
                $unattributed,
                0.0001,
                $label . ': the tax left over for a charge no line itemizes.'
            );

            $order->setData('tax_refunded', round(
                (float)$order->getData('tax_refunded') + round((float)$creditmemo->getTaxAmount(), 2),
                6
            ));
            $refunded = round(
                (float)$order->getData('two_surcharge_refunded') + (float)$creditmemo->getTwoSurchargeAmount(),
                6
            );
            $order->setData('two_surcharge_refunded', $refunded);
            $order->setData('base_two_surcharge_refunded', $refunded);
        }
    }

    /**
     * The pre-state Magento\Sales\Model\Order\Creditmemo\Total\Tax leaves:
     * the memo's own row tax, replaced by the order's whole remaining tax
     * allowance on the last memo and bounded by that allowance on any other.
     *
     * @param array<string, float|bool|null> $spec
     */
    private function nativeCreditmemo(Order $order, array $spec): Creditmemo
    {
        $allowance = (float)$order->getData('tax_invoiced') - (float)$order->getData('tax_refunded');
        $rowTax = (float)$spec['row_tax'];
        $memoTax = $spec['is_last'] ? $allowance : min($allowance, $rowTax);

        $items = [];
        if ($rowTax > 0) {
            $item = new \Magento\Framework\DataObject();
            $item->setTaxAmount($rowTax);
            $item->setBaseTaxAmount($rowTax);
            $items[] = $item;
        }

        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setData('subtotal', $spec['subtotal']);
        $creditmemo->setData('all_items', $items);
        $creditmemo->setData('shipping_tax_amount', 0.0);
        $creditmemo->setData('base_shipping_tax_amount', 0.0);
        $creditmemo->setData('tax_amount', $memoTax);
        $creditmemo->setData('base_tax_amount', $memoTax);
        $creditmemo->setData('grand_total', (float)$spec['subtotal'] + $memoTax);
        $creditmemo->setData('base_grand_total', (float)$spec['subtotal'] + $memoTax);
        if ($spec['override'] !== null) {
            $creditmemo->setData('two_surcharge_amount', $spec['override']);
        }

        return $creditmemo;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function memoSequenceCases(): array
    {
        return [
            'one memo taking the whole order' => [
                0.0,
                [
                    ['subtotal' => 74.0, 'row_tax' => 14.8, 'is_last' => true, 'override' => null],
                ],
                [
                    [
                        'row_tax' => 14.8, 'surcharge_net' => 13.0, 'tax_total' => 17.4,
                        'grand_total' => 104.4, 'unattributed' => 0.0,
                    ],
                ],
                'whole order on one memo',
            ],
            'surcharge prorated across two half memos' => [
                0.0,
                [
                    ['subtotal' => 37.0, 'row_tax' => 7.4, 'is_last' => false, 'override' => null],
                    ['subtotal' => 37.0, 'row_tax' => 7.4, 'is_last' => true, 'override' => null],
                ],
                [
                    [
                        'row_tax' => 7.4, 'surcharge_net' => 6.5, 'tax_total' => 8.7,
                        'grand_total' => 52.2, 'unattributed' => 0.0,
                    ],
                    [
                        'row_tax' => 7.4, 'surcharge_net' => 6.5, 'tax_total' => 8.7,
                        'grand_total' => 52.2, 'unattributed' => 0.0,
                    ],
                ],
                'surcharge split across two partial memos',
            ],
            'surcharge alone, then the merchandise' => [
                0.0,
                [
                    ['subtotal' => 0.0, 'row_tax' => 0.0, 'is_last' => false, 'override' => 13.0],
                    ['subtotal' => 74.0, 'row_tax' => 14.8, 'is_last' => true, 'override' => null],
                ],
                [
                    [
                        'row_tax' => 0.0, 'surcharge_net' => 13.0, 'tax_total' => 2.6,
                        'grand_total' => 15.6, 'unattributed' => 0.0,
                    ],
                    [
                        'row_tax' => 14.8, 'surcharge_net' => 0.0, 'tax_total' => 14.8,
                        'grand_total' => 88.8, 'unattributed' => 0.0,
                    ],
                ],
                'surcharge-only memo then the merchandise remainder',
            ],
            'surcharge held back to the last memo' => [
                0.0,
                [
                    ['subtotal' => 37.0, 'row_tax' => 7.4, 'is_last' => false, 'override' => 0.0],
                    ['subtotal' => 37.0, 'row_tax' => 7.4, 'is_last' => true, 'override' => null],
                ],
                [
                    [
                        'row_tax' => 7.4, 'surcharge_net' => 0.0, 'tax_total' => 7.4,
                        'grand_total' => 44.4, 'unattributed' => 0.0,
                    ],
                    [
                        'row_tax' => 7.4, 'surcharge_net' => 6.5, 'tax_total' => 8.7,
                        'grand_total' => 52.2, 'unattributed' => 0.0,
                    ],
                ],
                'no surcharge on the partial memo, prorated share on the last',
            ],
            // An order whose refunded tax has run ahead of its item rows: core
            // grants this memo less tax than its own rows carry, and the
            // shortfall must stay exactly that — the surcharge neither absorbs
            // it nor hides it from the other-charges validator.
            'a memo core grants less tax than its rows carry' => [
                12.4,
                [
                    ['subtotal' => 37.0, 'row_tax' => 7.4, 'is_last' => false, 'override' => null],
                ],
                [
                    [
                        'row_tax' => 7.4, 'surcharge_net' => 6.5, 'tax_total' => 6.3,
                        'grand_total' => 49.8, 'unattributed' => -2.4,
                    ],
                ],
                'core grants less tax than the memo rows carry',
            ],
        ];
    }
}
