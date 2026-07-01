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

/**
 * The invoice/credit-memo PDF surcharge row must show the NET surcharge —
 * its VAT belongs in the Tax line, matching the on-screen totals and the
 * grand total. (ABN-443 follow-up.)
 */
class SurchargeTest extends TestCase
{
    public function testPdfShowsNetSurchargeNotGross(): void
    {
        $source = new Order();
        $source->setData('two_surcharge_amount', 23.99);
        $source->setData('two_surcharge_tax_amount', 5.16);
        $source->setData('two_surcharge_description', 'Zakelijk op Rekening - 60 dagen');

        $orderFmt = new class {
            public function formatPriceTxt($v)
            {
                return number_format((float)$v, 2, '.', '');
            }
        };

        $block = new class($source, $orderFmt) extends Surcharge {
            private $s;
            private $o;
            public function __construct($s, $o)
            {
                $this->s = $s;
                $this->o = $o;
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

        $rows = $block->getTotalsForDisplay();

        $this->assertCount(1, $rows);
        $this->assertSame(
            '23.99',
            $rows[0]['amount'],
            'PDF surcharge row must show NET (23.99), not gross (29.15)'
        );
        $this->assertSame('Zakelijk op Rekening - 60 dagen:', $rows[0]['label']);
    }
}
