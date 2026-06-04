/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Refreshes the admin credit-memo totals when the merchant edits the Two
 * surcharge refund field, so the Tax line and Grand Total track the surcharge
 * without a manual click. The server-side recalculation lives in
 * Model/Total/Creditmemo/Surcharge; here we only re-trigger Magento's own
 * "Update Qty's" round-trip — the surcharge input is part of #edit_form, so
 * that round-trip serialises and posts it to the updateQty controller, which
 * re-renders the totals.
 *
 * Fires on blur and on a debounced change so a typed edit settles before the
 * (relatively expensive) server round-trip.
 *
 * Exposed as an init function taking (doc, win) so it can be unit-tested under
 * jsdom; the template invokes it with the live document.
 */
define([], function () {
    'use strict';

    var DEBOUNCE_MS = 700;
    var INPUT_ID = 'two_surcharge_refund';
    var UPDATE_BUTTON_SELECTOR = '[data-ui-id="order-items-update-button"]';

    return function init(doc, win) {
        doc = doc || document;
        win = win || window;

        var input = doc.getElementById(INPUT_ID);
        if (!input) {
            return;
        }

        var lastValue = input.value;
        var timer = null;

        function triggerReload() {
            if (timer) {
                win.clearTimeout(timer);
                timer = null;
            }
            // Skip if nothing changed since the last reload — avoids a
            // redundant server round-trip on a blur that follows a debounced
            // change, or a blur with no edit.
            if (input.value === lastValue) {
                return;
            }
            lastValue = input.value;

            var button = doc.querySelector(UPDATE_BUTTON_SELECTOR);
            if (!button) {
                return;
            }
            // Magento disables "Update Qty's" until an item qty changes;
            // re-enable it so the programmatic click fires the same updateQty
            // round-trip that serialises and posts the surcharge field.
            button.disabled = false;
            button.classList.remove('disabled');
            button.click();
        }

        input.addEventListener('blur', triggerReload);
        input.addEventListener('input', function () {
            if (timer) {
                win.clearTimeout(timer);
            }
            timer = win.setTimeout(triggerReload, DEBOUNCE_MS);
        });
    };
});
