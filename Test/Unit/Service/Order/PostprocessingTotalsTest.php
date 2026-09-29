<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Service\Order;

use PHPUnit\Framework\TestCase;
use Two\Gateway\Service\Order\PostprocessingTotals;

/**
 * The opt-in helper a subscriber calls after editing lines (TWO-26092).
 */
class PostprocessingTotalsTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testTotalsFollowTheLines(array $payload, array $expected, string $description): void
    {
        $this->assertSame($expected, (new PostprocessingTotals())->recompute($payload), $description);
    }

    public static function cases(): array
    {
        $a = self::line('100.00', '21.00', '121.00', '0.210000');
        $b = self::line('23.97', '5.03', '29.00', '0.21');
        $bucket = [['taxable_amount' => '123.97', 'tax_amount' => '26.03', 'tax_rate' => '0.210000']];
        $stale = ['taxable_amount' => '1.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000'];

        return [
            [['net_amount' => '0', 'tax_amount' => '0', 'gross_amount' => '0', 'tax_subtotals' => [$stale], 'line_items' => [$a, $b]], ['net_amount' => '123.97', 'tax_amount' => '26.03', 'gross_amount' => '150.00', 'tax_subtotals' => $bucket, 'line_items' => [$a, $b]], 'order totals and one bucket for 0.21 and 0.210000'],
            [['partial' => ['gross_amount' => '0', 'net_amount' => '0', 'tax_amount' => '0', 'line_items' => [$b]]], ['partial' => ['gross_amount' => '29.00', 'net_amount' => '23.97', 'tax_amount' => '5.03', 'line_items' => [$b]]], 'a partial capture block, no subtotals key added'],
            [['amount' => '0', 'currency' => 'EUR', 'line_items' => [$a, $b], 'tax_subtotals' => [$stale]], ['amount' => '150.00', 'currency' => 'EUR', 'line_items' => [$a, $b], 'tax_subtotals' => $bucket], 'a refund amount is the line gross'],
            [['net_amount' => '0', 'tax_amount' => '0', 'gross_amount' => '0', 'tax_subtotals' => null, 'line_items' => [$b]], ['net_amount' => '23.97', 'tax_amount' => '5.03', 'gross_amount' => '29.00', 'tax_subtotals' => null, 'line_items' => [$b]], 'subtotals switched off stay off'],
            [['discount_amount' => '5.00', 'net_amount' => '9', 'line_items' => [$b]], ['discount_amount' => '5.00', 'net_amount' => '23.97', 'line_items' => [$b], 'tax_amount' => '5.03', 'gross_amount' => '29.00'], 'discount_amount is left alone'],
            [[], [], 'a body-less request is untouched'],
        ];
    }

    private static function line(string $net, string $tax, string $gross, string $rate): array
    {
        return ['net_amount' => $net, 'tax_amount' => $tax, 'gross_amount' => $gross, 'tax_rate' => $rate];
    }
}
