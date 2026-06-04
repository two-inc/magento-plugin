<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Sales\Total;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Sales\Total\Surcharge;

/**
 * The order/invoice/creditmemo totals row for the surcharge must display the
 * NET surcharge — its VAT belongs in the Tax line (as on checkout). Showing
 * the gross value double-presents the VAT and stops the totals rows summing to
 * the grand total. (ABN-443 follow-up.)
 */
class SurchargeTest extends TestCase
{
    private const NET = 23.99;
    private const TAX = 5.16;

    private function makeSource(): Order
    {
        $s = new Order();
        $s->setData('two_surcharge_amount', self::NET);
        $s->setData('base_two_surcharge_amount', self::NET);
        $s->setData('two_surcharge_tax_amount', self::TAX);
        $s->setData('base_two_surcharge_tax_amount', self::TAX);
        $s->setData('two_surcharge_description', 'Zakelijk op Rekening - 60 dagen');
        return $s;
    }

    /**
     * Build the block with its framework collaborators stubbed: a parent
     * totals block exposing the source and capturing the registered row.
     */
    private function makeBlock(Order $source, \stdClass $capture): Surcharge
    {
        $parent = new class($source, $capture) {
            private $src;
            private $cap;
            public function __construct($src, $cap)
            {
                $this->src = $src;
                $this->cap = $cap;
            }
            public function getSource()
            {
                return $this->src;
            }
            public function addTotalBefore($total, $before)
            {
                $this->cap->total = $total;
                $this->cap->before = $before;
                return $this;
            }
        };

        return new class($parent) extends Surcharge {
            private $p;
            public function __construct($p)
            {
                $this->p = $p;
            }
            public function getParentBlock()
            {
                return $this->p;
            }
        };
    }

    public function testDisplaysSurchargeNetNotGross(): void
    {
        $capture = new \stdClass();
        $block = $this->makeBlock($this->makeSource(), $capture);

        $block->initTotals();

        $this->assertNotNull($capture->total ?? null, 'a surcharge totals row must be registered');
        $this->assertEqualsWithDelta(
            self::NET,
            (float)$capture->total->getValue(),
            0.0001,
            'surcharge row must show NET; its VAT belongs in the Tax line (matches checkout)'
        );
        $this->assertEqualsWithDelta(
            self::NET,
            (float)$capture->total->getData('base_value'),
            0.0001,
            'base_value must also be NET'
        );
        $this->assertSame('Zakelijk op Rekening - 60 dagen', $capture->total->getLabel());
        $this->assertSame(
            'tax',
            $capture->before,
            'surcharge row must sit directly above the Tax line (it contributes to the tax base)'
        );
    }
}
