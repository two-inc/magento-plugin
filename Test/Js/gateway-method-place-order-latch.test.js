/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Regression cover for TWO-24843: on Luma the Place Order button stayed
 * permanently greyed after a failed/blocked first attempt, and clicks on it
 * were silently swallowed, so checkout could not be recovered without a reload.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

/**
 * Minimal ko.observable stand-in with a settable value.
 */
function observable(initial) {
    let value = initial;
    return function (next) {
        if (arguments.length === 0) return value;
        value = next;
        return undefined;
    };
}

/**
 * jQuery-deferred stand-in whose settlement the test drives explicitly, so a
 * request can be left permanently in flight.
 */
function makeDeferred() {
    const doneCbs = [];
    const alwaysCbs = [];
    const d = {
        done: function (fn) {
            doneCbs.push(fn);
            return d;
        },
        fail: function () {
            return d;
        },
        always: function (fn) {
            alwaysCbs.push(fn);
            return d;
        },
        resolve: function () {
            doneCbs.forEach(function (fn) {
                fn();
            });
            alwaysCbs.forEach(function (fn) {
                fn();
            });
        },
        reject: function () {
            alwaysCbs.forEach(function (fn) {
                fn();
            });
        }
    };
    return d;
}

/**
 * Load the renderer with a quote whose shipping/virtual state the test picks.
 * Each load gets its own module instance, and therefore its own in-flight flag.
 */
function loadComponent(quoteState) {
    const quoteMock = defaultMocks()['Magento_Checkout/js/model/quote'];
    return loadAmdModule('view/frontend/web/js/view/payment/method-renderer/gateway_method.js', {
        'Magento_Checkout/js/model/quote': Object.assign({}, quoteMock, {
            shippingMethod: observable(
                'shippingMethod' in quoteState
                    ? quoteState.shippingMethod
                    : { carrier_code: 'free' }
            ),
            isVirtual: function () {
                return !!quoteState.isVirtual;
            }
        })
    });
}

/**
 * Build the `this` context placeOrder runs against, reusing the component's own
 * placeOrderBackend and showErrorMessage so the real code paths are exercised.
 */
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
            }
        },
        isPaymentTermsEnabled: true,
        isPaymentTermsAccepted: observable(true),
        isPlaceOrderActionAllowed: observable('allowed' in opts ? opts.allowed : true),
        isInvoiceEmailsEnabled: false,
        redirectAfterPlaceOrder: false,
        validate: function () {
            return true;
        },
        afterPlaceOrder: function () {},
        showErrorMessage: component.showErrorMessage,
        placeOrder: component.placeOrder,
        placeOrderBackend: component.placeOrderBackend,
        getPlaceOrderDeferredObject: function () {
            ctx.placeOrderCalls++;
            if (opts.throwSynchronously) {
                throw new Error('boom');
            }
            ctx.deferred = makeDeferred();
            return ctx.deferred;
        }
    };
    return ctx;
}

describe('gateway_method place-order latch (TWO-24843)', () => {
    test('refuses to post when a non-virtual quote has no shipping method', () => {
        const component = loadComponent({ shippingMethod: null });
        const ctx = makeContext(component, {});

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(0);
        expect(ctx.errors.join(' ')).toMatch(/shipping method is missing/i);
        // The blocked attempt must not latch the button either.
        expect(ctx.isPlaceOrderActionAllowed()).toBe(true);
    });

    test('still places a virtual quote, which legitimately has no shipping method', () => {
        const component = loadComponent({ shippingMethod: null, isVirtual: true });
        const ctx = makeContext(component, {});

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors).toEqual([]);
    });

    test('re-arms the button after a failed placement', () => {
        const component = loadComponent({});
        const ctx = makeContext(component, {});

        ctx.placeOrder.call(ctx);
        expect(ctx.isPlaceOrderActionAllowed()).toBe(false);

        ctx.deferred.reject();
        expect(ctx.isPlaceOrderActionAllowed()).toBe(true);
    });

    test('recovers a latch left set while no request is in flight', () => {
        // The state core's quote.billingAddress subscription leaves behind when
        // the billing address is momentarily null: latched false, nothing in
        // flight, and no writer that will ever set it back to true. Before the
        // fix this click was swallowed in silence.
        const component = loadComponent({});
        const ctx = makeContext(component, { allowed: false });

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors).toEqual([]);
    });

    test('does not double-submit, and says why, while a placement is in flight', () => {
        const component = loadComponent({});
        const ctx = makeContext(component, {});

        ctx.placeOrder.call(ctx);
        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.isPlaceOrderActionAllowed()).toBe(false);

        // Second click with the first request still unsettled.
        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors.join(' ')).toMatch(/already being placed/i);
    });

    test('clears the latch when getPlaceOrderDeferredObject throws synchronously', () => {
        const component = loadComponent({});
        const ctx = makeContext(component, { throwSynchronously: true });

        expect(() => ctx.placeOrder.call(ctx)).toThrow('boom');

        // No .always() was ever attached, so without the try/catch the button
        // would stay dead for the life of the page.
        expect(ctx.isPlaceOrderActionAllowed()).toBe(true);

        // And the next click still works.
        const ctx2 = makeContext(component, { allowed: false });
        ctx2.placeOrder.call(ctx2);
        expect(ctx2.placeOrderCalls).toBe(1);
    });
});
