<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order\SurchargeDisplay;

/**
 * The surcharge is presented net or gross according to the store's own
 * "Display Prices" switch — the buyer sees gross the moment the merchant
 * turns gross prices on, and never a mixture.
 */
class SurchargeDisplayTest extends TestCase
{
    /**
     * @dataProvider modes
     */
    public function testCartAndSalesTiersEachFollowTheirOwnConfig(
        bool $both,
        bool $incl,
        string $expected
    ): void {
        $taxConfig = $this->createMock(TaxConfig::class);
        $taxConfig->method('displayCartPricesBoth')->willReturn($both);
        $taxConfig->method('displayCartPricesInclTax')->willReturn($incl);
        $taxConfig->method('displaySalesPricesBoth')->willReturn($both);
        $taxConfig->method('displaySalesPricesInclTax')->willReturn($incl);

        $display = new SurchargeDisplay($taxConfig);

        $this->assertSame($expected, $display->forCart());
        $this->assertSame($expected, $display->forSales());
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: string}>
     */
    public function modes(): array
    {
        return [
            'excluding tax' => [false, false, SurchargeDisplay::EXCL],
            'including tax' => [false, true, SurchargeDisplay::INCL],
            'both, which wins over incl' => [true, true, SurchargeDisplay::BOTH],
        ];
    }

    /**
     * @dataProvider picks
     */
    public function testPickReturnsGrossForEveryModeButExcl(string $mode, float $expected): void
    {
        $display = new SurchargeDisplay($this->createMock(TaxConfig::class));

        $this->assertEqualsWithDelta($expected, $display->pick($mode, 100.0, 21.0), 1e-9);
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public function picks(): array
    {
        return [
            'excl' => [SurchargeDisplay::EXCL, 100.0],
            'incl' => [SurchargeDisplay::INCL, 121.0],
            'both falls back to gross for one-value callers' => [SurchargeDisplay::BOTH, 121.0],
        ];
    }
}
