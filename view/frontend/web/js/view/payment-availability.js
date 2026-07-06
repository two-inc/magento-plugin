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
 * On a genuine grand-total change it re-fetches the payment-information
 * endpoint and applies ONLY the returned method list — the server re-runs
 * isAvailable, so the method appears/disappears in both directions. It
 * deliberately does NOT call quote.setTotals(): the shared core action
 * (get-payment-information) does, which stamps the server's (possibly
 * pre-shipping) totals over the correctly-collected client totals — the
 * regression that got the first attempt reverted. Skipping setTotals also
 * means this can't re-emit the totals observable, so there is no refresh
 * loop to guard against beyond the no-op dedup.
 *
 * Mounted from checkout_index_index.xml under the always-present sidebar,
 * NOT under the Two payment renderer: when the method is hidden its renderer
 * is not instantiated, so a refresher living there could never bring it back.
 */
define([
    'uiComponent',
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'mage/storage',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/model/payment/method-converter',
    'Magento_Checkout/js/model/payment-service',
    'Magento_Checkout/js/model/error-processor',
    'Magento_Ui/js/model/messageList'
], function (
    Component,
    $,
    quote,
    urlBuilder,
    storage,
    customer,
    methodConverter,
    paymentService,
    errorProcessor,
    globalMessageList
) {
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
            // Trailing edge: a total that changes again mid-refresh is parked
            // here and re-run when the in-flight refresh resolves, so rapid
            // interactions (switch shipping, then apply a coupon) converge on
            // the final total.
            this._pendingGrandTotal = null;
            // Baseline from the totals already loaded at mount — core has just
            // fetched the payment list for this value, so there is nothing to
            // re-ask yet. A KO subscribable does not replay, so seeding here is
            // what lets us detect the first post-mount change on a
            // fast/returning-customer stack.
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
            var grandTotal = this._readGrandTotal(totals);

            if (grandTotal === null) {
                return;
            }
            if (this._lastGrandTotal === null) {
                this._lastGrandTotal = grandTotal;

                return;
            }
            if (grandTotal === this._lastGrandTotal) {
                return;
            }
            if (this._refreshing) {
                this._pendingGrandTotal = grandTotal;

                return;
            }
            this._lastGrandTotal = grandTotal;
            this._refresh();
        },

        /**
         * Re-fetch the payment-information endpoint and apply ONLY the method
         * list. Never touches totals (see class doc).
         */
        _refresh: function () {
            var self = this;

            this._refreshing = true;
            this._pendingGrandTotal = null;

            storage.get(this._paymentInformationUrl(), false)
                .done(function (response) {
                    paymentService.setPaymentMethods(
                        methodConverter(response['payment_methods'])
                    );
                })
                .fail(function (response) {
                    errorProcessor.process(response, globalMessageList);
                })
                .always(function () {
                    self._refreshing = false;
                    if (self._pendingGrandTotal !== null &&
                        self._pendingGrandTotal !== self._lastGrandTotal) {
                        self._lastGrandTotal = self._pendingGrandTotal;
                        self._refresh();
                    }
                });
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
