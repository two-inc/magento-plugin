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
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\GenericPaymentMethod;
use Two\Gateway\Model\Total\Creditmemo\OtherCharges;
use Two\Gateway\Model\Two as TwoPayment;
use Two\Gateway\Service\Order\OtherChargesResolver;

/**
 * The fee's VAT belongs to no item and no shipping, so core's Tax collector
 * grants it only on a memo bounded by the order-level allowance. This
 * collector must add whatever of it is still unclaimed — and nothing when the
 * allowance is already spent — or the VAT is refunded twice or never.
 */
class OtherChargesTest extends TestCase
{
    private const ORDER_SUBTOTAL = 200.0;
    private const NATIVE_GRAND = 100.0;
    private const NATIVE_TAX = 4.0;

    private const FEE_NET = 10.0;
    private const FEE_TAX = 2.0;

    /** A residual from the generic mechanism — no extension is identified. */
    private function residual(): array
    {
        return [
            'order_item_id' => 'other_charges',
            'net_amount' => (string)self::FEE_NET,
            'tax_amount' => (string)self::FEE_TAX,
            'tax_rate' => '0.200000',
        ];
    }

    private function makeOrder(string $method = 'two'): Order
    {
        $order = new Order();
        $order->setData('subtotal', self::ORDER_SUBTOTAL);
        $order->setData('grand_total', 1000.0);
        $order->setData('base_grand_total', 1000.0);
        $order->setData('total_paid', 1000.0);
        $order->setData('base_total_paid', 1000.0);
        $order->setData('total_refunded', 0.0);
        $order->setData('base_total_refunded', 0.0);
        $order->setData('tax_invoiced', 100.0);
        $order->setData('base_tax_invoiced', 100.0);
        $order->setData('tax_refunded', 0.0);
        $order->setData('base_tax_refunded', 0.0);
        $order->setData('base_to_order_rate', 1.0);
        $instance = $method === 'other'
            ? new \stdClass()
            : $this->createMock($method === 'acme_payment' ? GenericPaymentMethod::class : TwoPayment::class);
        $order->setData('payment', new class ($instance) {
            private $instance;

            public function __construct($instance)
            {
                $this->instance = $instance;
            }

            public function getMethodInstance()
            {
                return $this->instance;
            }
        });

        return $order;
    }

    /** Prior credit memos, as Order::getCreditmemosCollection() yields them. */
    private function withPriorMemos(Order $order, array $memos): Order
    {
        return $order->setCreditmemosCollection($memos);
    }

    /**
     * $grantedFeeTax is VAT core has put on the memo that belongs to no line
     * composition itemizes — i.e. what it granted this fee. The memo's own
     * NATIVE_TAX is attributed to its items, as core builds it.
     */
    private function makeCreditmemo(Order $order, float $cmSubtotal, float $grantedFeeTax = 0.0): Creditmemo
    {
        $item = new \Magento\Framework\DataObject();
        $item->setTaxAmount(self::NATIVE_TAX);

        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setData('subtotal', $cmSubtotal);
        $creditmemo->setData('grand_total', self::NATIVE_GRAND);
        $creditmemo->setData('base_grand_total', self::NATIVE_GRAND);
        $creditmemo->setData('tax_amount', self::NATIVE_TAX + $grantedFeeTax);
        $creditmemo->setData('base_tax_amount', self::NATIVE_TAX + $grantedFeeTax);
        $creditmemo->setData('all_items', [$item]);
        $creditmemo->setData('shipping_tax_amount', 0.0);
        $creditmemo->setData('two_surcharge_tax_amount', 0.0);

        return $creditmemo;
    }

    private function makeCollector(?array $residual): OtherCharges
    {
        $resolver = $this->createMock(OtherChargesResolver::class);
        $resolver->method('forOrder')->willReturn($residual);

        return new OtherCharges($resolver, $this->createMock(LogRepository::class));
    }

    /**
     * @dataProvider proportionProvider
     */
    public function testFeeAndItsUnclaimedVatAreBothProrated(
        float $cmSubtotal,
        float $expectedNet,
        float $expectedTax,
        string $description
    ): void {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order, $cmSubtotal);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertEqualsWithDelta($expectedNet, (float)$creditmemo->getTwoOtherChargesAmount(), 0.0001, $description);
        $this->assertEqualsWithDelta(
            $expectedTax,
            (float)$creditmemo->getTwoOtherChargesTaxAmount(),
            0.0001,
            $description
        );
        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND + $expectedNet + $expectedTax,
            (float)$creditmemo->getGrandTotal(),
            0.0001,
            'grand total moves by exactly what was persisted: ' . $description
        );
        $this->assertEqualsWithDelta(
            self::NATIVE_TAX + $expectedTax,
            (float)$creditmemo->getTaxAmount(),
            0.0001,
            $description
        );
    }

    public static function proportionProvider(): array
    {
        return [
            [self::ORDER_SUBTOTAL, self::FEE_NET, self::FEE_TAX, 'full refund takes the whole fee'],
            [self::ORDER_SUBTOTAL / 2, self::FEE_NET / 2, self::FEE_TAX / 2, 'half the items, half the fee'],
            [self::ORDER_SUBTOTAL / 4, self::FEE_NET / 4, self::FEE_TAX / 4, 'quarter of the items'],
        ];
    }

    /**
     * The case the earlier net-only version got wrong in the other direction:
     * when core has already granted the whole order-level tax allowance, the
     * fee's VAT is in tax_amount and must not be added again.
     */
    public function testNoVatIsAddedWhenTheOrdersTaxAllowanceIsAlreadySpent(): void
    {
        $order = $this->makeOrder();
        $order->setData('tax_invoiced', self::NATIVE_TAX + self::FEE_TAX);
        $order->setData('base_tax_invoiced', self::NATIVE_TAX + self::FEE_TAX);
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL, self::FEE_TAX);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertEqualsWithDelta(self::FEE_NET, (float)$creditmemo->getTwoOtherChargesAmount(), 0.0001);
        $this->assertEqualsWithDelta(0.0, (float)$creditmemo->getTwoOtherChargesTaxAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND + self::FEE_NET,
            (float)$creditmemo->getGrandTotal(),
            0.0001
        );
        $this->assertEqualsWithDelta(
            self::NATIVE_TAX + self::FEE_TAX,
            (float)$creditmemo->getTaxAmount(),
            0.0001
        );
    }

    public function testWhatEarlierCreditMemosTookIsNotRefundedAgain(): void
    {
        $prior = new Creditmemo();
        $prior->setData('id', 1);
        $prior->setData('two_other_charges_amount', 8.0);

        $order = $this->withPriorMemos($this->makeOrder(), [$prior]);
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertEqualsWithDelta(2.0, (float)$creditmemo->getTwoOtherChargesAmount(), 0.0001);
        $this->assertEqualsWithDelta(0.4, (float)$creditmemo->getTwoOtherChargesTaxAmount(), 0.0001);
    }

    /**
     * Entitlement is cumulative, so a share an earlier memo could not take —
     * because a ceiling clamped it — is recovered here instead of stranded.
     */
    public function testAShortfallLeftByAnEarlierMemoIsRecovered(): void
    {
        // Memo 1 refunded half the items but only 2.00 of its 5.00 share.
        $prior = new Creditmemo();
        $prior->setData('id', 1);
        $prior->setData('subtotal', self::ORDER_SUBTOTAL / 2);
        $prior->setData('two_other_charges_amount', 2.0);

        $order = $this->withPriorMemos($this->makeOrder(), [$prior]);
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL / 2);

        $this->makeCollector($this->residual())->collect($creditmemo);

        // Cumulative share is now 100%, so this memo takes the whole balance.
        $this->assertEqualsWithDelta(8.0, (float)$creditmemo->getTwoOtherChargesAmount(), 0.0001);
    }

    public function testBaseAmountsAreConvertedAtTheOrdersRate(): void
    {
        $order = $this->makeOrder();
        $order->setData('base_to_order_rate', 2.0);
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertEqualsWithDelta(self::FEE_NET / 2, (float)$creditmemo->getBaseTwoOtherChargesAmount(), 0.0001);
        $this->assertEqualsWithDelta(self::FEE_TAX / 2, (float)$creditmemo->getBaseTwoOtherChargesTaxAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND + (self::FEE_NET + self::FEE_TAX) / 2,
            (float)$creditmemo->getBaseGrandTotal(),
            0.0001
        );
    }

    /**
     * @dataProvider noOpProvider
     */
    public function testNothingIsAddedWhenThereIsNothingToReconcile(
        ?array $residual,
        float $orderSubtotal,
        float $cmSubtotal,
        float $orderPaid,
        string $description
    ): void {
        $order = $this->makeOrder();
        $order->setData('subtotal', $orderSubtotal);
        $order->setData('grand_total', $orderPaid);
        $order->setData('base_grand_total', $orderPaid);
        $order->setData('total_paid', $orderPaid);
        $order->setData('base_total_paid', $orderPaid);
        $creditmemo = $this->makeCreditmemo($order, $cmSubtotal);

        $this->makeCollector($residual)->collect($creditmemo);

        $this->assertNull($creditmemo->getTwoOtherChargesAmount(), $description);
        $this->assertEqualsWithDelta(self::NATIVE_GRAND, (float)$creditmemo->getGrandTotal(), 0.0001, $description);
        $this->assertEqualsWithDelta(self::NATIVE_TAX, (float)$creditmemo->getTaxAmount(), 0.0001, $description);
    }

    public static function noOpProvider(): array
    {
        $fee = ['net_amount' => '10.00', 'tax_amount' => '2.00'];

        return [
            [null, self::ORDER_SUBTOTAL, self::ORDER_SUBTOTAL, 1000.0, 'grand total fully accounted for'],
            [$fee, 0.0, 0.0, 1000.0, 'no order subtotal to prorate against'],
            [$fee, self::ORDER_SUBTOTAL, 0.0, 1000.0, 'adjustment-only credit memo refunds no items'],
            [
                $fee,
                self::ORDER_SUBTOTAL,
                self::ORDER_SUBTOTAL,
                self::NATIVE_GRAND,
                'credit memo already claims everything the order was paid',
            ],
            [
                ['net_amount' => '0.00', 'tax_amount' => '0.00'],
                self::ORDER_SUBTOTAL,
                self::ORDER_SUBTOTAL,
                1000.0,
                'a zero residual is not a fee',
            ],
        ];
    }

    public function testTheRefundCannotExceedWhatTheOrderWasPaid(): void
    {
        $order = $this->makeOrder();
        $order->setData('grand_total', 150.0);
        $order->setData('base_grand_total', 150.0);
        $order->setData('total_paid', 150.0);
        $order->setData('base_total_paid', 150.0);
        $order->setData('total_refunded', 46.0);
        $order->setData('base_total_refunded', 46.0);
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);

        $this->makeCollector($this->residual())->collect($creditmemo);

        // 4.00 of headroom, shared between net and its VAT in the fee's own
        // 10:2 ratio — validateForRefund() rejects the memo otherwise.
        $moved = (float)$creditmemo->getTwoOtherChargesAmount()
            + (float)$creditmemo->getTwoOtherChargesTaxAmount();
        $this->assertEqualsWithDelta(4.0, $moved, 0.0001);
        $this->assertEqualsWithDelta(10 / 12 * 4.0, (float)$creditmemo->getTwoOtherChargesAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND + 4.0,
            (float)$creditmemo->getGrandTotal(),
            0.0001
        );
        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND + 4.0,
            (float)$creditmemo->getBaseGrandTotal(),
            0.0001
        );
    }

    /**
     * A partially-invoiced order: the paid amount, not the grand total, is the
     * ceiling Magento enforces on a refund.
     */
    public function testAPartiallyPaidOrderIsCappedByWhatWasPaid(): void
    {
        $order = $this->makeOrder();
        $order->setData('total_paid', 102.0);
        $order->setData('base_total_paid', 102.0);
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $moved = (float)$creditmemo->getTwoOtherChargesAmount()
            + (float)$creditmemo->getTwoOtherChargesTaxAmount();
        $this->assertEqualsWithDelta(2.0, $moved, 0.0001);
    }

    /**
     * A fee extension applies store-wide, so the gate decides whose refund
     * total this may move. It resolves the method INSTANCE: a brand overlay's
     * GenericPaymentMethod extends Two under its own per-brand code, so a code
     * comparison would miss every branded install.
     *
     * @dataProvider paymentGateProvider
     */
    public function testOnlyTwoOrdersIncludingBrandOverlaysAreTouched(
        string $method,
        bool $expectFee,
        string $description
    ): void {
        $order = $this->makeOrder($method);
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);

        $this->makeCollector($this->residual())->collect($creditmemo);

        if ($expectFee) {
            $this->assertEqualsWithDelta(
                self::FEE_NET,
                (float)$creditmemo->getTwoOtherChargesAmount(),
                0.0001,
                $description
            );
            return;
        }

        $this->assertNull($creditmemo->getTwoOtherChargesAmount(), $description);
        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND,
            (float)$creditmemo->getGrandTotal(),
            0.0001,
            $description
        );
    }

    public static function paymentGateProvider(): array
    {
        return [
            ['two', true, 'the base payment method'],
            ['acme_payment', true, 'a brand overlay extending it under its own code'],
            ['other', false, 'an order paid by an unrelated method'],
        ];
    }

    /**
     * With no usable conversion rate the base amounts cannot be derived, and
     * assuming 1.0 would over-refund them on a converted-currency order.
     */
    public function testNoUsableConversionRateAddsNothing(): void
    {
        $order = $this->makeOrder();
        $order->setData('base_to_order_rate', 0.0);
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertNull($creditmemo->getTwoOtherChargesAmount());
        $this->assertEqualsWithDelta(self::NATIVE_GRAND, (float)$creditmemo->getGrandTotal(), 0.0001);
    }

    /**
     * The collector never runs on a memo with no order behind it.
     */
    public function testACreditmemoWithNoOrderIsLeftAlone(): void
    {
        $creditmemo = new Creditmemo();
        $creditmemo->setData('grand_total', self::NATIVE_GRAND);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertNull($creditmemo->getTwoOtherChargesAmount());
        $this->assertEqualsWithDelta(self::NATIVE_GRAND, (float)$creditmemo->getGrandTotal(), 0.0001);
    }

    /**
     * The rate comes from the residual's own verified tax_rate, not from
     * dividing its 2dp amounts: getOtherChargesLineItem() verified that rate
     * against the order on a line-count-scaled epsilon, so on a memo with
     * fewer lines the quotient of the rounded amounts can miss it and the line
     * gets refused with the money still on the memo.
     */
    public function testTheVerifiedRateIsUsedNotTheQuotientOfTheRoundedAmounts(): void
    {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);

        // 21.5% declared; 2.00/10.00 would re-derive a different 20%.
        $residual = $this->residual();
        $residual['tax_rate'] = '0.215000';

        $this->makeCollector($residual)->collect($creditmemo);

        $this->assertEqualsWithDelta(self::FEE_NET, (float)$creditmemo->getTwoOtherChargesAmount(), 0.0001);
        $this->assertEqualsWithDelta(2.15, (float)$creditmemo->getTwoOtherChargesTaxAmount(), 0.0001);
    }

    /**
     * On a partial memo of a surcharged order core omits the surcharge VAT
     * that ComposeRefund still declares in its surcharge line, so the memo's
     * tax reads SHORT against its own lines. Paying that shortfall out here
     * would refund another total's VAT under this charge's name, at a rate
     * that is not this charge's — so it defers instead.
     */
    public function testAnotherTotalsMissingVatIsNeverPaidOutAsThisCharge(): void
    {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);
        // ComposeRefund declares this surcharge VAT; core left it out of
        // the memo's tax_amount, so granted comes out negative.
        $creditmemo->setData('two_surcharge_tax_amount', 3.0);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertNull($creditmemo->getTwoOtherChargesAmount());
        $this->assertNull($creditmemo->getTwoOtherChargesTaxAmount());
        $this->assertEqualsWithDelta(self::NATIVE_GRAND, (float)$creditmemo->getGrandTotal(), 0.0001);
        $this->assertEqualsWithDelta(self::NATIVE_TAX, (float)$creditmemo->getTaxAmount(), 0.0001);
    }

    /**
     * A memo whose invoice can carry only part of the charge's VAT refunds a
     * correspondingly smaller net at the charge's exact rate — never the whole
     * net at a rate no tax rule applied. The rest stays for a later memo.
     */
    public function testAPartialVatAllowanceRefundsASmallerShareAtTheExactRate(): void
    {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);
        $invoice = new \Magento\Sales\Model\Order\Invoice();
        // 0.50 of headroom against a 2.00 share, at 20% -> 2.50 of net.
        $invoice->setData('tax_amount', self::NATIVE_TAX + 0.5);
        $creditmemo->setData('invoice', $invoice);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertEqualsWithDelta(2.5, (float)$creditmemo->getTwoOtherChargesAmount(), 0.0001);
        $this->assertEqualsWithDelta(0.5, (float)$creditmemo->getTwoOtherChargesTaxAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            self::NATIVE_GRAND + 3.0,
            (float)$creditmemo->getGrandTotal(),
            0.0001
        );
    }

    /**
     * The whole share fits, so it is added at the fee's own rate.
     */
    public function testAFullVatAllowanceAddsTheWholeShare(): void
    {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);
        $invoice = new \Magento\Sales\Model\Order\Invoice();
        $invoice->setData('tax_amount', self::NATIVE_TAX + self::FEE_TAX);
        $creditmemo->setData('invoice', $invoice);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $this->assertEqualsWithDelta(self::FEE_NET, (float)$creditmemo->getTwoOtherChargesAmount(), 0.0001);
        $this->assertEqualsWithDelta(self::FEE_TAX, (float)$creditmemo->getTwoOtherChargesTaxAmount(), 0.0001);
    }

    /**
     * Whatever the allowance does, the declared VAT either follows the fee's
     * own rate exactly or nothing is added — never a rate in between.
     *
     * @dataProvider invoiceTaxProvider
     */
    public function testTheDeclaredVatAlwaysMatchesTheFeesOwnRate(
        float $invoiceTax,
        bool $expectAdded,
        string $description
    ): void {
        $order = $this->makeOrder();
        $creditmemo = $this->makeCreditmemo($order, self::ORDER_SUBTOTAL);
        $invoice = new \Magento\Sales\Model\Order\Invoice();
        $invoice->setData('tax_amount', self::NATIVE_TAX + $invoiceTax);
        $creditmemo->setData('invoice', $invoice);

        $this->makeCollector($this->residual())->collect($creditmemo);

        $net = (float)$creditmemo->getTwoOtherChargesAmount();
        $tax = (float)$creditmemo->getTwoOtherChargesTaxAmount();

        if (!$expectAdded) {
            $this->assertNull($creditmemo->getTwoOtherChargesAmount(), $description);
            return;
        }

        $this->assertGreaterThan(0, $net, $description);
        $this->assertEqualsWithDelta(
            self::FEE_TAX / self::FEE_NET,
            $tax / $net,
            0.0001,
            $description
        );
    }

    public static function invoiceTaxProvider(): array
    {
        return [
            [0.0, false, 'the invoice carries no VAT for the charge at all'],
            [self::FEE_TAX / 2, true, 'the invoice carries only half the share'],
            [self::FEE_TAX, true, 'exactly the share'],
            [self::FEE_TAX + 5.0, true, 'more allowance than the share needs'],
        ];
    }

}
