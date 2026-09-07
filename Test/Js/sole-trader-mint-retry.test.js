/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25547 — a mint that fails to reach the server (network error, non-ok
 * response) must not be memoised forever: `prefetchBuyer()` held its
 * resolved-null answer permanently even when the reason was a transient
 * failure, not a real "no sole trader" answer, so one bad load cost the
 * whole page its mint for good.
 *
 * Mutation-resistance notes:
 *  - the retry is pinned by COUNT (`tokenMints`), not a boolean, so a second
 *    call that silently reuses the failed attempt's cached null reads as a
 *    failure;
 *  - a genuinely successful mint is asserted to stay memoised (no re-fetch on
 *    a second call), so "always retry" would also fail this suite.
 */

'use strict';

const { loadAmdModule, loadCompanyCapture, brandConfigMock, quoteAddress } = require('./amd-harness');

const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';
const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';
const CHECKOUT_API_URL = 'https://api.example';

/**
 * The real flow over Luma's wired capture component, with a `get-tokens`
 * fetch whose outcome is driven by `state.fail` rather than fixed at load —
 * so the same env can model a bad attempt followed by a good one.
 *
 * @returns {object} `{ flow, state, tokenMints }`
 */
function loadFlow() {
    const state = { fail: true, mintOutcome: null, buyer: null };
    let tokenMints = 0;
    const mocks = {
        'Magento_Checkout/js/model/quote': {
            billingAddress: quoteAddress({ countryId: 'GB' }),
            shippingAddress: quoteAddress({ countryId: 'GB' }),
            getQuoteId: function () { return 'cart-1'; },
            isVirtual: function () { return false; }
        },
        'Two_Gateway/js/model/company-search': { apiClientParams: function () { return { client: 'magento' }; } },
        'Two_Gateway/js/model/brand-config': brandConfigMock({
            checkoutPageUrl: CHECKOUT_PAGE_URL,
            checkoutApiUrl: CHECKOUT_API_URL,
            isCompanySearchEnabled: true,
            supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
        })
    };
    const globals = {
        setInterval: function () { return 1; },
        clearInterval: function () {},
        fetch: function (requestUrl) {
            if (String(requestUrl).indexOf('get-tokens') === -1) {
                if (!state.buyer) return Promise.resolve({ ok: false, status: 404 });
                return Promise.resolve({
                    ok: true,
                    json: function () { return Promise.resolve(state.buyer); }
                });
            }
            if (state.mintOutcome) return state.mintOutcome;
            if (state.fail) return Promise.reject(new Error('network down'));
            tokenMints += 1;
            return Promise.resolve({
                ok: true,
                json: function () {
                    return Promise.resolve([{ delegation_token: 'dt-1', autofill_token: 'at-1' }]);
                }
            });
        }
    };
    const SoleTraderCtor = loadAmdModule(SOLE_TRADER, mocks, globals);
    const component = loadCompanyCapture(mocks, globals).shipping;
    component.adoptSoleTrader = function () {};
    component.abandonSoleTrader = function () {};
    const flow = new SoleTraderCtor(component);
    return { flow: flow, state: state, tokenMints: function () { return tokenMints; } };
}

/** A mint outcome the test releases, so a failure can land late. */
function heldMint() {
    let reject;
    const promise = new Promise((_, rejectPromise) => { reject = rejectPromise; });
    return { promise: promise, fail: function () { reject(new Error('network down')); } };
}

const GOOD_BUYER = { company_name: 'Ada Stonemason', organization_number: '123456789' };

describe('a failed attempt answers nothing', () => {
    test.each([
        ['before the good lookup writes', true],
        ['after the good lookup wrote', false]
    ])('a failed mint landing %s leaves the good buyer standing', async (_case, releaseFirst) => {
        const { flow, state } = loadFlow();
        const held = heldMint();
        state.mintOutcome = held.promise;
        const failing = flow.prefetchBuyer();

        // Only forgetAutofilledBuyer() releases the memo in production, and it
        // bumps the generation too — which is the other, independent guard.
        flow._prefetch = null;
        state.mintOutcome = null;
        state.fail = false;
        state.buyer = GOOD_BUYER;
        flow.delegationToken = 'dt-0';
        flow.autofillToken = 'at-0';
        const good = flow.prefetchBuyer();

        if (releaseFirst) {
            held.fail();
            await failing;
            await good;
        } else {
            await good;
            held.fail();
            await failing;
        }

        expect(await good).toEqual(GOOD_BUYER);
        expect(flow.autofilledSoleTrader()).toEqual(GOOD_BUYER);
        expect(flow._prefetch).not.toBeNull();
    });
});

describe('a failed mint is not memoised', () => {
    test('a second prefetchBuyer() after a failed mint retries rather than reusing the cached null', async () => {
        const { flow, state, tokenMints } = loadFlow();

        const first = await flow.prefetchBuyer();
        expect(first).toBeNull();
        expect(flow.hasSignupTokens()).toBe(false);
        expect(tokenMints()).toBe(0);

        state.fail = false;
        const second = await flow.prefetchBuyer();

        expect(flow.hasSignupTokens()).toBe(true);
        expect(tokenMints()).toBe(1);
        expect(second).toBeNull(); // no buyer stubbed to answer with here — only the retry is under test
    });

    test('a successful mint stays memoised — prefetchBuyer() does not re-fetch on a second call', async () => {
        const { flow, state, tokenMints } = loadFlow();
        state.fail = false;

        await flow.prefetchBuyer();
        expect(tokenMints()).toBe(1);

        await flow.prefetchBuyer();
        expect(tokenMints()).toBe(1);
    });
});
