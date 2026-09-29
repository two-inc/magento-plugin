<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Api;

/**
 * Opt-in helper for OrderPostprocessingInterface subscribers (TWO-26092).
 *
 * The plugin never recomputes totals after the hook, because that would
 * overwrite a subscriber's own edits to them. A subscriber that edits lines
 * and wants the totals to follow calls this on its result.
 *
 * @api
 */
interface OrderPostprocessingTotalsInterface
{
    /**
     * Rebuild the totals that derive from `line_items`, wherever lines appear
     * (the top level, or the `partial` block of a capture):
     *
     * - `net_amount`, `tax_amount` and `gross_amount` become the sums of the
     *   lines, and a refund's `amount` the sum of its line gross;
     * - `tax_subtotals`, when the key is present and not null, is rebuilt
     *   with one bucket per distinct `tax_rate`.
     *
     * Amounts are written as 2dp decimal strings and rates as 6dp. Every
     * other field, `discount_amount` included, is left as it is. A payload
     * with no lines is returned unchanged.
     *
     * @param array $payload
     * @return array
     */
    public function recompute(array $payload): array;
}
