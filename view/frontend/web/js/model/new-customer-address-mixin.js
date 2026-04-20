define(['underscore'], function (_) {
    'use strict';

    return function (target) {
        return function (addressData) {
            var addressDataObject = target(addressData);

            if (_.isArray(addressDataObject.customAttributes)) {
                addressDataObject.customAttributes = _.filter(
                    addressDataObject.customAttributes,
                    function (item) {
                        return item && item.value != null && item.value !== '';
                    }
                );
            }

            return addressDataObject;
        };
    };
});
