/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Order-note field for the shipping address area (TWO-25263).
 *
 * Mounted into `shipping-address-fieldset` by
 * view/frontend/layout/checkout_index_index.xml, the same node the existing
 * address-autocomplete component uses.
 *
 * The value is held in Two_Gateway/js/model/order-note and read back by the
 * payment renderer's getData(), so it still leaves the browser as
 * `payment[orderNote]`. See that model's header for why, and for what
 * `renderedInShippingArea` is protecting against.
 *
 * The claim is made from `afterRender`, NOT from initialize(). Magento
 * constructs every component in the jsLayout tree whether or not it is ever
 * painted, so claiming at construction would suppress the payment tile's
 * fallback in exactly the cases the fallback exists for, leaving the buyer no
 * order-note field at all:
 *
 *   - virtual / downloadable-only carts, which never render the shipping step
 *     (component construction still happens), and
 *   - a returning customer with a saved address, where Magento only renders
 *     the shipping address form — and therefore this fieldset — inside the
 *     "New Address" flow. The sibling address-autocomplete component does not
 *     notice this because it paints nothing and drives the DOM through
 *     jQuery `$.async` on `#shipping-new-address-form`; a component that
 *     actually renders a field does notice.
 *
 * The claim is released on node disposal so navigating away from a rendered
 * shipping form hands the field back to the tile.
 */
define([
    'ko',
    'uiComponent',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/order-note'
], function (ko, Component, brandConfig, orderNoteModel) {
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

            return this;
        },

        /**
         * Bound as `afterRender` on the template's root node, so it runs only
         * when the field is genuinely in the DOM. While the claim stays
         * unmade the payment tile shows its own order-note field.
         *
         * @param {HTMLElement} element the rendered root node
         * @returns {void}
         */
        claimOrderNoteField: function (element) {
            if (!this.isOrderNoteFieldEnabled) {
                return;
            }

            orderNoteModel.renderedInShippingArea(true);

            if (element && ko.utils && ko.utils.domNodeDisposal) {
                ko.utils.domNodeDisposal.addDisposeCallback(element, function () {
                    orderNoteModel.renderedInShippingArea(false);
                });
            }
        }
    });
});
