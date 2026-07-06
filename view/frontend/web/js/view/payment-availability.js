/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Re-evaluates payment-method availability when the quote totals change.
 *
 * The payment method's availability (Model\Two::isAvailable) depends on
 * the order value via the minimum-order gate, which compares the quote
 * grand total (net or gross) against the funding-partner / merchant
 * minimum. That check is server-side and correct — but Luma's
 * payment-service caches the method list from the last
 * set-shipping-information / payment-information fetch and does NOT
 * re-filter it when the totals move afterward (a later shipping-method
 * switch, a coupon applied on the payment step). So a basket that crosses
 * the minimum after the payment step is reached keeps its stale
 * visibility until a full checkout reload. Hyvä and FireCheckout re-fetch
 * on every totals change and are unaffected; this component gives Luma
 * (and Luma-derived one-step checkouts) the same behaviour.
 *
 * The fix re-ASKS the server rather than re-deciding in JS: on a genuine
 * totals change it calls get-payment-information, which re-runs
 * isAvailable server-side and repopulates the payment-method list. The
 * minimum-order logic (currency conversion, net/gross basis,
 * platform-vs-merchant floor) stays in one place — the server gate — and
 * is never duplicated here, so the client cannot drift from what the
 * Two API enforces at order creation.
 *
 * Mounted from checkout_index_index.xml under the always-present sidebar,
 * NOT under the Two payment renderer: when the method is hidden
 * (below-minimum) its renderer is not instantiated, so a refresher living
 * there could never bring the method back once it becomes eligible.
 */
define([
    'uiComponent',
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/get-payment-information',
    'Magento_Ui/js/model/messageList'
], function (Component, $, quote, getPaymentInformation, globalMessageList) {
    'use strict';

    return Component.extend({
        defaults: {
            template: null
        },

        /**
         * @returns {Object} chainable
         */
        initialize: function () {
            this._super();

            // Guard against re-entrancy: get-payment-information calls
            // quote.setTotals() on success, which re-emits the totals
            // observable while a refresh is still in flight.
            this._refreshing = false;

            // Baseline the grand total from the totals already loaded at
            // mount (core has just fetched the payment list for this value
            // on step entry, so there is nothing to re-ask yet). A KO
            // subscribable does not replay, so seeding here is what lets us
            // detect the FIRST post-mount change on a fast/returning-customer
            // stack where the bootstrap emit fired before we subscribed.
            this._lastGrandTotal = this._readGrandTotal(quote.getTotals()());

            quote.getTotals().subscribe(this._onTotalsChanged.bind(this));

            return this;
        },

        /**
         * @param {Object|null} totals
         * @returns {Number|null} parsed grand total, or null when absent/NaN
         */
        _readGrandTotal: function (totals) {
            if (!totals) {
                return null;
            }
            var value = parseFloat(totals.grand_total);

            return isNaN(value) ? null : value;
        },

        /**
         * @param {Object|null} totals
         */
        _onTotalsChanged: function (totals) {
            if (this._refreshing) {
                return;
            }
            var grandTotal = this._readGrandTotal(totals);

            if (grandTotal === null) {
                return;
            }
            if (this._lastGrandTotal === null) {
                // First value we have seen — core already fetched the
                // payment list for it. Record as the baseline and wait for
                // a real change.
                this._lastGrandTotal = grandTotal;

                return;
            }
            if (grandTotal === this._lastGrandTotal) {
                // A no-op re-emit (Magento republishes the observable in
                // several flows without a value change) — including the one
                // get-payment-information itself triggers. Skipping it is
                // what keeps this off an infinite refresh loop.
                return;
            }
            this._lastGrandTotal = grandTotal;
            this._refreshAvailability();
        },

        /**
         * Re-run server-side availability and repopulate the payment-method
         * list for the current quote state.
         */
        _refreshAvailability: function () {
            var self = this;
            var deferred = $.Deferred();

            this._refreshing = true;
            // Pass the shared checkout message list so a failed refresh
            // reports through the standard error path rather than
            // dereferencing a null container inside the core action.
            getPaymentInformation(deferred, globalMessageList);
            $.when(deferred).always(function () {
                self._refreshing = false;
            });
        }
    });
});
