/**
 * Order summary line for the Two payment terms surcharge.
 *
 * Reads from the 'two_surcharge' totals segment produced by the
 * server-side Total Collector. No client-side calculation needed —
 * net or gross is already decided there from tax/cart_display/price,
 * and "Both" arrives as a second 'two_surcharge_incl' segment.
 */
define([
    'Magento_Checkout/js/view/summary/abstract-total',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/totals',
    'Two_Gateway/js/model/brand-config'
], function (Component, quote, totals, brandConfig) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Two_Gateway/checkout/summary/surcharge'
        },

        isDisplayed: function () {
            var segment = totals.getSegment('two_surcharge');
            var method = quote.paymentMethod();
            // Match against the active Two-family brand code rather
            // than the hardcoded vanilla `two_payment`, so brand
            // overlays (acme_payment, …) display the surcharge line
            // when their own payment method is selected.
            var activeCode = brandConfig.getActiveTwoBrandCode();
            return segment && parseFloat(segment.value) > 0
                && method && activeCode && method.method === activeCode;
        },

        getValue: function () {
            var segment = totals.getSegment('two_surcharge');
            var amount = segment ? parseFloat(segment.value) : 0;
            return this.getFormattedPrice(amount);
        },

        getTitle: function () {
            var segment = totals.getSegment('two_surcharge');
            return (segment && segment.title) || '';
        },

        hasInclTaxRow: function () {
            var segment = totals.getSegment('two_surcharge_incl');
            return !!(segment && parseFloat(segment.value) > 0);
        },

        getInclTaxValue: function () {
            var segment = totals.getSegment('two_surcharge_incl');
            var amount = segment ? parseFloat(segment.value) : 0;
            return this.getFormattedPrice(amount);
        },

        getInclTaxTitle: function () {
            var segment = totals.getSegment('two_surcharge_incl');
            return (segment && segment.title) || '';
        }
    });
});
