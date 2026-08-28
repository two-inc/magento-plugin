/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: starts the company-capture component, once, and keeps its mount
 * pointed at whichever host is currently on screen.
 *
 * Mounted from checkout_index_index.xml under the always-present SIDEBAR, not
 * under the Two payment renderer — the same reason payment-availability.js
 * lives there. A renderer is created per Two-family brand and destroyed on
 * every totals change, so a component booted from one is neither single nor
 * durable, which is exactly what tied the chips, the search mount and the
 * signup popup handle to a surface that keeps being torn down.
 *
 * This component holds no capture state of its own. It is a lifecycle hook:
 * boot once, re-point the mount when the checkout changes shape.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'Two_Gateway/js/model/company-capture'
], function (Component, quote, companyCapture) {
    'use strict';

    return Component.extend({
        defaults: {
            template: null
        },

        /** @returns {Object} chainable */
        initialize: function () {
            this._super();

            companyCapture.start();

            // Both of these change which host node exists: an address switching
            // between new and saved makes the address-step field come and go,
            // and a totals change rebuilds the payment tiles. The component
            // re-points its one control; it is never rebuilt.
            this._addressSubscription = quote.billingAddress.subscribe(
                this._onCheckoutShapeChanged.bind(this)
            );
            this._totalsSubscription = quote.getTotals().subscribe(
                this._onCheckoutShapeChanged.bind(this)
            );

            return this;
        },

        /**
         * Re-point the mount after the DOM the re-render produced has landed.
         * The subscription fires before Knockout has rebuilt the tiles, so a
         * synchronous read would resolve against the outgoing nodes.
         */
        _onCheckoutShapeChanged: function () {
            setTimeout(function () {
                // The quote is the only signal for a country the buyer never
                // typed — a saved address, or one core resolved for them. The
                // form's own `change` never fires for either.
                companyCapture.onCountryChanged();
                companyCapture.refreshMount();
            }, 0);
        },

        /**
         * The component itself deliberately survives this — only the
         * subscriptions are this object's to release. A checkout that disposes
         * and re-creates this hook must not take the buyer's captured company,
         * their open signup popup or their minted tokens with it.
         *
         * @returns {Object} chainable
         */
        destroy: function () {
            if (this._addressSubscription) {
                this._addressSubscription.dispose();
                this._addressSubscription = null;
            }
            if (this._totalsSubscription) {
                this._totalsSubscription.dispose();
                this._totalsSubscription = null;
            }
            this._super();
        }
    });
});
