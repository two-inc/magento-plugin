define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], function (
    Component,
    rendererList
) {
    'use strict';

    // Register the Two-branded payment method against the brand-agnostic
    // gateway_method renderer. Brand-overlay packages ship their own
    // wrapper file that pushes their own `type` against the same shared
    // renderer.
    rendererList.push({
        type: 'two_payment',
        component: 'Two_Gateway/js/view/payment/method-renderer/gateway_method'
    });
    return Component.extend({});
});
