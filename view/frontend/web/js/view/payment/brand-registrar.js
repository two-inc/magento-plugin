/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Generic brand-renderer registrar.
 *
 * The static two_payment.js registers the default Two brand at
 * module-load time. Brand-overlay packages used to ship their own
 * sibling file doing the same — that fanout is replaced by this
 * component, instantiated once per overlay brand via the synthesis
 * LayoutProcessor plugin which injects an entry under
 * checkout.steps.billing-step.payment.renders.children.<brandCode>
 * with `config.brandCode = <code>`.
 *
 * Push is deferred to `initialize` so each component instance gets
 * its own push (one entry per brand) rather than the file-level
 * single-push that two_payment.js does.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    return Component.extend({
        defaults: {
            brandCode: ''
        },

        initialize: function () {
            this._super();
            if (this.brandCode) {
                rendererList.push({
                    type: this.brandCode,
                    component: 'Two_Gateway/js/view/payment/method-renderer/gateway_method'
                });
            }
            return this;
        }
    });
});
