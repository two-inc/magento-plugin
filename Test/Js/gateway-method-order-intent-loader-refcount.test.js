/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326: on Amasty OneStepCheckout and Fire Checkout, the full-screen
 * loader shown while an order_intent request is in flight was dismissed
 * BEFORE that request actually resolved — unlike Luma, where it correctly
 * stays up for the whole round trip.
 *
 * Root cause: fillCustomerData() re-runs on every fresh instance's init
 * (registeredOrganisationMode() → initObservable()) and re-applies the
 * customer-data section's already-captured company via applyCompanyData()
 * → fillCompanyData(). Amasty rebuilds the payment method list, and Fire
 * Checkout re-renders this payment renderer outright, on every
 * shipping/totals change — so a re-render mid-flight spins up a FRESH
 * renderer instance whose own `_orderIntentInFlightFor` guard is unset,
 * and it independently fires its OWN order_intent POST for the SAME
 * company while the orphaned previous instance's request is still
 * outstanding. With a plain per-instance startLoader()/stopLoader() pair,
 * whichever request settles FIRST (typically the orphaned one, since it
 * started earlier) hid the shared full-screen loader while the surviving
 * instance's own request — the one whose resolution actually updates the
 * currently-rendered notice text — was still pending.
 *
 * Luma never re-renders this component on totals/shipping changes, so it
 * only ever has one order-intent request in flight at a time and never
 * hit this.
 *
 * Fixed by moving the loader start/stop pairing to a module-scope
 * reference count (mirroring the existing `placeOrderInFlight`
 * module-scope pattern in this same file), so the loader only hides once
 * EVERY outstanding order-intent request — across every instance sharing
 * this module — has settled.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

/** Plain (non-ko) observable factory matching the sibling TWO-25347 specs. */
function plainObservable(initial) {
    let v = initial;
    const fn = function (next) {
        if (!arguments.length) return v;
        v = next;
        return fn;
    };
    return fn;
}

describe('order-intent full-screen loader survives a mid-flight re-render (TWO-25326)', () => {
    test('the loader stays up until BOTH a re-render\'s new request AND the orphaned old one settle', () => {
        const seq = [];
        const loader = {
            startLoader: function () {
                seq.push('startLoader');
            },
            stopLoader: function () {
                seq.push('stopLoader');
            }
        };

        // A single require of the module — exactly one page load, one
        // RequireJS module instance, one module-scope refcount shared by
        // every renderer instance built from it, matching real behaviour.
        const component = loadAmdModule(RENDERER, {
            'Magento_Checkout/js/model/full-screen-loader': loader
        });

        const deferreds = [];
        function makeDeferred() {
            const d = {
                always: function (fn) {
                    d._always = fn;
                    return d;
                },
                done: function (fn) {
                    d._done = fn;
                    return d;
                },
                fail: function () {
                    return d;
                }
            };
            deferreds.push(d);
            return d;
        }

        function makeCtx() {
            return Object.assign({}, component, {
                companyName: plainObservable(''),
                companyId: plainObservable(''),
                orderIntentApprovedNotice: plainObservable(''),
                orderIntentDeclinedNotice: plainObservable(''),
                resolveOrderIntentApprovedNotice: function () {
                    return 'approved';
                },
                resolveOrderIntentDeclinedNotice: function () {
                    return 'declined';
                },
                isOrderIntentEnabled: true,
                placeOrderIntent: function () {
                    return makeDeferred();
                }
            });
        }

        // Instance A (e.g. the pre-re-render renderer) captures a company
        // and fires its order-intent request.
        const instanceA = makeCtx();
        instanceA.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });
        expect(seq).toEqual(['startLoader']);

        // Amasty/Fire re-render mid-flight: a FRESH instance B is created
        // and its own init re-applies the SAME captured company, firing a
        // second, independent order-intent request while A's is still
        // outstanding.
        const instanceB = makeCtx();
        instanceB.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });
        expect(seq).toEqual(['startLoader', 'startLoader']);

        // A's orphaned request settles first — this must NOT hide the
        // loader while B's (the currently-rendered instance's) request is
        // still pending.
        deferreds[0]._always();
        deferreds[0]._done({ approved: true });
        expect(seq).toEqual(['startLoader', 'startLoader']);

        // B's request — the one that actually matters, since B is the
        // live rendered instance — finally settles. Only now must the
        // loader come down.
        deferreds[1]._always();
        deferreds[1]._done({ approved: true });
        expect(seq).toEqual(['startLoader', 'startLoader', 'stopLoader']);
    });

    test('Luma\'s single-instance case is unaffected: one request, one start, one stop', () => {
        const seq = [];
        const loader = {
            startLoader: function () {
                seq.push('startLoader');
            },
            stopLoader: function () {
                seq.push('stopLoader');
            }
        };
        const component = loadAmdModule(RENDERER, {
            'Magento_Checkout/js/model/full-screen-loader': loader
        });

        let resolveDeferred;
        const ctx = Object.assign({}, component, {
            companyName: plainObservable(''),
            companyId: plainObservable(''),
            isOrderIntentEnabled: true,
            placeOrderIntent: function () {
                return {
                    always: function (fn) {
                        resolveDeferred = fn;
                        return this;
                    },
                    done: function () {
                        return this;
                    },
                    fail: function () {
                        return this;
                    }
                };
            }
        });

        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });
        expect(seq).toEqual(['startLoader']);

        resolveDeferred();
        expect(seq).toEqual(['startLoader', 'stopLoader']);
    });

    test('a synchronous throw from placeOrderIntent() still decrements the shared refcount (does not leak it stuck-open)', () => {
        const seq = [];
        const loader = {
            startLoader: function () {
                seq.push('startLoader');
            },
            stopLoader: function () {
                seq.push('stopLoader');
            }
        };
        const component = loadAmdModule(RENDERER, {
            'Magento_Checkout/js/model/full-screen-loader': loader
        });

        const ctx = Object.assign({}, component, {
            companyName: plainObservable(''),
            companyId: plainObservable(''),
            isOrderIntentEnabled: true,
            generalErrorMessage: 'Something went wrong.',
            showErrorMessage: function () {},
            placeOrderIntent: function () {
                throw new Error('billingAddress was transiently null');
            }
        });

        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });

        expect(seq).toEqual(['startLoader', 'stopLoader']);
    });
});
