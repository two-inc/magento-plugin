/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

describe('gateway_method afterPlaceOrder redirect', () => {
    function loadComponent(seq, redirectUrl) {
        const loader = {
            startLoader: function () { seq.push('startLoader'); },
            stopLoader: function () { seq.push('stopLoader'); }
        };
        function $() { return {}; }
        $.mage = {
            cookies: { get: function () { return redirectUrl; } },
            redirect: function () { seq.push('redirect'); }
        };
        const component = loadAmdModule(
            'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
            {
                jquery: $,
                'Magento_Checkout/js/model/full-screen-loader': loader
            }
        );
        return component;
    }

    test('keeps the full-screen loader up until the redirect navigates away', function () {
        const seq = [];
        const component = loadComponent(seq, 'https://checkout.example/redirect');

        component.afterPlaceOrder.call({ _brandConfig: { redirectUrlCookieCode: 'two_redirect' } });

        // The loader must be (re)started before the redirect fires, so the
        // overlay stays visible for the seconds the browser takes to navigate.
        expect(seq).toEqual(['startLoader', 'redirect']);
    });

    test('does nothing when there is no redirect cookie', function () {
        const seq = [];
        const component = loadComponent(seq, null);

        component.afterPlaceOrder.call({ _brandConfig: { redirectUrlCookieCode: 'two_redirect' } });

        expect(seq).toEqual([]);
    });
});
