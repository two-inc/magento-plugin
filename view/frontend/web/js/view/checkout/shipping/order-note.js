/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Order-note field for the shipping address area (TWO-25263).
 *
 * Mounted into `shipping-address-fieldset` by
 * view/frontend/layout/checkout_index_index.xml, which is the same node the
 * existing address-autocomplete component uses, so Luma, Amasty and Fire
 * Checkout all pick it up without a per-front-end plugin.
 *
 * The value is held in Two_Gateway/js/model/order-note and read back by the
 * payment renderer's getData(), so it still leaves the browser as
 * `payment[orderNote]`. See that model's header for why, and for what
 * `renderedInShippingArea` is protecting against.
 */
define([
    'uiComponent',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/order-note'
], function (Component, brandConfig, orderNoteModel) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Two_Gateway/checkout/shipping/order-note'
        },

        initialize: function () {
            this._super();

            var config = brandConfig.getActiveTwoBrandConfig();

            this.isOrderNoteFieldEnabled = !!config.isOrderNoteFieldEnabled;
            this.orderNote = orderNoteModel.orderNote;

            // Claim the field only when it will actually render. While this
            // stays false the payment tile keeps showing its own order-note
            // input, which is the fallback for virtual carts (no shipping
            // step) and for any front-end whose jsLayout lacks the
            // shipping-address-fieldset node this component mounts into.
            if (this.isOrderNoteFieldEnabled) {
                orderNoteModel.renderedInShippingArea(true);
            }

            return this;
        }
    });
});
