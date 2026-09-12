/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * A payment term the server would not apply snaps the chips back to the term
 * the quote is priced on, and is said in the tile's own live region rather
 * than the page-level banner — even when re-rendering a chip throws while the
 * revert is being notified. Placement waits for the buyer either way
 * (ABN-550).
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

const REVERT_MESSAGE = 'Could not update payment term. Please try again.';

function observable(initial) {
    let value = initial;
    const fn = function (next) {
        if (arguments.length === 0) return value;
        value = next;
        return undefined;
    };
    fn.subscribe = function () {};
    return fn;
}

/**
 * Load the real surcharge model with the /select-term callbacks captured, so
 * the test can settle the call as a success or either kind of refusal.
 */
function loadModel() {
    const mocks = defaultMocks();
    const messages = [];
    const captured = {};

    const $ = Object.assign(function () { return mocks.jquery.apply(null, arguments); }, mocks.jquery, {
        ajax: function (opts) {
            const post = opts.type === 'POST';
            const chain = {
                done: function (cb) { if (post) captured.done = cb; return chain; },
                fail: function (cb) { if (post) captured.fail = cb; return chain; },
                always: function (cb) { if (post) captured.always = cb; return chain; }
            };
            return chain;
        }
    });

    const model = loadAmdModule('view/frontend/web/js/model/surcharge.js', {
        jquery: $,
        'Two_Gateway/js/model/brand-config': {
            getActiveTwoBrandConfig: function () { return { selectedPaymentTerm: 30 }; }
        },
        'Magento_Ui/js/model/messageList': {
            addErrorMessage: function (payload) { messages.push(payload.message); },
            addSuccessMessage: function () {}
        },
        'Magento_Checkout/js/model/quote': Object.assign({}, mocks['Magento_Checkout/js/model/quote'], {
            getQuoteId: function () { return 42; },
            getTotals: function () {
                return observable({ grand_total: 1000, total_segments: [{ code: 'grand_total', value: 1000 }] });
            },
            setTotals: function () {}
        })
    });

    return { model: model, messages: messages, captured: captured };
}

const SETTLED = {
    grand_total: 1100,
    base_grand_total: 1100,
    tax_amount: 0,
    total_segments: [{ code: 'grand_total', value: 1100 }],
    term_surcharges: [{ days: 30, net: 0, gross: 0 }, { days: 60, net: 100, gross: 121 }]
};

/** The /select-term answer that confirms nothing: no totals the term was collected on. */
const UNSETTLED = { grand_total: 1100, total_segments: [] };

describe('surcharge model term revert', function () {
    it.each([
        { outcome: 'done', answer: SETTLED, throwingChip: false, status: '', term: 60,
          description: 'an applied term keeps quiet and leaves the chips on it' },
        { outcome: 'fail', answer: null, throwingChip: false, status: REVERT_MESSAGE, term: 30,
          description: 'a refused call reverts the chips and says so' },
        { outcome: 'done', answer: UNSETTLED, throwingChip: false, status: REVERT_MESSAGE, term: 30,
          description: 'an answer confirming no totals reverts the chips and says so' },
        { outcome: 'fail', answer: null, throwingChip: true, status: REVERT_MESSAGE, term: 30,
          description: 'a chip throwing during the revert still leaves the buyer told' },
        { outcome: 'done', answer: UNSETTLED, throwingChip: true, status: REVERT_MESSAGE, term: 30,
          description: 'a chip throwing during an unconfirmed revert still leaves the buyer told' }
    ])('$description', function (testCase) {
        const { model, messages, captured } = loadModel();

        model.selectTerm(60);
        expect(model.selectedTerm()).toBe(60);

        // Subscribed only now, so it fires on the revert write rather than on
        // the click that opened the call.
        if (testCase.throwingChip) {
            model.selectedTerm.subscribe(function () {
                throw new Error('chip binding re-evaluated');
            });
        }

        if (testCase.outcome === 'done') {
            captured.done(testCase.answer);
        } else {
            captured.fail({}, 'error', 'Internal Server Error');
        }
        captured.always();

        expect(model.termStatusMessage()).toBe(testCase.status);
        // Nothing reaches the page-level banner above the payment tile.
        expect(messages).toEqual([]);
        expect(model.selectedTerm()).toBe(testCase.term);
        expect(model.isTermReconciled()).toBe(testCase.status === '');
    });
});
