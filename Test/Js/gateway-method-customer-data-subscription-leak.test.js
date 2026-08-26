/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25347: fillCustomerData() subscribes to shared quote/customerData
 * singletons and, before this fix, never disposed the previous set on a
 * re-render — dispose() tore down only `_twoVisibilitySub`. fillCustomerData()
 * is re-callable and Fire Checkout re-renders this payment renderer on every
 * totals/shipping change, so stacked subscriptions accumulated fast enough to
 * be visible there: a single company pick fired one order_intent POST per
 * SURVIVING subscription, not one.
 *
 * The `countryCode` customer-data subscription this file used to count is gone
 * (TWO-25503) — the capture component's delegated country watcher replaced it —
 * so the counts below are over the three singletons that remain.
 *
 * These specs use a real-dispose observable double (the shared harness's
 * makeObservable() stubs dispose() as a no-op, which would make this fix
 * untestable against it) to prove the actual subscriber count, not just that
 * dispose() was called.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

/** Observable whose subscribe() returns a dispose() that actually removes the subscriber. */
function realDisposeObservable(initial) {
    let value = initial;
    let subs = [];
    function obs(next) {
        if (!arguments.length) return value;
        value = next;
        subs.forEach((fn) => fn(value));
        return obs;
    }
    obs.subscribe = function (fn) {
        subs.push(fn);
        return {
            dispose: function () {
                subs = subs.filter((s) => s !== fn);
            }
        };
    };
    obs.subscriberCount = function () {
        return subs.length;
    };
    obs.extend = function () {
        return obs;
    };
    obs.peek = function () {
        return value;
    };
    return obs;
}

/**
 * A capture-component double that records what the renderer asks of it.
 *
 * `config()` answering non-null is what "the component has booted" means to the
 * renderer, so a test can put it either side of that line.
 *
 * @param {?object} config
 */
function makeCaptureComponent(config) {
    const calls = { refreshMount: 0 };
    return {
        calls: calls,
        module: {
            config: function () { return config; },
            countryCode: function () { return 'gb'; },
            mountSelector: function () { return ''; },
            soleTrader: function () { return null; },
            refreshMount: function () { calls.refreshMount += 1; }
        }
    };
}

function loadRenderer(options) {
    const opts = options || {};
    const address = { getCacheKey: () => 'k', countryId: 'GB' };
    const shippingAddress = realDisposeObservable(address);
    const billingAddress = realDisposeObservable(address);
    const companyDataSection = realDisposeObservable('');
    const shippingTelephoneSection = realDisposeObservable('');
    const capture = makeCaptureComponent('booted' in opts ? opts.booted : {});

    const renderer = loadAmdModule(RENDERER, {
        'Two_Gateway/js/model/company-capture-component': capture.module,
        'Magento_Customer/js/customer-data': {
            get: function (key) {
                if (key === 'companyData') return companyDataSection;
                if (key === 'shippingTelephone') return shippingTelephoneSection;
                return realDisposeObservable('');
            },
            set: function () {},
            reload: function () {}
        },
        'Magento_Checkout/js/model/quote': {
            shippingAddress: shippingAddress,
            billingAddress: billingAddress,
            getTotals: () => realDisposeObservable({}),
            getQuoteId: () => null,
            paymentMethod: realDisposeObservable(null),
            shippingMethod: realDisposeObservable({ carrier_code: 'freeshipping' }),
            isVirtual: () => false
        }
    });

    return {
        renderer,
        shippingAddress,
        billingAddress,
        companyDataSection,
        shippingTelephoneSection,
        capture
    };
}

describe('fillCustomerData() subscription lifecycle (TWO-25347)', () => {
    test('three re-calls with no dispose() between them still leave one live subscriber per singleton', () => {
        const {
            renderer,
            shippingAddress,
            billingAddress,
            companyDataSection,
            shippingTelephoneSection
        } = loadRenderer();

        // Exactly Fire Checkout's re-render pattern. Before the fix these
        // would be 3, not 1.
        renderer.fillCustomerData();
        renderer.fillCustomerData();
        renderer.fillCustomerData();

        expect(shippingAddress.subscriberCount()).toBe(1);
        expect(billingAddress.subscriberCount()).toBe(1);
        expect(companyDataSection.subscriberCount()).toBe(1);
        expect(shippingTelephoneSection.subscriberCount()).toBe(1);
    });

    test('dispose() removes fillCustomerData()\'s subscriptions', () => {
        const {
            renderer,
            shippingAddress,
            billingAddress,
            companyDataSection,
            shippingTelephoneSection
        } = loadRenderer();
        renderer._super = function () {};

        renderer.fillCustomerData();
        expect(shippingAddress.subscriberCount()).toBe(1);

        renderer.dispose();

        expect(shippingAddress.subscriberCount()).toBe(0);
        expect(billingAddress.subscriberCount()).toBe(0);
        expect(companyDataSection.subscriberCount()).toBe(0);
        expect(shippingTelephoneSection.subscriberCount()).toBe(0);
    });

    test('a single company-data change fires applyCompanyData exactly once after three stacked calls', () => {
        const { renderer, companyDataSection } = loadRenderer();

        let applyCalls = 0;
        renderer.applyCompanyData = function () {
            applyCalls += 1;
        };

        renderer.fillCustomerData();
        renderer.fillCustomerData();
        renderer.fillCustomerData();
        // Each fillCustomerData() call above also does its own one-shot
        // read; only count the NOTIFICATION-driven calls that follow.
        applyCalls = 0;

        companyDataSection({ companyName: 'Acme Widgets AS', companyId: '123' });

        expect(applyCalls).toBe(1);
    });

    test('a synchronous throw during the one-shot company read does not abort the rest of fillCustomerData()', () => {
        // fillCustomerData() does the one-shot `companyData` read BEFORE wiring
        // the remaining subscriptions. If that read reaches fillCompanyData() →
        // placeOrderIntent() and THAT throws synchronously, an unswallowed throw
        // would abort fillCustomerData() mid-function and permanently skip every
        // subscription after the throw point — for the rest of the page session,
        // not just this one company pick.
        const { renderer, shippingAddress, billingAddress, companyDataSection } = loadRenderer();
        renderer.isOrderIntentEnabled = true;
        renderer.showErrorMessage = function () {};
        renderer.placeOrderIntent = function () {
            throw new Error('billingAddress was transiently null');
        };
        // Seeded so the one-shot read has both name and id — otherwise
        // fillCompanyData() early-returns before ever reaching
        // placeOrderIntent(), and the throw path is never exercised.
        companyDataSection({ companyName: 'Acme Widgets AS', companyId: '123' });

        expect(() => renderer.fillCustomerData()).not.toThrow();

        expect(shippingAddress.subscriberCount()).toBe(1);
        expect(billingAddress.subscriberCount()).toBe(1);
        expect(companyDataSection.subscriberCount()).toBe(1);
    });
});

describe('fillCompanyData() in-flight guard (TWO-25347 belt-and-braces)', () => {
    /**
     * The renderer's own company observables are deliberately NOT stubbed here:
     * they are aliases of the page-level identity singleton, which is what
     * fillCompanyData() writes and what the settle-time guards read back — a
     * stub would leave both reading '' and make the cross-company case vacuous.
     */
    test('a second call for the SAME company while a request is in flight does not re-fire placeOrderIntent', () => {
        const { renderer } = loadRenderer();
        let placeOrderIntentCalls = 0;
        let resolveDeferred;
        const ctx = Object.assign({}, renderer, {
            isOrderIntentEnabled: true,
            placeOrderIntent: function () {
                placeOrderIntentCalls += 1;
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
        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });

        expect(placeOrderIntentCalls).toBe(1);

        // Settling the first request re-arms the guard for a later, genuine
        // re-selection of the same company.
        resolveDeferred();
        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });

        expect(placeOrderIntentCalls).toBe(2);
    });

    test('a synchronous throw from placeOrderIntent() clears the guard, shows a message, and does NOT propagate', () => {
        // placeOrderIntent() can throw synchronously — quote.billingAddress()
        // is legitimately null for a transient window and it reads
        // `billingAddress.countryId` unguarded. An unhandled throw skips
        // `.always()`, and `_orderIntentInFlightFor` would stay set to that
        // companyId FOREVER, so every later pick of the SAME company would
        // silently no-op with no recovery short of a page reload.
        //
        // It must not RETHROW either: every caller is an unguarded synchronous
        // context with its own statements after the call.
        const { renderer } = loadRenderer();
        let placeOrderIntentCalls = 0;
        const errors = [];
        const ctx = Object.assign({}, renderer, {
            isOrderIntentEnabled: true,
            generalErrorMessage: 'Something went wrong.',
            showErrorMessage: function (message) {
                errors.push(message);
            },
            placeOrderIntent: function () {
                placeOrderIntentCalls += 1;
                if (placeOrderIntentCalls === 1) {
                    // The transient-null-billingAddress window — only the FIRST
                    // attempt hits it.
                    throw new Error('billingAddress was transiently null');
                }
                return {
                    always: function () { return this; },
                    done: function () { return this; },
                    fail: function () { return this; }
                };
            }
        });

        let statementAfterRan = false;
        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });
        statementAfterRan = true;

        expect(statementAfterRan).toBe(true);
        expect(errors).toEqual(['Something went wrong.']);
        expect(ctx._orderIntentInFlightFor).toBeNull();

        // The buyer picks the SAME company again, once the transient window has
        // passed — must not be swallowed by a stuck guard.
        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });

        expect(placeOrderIntentCalls).toBe(2);
    });

    test('a stale response for a PREVIOUS company does not overwrite the notice for the CURRENT one', () => {
        // The in-flight guard only dedupes a repeat request for the SAME
        // company. It does nothing to stop a stale response for company A
        // landing AFTER the buyer has moved on to company B —
        // resolveCompanyNotice() reads the observables LIVE at settle time, so
        // A's verdict would render with B's name and number substituted in.
        const { renderer } = loadRenderer();
        const deferreds = {};
        const processed = [];
        const ctx = Object.assign({}, renderer, {
            isOrderIntentEnabled: true,
            placeOrderIntent: function () {
                const companyId = ctx.companyId();
                const deferred = {
                    always: function (fn) {
                        deferred._always = fn;
                        return deferred;
                    },
                    done: function (fn) {
                        deferred._done = fn;
                        return deferred;
                    },
                    fail: function () {
                        return deferred;
                    }
                };
                deferreds[companyId] = deferred;
                return deferred;
            },
            processOrderIntentSuccessResponse: function (response) {
                processed.push({ company: this.companyName(), response: response });
            }
        });

        // Company A selected — request A fires and is left hanging. The buyer
        // changes their mind before it settles; the guard is keyed per-company,
        // so B is not deduped against A's still-open request.
        ctx.fillCompanyData({ companyName: 'Company A', companyId: 'aaa' });
        ctx.fillCompanyData({ companyName: 'Company B', companyId: 'bbb' });

        deferreds['aaa']._always();
        deferreds['aaa']._done({ approved: true });

        expect(processed).toEqual([]);

        // B's own response landing normally still works.
        deferreds['bbb']._always();
        deferreds['bbb']._done({ approved: true });

        expect(processed).toEqual([{ company: 'Company B', response: { approved: true } }]);
    });
});

describe('an address change re-points the capture component\'s mount', () => {
    /**
     * A NEW address and a SAVED one differ in whether the address step renders
     * a company field at all, which is what the component picks its mount by —
     * and nothing else fires on that switch. Before this the tile's picker
     * simply never appeared for a buyer who switched to a saved address.
     */
    test.each([
        ['updateShippingAddress', 'a shipping address change'],
        ['updateBillingAddress', 'a billing address change']
    ])('%s asks for a re-point (%s)', (method) => {
        const { renderer, capture } = loadRenderer();

        renderer[method]({ getCacheKey: () => 'other', countryId: 'GB' });

        expect(capture.calls.refreshMount).toBe(1);
    });

    test('a shipping address that is NOT the billing address still re-points', () => {
        // updateAddress() is gated on the cache keys matching; the re-point is
        // deliberately not, because the form's presence changes either way.
        const { renderer, capture } = loadRenderer();

        renderer.updateShippingAddress({ getCacheKey: () => 'different', countryId: 'GB' });

        expect(capture.calls.refreshMount).toBe(1);
    });

    test('nothing is asked of a component that has not booted yet', () => {
        // The sidebar hook that starts the component and this renderer have no
        // ordering guarantee, and the component re-points itself on start().
        const { renderer, capture } = loadRenderer({ booted: null });

        renderer.updateBillingAddress({ getCacheKey: () => 'k', countryId: 'GB' });

        expect(capture.calls.refreshMount).toBe(0);
    });
});
