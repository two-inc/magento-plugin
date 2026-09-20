/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25800 — the express marker is CONSUMED, and the address bar says so.
 *
 * The marker names one attempt, so every case here uses a token of the shape
 * the button actually mints. That choice is load-bearing: a fixture value of
 * `1` is the one value that cannot tell a working strip from a strip matching
 * some other literal, because it is short enough to sit inside almost any
 * pattern. A marker left in the address bar survives a reload and a shared
 * link, so the assertion is on the rewritten URL, not on the call.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const MODULE = 'view/frontend/web/js/view/checkout/preselect.js';

/** The shape mintToken() produces: base36 time plus base36 randomness. */
const TOKEN = 'mu8m4iam186nr99y15tpi';

function makeWindow(search, options) {
    const store = (options && options.store) || {};
    const refuseHistory = !!(options && options.refuseHistory);

    return {
        replaced: [],
        store: store,
        location: { pathname: '/checkout/', search: search, hash: '' },
        history: {
            state: { page: 1 },
            replaceState: function (state, title, url) {
                if (refuseHistory) {
                    throw new Error('refused');
                }
                this.owner.replaced.push(url);
            }
        },
        sessionStorage: {
            getItem: function (k) {
                return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null;
            },
            setItem: function (k, v) {
                store[k] = String(v);
            },
            removeItem: function (k) {
                delete store[k];
            }
        }
    };
}

function mount(search, options) {
    const window = makeWindow(search, options);
    window.history.owner = window;

    const methodList = [];
    methodList.subscribe = function () {
        return { dispose: function () {} };
    };

    const factory = loadAmdModule(
        MODULE,
        {
            'Magento_Checkout/js/model/payment-service': {
                getAvailablePaymentMethods: function () {
                    return [];
                }
            },
            'Magento_Checkout/js/model/payment/method-list': methodList,
            'Magento_Checkout/js/action/select-payment-method': function () {},
            'Magento_Checkout/js/checkout-data': {
                getSelectedPaymentMethod: function () {
                    return null;
                }
            },
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandCode: function () {
                    return 'two_payment';
                }
            }
        },
        { window: window }
    );

    new factory();

    return window;
}

describe('the express marker in the address bar', () => {
    test('a consumed marker is removed from the URL', () => {
        const window = mount('?' + 'two_express=' + TOKEN);

        expect(window.replaced).toHaveLength(1);
        expect(window.replaced[0]).not.toContain('two_express');
        expect(window.replaced[0]).toBe('/checkout/');
    });

    test('an unrelated parameter beside it survives', () => {
        const window = mount('?from=pdp&two_express=' + TOKEN);

        expect(window.replaced[0]).not.toContain('two_express');
        expect(window.replaced[0]).toContain('from=pdp');
    });

    test('a parameter AFTER it survives, and the query stays well formed', () => {
        const window = mount('?two_express=' + TOKEN + '&from=pdp');

        expect(window.replaced[0]).not.toContain('two_express');
        expect(window.replaced[0]).toBe('/checkout/?from=pdp');
    });

    test('a checkout reached without a marker rewrites nothing', () => {
        const window = mount('?from=pdp');

        expect(window.replaced).toHaveLength(0);
    });

    /**
     * A history write can be refused - a sandboxed or embedded view - and the
     * storage record is then the only one. The marker is still in the address
     * bar, where a reload or a copied URL carries it on, so the next load that
     * still carries one tries again rather than returning on the storage
     * record alone.
     */
    test('a marker this tab already consumed is still stripped on the next load', () => {
        const store = {};

        const refused = mount('?two_express=' + TOKEN, { store: store, refuseHistory: true });

        expect(refused.replaced).toHaveLength(0);
        expect(store['two_gateway_express_consumed:' + TOKEN]).toBe('1');

        const reloaded = mount('?two_express=' + TOKEN, { store: store });

        expect(reloaded.replaced).toHaveLength(1);
        expect(reloaded.replaced[0]).toBe('/checkout/');
    });
});
