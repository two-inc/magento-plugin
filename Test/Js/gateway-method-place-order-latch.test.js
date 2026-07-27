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
 *
 * Callbacks are kept in one registration-ordered list, and a throw from any of
 * them propagates and abandons the rest — which is what jQuery does (`always`
 * is `.done(fn).fail(fn)`, appending to the same callback list, and
 * Callbacks.fireWith invokes them synchronously). Registration order is
 * therefore load-bearing behaviour, not an implementation detail, and the
 * stand-in has to reproduce it for the .always()-before-.done() cover below to
 * mean anything.
 */
function makeDeferred() {
    const cbs = [];
    function fire(kinds) {
        cbs.forEach(function (entry) {
            if (kinds.indexOf(entry.kind) !== -1) entry.fn();
        });
    }
    const d = {
        done: function (fn) {
            cbs.push({ kind: 'done', fn: fn });
            return d;
        },
        fail: function (fn) {
            cbs.push({ kind: 'fail', fn: fn || function () {} });
            return d;
        },
        always: function (fn) {
            cbs.push({ kind: 'always', fn: fn });
            return d;
        },
        resolve: function () {
            fire(['done', 'always']);
        },
        reject: function () {
            fire(['fail', 'always']);
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
            },
            errorMessages: {
                remove: function (predicate) {
                    for (let i = errors.length - 1; i >= 0; i--) {
                        if (predicate(errors[i])) errors.splice(i, 1);
                    }
                }
            }
        },
        isPaymentTermsEnabled: 'termsEnabled' in opts ? opts.termsEnabled : true,
        isPaymentTermsAccepted: observable('termsAccepted' in opts ? opts.termsAccepted : true),
        isPlaceOrderActionAllowed: observable('allowed' in opts ? opts.allowed : true),
        isInvoiceEmailsEnabled: false,
        redirectAfterPlaceOrder: false,
        validate: function () {
            return true;
        },
        afterPlaceOrder: function () {
            ctx.afterPlaceOrderCalls++;
            if (opts.afterPlaceOrderThrows) {
                throw new Error('afterPlaceOrder boom');
            }
        },
        afterPlaceOrderCalls: 0,
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

describe('gateway_method renderer defects (TWO-25174)', () => {
    test('places the order when payment terms are disabled and unaccepted', () => {
        // No checkbox renders when the feature is off, so nothing ever writes the
        // observable. Requiring acceptance anyway made the button silently dead.
        const component = loadComponent({});
        const ctx = makeContext(component, { termsEnabled: false, termsAccepted: false });

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors).toEqual([]);
    });

    test('still refuses, with a message, when terms are enabled and unaccepted', () => {
        const component = loadComponent({});
        const ctx = makeContext(component, { termsEnabled: true, termsAccepted: false });
        ctx.processTermsNotAcceptedErrorResponse = component.processTermsNotAcceptedErrorResponse;
        ctx.termsNotAcceptedMessage = 'Please accept the payment terms';

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(0);
        expect(ctx.errors).toEqual(['Please accept the payment terms']);
    });

    test('two rapid clicks still yield exactly one place-order request', () => {
        // The guarantee PR #262 established, re-asserted after re-keying the
        // in-flight check off placeOrderInFlight.
        const component = loadComponent({});
        const ctx = makeContext(component, {});

        ctx.placeOrder.call(ctx);
        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors.join(' ')).toMatch(/already being placed/i);
    });

    test('refuses a second click even if the shared latch is re-armed mid-request', () => {
        // isPlaceOrderActionAllowed is prototype-shared and core's
        // quote.billingAddress subscription can set it true while our request is
        // still running. Keying the guard on that observable let the second click
        // through to a second order-create POST; keying it on placeOrderInFlight
        // does not.
        const component = loadComponent({});
        const ctx = makeContext(component, {});

        ctx.placeOrder.call(ctx);
        expect(ctx.placeOrderCalls).toBe(1);

        ctx.isPlaceOrderActionAllowed(true); // core re-arms behind our back
        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors.join(' ')).toMatch(/already being placed/i);
    });

    test('clears the latch even when afterPlaceOrder() throws on success', () => {
        const component = loadComponent({});
        const ctx = makeContext(component, { afterPlaceOrderThrows: true });

        ctx.placeOrder.call(ctx);
        expect(ctx.isPlaceOrderActionAllowed()).toBe(false);

        expect(() => ctx.deferred.resolve()).toThrow('afterPlaceOrder boom');

        // .always() ran first, so both the observable and the module-scope
        // in-flight flag are clear despite the throw...
        expect(ctx.afterPlaceOrderCalls).toBe(1);
        expect(ctx.isPlaceOrderActionAllowed()).toBe(true);

        // ...and the next click is accepted rather than swallowed.
        const ctx2 = makeContext(component, {});
        ctx2.placeOrder.call(ctx2);
        expect(ctx2.placeOrderCalls).toBe(1);
        expect(ctx2.errors).toEqual([]);
    });

    test('renders the invalid-email message exactly once per rejected click', () => {
        // validateEmails() both validates and displays; placeOrder() used to
        // display the same message again on the false return, so the buyer saw
        // the error twice — once auto-dismissing, once sticky.
        jest.useFakeTimers();
        try {
            const component = loadComponent({});
            const ctx = makeContext(component, {});
            ctx.isInvoiceEmailsEnabled = true;
            ctx.invoiceEmails = observable('not-an-email');
            ctx.invalidEmailListMessage = 'One or more emails are invalid';
            ctx.validateEmails = component.validateEmails;

            ctx.placeOrder.call(ctx);

            expect(ctx.placeOrderCalls).toBe(0);
            expect(ctx.errors).toEqual(['One or more emails are invalid']);

            // And the single message is the auto-dismissing one, so nothing is
            // left stuck on screen after the buyer fixes the address.
            jest.advanceTimersByTime(3000);
            expect(ctx.errors).toEqual([]);
        } finally {
            jest.useRealTimers();
        }
    });

    test('places the order when the forward-email list is valid', () => {
        const component = loadComponent({});
        const ctx = makeContext(component, {});
        ctx.isInvoiceEmailsEnabled = true;
        ctx.invoiceEmails = observable('a@b.com, c@d.com');
        ctx.invalidEmailListMessage = 'One or more emails are invalid';
        ctx.validateEmails = component.validateEmails;

        ctx.placeOrder.call(ctx);

        expect(ctx.placeOrderCalls).toBe(1);
        expect(ctx.errors).toEqual([]);
    });

    test('showErrorMessage auto-dismisses after the requested duration', () => {
        // Two definitions of showErrorMessage existed in the same object literal;
        // the later, duration-less one won, so the auto-dismiss was dead code and
        // validateEmails' timeout never fired.
        jest.useFakeTimers();
        try {
            const component = loadComponent({});
            const messages = [];
            const ctx = {
                messageContainer: {
                    addErrorMessage: function (m) {
                        messages.push(m.message);
                    },
                    errorMessages: {
                        remove: function (predicate) {
                            for (let i = messages.length - 1; i >= 0; i--) {
                                if (predicate(messages[i])) messages.splice(i, 1);
                            }
                        }
                    }
                }
            };

            component.showErrorMessage.call(ctx, 'transient', 3000);
            expect(messages).toEqual(['transient']);

            jest.advanceTimersByTime(2999);
            expect(messages).toEqual(['transient']);

            jest.advanceTimersByTime(1);
            expect(messages).toEqual([]);
        } finally {
            jest.useRealTimers();
        }
    });

    test('showErrorMessage without a duration leaves the message in place', () => {
        jest.useFakeTimers();
        try {
            const component = loadComponent({});
            const messages = [];
            const ctx = {
                messageContainer: {
                    addErrorMessage: function (m) {
                        messages.push(m.message);
                    },
                    errorMessages: {
                        remove: function () {
                            throw new Error('must not be called');
                        }
                    }
                }
            };

            component.showErrorMessage.call(ctx, 'sticky');
            jest.advanceTimersByTime(60000);
            expect(messages).toEqual(['sticky']);
        } finally {
            jest.useRealTimers();
        }
    });
});
