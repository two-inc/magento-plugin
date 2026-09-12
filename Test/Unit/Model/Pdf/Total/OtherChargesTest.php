<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Pdf\Total;

use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Pdf\Total\OtherCharges;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * The PDF is the credit note a merchant actually sends out, so its rows have
 * to sum to its own grand total. The label names no source because the
 * collector behind it never learns one.
 */
class OtherChargesTest extends TestCase
{
    private const NET = 10.0;
    private const TAX = 2.0;

    private function makeOrderStub(): object
    {
        return new class {
            public function formatPriceTxt($v)
            {
                return number_format((float)$v, 2, '.', '');
            }

            public function getStore()
            {
                return null;
            }
        };
    }

    private function makeBlock(string $mode, bool $withCharge = true): OtherCharges
    {
        $source = new Creditmemo();
        if ($withCharge) {
            $source->setData('two_other_charges_amount', self::NET);
            $source->setData('two_other_charges_tax_amount', self::TAX);
        }

        $display = $this->createMock(SurchargeDisplay::class);
        $display->method('forSales')->willReturn($mode);
        $display->method('pick')
            ->willReturnCallback(static function (string $m, float $net, float $tax): float {
                return $m === SurchargeDisplay::EXCL ? $net : $net + $tax;
            });

        return new class ($source, $this->makeOrderStub(), $display) extends OtherCharges {
            private $s;
            private $o;
            private $d;

            public function __construct($s, $o, $display)
            {
                $this->s = $s;
                $this->o = $o;
                $this->d = $display;
            }

            protected function getSurchargeDisplay(): SurchargeDisplay
            {
                return $this->d;
            }

            public function getSource()
            {
                return $this->s;
            }

            public function getOrder()
            {
                return $this->o;
            }

            public function getAmountPrefix()
            {
                return '';
            }

            public function getFontSize()
            {
                return 7;
            }
        };
    }

    /**
     * @dataProvider singleRowModes
     */
    public function testSingleRowModeShowsTheReconciledAmount(string $mode, string $expected): void
    {
        $rows = $this->makeBlock($mode)->getTotalsForDisplay();

        $this->assertCount(1, $rows);
        $this->assertSame($expected, $rows[0]['amount']);
        $this->assertSame('Other charges:', $rows[0]['label']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function singleRowModes(): array
    {
        return [
            'excl shows net' => [SurchargeDisplay::EXCL, '10.00'],
            'incl shows net plus tax' => [SurchargeDisplay::INCL, '12.00'],
        ];
    }

    public function testBothModeShowsPairedExclAndInclLines(): void
    {
        $rows = $this->makeBlock(SurchargeDisplay::BOTH)->getTotalsForDisplay();

        $this->assertCount(2, $rows);
        $this->assertSame('10.00', $rows[0]['amount']);
        $this->assertSame('Other charges (Excl. Tax):', $rows[0]['label']);
        $this->assertSame('12.00', $rows[1]['amount']);
        $this->assertSame('Other charges (Incl. Tax):', $rows[1]['label']);
    }

    public function testAnOrdinaryDocumentPrintsNoRow(): void
    {
        $this->assertSame([], $this->makeBlock(SurchargeDisplay::EXCL, false)->getTotalsForDisplay());
    }
}
