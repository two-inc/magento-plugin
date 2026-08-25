/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503: the term selected at render time is cross-checked against the
 * live term set immediately before submit, so a term withdrawn mid-checkout
 * is refused client-side instead of posted and rejected by the API.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

function observable(initial) {
    let value = initial;
    return function (next) {
        if (arguments.length === 0) return value;
        value = next;
        return undefined;
    };
}

function makeDeferred() {
    const d = {
        done: function () { return d; },
        fail: function () { return d; },
        always: function () { return d; }
    };
    return d;
}

/**
 * Load the renderer against a surcharge model whose live term map the spec
 * picks. Each load gets its own module instance.
 */
function loadComponent(liveTermSurcharges) {
    const surchargeMock = defaultMocks()['Two_Gateway/js/model/surcharge'];
    return loadAmdModule('view/frontend/web/js/view/payment/method-renderer/gateway_method.js', {
        'Two_Gateway/js/model/surcharge': Object.assign({}, surchargeMock, {
            termSurcharges: observable(liveTermSurcharges)
        })
    });
}

function makeContext(component, opts) {
    const errors = [];
    const ctx = {
        errors: errors,
        placeOrderCalls: 0,
        messageContainer: {
            clear: function () {
                errors.length = 0;
            },
            addErrorMessage: function (m) {
                errors.push(m.message);
            },
            errorMessages: { remove: function () {} }
        },
        availableBuyerTerms: 'availableBuyerTerms' in opts ? opts.availableBuyerTerms : [14, 30, 60],
        selectedTerm: observable('selectedTerm' in opts ? opts.selectedTerm : 30),
        termUnavailableMessage: 'Terms gone. Reselect.',
        isPaymentTermsEnabled: false,
        isPaymentTermsAccepted: observable(true),
        isPlaceOrderActionAllowed: observable(true),
        isCompanyCaptured: function () {
            return true;
        },
        isInvoiceEmailsEnabled: false,
        redirectAfterPlaceOrder: false,
        validate: function () {
            return true;
        },
        afterPlaceOrder: function () {},
        showErrorMessage: component.showErrorMessage,
        isSelectedTermStillAvailable: component.isSelectedTermStillAvailable,
        placeOrder: component.placeOrder,
        placeOrderBackend: component.placeOrderBackend,
        getPlaceOrderDeferredObject: function () {
            ctx.placeOrderCalls++;
            return makeDeferred();
        }
    };
    return ctx;
}

describe('gateway_method term-still-available submit gate (TWO-25503)', () => {
    test('blocks submit and asks the buyer to reselect when the term is gone', () => {
        // Given: 30 was selected at render, the live set now offers 14 and 60
        const component = loadComponent({ 14: '1.00', 60: '3.00' });
        const ctx = makeContext(component, { selectedTerm: 30 });

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(0);
        expect(ctx.errors).toEqual(['Terms gone. Reselect.']);
        // Blocked, not latched — the buyer must be able to reselect and retry.
        expect(ctx.isPlaceOrderActionAllowed()).toBe(true);
    });

    test('places the order when the selected term is still offered', () => {
        const component = loadComponent({ 14: '1.00', 30: '2.00', 60: '3.00' });
        const ctx = makeContext(component, { selectedTerm: 30 });

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors).toEqual([]);
    });

    test('an empty live map is the loading state, not a withdrawn term', () => {
        const component = loadComponent({});
        const ctx = makeContext(component, { selectedTerm: 30 });

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors).toEqual([]);
    });

    test('a quote with no term selected is left alone', () => {
        const component = loadComponent({ 14: '1.00' });
        const ctx = makeContext(component, { selectedTerm: 0 });

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors).toEqual([]);
    });

    test('a checkout offering no buyer terms is left alone', () => {
        const component = loadComponent({ 14: '1.00' });
        const ctx = makeContext(component, { availableBuyerTerms: [], selectedTerm: 30 });

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors).toEqual([]);
    });

    test('matches the live key as a string, so a numeric term is not a miss', () => {
        // Regression guard: /surcharges returns term_surcharges keyed by day
        // count, which lands in the map as a string key.
        const component = loadComponent({ '30': '2.00' });
        const ctx = makeContext(component, { selectedTerm: 30 });

        expect(ctx.isSelectedTermStillAvailable.call(ctx)).toBe(true);
    });
});
