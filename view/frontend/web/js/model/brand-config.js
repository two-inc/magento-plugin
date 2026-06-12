/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Brand-overlay-aware config lookup. Reads from
 * `window.checkoutConfig.payment[<methodCode>]` so each brand-overlay
 * payment method (two_payment, acme_payment, …) gets its own config
 * subtree without forking the gateway_method renderer per brand.
 *
 * Returns an empty object when the requested subtree is absent so
 * callers can safely property-access without null checks.
 *
 * Default export is a callable function (`getBrandConfig(code)`) so
 * the gateway_method renderer keeps its existing usage. Two
 * additional properties resolve the active Two-family brand at
 * runtime for callers that don't already know the method code:
 *
 *   getBrandConfig.getActiveTwoBrandCode()
 *     → scans `window.checkoutConfig.payment` and returns the first
 *       key whose subtree has a truthy `redirectUrlCookieCode`
 *       (emitted only by Two_Gateway's ConfigProvider, so it's a
 *       reliable Two-family sentinel). Returns `null` if none.
 *
 *   getBrandConfig.getActiveTwoBrandConfig()
 *     → shorthand for `getBrandConfig(getActiveTwoBrandCode())`.
 *       Always returns an object (empty if no Two-family brand is
 *       active) so callers can property-access without guards.
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

    function getBrandConfig(code) {
        var checkoutConfig = (typeof window !== 'undefined' && window.checkoutConfig) || {};
        return (checkoutConfig.payment || {})[code] || {};
    }

    /**
     * Returns the payment-method code for the currently-active Two-family
     * brand (two_payment, acme_payment, …) by scanning checkoutConfig.payment
     * for a subtree with a truthy `redirectUrlCookieCode`. That field is
     * emitted only by Two_Gateway's Model/Ui/ConfigProvider, so its presence
     * is a reliable Two-family sentinel that does not collide with other
     * Magento payment methods.
     */
    getBrandConfig.getActiveTwoBrandCode = function () {
        var checkoutConfig = (typeof window !== 'undefined' && window.checkoutConfig) || {};
        var payment = checkoutConfig.payment || {};
        for (var code in payment) {
            if (Object.prototype.hasOwnProperty.call(payment, code)
                && payment[code]
                && payment[code].redirectUrlCookieCode) {
                return code;
            }
        }
        return null;
    };

    /**
     * Returns the config subtree for the active Two-family brand, or an
     * empty object if none is active. Always object-typed so callers can
     * property-access without null checks.
     */
    getBrandConfig.getActiveTwoBrandConfig = function () {
        var code = getBrandConfig.getActiveTwoBrandCode();
        return code ? getBrandConfig(code) : {};
    };

    return getBrandConfig;
}));
