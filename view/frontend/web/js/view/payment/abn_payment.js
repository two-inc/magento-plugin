define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], function (
    Component,
    rendererList
) {
    'use strict';

    rendererList.push({
        type: 'abn_payment',
        component: 'ABN_Gateway/js/view/payment/method-renderer/abn_payment'
    });
    return Component.extend({});
});
