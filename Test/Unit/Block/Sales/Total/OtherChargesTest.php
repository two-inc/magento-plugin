<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Sales\Total;

use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Block\Sales\Total\OtherCharges;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * Without this row the merchant sees a refund grand total carrying a charge
 * they cannot identify. The label is generic because the residual is: the
 * mechanism behind it never learns whose fee it reconciled.
 */
class OtherChargesTest extends TestCase
{
    private const NET = 10.0;
    private const TAX = 2.0;

    private function makeSource(): Creditmemo
    {
        $s = new Creditmemo();
        $s->setData('two_other_charges_amount', self::NET);
        $s->setData('base_two_other_charges_amount', self::NET);
        $s->setData('two_other_charges_tax_amount', self::TAX);
        $s->setData('base_two_other_charges_tax_amount', self::TAX);

        return $s;
    }

    private function makeBlock(Creditmemo $source, \stdClass $capture, string $mode): OtherCharges
    {
        $parent = new class ($source, $capture) {
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
            public function addTotal($total, $after)
            {
                $this->cap->second = $total;
                $this->cap->after = $after;
                return $this;
            }
        };

        return new class ($parent, $this->makeDisplay($mode)) extends OtherCharges {
            private $p;
            private $d;
            public function __construct($p, $d)
            {
                $this->p = $p;
                $this->d = $d;
            }
            public function getParentBlock()
            {
                return $this->p;
            }
            protected function getSurchargeDisplay(): SurchargeDisplay
            {
                return $this->d;
            }
        };
    }

    private function makeDisplay(string $mode): SurchargeDisplay
    {
        $display = $this->createMock(SurchargeDisplay::class);
        $display->method('forSales')->willReturn($mode);
        $display->method('pick')
            ->willReturnCallback(static function (string $m, float $net, float $tax): float {
                return $m === SurchargeDisplay::EXCL ? $net : $net + $tax;
            });

        return $display;
    }

    /**
     * @dataProvider singleRowModes
     */
    public function testSingleRowModeShowsTheReconciledAmount(string $mode, float $expected): void
    {
        $capture = new \stdClass();
        $block = $this->makeBlock($this->makeSource(), $capture, $mode);

        $block->initTotals();

        $this->assertNotNull($capture->total ?? null, 'an other-charges totals row must be registered');
        $this->assertEqualsWithDelta($expected, (float)$capture->total->getValue(), 0.0001);
        $this->assertEqualsWithDelta($expected, (float)$capture->total->getData('base_value'), 0.0001);
        $this->assertSame('Other charges', (string)$capture->total->getLabel());
        $this->assertSame('two_other_charges', $capture->total->getData('code'));
        $this->assertSame('tax', $capture->before);
        $this->assertNull($capture->second ?? null, 'a single-row mode must not register a second row');
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public static function singleRowModes(): array
    {
        return [
            'excl shows net' => [SurchargeDisplay::EXCL, self::NET],
            'incl shows net plus tax' => [SurchargeDisplay::INCL, self::NET + self::TAX],
        ];
    }

    public function testBothModeRegistersPairedExclAndInclRows(): void
    {
        $capture = new \stdClass();
        $block = $this->makeBlock($this->makeSource(), $capture, SurchargeDisplay::BOTH);

        $block->initTotals();

        $this->assertSame('two_other_charges_excl', $capture->total->getData('code'));
        $this->assertEqualsWithDelta(self::NET, (float)$capture->total->getValue(), 0.0001);
        $this->assertSame('Other charges (Excl. Tax)', (string)$capture->total->getLabel());
        $this->assertSame('tax', $capture->before);

        $this->assertSame('two_other_charges_incl', $capture->second->getData('code'));
        $this->assertEqualsWithDelta(self::NET + self::TAX, (float)$capture->second->getValue(), 0.0001);
        $this->assertSame('Other charges (Incl. Tax)', (string)$capture->second->getLabel());
        $this->assertSame('two_other_charges_excl', $capture->after);
    }

    public function testNoRowWhenNothingWasReconciled(): void
    {
        $capture = new \stdClass();
        $block = $this->makeBlock(new Creditmemo(), $capture, SurchargeDisplay::EXCL);

        $block->initTotals();

        $this->assertNull($capture->total ?? null);
    }

    /**
     * The surcharge has its own collector, its own columns and its own row.
     * This row must never restate it.
     */
    public function testTheSurchargeIsNotRenderedByThisBlock(): void
    {
        $source = new Creditmemo();
        $source->setData('two_surcharge_amount', 58.09);
        $source->setData('two_surcharge_tax_amount', 12.49);

        $capture = new \stdClass();
        $block = $this->makeBlock($source, $capture, SurchargeDisplay::EXCL);

        $block->initTotals();

        $this->assertNull($capture->total ?? null);
    }
}
