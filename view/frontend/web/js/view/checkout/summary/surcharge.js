/**
 * Order summary line for the Two payment terms surcharge.
 *
 * Reads from the 'two_surcharge' totals segment produced by the
 * server-side Total Collector. No client-side calculation needed.
 */
define([
    'Magento_Checkout/js/view/summary/abstract-total',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/totals',
    'mage/translate'
], function (Component, quote, totals, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Two_Gateway/checkout/summary/surcharge'
        },

        isDisplayed: function () {
            var segment = totals.getSegment('two_surcharge');
            var method = quote.paymentMethod();
            return segment && parseFloat(segment.value) > 0
                && method && method.method === 'two_payment';
        },

        getValue: function () {
            var segment = totals.getSegment('two_surcharge');
            var amount = segment ? parseFloat(segment.value) : 0;
            return this.getFormattedPrice(amount);
        },

        getTitle: function () {
            var segment = totals.getSegment('two_surcharge');
            return (segment && segment.title) || $t('Payment terms fee');
        }
    });
});
