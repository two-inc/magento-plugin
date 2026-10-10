/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-26297: a Two-family renderer registered after Luma's payment list has
 * built its renderers is created by the registration itself, and only then.
 *
 * On a virtual quote core seeds the method list while the checkout boots, so
 * the list's first pass can run before this renderer type is pushed. Core only
 * creates renderers for methods the list later ADDS, so without this the method
 * stays off the page until a reload. The one thing the registration must never
 * do is create a second renderer, which is what happens if it acts while the
 * list's own first pass is still queued behind the default method group.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const MODULE = 'view/frontend/web/js/model/register-renderer.js';
const COMPONENT = 'Two_Gateway/js/view/payment/method-renderer/gateway_method';
const LIST_NAME = 'checkout.steps.billing-step.payment.payments-list';

function setup(state) {
    const pushed = [];
    const created = [];
    const items = {};
    const list = {
        name: LIST_NAME,
        configDefaultGroup: { name: 'methodGroup' },
        createRenderer: function (method) {
            created.push(method.method);
        }
    };
    if (state.list === 'no-create') {
        delete list.createRenderer;
    }
    if (state.list) {
        items['index = payments-list'] = list;
    }
    if (state.group) {
        items.methodGroup = {};
    }
    (state.rendered || []).forEach(function (code) {
        items[LIST_NAME + '.' + code] = {};
    });

    const registerRenderer = loadAmdModule(MODULE, {
        uiRegistry: {
            get: function (query) {
                return items[query];
            }
        },
        'Magento_Checkout/js/model/payment/renderer-list': {
            push: function (r) {
                pushed.push(r);
            }
        },
        'Magento_Checkout/js/model/payment/method-list': function () {
            return (state.methods || []).map(function (code) {
                return { method: code };
            });
        }
    });

    registerRenderer('two_payment', COMPONENT);

    return { pushed: pushed, created: created };
}

describe('registerRenderer (TWO-26297)', () => {
    test.each([
        [{}, [], 'no payment list yet: its own first pass will find the entry'],
        [
            { list: true, methods: ['two_payment'] },
            [],
            'list built but its first pass still queued on the method group'
        ],
        [
            { list: true, group: true, methods: ['two_payment', 'checkmo'] },
            ['two_payment'],
            'first pass already ran without this type: create it'
        ],
        [
            { list: true, group: true, methods: ['two_payment'], rendered: ['two_payment'] },
            [],
            'renderer already exists: never a second one'
        ],
        [
            { list: true, group: true, methods: ['checkmo'] },
            [],
            'method not offered: nothing to create'
        ],
        [
            { list: true, group: true, methods: [] },
            [],
            'quote with shipping, list still empty: the later add creates it'
        ],
        [
            { list: 'no-create', group: true, methods: ['two_payment'] },
            [],
            'a list without createRenderer is left alone'
        ]
    ])('%j creates %j: %s', (state, expected, description) => {
        const result = setup(state);

        expect(result.pushed).toEqual([{ type: 'two_payment', component: COMPONENT }]);
        expect({ created: result.created, description }).toEqual({
            created: expected,
            description
        });
    });
});
