<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\Creditmemo;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Adminhtml\Creditmemo\OtherChargesOverride;

/**
 * The editable charge row on the credit-memo create form replaces the
 * read-only one and sits directly above the Tax line, where the charge is part
 * of the tax base. Its own gate is the order's DERIVED residual: no order
 * column records a third-party fee.
 */
class OtherChargesOverrideTest extends TestCase
{
    private const CHARGED = 10.0;

    private function parent(\stdClass $capture)
    {
        return new class ($capture) {
            private $cap;
            public function __construct($cap)
            {
                $this->cap = $cap;
                $this->cap->removed = [];
            }
            public function removeTotal($code)
            {
                $this->cap->removed[] = $code;
                return $this;
            }
            public function addTotalBefore($total, $before)
            {
                $this->cap->total = $total;
                $this->cap->before = $before;
                return $this;
            }
        };
    }

    /**
     * @param float $charged The residual the resolver derives, net.
     * @param float $priorRefunded What earlier memos took of it.
     */
    private function block(
        ?Creditmemo $creditmemo,
        float $charged = self::CHARGED,
        float $priorRefunded = 0.0,
        $parent = null
    ): OtherChargesOverride {
        return new class ($creditmemo, $charged, $priorRefunded, $parent) extends OtherChargesOverride {
            private $cm;
            private $charged;
            private $priorRefunded;
            private $p;

            public function __construct($cm, $charged, $priorRefunded, $p)
            {
                $this->cm = $cm;
                $this->charged = $charged;
                $this->priorRefunded = $priorRefunded;
                $this->p = $p;
            }

            public function getCreditmemo()
            {
                return $this->cm;
            }

            public function getParentBlock()
            {
                return $this->p;
            }

            protected function chargedNet(): float
            {
                return $this->charged;
            }

            protected function priorRefunded(): float
            {
                return $this->priorRefunded;
            }

            protected function localeDecimalSymbol(): string
            {
                return ',';
            }
        };
    }

    private function creditmemo(float $cmSubtotal, float $orderSubtotal = 200.0): Creditmemo
    {
        $order = new Order();
        $order->setData('subtotal', $orderSubtotal);

        $creditmemo = new Creditmemo();
        $creditmemo->setOrder($order);
        $creditmemo->setData('subtotal', $cmSubtotal);

        return $creditmemo;
    }

    public function testTheEditableRowReplacesTheReadOnlyOnesAboveTax(): void
    {
        $capture = new \stdClass();
        $block = $this->block($this->creditmemo(200.0), self::CHARGED, 0.0, $this->parent($capture));

        $block->initTotals();

        $this->assertSame(
            ['two_other_charges', 'two_other_charges_excl', 'two_other_charges_incl'],
            $capture->removed,
            'every read-only row the display modes emit must be removed first'
        );
        $this->assertSame('two_other_charges_override', $capture->total->getData('block_name'));
        $this->assertSame(
            'tax',
            $capture->before ?? null,
            'the editable charge row must be inserted above the Tax line'
        );
    }

    /**
     * The row is offered on the strength of the derived residual, since no
     * order column records the charge.
     *
     * @dataProvider displayProvider
     */
    public function testTheRowIsOfferedOnlyWhenTheOrderCarriesACharge(
        ?Creditmemo $creditmemo,
        float $charged,
        bool $expected,
        string $description
    ): void {
        $this->assertSame($expected, $this->block($creditmemo, $charged)->shouldDisplay(), $description);
    }

    public static function displayProvider(): array
    {
        $order = new Order();
        $order->setData('subtotal', 200.0);
        $withOrder = new Creditmemo();
        $withOrder->setOrder($order);
        $withOrder->setData('subtotal', 200.0);

        $noOrder = new Creditmemo();

        return [
            [$withOrder, self::CHARGED, true, 'the order carries a residual'],
            [$withOrder, 0.0, false, 'nothing unitemized on the order'],
            [$noOrder, self::CHARGED, false, 'a creditmemo with no order behind it'],
            [null, self::CHARGED, false, 'no creditmemo in the registry'],
        ];
    }

    /**
     * @dataProvider defaultProvider
     */
    public function testThePrefillFollowsWhatTheCollectorResolved(
        float $cmSubtotal,
        $stamped,
        float $priorRefunded,
        float $expected,
        string $description
    ): void {
        $creditmemo = $this->creditmemo($cmSubtotal);
        if ($stamped !== 'absent') {
            $creditmemo->setData('two_other_charges_amount', $stamped);
        }

        $block = $this->block($creditmemo, self::CHARGED, $priorRefunded);

        $this->assertEqualsWithDelta($expected, $block->getDefaultRefund(), 0.0001, $description);
    }

    public static function defaultProvider(): array
    {
        return [
            [
                200.0,
                'absent',
                0.0,
                0.0,
                'the collector granted nothing, so the field must not prefill an amount it refused',
            ],
            [
                100.0,
                7.25,
                0.0,
                7.25,
                'the collector resolved the merchant\'s override, which the field must show back',
            ],
            [
                200.0,
                0.0,
                0.0,
                0.0,
                'an explicit zero must not snap back to the full default',
            ],
            [200.0, 99.0, 0.0, self::CHARGED, 'a resolved value above the cap is shown at the cap'],
            [200.0, 9.0, 6.0, 4.0, 'the prefill cannot exceed what the charge has left'],
        ];
    }

    public function testThePrefillIsRenderedInTheAdminLocale(): void
    {
        $creditmemo = $this->creditmemo(100.0);
        $creditmemo->setData('two_other_charges_amount', 2.5);

        $this->assertSame(
            '2,50',
            $this->block($creditmemo)->getFormattedDefaultRefund(),
            'nl_NL must render 2dp with a comma decimal separator, not the raw "2.5"'
        );
    }
}
