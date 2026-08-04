/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25347: fillCustomerData() subscribes to four shared quote/
 * customerData singletons and, before this fix, never disposed the
 * previous set on a re-render — dispose() tore down only
 * `_twoVisibilitySub`. fillCustomerData() is re-callable
 * (registeredOrganisationMode(), reached whenever initObservable() runs
 * again), and Fire Checkout re-renders this payment renderer on every
 * totals/shipping change, so stacked subscriptions accumulated fast enough
 * to be visible there: a single company pick fired one order_intent POST
 * per SURVIVING subscription, not one.
 *
 * These specs use a real-dispose observable double (the shared harness's
 * makeObservable() stubs dispose() as a no-op, which would make this fix
 * untestable against it) to prove the actual subscriber count, not just
 * that dispose() was called.
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

function loadRenderer() {
    const address = { getCacheKey: () => 'k', countryId: 'GB' };
    const shippingAddress = realDisposeObservable(address);
    const billingAddress = realDisposeObservable(address);
    const companyDataSection = realDisposeObservable('');
    const shippingTelephoneSection = realDisposeObservable('');
    const countryCodeSection = realDisposeObservable('');

    const renderer = loadAmdModule(RENDERER, {
        'Magento_Customer/js/customer-data': {
            get: function (key) {
                if (key === 'companyData') return companyDataSection;
                if (key === 'shippingTelephone') return shippingTelephoneSection;
                if (key === 'countryCode') return countryCodeSection;
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

    // Normally seeded by initialize(), which this harness deliberately
    // bypasses to keep fillCustomerData() the only thing under test.
    renderer.supportedCompanyTypes = { gb: [] };

    return { renderer, shippingAddress, billingAddress, companyDataSection };
}

describe('fillCustomerData() subscription lifecycle (TWO-25347)', () => {
    test('a re-call without an intervening dispose() still leaves exactly one live subscriber per singleton', () => {
        const { renderer, shippingAddress, billingAddress, companyDataSection } = loadRenderer();

        renderer.fillCustomerData();
        renderer.fillCustomerData();
        renderer.fillCustomerData();

        // Three calls, no dispose() between them — this is exactly Fire
        // Checkout's re-render pattern. Before the fix these would be 3, not 1.
        expect(shippingAddress.subscriberCount()).toBe(1);
        expect(billingAddress.subscriberCount()).toBe(1);
        expect(companyDataSection.subscriberCount()).toBe(1);
    });

    test('dispose() removes fillCustomerData()\'s subscriptions', () => {
        const { renderer, shippingAddress, billingAddress, companyDataSection } = loadRenderer();
        renderer._super = function () {};

        renderer.fillCustomerData();
        expect(shippingAddress.subscriberCount()).toBe(1);

        renderer.dispose();

        expect(shippingAddress.subscriberCount()).toBe(0);
        expect(billingAddress.subscriberCount()).toBe(0);
        expect(companyDataSection.subscriberCount()).toBe(0);
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

    test('a synchronous throw during the one-shot company read does not abort the rest of fillCustomerData() (found in round-2 adversarial review, 2026-08-04)', () => {
        // Han's round-2 finding: fillCustomerData() calls applyCompanyData()
        // (the one-shot companyData read) BEFORE wiring the shippingTelephone/
        // countryCode/shippingAddress/billingAddress subscriptions. If that
        // one-shot call reaches fillCompanyData() → placeOrderIntent() and
        // THAT throws synchronously, an unswallowed throw would abort
        // fillCustomerData() mid-function and permanently skip every
        // subscription after the throw point — for the rest of the page
        // session, not just this one company pick. This is why the round-1
        // fix (swallow-and-message, not rethrow) matters beyond the guard
        // itself: fillCompanyData() must never let an internal throw escape
        // into a caller with mandatory follow-up work.
        const { renderer, shippingAddress, billingAddress, companyDataSection } = loadRenderer();
        renderer.isOrderIntentEnabled = true;
        renderer.showErrorMessage = function () {};
        renderer.placeOrderIntent = function () {
            throw new Error('billingAddress was transiently null');
        };
        // Seeded so the one-shot `customerData.get('companyData')()` read
        // inside fillCustomerData() has both name and id — otherwise
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
    test('a second call for the SAME company while a request is in flight does not re-fire placeOrderIntent', () => {
        const component = loadAmdModule(RENDERER);
        let placeOrderIntentCalls = 0;
        let resolveDeferred;
        const ctx = Object.assign({}, component, {
            companyName: (function () {
                let v = '';
                const fn = function (next) {
                    if (!arguments.length) return v;
                    v = next;
                    return fn;
                };
                return fn;
            })(),
            companyId: (function () {
                let v = '';
                const fn = function (next) {
                    if (!arguments.length) return v;
                    v = next;
                    return fn;
                };
                return fn;
            })(),
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

    /** Plain (non-ko) observable factory, matching the pattern above. */
    function plainObservable(initial) {
        let v = initial;
        const fn = function (next) {
            if (!arguments.length) return v;
            v = next;
            return fn;
        };
        return fn;
    }

    test('a synchronous throw from placeOrderIntent() clears the guard, shows a message, and does NOT propagate (found in adversarial review, 2026-08-04; refined in round 2)', () => {
        // BLOCKER found in round 1: placeOrderIntent() can throw
        // synchronously (quote.billingAddress() can legitimately be null for
        // a transient window; placeOrderIntent() reads
        // `billingAddress.countryId` unguarded). Before the fix, an
        // unhandled throw here skipped `.always()` entirely, and
        // `_orderIntentInFlightFor` stayed set to that companyId FOREVER —
        // every later pick of the SAME company would silently no-op against
        // the in-flight guard, with no recovery short of a page reload.
        //
        // Round 2 found the FIRST fix (try/catch + rethrow, mirroring
        // placeOrderBackend()) was still wrong: every caller of
        // fillCompanyData() is an unguarded synchronous context with its own
        // statements AFTER the call (updateAddress()'s project/department
        // writes, the select2:select handler's addressLookup() call,
        // refreshTileCompanySearchBinding()) — rethrowing aborted all of
        // those too, and gave the buyer no visible sign anything went wrong.
        // The catch now shows a message and does NOT rethrow.
        const component = loadAmdModule(RENDERER);
        let placeOrderIntentCalls = 0;
        const errors = [];
        const ctx = Object.assign({}, component, {
            companyName: plainObservable(''),
            companyId: plainObservable(''),
            isOrderIntentEnabled: true,
            generalErrorMessage: 'Something went wrong.',
            showErrorMessage: function (message) {
                errors.push(message);
            },
            placeOrderIntent: function () {
                placeOrderIntentCalls += 1;
                if (placeOrderIntentCalls === 1) {
                    // Simulates the transient-null-billingAddress window —
                    // only the FIRST attempt hits it.
                    throw new Error('billingAddress was transiently null');
                }
                return {
                    always: function () {
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

        // No longer throws out of fillCompanyData() — a caller's own
        // statements after this call must still run.
        let statementAfterRan = false;
        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });
        statementAfterRan = true;
        expect(statementAfterRan).toBe(true);

        expect(errors).toEqual(['Something went wrong.']);
        expect(ctx._orderIntentInFlightFor).toBeNull();

        // The buyer picks the SAME company again (e.g. re-selecting after
        // the transient null window has passed) — must not be swallowed by
        // a stuck guard.
        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });

        expect(placeOrderIntentCalls).toBe(2);
    });

    test('a stale response for a PREVIOUS company does not overwrite the notice for the CURRENT one (cross-company race, found in adversarial review, 2026-08-04)', () => {
        // BLOCKER found reviewing this PR: the in-flight guard only dedupes
        // a repeat request for the SAME company. It does nothing to stop a
        // stale response for company A landing AFTER the buyer has already
        // moved on to company B — resolveCompanyNotice() reads
        // companyName()/companyId() LIVE at settle time, so A's verdict
        // would render with B's name/number substituted in.
        const component = loadAmdModule(RENDERER);
        const deferreds = {};
        const processed = [];
        const ctx = Object.assign({}, component, {
            companyName: plainObservable(''),
            companyId: plainObservable(''),
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

        // Company A selected — request A fires and is left hanging.
        ctx.fillCompanyData({ companyName: 'Company A', companyId: 'aaa' });
        // Buyer changes their mind before A settles — company B selected.
        // The in-flight guard is keyed per-company, so this does not get
        // deduped against A's still-open request.
        ctx.fillCompanyData({ companyName: 'Company B', companyId: 'bbb' });

        // A's stale response finally lands, AFTER the buyer moved to B.
        deferreds['aaa']._always();
        deferreds['aaa']._done({ approved: true });

        // A's verdict must be dropped, not rendered against the now-current
        // companyName/companyId (which are B's).
        expect(processed).toEqual([]);

        // B's own response landing normally still works.
        deferreds['bbb']._always();
        deferreds['bbb']._done({ approved: true });
        expect(processed).toEqual([{ company: 'Company B', response: { approved: true } }]);
    });
});

describe('tile company-search re-binds on an address-type switch (found in adversarial review, 2026-08-04)', () => {
    /**
     * BLOCKER found reviewing this PR: enableCompanySearch() only runs from
     * three call sites (initObservable()'s registeredOrganisationMode(), the
     * "Search for company" link, and the supported-company-types callback)
     * — NONE of which fire when a buyer switches between a NEW and a SAVED
     * shipping/billing address. `#shipping-new-address-form` appears and
     * disappears exactly on that switch, which is the live DOM signal
     * isTileCompanySearchActive() reads. Fixed by having
     * updateShippingAddress()/updateBillingAddress() call
     * refreshTileCompanySearchBinding() unconditionally.
     *
     * `formHasCompanyField` is mutable so a single jquery mock can simulate
     * the address-area form appearing/disappearing across two calls in one
     * test — the real module closes over this `$` at require time, so the
     * double has to be able to change its answer without a new require.
     */
    function loadRendererWithToggleableAddressForm() {
        let formHasCompanyField = true; // address-area form present (NEW address)

        function makeNode() {
            const node = {
                length: 0,
                on: () => node,
                off: () => node,
                val: () => node,
                text: () => node,
                attr: () => node,
                data: () => null,
                find: () => node,
                closest: () => node,
                each: () => node,
                hide: () => node,
                show: () => node,
                select2: () => node
            };
            return node;
        }
        function $(selector) {
            if (selector === '#shipping-new-address-form input[name="company"]') {
                const node = makeNode();
                node.length = formHasCompanyField ? 1 : 0;
                return node;
            }
            return makeNode();
        }
        $.fn = {};
        $.extend = Object.assign;
        $.async = function (sel, cb) {
            cb($(sel));
        };
        $.Deferred = function () {
            const d = {
                resolve: () => d,
                reject: () => d,
                promise: () => d,
                done: () => d,
                fail: () => d,
                always: () => d
            };
            return d;
        };
        $.mage = { cookies: { get: () => null, set: () => {} }, redirect: () => {} };
        $.validator = { methods: {}, addMethod: function () {} };

        const address = { getCacheKey: () => 'k', countryId: 'GB' };
        const renderer = loadAmdModule(RENDERER, {
            jquery: $,
            'Magento_Checkout/js/model/quote': {
                shippingAddress: { subscribe: () => ({ dispose: () => {} }), peek: () => address },
                billingAddress: (function () {
                    const fn = function () {
                        return address;
                    };
                    return fn;
                })(),
                getTotals: () => ({}),
                getQuoteId: () => null,
                paymentMethod: { subscribe: () => ({ dispose: () => {} }) },
                shippingMethod: { subscribe: () => ({ dispose: () => {} }) },
                isVirtual: () => false
            }
        });
        renderer.supportedCompanyTypes = { gb: [] };
        renderer.isAddressAreaCompanySearchEnabled = true;

        const enableCalls = [];
        const destroyCalls = [];
        renderer.enableCompanySearch = function () {
            enableCalls.push(1);
        };
        renderer.destroyCompanySearchWidget = function () {
            destroyCalls.push(1);
        };

        return {
            renderer,
            enableCalls,
            destroyCalls,
            setFormPresent: (present) => {
                formHasCompanyField = present;
            }
        };
    }

    test('switching from a NEW address (form present) to a SAVED one (form gone) re-binds the tile widget', () => {
        const { renderer, enableCalls, destroyCalls, setFormPresent } =
            loadRendererWithToggleableAddressForm();

        // Address-area form present → tile is NOT the active location.
        setFormPresent(true);
        renderer.updateBillingAddress({ getCacheKey: () => 'k', countryId: 'GB' });
        expect(enableCalls.length).toBe(0);
        expect(destroyCalls.length).toBe(1);

        // Buyer switches to a saved address → the address-area form is gone.
        setFormPresent(false);
        renderer.updateBillingAddress({ getCacheKey: () => 'k', countryId: 'GB' });
        expect(enableCalls.length).toBe(1);
    });

    test('shipping-address changes also trigger the refresh (not only billing)', () => {
        const { renderer, enableCalls, setFormPresent } = loadRendererWithToggleableAddressForm();

        setFormPresent(false);
        renderer.updateShippingAddress({ getCacheKey: () => 'k', countryId: 'GB' });

        expect(enableCalls.length).toBe(1);
    });
});
