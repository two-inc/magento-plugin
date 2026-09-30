<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Two\Gateway\Api\OrderPostprocessingTotalsInterface;

/**
 * Moves totals by the change in a payload's lines, and rebuilds its tax
 * subtotals from them (TWO-26092).
 */
class PostprocessingTotals implements OrderPostprocessingTotalsInterface
{
    /**
     * @inheritDoc
     */
    public function recompute(array $payload, array $before): array
    {
        foreach (self::lineBlockKeys($payload) as $key) {
            $original = $key === '' ? $before : $before[$key] ?? [];
            $original = is_array($original['line_items'] ?? null) ? $original['line_items'] : [];
            if ($key === '') {
                $payload = $this->recomputeBlock($payload, $original);
            } else {
                $payload[$key] = $this->recomputeBlock($payload[$key], $original);
            }
        }

        return $payload;
    }

    /**
     * Where a payload carries lines: '' for the top level, 'partial' for a
     * partial capture's block.
     *
     * @param array $payload
     * @return string[]
     */
    public static function lineBlockKeys(array $payload): array
    {
        $keys = [];
        if (isset($payload['line_items']) && is_array($payload['line_items'])) {
            $keys[] = '';
        }
        if (isset($payload['partial']['line_items']) && is_array($payload['partial']['line_items'])) {
            $keys[] = 'partial';
        }

        return $keys;
    }

    /**
     * Lines grouped by rate, each with its summed net and tax, keyed by the
     * rate at 6dp so '0.21' and '0.210000' share one bucket.
     *
     * @param array $lines
     * @return array<string, array{taxable_amount: float, tax_amount: float}>
     */
    public static function sumByRate(array $lines): array
    {
        $buckets = [];
        foreach ($lines as $line) {
            $rate = self::amount($line['tax_rate'] ?? 0, 6);
            $buckets[$rate]['taxable_amount'] = ($buckets[$rate]['taxable_amount'] ?? 0.0)
                + (float)($line['net_amount'] ?? 0);
            $buckets[$rate]['tax_amount'] = ($buckets[$rate]['tax_amount'] ?? 0.0)
                + (float)($line['tax_amount'] ?? 0);
        }

        return $buckets;
    }

    /**
     * @param array $lines
     * @return array{net_amount: float, tax_amount: float, gross_amount: float}
     */
    private static function sums(array $lines): array
    {
        $sums = ['net_amount' => 0.0, 'tax_amount' => 0.0, 'gross_amount' => 0.0];
        foreach ($lines as $line) {
            foreach (array_keys($sums) as $field) {
                $sums[$field] += (float)($line[$field] ?? 0);
            }
        }

        return $sums;
    }

    /**
     * @param mixed $value
     * @param int $dp
     * @return string
     */
    public static function amount($value, int $dp = 2): string
    {
        return number_format((float)$value, $dp, '.', '');
    }

    /**
     * @param array $block
     * @param array $originalLines
     * @return array
     */
    private function recomputeBlock(array $block, array $originalLines): array
    {
        $change = self::sums($block['line_items']);
        foreach (self::sums($originalLines) as $field => $sum) {
            $change[$field] -= $sum;
        }

        if (array_key_exists('amount', $block)) {
            $block['amount'] = self::amount((float)$block['amount'] + $change['gross_amount']);
        } else {
            foreach ($change as $field => $delta) {
                $block[$field] = self::amount((float)($block[$field] ?? 0) + $delta);
            }
        }

        if (isset($block['tax_subtotals'])) {
            $subtotals = [];
            foreach (self::sumByRate($block['line_items']) as $rate => $sums) {
                $subtotals[] = [
                    'taxable_amount' => self::amount($sums['taxable_amount']),
                    'tax_amount' => self::amount($sums['tax_amount']),
                    'tax_rate' => (string)$rate,
                ];
            }
            $block['tax_subtotals'] = $subtotals;
        }

        return $block;
    }
}
