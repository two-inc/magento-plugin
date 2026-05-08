var config = {
    paths: {},
    shim: {},
    config: {
        mixins: {
            'Magento_Checkout/js/action/set-shipping-information': {
                'ABN_Gateway/js/action/set-shipping-information-mixin': true
            },
            'Magento_Checkout/js/model/new-customer-address': {
                'ABN_Gateway/js/model/new-customer-address-mixin': true
            },
            'Magento_Ui/js/view/messages': {
                'ABN_Gateway/js/view/messages-sticky-errors-mixin': true
            }
        }
    }
};
