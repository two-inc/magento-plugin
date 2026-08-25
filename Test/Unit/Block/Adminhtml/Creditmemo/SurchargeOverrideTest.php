<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Block\Adminhtml\Creditmemo;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Block\Adminhtml\Creditmemo\SurchargeOverride;

/**
 * The editable surcharge override row on the credit-memo create form must sit
 * directly above the Tax line — same ordering as the read-only row elsewhere
 * (follow-up to the surcharge-VAT double-count bug). The block removes the static row and re-adds an
 * editable placeholder; that re-add must anchor before `tax`, not
 * `grand_total`.
 */
class SurchargeOverrideTest extends TestCase
{
    public function testEditableRowIsInsertedAboveTax(): void
    {
        $capture = new \stdClass();
        $parent = new class($capture) {
            private $cap;
            public function __construct($cap)
            {
                $this->cap = $cap;
            }
            public function removeTotal($code)
            {
                $this->cap->removed = $code;
                return $this;
            }
            public function addTotalBefore($total, $before)
            {
                $this->cap->total = $total;
                $this->cap->before = $before;
                return $this;
            }
        };

        $block = new class($parent) extends SurchargeOverride {
            private $p;
            public function __construct($p)
            {
                $this->p = $p;
            }
            public function getParentBlock()
            {
                return $this->p;
            }
            public function shouldDisplay(): bool
            {
                return true;
            }
        };

        $block->initTotals();

        $this->assertSame('two_surcharge', $capture->removed ?? null, 'static row must be removed first');
        $this->assertSame('two_surcharge_override', $capture->total->getData('block_name'));
        $this->assertSame(
            'tax',
            $capture->before ?? null,
            'editable surcharge override row must be inserted above the Tax line'
        );
    }

    public function testFormatsDefaultRefundInAdminLocale(): void
    {
        $nl = new class extends SurchargeOverride {
            public function __construct()
            {
            }
            public function getDefaultRefund(): float
            {
                return 2.5;
            }
            protected function localeDecimalSymbol(): string
            {
                return ',';
            }
        };
        $this->assertSame(
            '2,50',
            $nl->getFormattedDefaultRefund(),
            'nl_NL must render 2dp with a comma decimal separator (not the raw "2.5")'
        );

        $en = new class extends SurchargeOverride {
            public function __construct()
            {
            }
            public function getDefaultRefund(): float
            {
                return 2.5;
            }
            protected function localeDecimalSymbol(): string
            {
                return '.';
            }
        };
        $this->assertSame('2.50', $en->getFormattedDefaultRefund());
    }

    private function blockWith($creditmemo, $order): SurchargeOverride
    {
        return new class($creditmemo, $order) extends SurchargeOverride {
            private $cm;
            private $ord;
            public function __construct($cm, $ord)
            {
                $this->cm = $cm;
                $this->ord = $ord;
            }
            public function getCreditmemo()
            {
                return $this->cm;
            }
            public function getOrder()
            {
                return $this->ord;
            }
        };
    }

    public function testGetDefaultRefundHonoursExplicitZero(): void
    {
        $order = new \Magento\Sales\Model\Order();
        $order->setData('two_surcharge_amount', 100.0);
        $order->setData('two_surcharge_refunded', 0.0);
        $order->setData('subtotal', 1000.0);

        $cm = new \Magento\Sales\Model\Order\Creditmemo();
        $cm->setData('subtotal', 1000.0);
        $cm->setData('two_surcharge_amount', 0.0); // collector resolved an explicit 0

        $this->assertSame(
            0.0,
            $this->blockWith($cm, $order)->getDefaultRefund(),
            'an explicit 0 must not snap back to the full proportional default'
        );
    }

    public function testGetDefaultRefundUsesProportionalDefaultWhenNotCollected(): void
    {
        $order = new \Magento\Sales\Model\Order();
        $order->setData('two_surcharge_amount', 100.0);
        $order->setData('two_surcharge_refunded', 0.0);
        $order->setData('subtotal', 1000.0);

        $cm = new \Magento\Sales\Model\Order\Creditmemo();
        $cm->setData('subtotal', 1000.0); // two_surcharge_amount never set → not collected

        $this->assertSame(
            100.0,
            $this->blockWith($cm, $order)->getDefaultRefund(),
            'with no collected value, fall back to the proportional default'
        );
    }

    /**
     * getLabel()'s no-order-description fallback must read the active
     * brand's product name rather than hardcoding "Two" — a partner brand
     * overlay must see its own name on this row, not Two's
     * (TWO-25386 follow-up).
     *
     * @dataProvider brandProductNames
     */
    public function testGetLabelFallsBackToBrandProductNameWhenOrderHasNoDescription(string $productName): void
    {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn($productName);

        $order = new \Magento\Sales\Model\Order();
        $order->setData('two_surcharge_description', ''); // no per-order description set

        $block = new class($order, $brandRegistry) extends SurchargeOverride {
            private $ord;
            private $brand;
            public function __construct($ord, $brand)
            {
                $this->ord = $ord;
                $this->brand = $brand;
            }
            public function getOrder()
            {
                return $this->ord;
            }
            protected function getBrandRegistry(): BrandRegistryInterface
            {
                return $this->brand;
            }
        };

        $this->assertSame('Refund ' . $productName . ' surcharge', $block->getLabel());
    }

    /**
     * getLabel() must still prefer the per-order description when one is
     * present, regardless of brand — the brand product name is only a
     * fallback.
     */
    public function testGetLabelPrefersOrderDescriptionOverBrandFallback(): void
    {
        $brandRegistry = $this->createMock(BrandRegistryInterface::class);
        $brandRegistry->method('getProductName')->willReturn('Should not be used');

        $order = new \Magento\Sales\Model\Order();
        $order->setData('two_surcharge_description', 'Partner Product - 30 dagen');

        $block = new class($order, $brandRegistry) extends SurchargeOverride {
            private $ord;
            private $brand;
            public function __construct($ord, $brand)
            {
                $this->ord = $ord;
                $this->brand = $brand;
            }
            public function getOrder()
            {
                return $this->ord;
            }
            protected function getBrandRegistry(): BrandRegistryInterface
            {
                return $this->brand;
            }
        };

        $this->assertSame('Refund Partner Product - 30 dagen', $block->getLabel());
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
