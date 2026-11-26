/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-27389: the decline return's marker makes the checkout forget the payment
 * method the browser remembers, once, and leaves everything else it remembers
 * alone. The assertion is on the rewritten address as well as the call: a
 * marker left in the address bar would clear a later choice on a reload.
 *
 * TWO-27396: on the marker, the newest error queued in the `mage-messages`
 * cookie is shown in the checkout's message area and the queue is emptied, so
 * it shows once, here, and not on the buyer's next page. Without the marker
 * the queue is left alone.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const MODULE = 'view/frontend/web/js/view/checkout/forget-declined-choice.js';

const DECLINE = 'Two could not approve this purchase. Please choose another payment method.';

function mount(search, refuseHistory, queued) {
    const cleared = [];
    const replaced = [];
    const shown = [];
    const cookieWrites = [];
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
    const $ = {
        cookieStorage: {
            get: function (name) {
                return name === 'mage-messages' ? queued : null;
            }
        },
        mage: {
            cookies: {
                set: function (name, value) {
                    cookieWrites.push([name, value]);
                }
            }
        }
    };
    const factory = loadAmdModule(
        MODULE,
        {
            jquery: $,
            'Magento_Checkout/js/checkout-data': {
                setSelectedPaymentMethod: function (method) {
                    cleared.push(method);
                }
            },
            'Magento_Ui/js/model/messageList': {
                addErrorMessage: function (message) {
                    shown.push(message.message);
                }
            }
        },
        { window: window }
    );

    new factory();

    return { cleared: cleared, replaced: replaced, shown: shown, cookieWrites: cookieWrites };
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

describe('showing the decline message once, inside the checkout', () => {
    const EMPTIED = [['mage-messages', '']];

    test.each([
        // search, queued in the cookie, shown, cookie writes, description
        ['?two_declined=1', [{ type: 'error', text: DECLINE }], [DECLINE], EMPTIED, 'the decline message is shown and the queue emptied'],
        ['?two_declined=1', [{ type: 'error', text: 'older' }, { type: 'success', text: 'ok' }, { type: 'error', text: DECLINE }], [DECLINE], EMPTIED, 'only the newest error is shown; older ones are dropped with the queue'],
        ['?two_declined=1', [{ type: 'success', text: 'ok' }], [], EMPTIED, 'no queued error: nothing shown, queue still emptied'],
        ['?two_declined=1', null, [], EMPTIED, 'no cookie: nothing shown'],
        ['?two_declined=1', 'garbled', [], EMPTIED, 'an unreadable queue shows nothing'],
        ['', [{ type: 'error', text: DECLINE }], [], [], 'no marker: the queue is left for the page that reads it']
    ])('%s %#', (search, queued, shown, cookieWrites, description) => {
        const result = mount(search, false, queued);

        expect({ description, shown: result.shown }).toEqual({ description, shown });
        expect({ description, cookieWrites: result.cookieWrites }).toEqual({ description, cookieWrites });
    });
});
