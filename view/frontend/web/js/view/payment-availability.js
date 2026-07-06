/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Re-evaluates payment-method availability when the quote totals change.
 *
 * Whether the Two method is offered depends on the order value via the
 * server-side minimum-order gate (Model\Two::isAvailable). On Luma the
 * payment-service caches the method list from the last
 * set-shipping-information / payment-information fetch and never re-filters
 * it when totals move afterwards (a later shipping-method switch, a coupon
 * applied on the payment step), so a basket crossing the threshold keeps its
 * stale visibility until a full checkout reload. Hyvä and FireCheckout
 * re-fetch on every totals change and are unaffected; this gives Luma (and
 * Luma-derived one-step checkouts) the same behaviour.
 *
 * On a genuine totals change it re-fetches the payment-information endpoint
 * (the server re-runs isAvailable) and applies the returned method list ONLY
 * WHEN the set of available methods actually changed. Two constraints drive
 * that:
 *   - It never calls quote.setTotals(). The shared core action
 *     (get-payment-information) does, which stamps the server's possibly-
 *     pre-shipping totals over the correctly-collected client totals — the
 *     regression that got the first attempt reverted.
 *   - It skips paymentService.setPaymentMethods() when the method set is
 *     unchanged. That call swaps the availablePaymentMethods observable with
 *     fresh object references, which makes Luma's payment list rebuild EVERY
 *     renderer — wiping a half-filled Two form and the selected method. We
 *     only want to add/remove Two as it crosses the minimum, not churn the
 *     list on every totals tick.
 *
 * Server isAvailable + place-order + API enforcement stay the sole source of
 * truth; no minimum logic is duplicated here.
 *
 * Mounted from checkout_index_index.xml under the always-present sidebar,
 * NOT under the Two payment renderer: when the method is hidden its renderer
 * is not instantiated, so a refresher living there could never bring it back.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'mage/storage',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/model/payment/method-converter',
    'Magento_Checkout/js/model/payment-service'
], function (Component, quote, urlBuilder, storage, customer, methodConverter, paymentService) {
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

            this._refreshing = false;
            // Trailing edge: a change during an in-flight fetch is parked here
            // and run when that fetch resolves, so rapid interactions converge.
            this._pendingKey = null;
            // Baseline from the totals already loaded at mount — core has just
            // fetched the payment list for this value. A KO subscribable does
            // not replay, so seeding here is what lets us detect the first
            // post-mount change on a fast/returning-customer stack.
            this._lastKey = this._readKey(quote.getTotals()());
            this._totalsSubscription = quote.getTotals().subscribe(this._onTotalsChanged.bind(this));

            return this;
        },

        /**
         * Dispose the totals subscription so a torn-down instance (checkout
         * re-render, one-step-checkout derivative) leaves no zombie handler.
         */
        destroy: function () {
            if (this._totalsSubscription) {
                this._totalsSubscription.dispose();
                this._totalsSubscription = null;
            }
            this._super();
        },

        /**
         * Availability key: grand total AND tax. The min-order gate can compare
         * on a net basis (grand_total − tax), so a tax-only move can flip
         * availability without changing grand_total; keying on both makes the
         * dedup match what the server actually gates on.
         *
         * @param {Object|null} totals
         * @returns {String|null} null when totals/grand_total is absent or NaN
         */
        _readKey: function (totals) {
            if (!totals) {
                return null;
            }
            var grand = parseFloat(totals.grand_total);
            if (isNaN(grand)) {
                return null;
            }
            var tax = parseFloat(totals.tax_amount) || 0;

            return grand + '|' + tax;
        },

        /**
         * @param {Object|null} totals
         */
        _onTotalsChanged: function (totals) {
            var key = this._readKey(totals);

            if (key === null) {
                return;
            }
            if (this._lastKey === null) {
                this._lastKey = key;

                return;
            }
            if (key === this._lastKey) {
                return;
            }
            if (this._refreshing) {
                this._pendingKey = key;

                return;
            }
            this._refresh(key);
        },

        /**
         * Re-fetch payment-information and apply the method list only when the
         * available-method set changed. Never touches totals.
         *
         * @param {String} targetKey
         */
        _refresh: function (targetKey) {
            var self = this;
            var priorKey = this._lastKey;

            this._lastKey = targetKey;
            this._refreshing = true;
            this._pendingKey = null;

            storage.get(this._paymentInformationUrl(), false)
                .done(function (response) {
                    self._applyIfChanged(response);
                })
                .fail(function () {
                    // Silent: a background availability probe must not paint the
                    // checkout error banner. Roll the key back so the next
                    // totals emit retries rather than the failure stranding a
                    // stale list. (Availability is server-enforced at
                    // place-order regardless.)
                    self._lastKey = priorKey;
                    if (typeof console !== 'undefined' && console.warn) {
                        console.warn('Two_Gateway: payment availability refresh failed');
                    }
                })
                .always(function () {
                    self._refreshing = false;
                    if (self._pendingKey !== null && self._pendingKey !== self._lastKey) {
                        self._refresh(self._pendingKey);
                    }
                });
        },

        /**
         * Swap the method list only when the set of available method codes
         * differs from what's shown — otherwise Luma rebuilds every renderer
         * and wipes in-progress payment forms (see class doc).
         *
         * @param {Object} response
         */
        _applyIfChanged: function (response) {
            var incoming = methodConverter((response && response['payment_methods']) || []);

            if (this._codes(incoming) !== this._codes(paymentService.getAvailablePaymentMethods())) {
                paymentService.setPaymentMethods(incoming);
            }
        },

        /**
         * Order-independent signature of a method list's codes.
         *
         * @param {Array|null} methods
         * @returns {String}
         */
        _codes: function (methods) {
            return (methods || [])
                .map(function (m) { return m.method; })
                .sort()
                .join(',');
        },

        /**
         * Guest vs registered payment-information URL (mirrors the core action).
         * @returns {String}
         */
        _paymentInformationUrl: function () {
            if (customer.isLoggedIn()) {
                return urlBuilder.createUrl('/carts/mine/payment-information', {});
            }

            return urlBuilder.createUrl('/guest-carts/:cartId/payment-information', {
                cartId: quote.getQuoteId()
            });
        }
    });
});
