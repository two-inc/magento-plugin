/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Smoke-load test for every JS file shipped by the module. Each file
 * is loaded through the AMD harness with mocked Magento RequireJS
 * deps and asserted to return *something* (i.e. didn't throw mid-eval,
 * didn't fail to register, didn't reference an unmocked dep).
 *
 * Catches:
 *   - syntax errors
 *   - typos in dep paths (would surface as "unmocked dep" failures here)
 *   - factory functions that throw at load time
 *
 * Does NOT exercise the deep behaviour of each module — those need
 * dedicated test files (see brand-config.test.js for an example).
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const JS_FILES = [
    'view/adminhtml/requirejs-config.js',
    'view/adminhtml/web/js/button-functions.js',
    'view/adminhtml/web/js/payment-terms-config.js',
    'view/adminhtml/web/js/surcharge-grid.js',
    'view/frontend/requirejs-config.js',
    'view/frontend/web/js/action/set-shipping-information-mixin.js',
    'view/frontend/web/js/model/brand-config.js',
    'view/frontend/web/js/model/new-customer-address-mixin.js',
    'view/frontend/web/js/model/surcharge.js',
    'view/frontend/web/js/view/address-autocomplete.js',
    'view/frontend/web/js/view/checkout/summary/surcharge.js',
    'view/frontend/web/js/view/messages-sticky-errors-mixin.js',
    'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
    'view/frontend/web/js/view/payment/two_payment.js'
];

describe('Two_Gateway JS smoke load', () => {
    JS_FILES.forEach((file) => {
        it(`loads without throwing: ${file}`, () => {
            const out = loadAmdModule(file);
            expect(out).toBeDefined();
        });
    });
});
