<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model\Pdf\Total;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Model\Pdf\Total\Surcharge;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * The invoice/credit-memo PDF surcharge row follows `tax/sales_display/price`:
 * a store showing gross prices must show a gross surcharge, and "Both" gets
 * the paired excl/incl lines core uses for Subtotal and Shipping.
 */
class SurchargeTest extends TestCase
{
    private const NET = 23.99;
    private const TAX = 5.16;

    private function makeBlock(string $mode): Surcharge
    {
        $source = new Order();
        $source->setData('two_surcharge_amount', self::NET);
        $source->setData('two_surcharge_tax_amount', self::TAX);
        $source->setData('two_surcharge_description', 'Business Invoice - 60 days');

        $order = new class {
            public function formatPriceTxt($v)
            {
                return number_format((float)$v, 2, '.', '');
            }
            public function getStore()
            {
                return null;
            }
        };

        $display = $this->createMock(SurchargeDisplay::class);
        $display->method('forSales')->willReturn($mode);
        $display->method('pick')
            ->willReturnCallback(static function (string $m, float $net, float $tax): float {
                return $m === SurchargeDisplay::EXCL ? $net : $net + $tax;
            });

        return new class($source, $order, $display) extends Surcharge {
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
    public function testSingleRowModeShowsTheConfiguredAmount(string $mode, string $expected): void
    {
        $rows = $this->makeBlock($mode)->getTotalsForDisplay();

        $this->assertCount(1, $rows);
        $this->assertSame($expected, $rows[0]['amount']);
        $this->assertSame('Business Invoice - 60 days:', $rows[0]['label']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function singleRowModes(): array
    {
        return [
            'excl shows net' => [SurchargeDisplay::EXCL, '23.99'],
            'incl shows net plus tax' => [SurchargeDisplay::INCL, '29.15'],
        ];
    }

    public function testBothModeShowsPairedExclAndInclLines(): void
    {
        $rows = $this->makeBlock(SurchargeDisplay::BOTH)->getTotalsForDisplay();

        $this->assertCount(2, $rows);
        $this->assertSame('23.99', $rows[0]['amount']);
        $this->assertSame('Business Invoice - 60 days (Excl. Tax):', $rows[0]['label']);
        $this->assertSame('29.15', $rows[1]['amount']);
        $this->assertSame('Business Invoice - 60 days (Incl. Tax):', $rows[1]['label']);
    }

    public function testNoSurchargeSkipsTheRowEntirely(): void
    {
        $source = new Order();
        $order = new class {
            public function formatPriceTxt($v)
            {
                return (string)$v;
            }
            public function getStore()
            {
                return null;
            }
        };
        $display = $this->createMock(SurchargeDisplay::class);

        $block = new class($source, $order, $display) extends Surcharge {
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
        };

        $this->assertSame([], $block->getTotalsForDisplay());
    }
}
