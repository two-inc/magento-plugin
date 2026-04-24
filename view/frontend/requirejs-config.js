var config = {
    paths: {},
    shim: {},
    config: {
        mixins: {
            'Magento_Checkout/js/action/set-shipping-information': {
                'Two_Gateway/js/action/set-shipping-information-mixin': true
            },
            'Magento_Checkout/js/model/new-customer-address': {
                'Two_Gateway/js/model/new-customer-address-mixin': true
            }
        }
    }
};
