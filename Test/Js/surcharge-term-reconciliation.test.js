/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-550: the order is composed on the term the chips show as selected, so a
 * selection the server has not confirmed it priced the quote on can be charged
 * against a total the summary never showed. Placement is refused until the two
 * agree, and a /select-term the server did not take puts the chips back.
 */

'use strict';

const { loadAmdModule, defaultMocks, brandConfigMock } = require('./amd-harness');

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

const FEES = {
    term_surcharges: [
        { days: 30, net: 100, gross: 121 },
        { days: 60, net: 150, gross: 181.5 },
        { days: 90, net: 200, gross: 242 }
    ],
    tax_display: 'excl'
};

/** The /select-term answer for a term, carrying the segments it re-collected. */
function settledResponse(net) {
    return {
        grand_total: 1000 + net,
        total_segments: [{ code: 'two_surcharge', title: 'fee', value: net }],
        term_surcharges: FEES.term_surcharges,
        tax_display: 'excl'
    };
}

/**
 * The real surcharge model over captured /surcharges and /select-term calls.
 * Each POST's callbacks are kept separately, so a spec can settle two chip
 * clicks in whichever order it wants.
 */
function loadModel() {
    const mocks = defaultMocks();
    const posts = [];
    const captured = { errors: [] };
    const totalsObservable = observable({ grand_total: 1000, total_segments: [] });

    const $ = Object.assign(function () { return mocks.jquery.apply(null, arguments); }, mocks.jquery, {
        ajax: function (opts) {
            const bound = {};
            const chain = {
                done: function (cb) { bound.done = cb; return chain; },
                fail: function (cb) { bound.fail = cb; return chain; },
                always: function (cb) { bound.always = cb; return chain; }
            };
            if (opts.type === 'POST') {
                posts.push(bound);
            } else {
                captured.get = function (data) { bound.done(data); };
            }
            return chain;
        }
    });

    const model = loadAmdModule('view/frontend/web/js/model/surcharge.js', {
        jquery: $,
        'Magento_Checkout/js/model/quote': Object.assign({}, mocks['Magento_Checkout/js/model/quote'], {
            getQuoteId: function () { return 42; },
            getTotals: function () { return totalsObservable; },
            setTotals: function (next) { totalsObservable(next); }
        }),
        'Magento_Ui/js/model/messageList': {
            addErrorMessage: function (m) { captured.errors.push(m.message); }
        },
        // The term the page was rendered for, which is also the term the server
        // has already priced the summary on — nothing else may write the
        // selection, or the confirmed term it is compared against desyncs.
        'Two_Gateway/js/model/brand-config': brandConfigMock({ selectedPaymentTerm: 30, currencySymbol: '\u20ac' })
    });

    return { model: model, posts: posts, captured: captured, totals: totalsObservable };
}

/** Settle one captured POST the way the spec asks for. */
function settle(ctx, index, outcome, net) {
    const post = ctx.posts[index];
    if (outcome === 'failed') {
        post.fail({}, 'error', 'Internal Server Error');
    } else if (outcome === 'empty') {
        // A 200 the server answered without the totals it re-collected.
        post.done({ term_surcharges: FEES.term_surcharges });
    } else {
        post.done(settledResponse(net));
    }
    post.always();
}

/** The surcharge value the order summary is showing. */
function shownSurcharge(ctx) {
    const segment = (ctx.totals().total_segments || []).find(function (s) {
        return s.code === 'two_surcharge';
    });
    return segment ? segment.value : null;
}

describe('surcharge model confirmed-term reconciliation (ABN-550)', function () {
    it.each([
        ['an untouched checkout is reconciled: the server rendered the summary', 'none', null, true],
        ['a chip click in flight is not reconciled — nothing has confirmed it', 'pending', null, false],
        ['a confirmed chip click is reconciled', 'settled', 200, true],
        ['a refused chip click reverts, so the chips and the quote agree again', 'failed', null, true],
        ['a 200 carrying no totals reverts as well — nothing confirmed the term', 'empty', null, true]
    ])('%s', function (because, outcome, net, expected) {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        if (outcome !== 'none') {
            ctx.model.selectTerm(90);
            if (outcome !== 'pending') settle(ctx, 0, outcome, net);
        }

        expect(ctx.model.isTermReconciled()).toBe(expected);
    });

    it.each([
        ['a refused chip click', 'failed'],
        ['a 200 that carried no re-collected totals', 'empty']
    ])('%s puts the chips back on the confirmed term and says so', function (because, outcome) {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        ctx.model.selectTerm(90);
        settle(ctx, 0, outcome);

        expect(ctx.model.selectedTerm()).toBe(30);
        expect(ctx.model.isTermReconciled()).toBe(true);
        expect(ctx.captured.errors).toEqual(['Could not update payment term. Please try again.']);
    });

    it('a second click while the first is in flight reverts to the last CONFIRMED term', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        ctx.model.selectTerm(90);
        ctx.model.selectTerm(60);
        // The superseded call answers first and must change nothing.
        settle(ctx, 0, 'settled', 200);
        expect(ctx.model.isTermReconciled()).toBe(false);

        settle(ctx, 1, 'failed');

        expect(ctx.model.selectedTerm()).toBe(30);
        expect(ctx.model.isTermReconciled()).toBe(true);
    });

    it('a superseded response never writes its own term into the summary', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        ctx.model.selectTerm(90);
        ctx.model.selectTerm(60);
        settle(ctx, 1, 'settled', 150);
        // 90's answer lands late; applying it would show a term nobody selected.
        settle(ctx, 0, 'settled', 200);

        expect(shownSurcharge(ctx)).toBe(150);
        expect(ctx.model.isTermReconciled()).toBe(true);
    });
});

/**
 * The renderer over a surcharge model whose reconciliation verdict the spec
 * picks, so the submit gate and the button binding are exercised on their own.
 */
function loadRenderer(reconciled) {
    const surchargeMock = defaultMocks()['Two_Gateway/js/model/surcharge'];
    return loadAmdModule('view/frontend/web/js/view/payment/method-renderer/gateway_method.js', {
        'Two_Gateway/js/model/surcharge': Object.assign({}, surchargeMock, {
            termSurcharges: observable({ 30: '1.00', 90: '2.00' }),
            isTermReconciled: function () { return reconciled; }
        })
    });
}

function makeRendererContext(component) {
    const errors = [];
    const ctx = {
        errors: errors,
        placeOrderCalls: 0,
        messageContainer: {
            clear: function () { errors.length = 0; },
            addErrorMessage: function (m) { errors.push(m.message); },
            errorMessages: { remove: function () {} }
        },
        availableBuyerTerms: [30, 90],
        selectedTerm: observable(90),
        termUnavailableMessage: 'Terms gone. Reselect.',
        isPaymentTermsEnabled: false,
        isPaymentTermsAccepted: observable(true),
        isPlaceOrderActionAllowed: observable(true),
        isCompanyCaptured: function () { return true; },
        isInvoiceEmailsEnabled: false,
        redirectAfterPlaceOrder: false,
        validate: function () { return true; },
        afterPlaceOrder: function () {},
        getCode: function () { return 'two_payment'; },
        isChecked: function () { return 'two_payment'; },
        showErrorMessage: component.showErrorMessage,
        isSelectedTermStillAvailable: component.isSelectedTermStillAvailable,
        isTermReconciled: component.isTermReconciled,
        isOrderIntentDeclined: component.isOrderIntentDeclined,
        isPlaceOrderEnabled: component.isPlaceOrderEnabled,
        placeOrder: component.placeOrder,
        placeOrderBackend: component.placeOrderBackend,
        getPlaceOrderDeferredObject: function () {
            ctx.placeOrderCalls++;
            const d = { done: function () { return d; }, fail: function () { return d; }, always: function () { return d; } };
            return d;
        }
    };
    return ctx;
}

describe('gateway_method reconciliation submit gate (ABN-550)', function () {
    it.each([
        ['a confirmed selection places the order and leaves the button enabled', true, 1, [], true],
        [
            'an unconfirmed selection is refused rather than charged a total the summary never showed',
            false,
            0,
            ['The selected payment term is still being applied. Please try again shortly.'],
            false
        ]
    ])('%s', function (because, reconciled, expectedCalls, expectedErrors, expectedEnabled) {
        const component = loadRenderer(reconciled);
        const ctx = makeRendererContext(component);

        expect(ctx.isPlaceOrderEnabled.call(ctx)).toBe(expectedEnabled);

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(expectedCalls);
        expect(ctx.errors).toEqual(expectedErrors);
    });
});
