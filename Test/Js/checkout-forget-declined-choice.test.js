/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-27389: the decline return's marker makes the checkout forget the payment
 * method the browser remembers, once, and leaves everything else it remembers
 * alone. The assertion is on the rewritten address as well as the call: a
 * marker left in the address bar would clear a later choice on a reload.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const MODULE = 'view/frontend/web/js/view/checkout/forget-declined-choice.js';

function mount(search, refuseHistory) {
    const cleared = [];
    const replaced = [];
    const window = {
        location: { pathname: '/checkout/', search: search, hash: '#payment' },
        history: {
            state: null,
            replaceState: function (state, title, url) {
                if (refuseHistory) {
                    throw new Error('refused');
                }
                replaced.push(url);
            }
        }
    };
    const factory = loadAmdModule(
        MODULE,
        {
            'Magento_Checkout/js/checkout-data': {
                setSelectedPaymentMethod: function (method) {
                    cleared.push(method);
                }
            }
        },
        { window: window }
    );

    new factory();

    return { cleared: cleared, replaced: replaced };
}

describe('forgetting the payment choice after a decline', () => {
    test.each([
        // search, history refused, choice cleared, address after, description
        ['?two_declined=1', false, [null], ['/checkout/#payment'], 'the marker alone: cleared once, address cleaned'],
        ['?from=x&two_declined=1', false, [null], ['/checkout/?from=x#payment'], 'a parameter before it survives'],
        ['?two_declined=1&from=x', false, [null], ['/checkout/?from=x#payment'], 'a parameter after it survives'],
        ['?two_declined=1', true, [null], [], 'a refused history write still clears the choice'],
        ['', false, [], [], 'no marker: nothing forgotten, nothing rewritten'],
        ['?two_declined=2', false, [], [], 'another value is not the marker'],
        ['?xtwo_declined=1', false, [], [], 'a longer name is not the marker'],
        ['?two_express=abc', false, [], [], 'the express marker is not this one']
    ])('%s (refused: %s)', (search, refused, cleared, replaced, description) => {
        const result = mount(search, refused);

        expect({ description, cleared: result.cleared }).toEqual({ description, cleared });
        expect({ description, replaced: result.replaced }).toEqual({ description, replaced });
    });
});
