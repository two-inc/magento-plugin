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
    public function testTotalsFollowTheLines(array $payload, array $before, array $expected, string $description): void
    {
        $this->assertSame($expected, (new PostprocessingTotals())->recompute($payload, $before), $description);
    }

    public static function cases(): array
    {
        $a = self::line('100.00', '21.00', '121.00', '0.210000');
        $b = self::line('23.97', '5.03', '29.00', '0.21');
        $bucket = [['taxable_amount' => '123.97', 'tax_amount' => '26.03', 'tax_rate' => '0.210000']];
        $stale = ['taxable_amount' => '1.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000'];
        $untaxed = self::line('29.00', '0.00', '29.00', '0.000000');
        $credited = ['net_amount' => '109.00', 'tax_amount' => '21.00', 'gross_amount' => '130.00', 'line_items' => [$a, $untaxed]];
        $composedBuckets = [
            ['taxable_amount' => '100.00', 'tax_amount' => '21.00', 'tax_rate' => '0.210000'],
            ['taxable_amount' => '29.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000'],
        ];
        $giftCard = ['net_amount' => '109.00', 'tax_amount' => '21.00', 'gross_amount' => '130.00', 'tax_subtotals' => $composedBuckets, 'line_items' => [$a, $untaxed]];
        // 10.00 net at 21% the shop declared outside its lines.
        $rateResidual = ['net_amount' => '119.00', 'tax_amount' => '18.90', 'gross_amount' => '137.90', 'tax_subtotals' => [['taxable_amount' => '90.00', 'tax_amount' => '18.90', 'tax_rate' => '0.210000'], $composedBuckets[1]], 'line_items' => [$a, $untaxed]];
        $composed = ['net_amount' => '129.00', 'tax_amount' => '21.00', 'gross_amount' => '150.00', 'line_items' => [$a, $untaxed]];
        $refund = ['amount' => '140.00', 'currency' => 'EUR', 'line_items' => [$a, $untaxed], 'tax_subtotals' => $composedBuckets];

        return [
            [['net_amount' => '0', 'tax_amount' => '0', 'gross_amount' => '0', 'tax_subtotals' => [$stale], 'line_items' => [$a, $b]], [], ['net_amount' => '123.97', 'tax_amount' => '26.03', 'gross_amount' => '150.00', 'tax_subtotals' => $bucket, 'line_items' => [$a, $b]], 'order totals and one bucket for 0.21 and 0.210000'],
            [['partial' => ['gross_amount' => '0', 'net_amount' => '0', 'tax_amount' => '0', 'line_items' => [$b]]], [], ['partial' => ['gross_amount' => '29.00', 'net_amount' => '23.97', 'tax_amount' => '5.03', 'line_items' => [$b]]], 'a partial capture block, no subtotals key added'],
            [['amount' => '0', 'currency' => 'EUR', 'line_items' => [$a, $b], 'tax_subtotals' => [$stale]], [], ['amount' => '150.00', 'currency' => 'EUR', 'line_items' => [$a, $b], 'tax_subtotals' => $bucket], 'a refund amount moves by the line gross'],
            [['net_amount' => '0', 'tax_amount' => '0', 'gross_amount' => '0', 'tax_subtotals' => null, 'line_items' => [$b]], [], ['net_amount' => '23.97', 'tax_amount' => '5.03', 'gross_amount' => '29.00', 'tax_subtotals' => null, 'line_items' => [$b]], 'subtotals switched off stay off'],
            [['discount_amount' => '5.00', 'net_amount' => '0', 'line_items' => [$b]], [], ['discount_amount' => '5.00', 'net_amount' => '23.97', 'line_items' => [$b], 'tax_amount' => '5.03', 'gross_amount' => '29.00'], 'discount_amount is left alone'],
            [['line_items' => [$a, $b]] + $credited, $credited, ['line_items' => [$a, $b], 'net_amount' => '103.97', 'tax_amount' => '26.03', 'gross_amount' => '130.00'], 'a re-split keeps the 20.00 of store credit the totals carried'],
            [['amount' => '130.00', 'line_items' => [$a, $b]], ['amount' => '130.00', 'line_items' => [$a, $untaxed]], ['amount' => '130.00', 'line_items' => [$a, $b]], 'so does a refund amount'],
            [['line_items' => [$a, $b]] + $giftCard, $giftCard, ['line_items' => [$a, $b], 'net_amount' => '103.97', 'tax_amount' => '26.03', 'gross_amount' => '130.00', 'tax_subtotals' => $bucket], 'a gift-card order with a re-split keeps the 20.00 of gift card'],
            [['line_items' => [$a, $b]] + $rateResidual, $rateResidual, ['line_items' => [$a, $b], 'net_amount' => '113.97', 'tax_amount' => '23.93', 'gross_amount' => '137.90', 'tax_subtotals' => [['taxable_amount' => '113.97', 'tax_amount' => '23.93', 'tax_rate' => '0.210000']]], 'a per-rate residual survives in its bucket'],
            [['net_amount' => '999.00', 'tax_amount' => '0.00', 'gross_amount' => '999.00', 'line_items' => [$a, $b]], $composed, ['net_amount' => '123.97', 'tax_amount' => '26.03', 'gross_amount' => '150.00', 'line_items' => [$a, $b]], 'a hand-edited total is replaced, not moved by the line change'],
            [['line_items' => [$a, $b]] + $refund, $refund, ['line_items' => [$a, $b], 'amount' => '140.00', 'currency' => 'EUR', 'tax_subtotals' => $bucket], 'a refund with a re-split line keeps its positive amount and 10.00 residual'],
            [[], [], [], 'a body-less request is untouched'],
        ];
    }

    private static function line(string $net, string $tax, string $gross, string $rate): array
    {
        return ['net_amount' => $net, 'tax_amount' => $tax, 'gross_amount' => $gross, 'tax_rate' => $rate];
    }
}
