<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Sales\Total;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Block\Sales\Total\Surcharge;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * The order/invoice/creditmemo totals row for the surcharge follows
 * `tax/sales_display/price`: a store showing gross prices must show a gross
 * surcharge, and "Both" gets the paired excl/incl rows core uses for Subtotal
 * and Shipping.
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
        $s->setData('two_surcharge_description', 'Business Invoice - 60 days');
        return $s;
    }

    /**
     * Build the block with its framework collaborators stubbed: a parent
     * totals block exposing the source and capturing the registered row.
     */
    private function makeBlock(Order $source, \stdClass $capture, string $mode): Surcharge
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
            public function addTotal($total, $after)
            {
                $this->cap->second = $total;
                $this->cap->after = $after;
                return $this;
            }
        };

        return new class($parent, $this->makeDisplay($mode)) extends Surcharge {
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
    public function testSingleRowModeShowsTheConfiguredAmount(string $mode, float $expected): void
    {
        $capture = new \stdClass();
        $block = $this->makeBlock($this->makeSource(), $capture, $mode);

        $block->initTotals();

        $this->assertNotNull($capture->total ?? null, 'a surcharge totals row must be registered');
        $this->assertEqualsWithDelta($expected, (float)$capture->total->getValue(), 0.0001);
        $this->assertEqualsWithDelta($expected, (float)$capture->total->getData('base_value'), 0.0001);
        $this->assertSame('Business Invoice - 60 days', $capture->total->getLabel());
        $this->assertSame('two_surcharge', $capture->total->getData('code'));
        $this->assertSame(
            'tax',
            $capture->before,
            'surcharge row must sit directly above the Tax line (it contributes to the tax base)'
        );
        $this->assertNull($capture->second ?? null, 'a single-row mode must not register a second row');
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public function singleRowModes(): array
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

        $this->assertSame('two_surcharge_excl', $capture->total->getData('code'));
        $this->assertEqualsWithDelta(self::NET, (float)$capture->total->getValue(), 0.0001);
        $this->assertSame('Business Invoice - 60 days (Excl. Tax)', (string)$capture->total->getLabel());
        $this->assertSame('tax', $capture->before);

        $this->assertSame('two_surcharge_incl', $capture->second->getData('code'));
        $this->assertEqualsWithDelta(self::NET + self::TAX, (float)$capture->second->getValue(), 0.0001);
        $this->assertEqualsWithDelta(
            self::NET + self::TAX,
            (float)$capture->second->getData('base_value'),
            0.0001
        );
        $this->assertSame('Business Invoice - 60 days (Incl. Tax)', (string)$capture->second->getLabel());
        $this->assertSame(
            'two_surcharge_excl',
            $capture->after,
            'the incl row must follow the excl row, matching core Subtotal ordering'
        );
    }

    /**
     * When the order/invoice/creditmemo has no per-order surcharge
     * description, the row label must fall back to the active brand's
     * product name, not a hardcoded "Two" — a partner brand overlay must
     * see its own name on this totals row (TWO-25386 follow-up).
     *
     * @dataProvider brandProductNames
     */
    public function testLabelFallsBackToBrandProductNameWhenSourceHasNoDescription(string $productName): void
    {
        $source = new Order();
        $source->setData('two_surcharge_amount', 23.99);
        $source->setData('base_two_surcharge_amount', 23.99);
        // Deliberately no 'two_surcharge_description' set.

        $capture = new \stdClass();
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

        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn($productName);

        $block = new class($parent, $brandRegistry, $this->makeDisplay(SurchargeDisplay::EXCL)) extends Surcharge {
            private $p;
            private $brand;
            private $d;
            public function __construct($p, $brand, $d)
            {
                $this->p = $p;
                $this->brand = $brand;
                $this->d = $d;
            }
            public function getParentBlock()
            {
                return $this->p;
            }
            protected function getBrandRegistry(): BrandRegistryInterface
            {
                return $this->brand;
            }
            protected function getSurchargeDisplay(): SurchargeDisplay
            {
                return $this->d;
            }
        };

        $block->initTotals();

        $this->assertSame($productName . ' surcharge', $capture->total->getLabel());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function brandProductNames(): array
    {
        return [
            'base brand' => ['Two'],
            'hypothetical overlay brand' => ['Acme Corp'],
        ];
    }
}
