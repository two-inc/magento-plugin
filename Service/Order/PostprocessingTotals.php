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
            $original = is_array($original) ? $original : [];
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
     * Each total, and each per-rate subtotal, is the sum over the block's lines
     * plus the residual it carried over its lines in the block as received.
     *
     * @param array $block
     * @param array $original The same block as the subscriber received it.
     * @return array
     */
    private function recomputeBlock(array $block, array $original): array
    {
        $originalLines = is_array($original['line_items'] ?? null) ? $original['line_items'] : [];
        $sums = self::sums($block['line_items']);
        $originalSums = self::sums($originalLines);
        $residual = static fn (string $total, string $field): float => is_numeric($original[$total] ?? null)
            ? (float)$original[$total] - $originalSums[$field]
            : 0.0;

        // Refund lines carry positive amounts, like `amount` itself.
        $totals = array_key_exists('amount', $block)
            ? ['amount' => 'gross_amount']
            : ['net_amount' => 'net_amount', 'tax_amount' => 'tax_amount', 'gross_amount' => 'gross_amount'];
        foreach ($totals as $total => $field) {
            $block[$total] = self::amount($sums[$field] + $residual($total, $field));
        }

        if (isset($block['tax_subtotals'])) {
            $block['tax_subtotals'] = $this->subtotals($block['line_items'], $original, $originalLines);
        }

        return $block;
    }

    /**
     * One bucket per rate: its lines, plus what the received bucket at that
     * rate carried over the received lines at it. A rate the received payload
     * had no bucket for carries nothing extra.
     *
     * @param array $lines
     * @param array $original
     * @param array $originalLines
     * @return array
     */
    private function subtotals(array $lines, array $original, array $originalLines): array
    {
        $residuals = [];
        foreach (is_array($original['tax_subtotals'] ?? null) ? $original['tax_subtotals'] : [] as $bucket) {
            $rate = self::amount($bucket['tax_rate'] ?? 0, 6);
            $residuals[$rate]['taxable_amount'] = ($residuals[$rate]['taxable_amount'] ?? 0.0)
                + (float)($bucket['taxable_amount'] ?? 0);
            $residuals[$rate]['tax_amount'] = ($residuals[$rate]['tax_amount'] ?? 0.0)
                + (float)($bucket['tax_amount'] ?? 0);
        }
        foreach (self::sumByRate($originalLines) as $rate => $sums) {
            if (isset($residuals[$rate])) {
                $residuals[$rate]['taxable_amount'] -= $sums['taxable_amount'];
                $residuals[$rate]['tax_amount'] -= $sums['tax_amount'];
            }
        }

        $buckets = self::sumByRate($lines);
        foreach ($residuals as $rate => $residual) {
            if (!isset($buckets[$rate]) && abs($residual['taxable_amount']) < 0.005 && abs($residual['tax_amount']) < 0.005) {
                continue;
            }
            foreach ($residual as $field => $value) {
                $buckets[$rate][$field] = ($buckets[$rate][$field] ?? 0.0) + $value;
            }
        }

        $subtotals = [];
        foreach ($buckets as $rate => $sums) {
            $subtotals[] = [
                'taxable_amount' => self::amount($sums['taxable_amount']),
                'tax_amount' => self::amount($sums['tax_amount']),
                'tax_rate' => (string)$rate,
            ];
        }

        return $subtotals;
    }
}
