/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Client-side minimum-order visibility test for the Two payment method.
 *
 * `minimums` are the server-resolved constraints (`{amount, basis}`) already
 * projected into the quote's display currency by Model\Two::getDisplayMinimums
 * — so this only compares, it does not re-derive the rule or do any FX. The
 * method is visible only when the live quote total satisfies EVERY minimum on
 * its declared basis (net = grand total − tax, gross = grand total).
 *
 * Display-only: server isAvailable + place-order + the Two API are the
 * enforcers. Absent/empty minimums or absent totals → visible (never hide on
 * missing data; the server list already gates presence).
 *
 * @param {Object|null} totals - Magento quote totals segment (grand_total, tax_amount)
 * @param {Array|null} minimums - [{amount:Number, basis:'net'|'gross'}]
 * @returns {Boolean}
 */
define([], function () {
    'use strict';

    return function isAboveMinimums(totals, minimums) {
        if (!minimums || !minimums.length) {
            return true;
        }
        if (!totals) {
            return true;
        }
        var grand = parseFloat(totals.grand_total) || 0;
        var tax = parseFloat(totals.tax_amount) || 0;

        return minimums.every(function (m) {
            var basketValue = m.basis === 'gross' ? grand : grand - tax;

            // +epsilon mirrors the server gate's >= at currency precision.
            return basketValue + 0.0001 >= m.amount;
        });
    };
});
