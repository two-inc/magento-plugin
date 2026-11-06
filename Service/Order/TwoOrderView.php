<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

/**
 * The order postprocessing context's `order_lines` and `order_refunds`
 * (TWO-26309), from Two's GET /v1/order/{id} response.
 */
class TwoOrderView
{
    /**
     * Two's order lines, with Two's line ids; null without a response carrying them.
     *
     * @param mixed $twoOrder
     * @return array|null
     */
    public static function lines($twoOrder): ?array
    {
        return self::listOf($twoOrder, 'line_items');
    }

    /**
     * Two's refunds on the order, each refund line's prototype_id naming the
     * order line it credited. Two leaves it empty, so an empty one is filled
     * with the order line that matches on name, description, type, tax code
     * and rate. Within one refund each order line is credited by at most one
     * line, so where several order lines match, the refund's matching lines
     * go to distinct order lines, the largest to the one with the most left
     * to refund; only a refund with more matching lines than there are order
     * lines gives one a second. A best match, not Two's record: a refund line
     * that matches no order line keeps its empty prototype_id. Null without a
     * response carrying refunds.
     *
     * @param mixed $twoOrder
     * @return array|null
     */
    public static function refunds($twoOrder): ?array
    {
        $refunds = self::listOf($twoOrder, 'refunds');
        if ($refunds === null) {
            return null;
        }
        $left = [];
        $byLook = [];
        foreach (self::lines($twoOrder) ?? [] as $line) {
            if (is_array($line) && !empty($line['id'])) {
                $left[(string)$line['id']] = (float)($line['gross_amount'] ?? 0);
                $byLook[self::look($line)][] = (string)$line['id'];
            }
        }
        // Lines Two already names come off first, so a match is judged on what is really left.
        foreach ($refunds as $refund) {
            foreach (is_array($refund['line_items'] ?? null) ? $refund['line_items'] : [] as $line) {
                $parent = is_array($line) ? (string)($line['prototype_id'] ?? '') : '';
                if ($parent !== '' && isset($left[$parent])) {
                    $left[$parent] -= abs((float)($line['gross_amount'] ?? 0));
                }
            }
        }
        foreach ($refunds as $r => $refund) {
            if (!is_array($refund['line_items'] ?? null)) {
                continue;
            }
            $unnamed = [];
            foreach ($refund['line_items'] as $l => $line) {
                if (is_array($line) && empty($line['prototype_id']) && isset($byLook[self::look($line)])) {
                    $unnamed[$l] = abs((float)($line['gross_amount'] ?? 0));
                }
            }
            arsort($unnamed);
            $used = [];
            foreach ($unnamed as $l => $gross) {
                $candidates = $byLook[self::look($refund['line_items'][$l])];
                $fresh = array_values(array_diff($candidates, $used));
                $parent = null;
                foreach ($fresh === [] ? $candidates : $fresh as $id) {
                    if ($parent === null || $left[$id] > $left[$parent] + 0.0001) {
                        $parent = $id;
                    }
                }
                $left[$parent] -= $gross;
                $used[] = $parent;
                $refunds[$r]['line_items'][$l]['prototype_id'] = $parent;
            }
        }

        return $refunds;
    }

    /**
     * @param mixed $twoOrder
     * @param string $key
     * @return array|null
     */
    private static function listOf($twoOrder, string $key): ?array
    {
        return is_array($twoOrder) && is_array($twoOrder[$key] ?? null) ? array_values($twoOrder[$key]) : null;
    }

    /**
     * The fields a refund line copies from the order line it was made from, as one key.
     *
     * @param array $line
     * @return string
     */
    private static function look(array $line): string
    {
        $look = [];
        foreach (['name', 'description', 'type', 'tax_code'] as $field) {
            $look[] = is_scalar($line[$field] ?? null) ? (string)$line[$field] : '';
        }
        $look[] = is_numeric($line['tax_rate'] ?? null) ? number_format((float)$line['tax_rate'], 6, '.', '') : '';

        return implode("\0", $look);
    }
}
