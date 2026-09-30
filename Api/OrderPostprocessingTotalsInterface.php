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
     * Carry a subscriber's line edits into the totals that derive from
     * `line_items`, wherever lines appear (the top level, or the `partial`
     * block of a capture):
     *
     * - `net_amount`, `tax_amount` and `gross_amount` each move by the change
     *   in the sum of that field over the lines between `$before` and
     *   `$payload`, and a refund's `amount` by the change in line gross. Any
     *   difference the composed payload already carried between a total and
     *   its lines (store credit, a gift card, an unitemised fee) is kept;
     * - `tax_subtotals`, when the key is present and not null, is rebuilt
     *   with one bucket per distinct `tax_rate`.
     *
     * Amounts are written as 2dp decimal strings and rates as 6dp. Every
     * other field, `discount_amount` included, is left as it is. A payload
     * with no lines is returned unchanged.
     *
     * @param array $payload The payload with the subscriber's line edits.
     * @param array $before The payload the subscriber received.
     * @return array
     */
    public function recompute(array $payload, array $before): array;
}
