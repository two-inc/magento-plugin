/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The term chips show gross the moment the store shows gross prices at
 * checkout. Both amounts arrive from the server on every surcharge response,
 * so switching mode never leaves a chip pairing one term's net with another
 * response's gross.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

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
 * Load the real surcharge model with the /surcharges request captured, so the
 * test can answer it with an arbitrary endpoint payload.
 */
function loadModel() {
    const mocks = defaultMocks();
    const captured = {};

    const $ = Object.assign(function () { return mocks.jquery.apply(null, arguments); }, mocks.jquery, {
        ajax: function (opts) {
            const chain = {
                done: function (cb) { captured[opts.type === 'POST' ? 'post' : 'get'] = cb; return chain; },
                fail: function () { return chain; },
                always: function () { return chain; }
            };
            return chain;
        }
    });

    const model = loadAmdModule('view/frontend/web/js/model/surcharge.js', {
        jquery: $,
        'Magento_Checkout/js/model/quote': Object.assign({}, mocks['Magento_Checkout/js/model/quote'], {
            getQuoteId: function () { return 42; },
            getTotals: function () {
                return observable({ grand_total: 1000, total_segments: [] });
            }
        })
    });

    return { model: model, captured: captured };
}

const RESPONSE = {
    term_surcharges: [
        { days: 30, net: 100, gross: 121 },
        { days: 60, net: 200, gross: 242 }
    ]
};

describe('surcharge model term previews', function () {
    it.each([
        ['excl', { 30: 100, 60: 200 }],
        ['incl', { 30: 121, 60: 242 }],
        ['both', { 30: 121, 60: 242 }]
    ])('renders the %s amounts the store asks for', function (mode, expected) {
        const { model, captured } = loadModel();

        captured.get(Object.assign({ tax_display: mode }, RESPONSE));

        expect(model.taxDisplay()).toBe(mode);
        expect(model.displayedTermSurcharges()).toEqual(expected);
    });

    it('keeps net available alongside gross regardless of mode', function () {
        const { model, captured } = loadModel();

        captured.get(Object.assign({ tax_display: 'incl' }, RESPONSE));

        expect(model.termSurcharges()).toEqual({ 30: 100, 60: 200 });
        expect(model.termSurchargesGross()).toEqual({ 30: 121, 60: 242 });
    });

    it('falls back to net when a response carries no gross', function () {
        const { model, captured } = loadModel();

        captured.get({ tax_display: 'incl', term_surcharges: [{ days: 30, net: 100 }] });

        expect(model.displayedTermSurcharges()).toEqual({ 30: 100 });
    });

    it('reads the selected term from the displayed map, not always net', function () {
        const { model, captured } = loadModel();

        captured.get(Object.assign({ tax_display: 'incl' }, RESPONSE));
        model.selectedTerm(60);

        expect(model.getAmount()).toBe(242);
    });

    it('applies the display mode returned by /select-term', function () {
        const { model, captured } = loadModel();

        captured.get(Object.assign({ tax_display: 'excl' }, RESPONSE));
        model.recalculateTotals(60);
        captured.post({
            term_surcharges: [{ days: 60, net: 200, gross: 242 }],
            tax_display: 'incl'
        });

        expect(model.taxDisplay()).toBe('incl');
        expect(model.displayedTermSurcharges()).toEqual({ 60: 242 });
    });
});
