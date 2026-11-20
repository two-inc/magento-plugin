define(['uiComponent', 'Two_Gateway/js/model/register-renderer'], function (
    Component,
    registerRenderer
) {
    'use strict';

    // Register the Two-branded payment method against the brand-agnostic
    // gateway_method renderer. Brand-overlay packages ship their own
    // wrapper file that pushes their own `type` against the same shared
    // renderer. registerRenderer also creates the renderer when Luma's
    // payment list has already been built without it (TWO-26297).
    registerRenderer('two_payment', 'Two_Gateway/js/view/payment/method-renderer/gateway_method');
    return Component.extend({});
});
