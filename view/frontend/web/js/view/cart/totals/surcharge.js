/**
 * Cart-page totals line for the Two payment terms surcharge.
 *
 * Reuses the checkout summary component but relaxes the display rule: the
 * cart page has no payment-method selector, so there is no live method to
 * gate on. The server-side Total Collector only emits the 'two_surcharge'
 * segment when the Two payment method and a term are active on the quote, so
 * a positive segment value is itself the signal that the surcharge applies.
 * Without this, the cart page summed the surcharge into the totals (because
 * the collector ran) but never rendered it as its own line.
 */
define([
    'Two_Gateway/js/view/checkout/summary/surcharge',
    'Magento_Checkout/js/model/totals'
], function (Component, totals) {
    'use strict';

    return Component.extend({
        isDisplayed: function () {
            var segment = totals.getSegment('two_surcharge');
            return !!(segment && parseFloat(segment.value) > 0);
        }
    });
});
