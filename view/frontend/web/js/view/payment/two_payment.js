define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], function (
    Component,
    rendererList
) {
    'use strict';

    rendererList.push({
        type: 'two_payment',
        component: 'Two_Gateway/js/view/payment/method-renderer/two_payment'
    });
    return Component.extend({});
});
