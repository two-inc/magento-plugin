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
});
