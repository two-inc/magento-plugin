/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Brand-overlay-aware config lookup. Reads from
 * `window.checkoutConfig.payment[<methodCode>]` so each brand-overlay
 * payment method (two_payment, abn_payment, …) gets its own config
 * subtree without forking the gateway_method renderer per brand.
 *
 * Returns an empty object when the requested subtree is absent so
 * callers can safely property-access without null checks.
 *
 * UMD wrapper supports both AMD (in-browser, Magento RequireJS) and
 * CommonJS (Jest test runner under Node).
 */
(function (root, factory) {
    'use strict';
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.TwoGatewayBrandConfig = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    return function getBrandConfig(code) {
        var checkoutConfig = (typeof window !== 'undefined' && window.checkoutConfig) || {};
        return (checkoutConfig.payment || {})[code] || {};
    };
}));
