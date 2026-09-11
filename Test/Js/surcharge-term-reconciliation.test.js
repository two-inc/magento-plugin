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
    const subscribers = [];
    const fn = function (next) {
        if (arguments.length === 0) return value;
        value = next;
        subscribers.forEach(function (cb) { cb(next); });
        return undefined;
    };
    fn.subscribe = function (cb) { subscribers.push(cb); };
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
    const captured = { errors: [], getCalls: 0 };
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
                captured.getCalls++;
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
    } else if (outcome === 'blank') {
        post.done({ grand_total: 1000, total_segments: [], term_surcharges: FEES.term_surcharges });
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
        ['none', null, true, 'an untouched checkout is reconciled: the server rendered the summary'],
        ['pending', null, false, 'a chip click in flight is not reconciled — nothing has confirmed it'],
        ['settled', 200, true, 'a confirmed chip click is reconciled'],
        ['failed', null, true, 'a refused chip click reverts, so the chips and the quote agree again'],
        ['empty', null, true, 'a 200 carrying no totals reverts — nothing confirmed the term'],
        ['blank', null, true, 'a 200 carrying an empty segment set reverts too']
    ])('%s with net %p is reconciled=%p (%s)', function (outcome, net, expected) {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        if (outcome !== 'none') {
            ctx.model.selectTerm(90);
            if (outcome !== 'pending') settle(ctx, 0, outcome, net);
        }

        expect(ctx.model.isTermReconciled()).toBe(expected);
    });

    it.each([
        ['failed', 'a refused chip click'],
        ['empty', 'a 200 that carried no re-collected totals'],
        ['blank', 'a 200 whose segment set was empty, which would blank the summary']
    ])('puts the chips back on the confirmed term and says so: %s (%s)', function (outcome, because) {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        ctx.model.selectTerm(90);
        settle(ctx, 0, outcome);

        expect(ctx.model.selectedTerm()).toBe(30);
        expect(ctx.model.isTermReconciled()).toBe(true);
        expect(ctx.captured.errors).toEqual(['Could not update payment term. Please try again.']);
    });

    it('a chip clicked while a call is in flight is sent only once it settles', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        ctx.model.selectTerm(90);
        ctx.model.selectTerm(60);

        expect(ctx.posts).toHaveLength(1);

        settle(ctx, 0, 'settled', 200);

        expect(ctx.posts).toHaveLength(2);
        expect(ctx.model.isTermReconciled()).toBe(false);

        settle(ctx, 1, 'settled', 150);

        expect(ctx.model.selectedTerm()).toBe(60);
        expect(shownSurcharge(ctx)).toBe(150);
        expect(ctx.model.isTermReconciled()).toBe(true);
    });

    it('a queued chip is dropped when the call in flight is refused', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        ctx.model.selectTerm(90);
        ctx.model.selectTerm(60);
        settle(ctx, 0, 'failed');

        expect(ctx.posts).toHaveLength(1);
        expect(ctx.model.selectedTerm()).toBe(30);
        expect(ctx.model.isTermReconciled()).toBe(true);
    });

    it('a chip clicked back to the term in flight sends nothing more', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        ctx.model.selectTerm(90);
        ctx.model.selectTerm(60);
        ctx.model.selectTerm(90);

        settle(ctx, 0, 'settled', 200);

        expect(ctx.posts).toHaveLength(1);
        expect(ctx.model.selectedTerm()).toBe(90);
        expect(ctx.model.isTermReconciled()).toBe(true);
    });

    it('a totals subscriber throwing leaves the term confirmed and the fetch usable', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        let thrown = false;
        ctx.totals.subscribe(function () {
            if (thrown) return;
            thrown = true;
            throw new Error('a third-party summary subscriber');
        });
        ctx.model.selectTerm(90);

        settle(ctx, 0, 'settled', 200);

        expect(ctx.model.isUpdating()).toBe(false);
        expect(ctx.model.selectedTerm()).toBe(90);
        expect(ctx.model.isTermReconciled()).toBe(true);

        // The self-emission flag is released, so a later totals change is still
        // a change this model reacts to.
        const feeCallsBefore = ctx.captured.getCalls;
        ctx.totals({ grand_total: 1400, total_segments: [{ code: 'shipping', title: 'ship', value: 400 }] });

        expect(ctx.captured.getCalls).toBe(feeCallsBefore + 1);
    });

    it('a settled chip click does not refetch the fees it just received', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        const feeCallsBefore = ctx.captured.getCalls;
        ctx.model.selectTerm(90);

        settle(ctx, 0, 'settled', 200);

        expect(ctx.captured.getCalls).toBe(feeCallsBefore);
    });

    it('a chip binding throwing on the updating flag does not strand the queue', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        let thrown = false;
        ctx.model.isUpdating.subscribe(function (updating) {
            if (updating || thrown) return;
            thrown = true;
            throw new Error('a chip binding');
        });
        ctx.model.selectTerm(90);
        ctx.model.selectTerm(60);

        settle(ctx, 0, 'settled', 200);

        expect(thrown).toBe(true);
        expect(ctx.posts).toHaveLength(2);
    });

    it('a totals change dropped during a chip click is re-evaluated after it', function () {
        const ctx = loadModel();
        ctx.captured.get(FEES);
        ctx.model.selectTerm(90);
        // Shipping settles mid-click: the subscriber cannot refetch yet.
        ctx.totals({ grand_total: 1400, total_segments: [{ code: 'shipping', title: 'ship', value: 400 }] });
        const feeCallsBefore = ctx.captured.getCalls;

        settle(ctx, 0, 'settled', 200);

        expect(ctx.captured.getCalls).toBe(feeCallsBefore + 1);
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

describe('the chips say why the button is disabled (ABN-550)', function () {
    it.each([
        [true, 'a call in flight says so, since the disabled button cannot answer a click'],
        [false, 'a settled checkout says nothing']
    ])('updating=%p (%s)', function (updating) {
        const surchargeMock = defaultMocks()['Two_Gateway/js/model/surcharge'];
        const component = loadAmdModule(
            'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
            {
                'Two_Gateway/js/model/surcharge': Object.assign({}, surchargeMock, {
                    isUpdating: function () { return updating; }
                })
            }
        );

        expect(component.isTermUpdating.call(component)).toBe(updating);
    });

    it('the template shows the status only while a call is in flight', function () {
        const template = require('fs').readFileSync(
            require('path').resolve(__dirname, '..', '..', 'view/frontend/web/template/payment/gateway_method.html'),
            'utf8'
        );

        expect(template).toContain('isTermUpdating()');
        expect(template).toContain('Applying the selected payment term…');
    });
});

describe('gateway_method reconciliation submit gate (ABN-550)', function () {
    it.each([
        [true, 1, [], true, 'a confirmed selection places the order and leaves the button enabled'],
        [
            false,
            0,
            ['The selected payment term is still being applied. Please try again shortly.'],
            false,
            'an unconfirmed selection is refused rather than charged a total the summary never showed'
        ]
    ])('reconciled=%p -> %p placements, %p errors, enabled=%p (%s)', function (reconciled, expectedCalls, expectedErrors, expectedEnabled) {
        const component = loadRenderer(reconciled);
        const ctx = makeRendererContext(component);

        expect(ctx.isPlaceOrderEnabled.call(ctx)).toBe(expectedEnabled);

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(expectedCalls);
        expect(ctx.errors).toEqual(expectedErrors);
    });
});
