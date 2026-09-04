/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25547 — the merchant-level mint gate: whether tokens are minted and the
 * buyer's own session looked up is the intersection of the merchant's own
 * buyer-country restriction (`soleTraderCountryRestriction`, off
 * `GET /v1/merchant`'s `supported_buyer_countries`) and the registry's
 * sole-trader-supported countries, resolved ONCE at `start()` — never from
 * whichever country the buyer currently has selected in the checkout form.
 *
 * Mutation-resistance notes:
 *  - every case pins the mint COUNT (`prefetchCalls`), not just a boolean, so
 *    a gate that resolves correctly but still mints (or skips minting)
 *    reads as a failure;
 *  - the decoupling case drives a REAL country change after boot and asserts
 *    the count does not move, which is the exact defect this replaces —
 *    asserting the gate's return value alone would not catch a re-mint
 *    reintroduced elsewhere.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const CONTROLLER = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';

function flush() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/**
 * A complete host bound to a fixed selected country ('gb') that never
 * changes on its own — only `onCountryChanged()` moves it — so the gate
 * cases can drive a country change explicitly and check nothing about the
 * mint decision moves with it.
 *
 * @param {object} config the brand config subtree: `soleTraderCountryRestriction`
 *        plus a `supportedCompanyTypes` seed answering every country the
 *        case cares about, so no case depends on a live fetch.
 * @returns {object} `{ Controller, host, prefetchCalls }`
 */
function makeHost(config) {
    // Every registry lookup this suite makes is seeded; an unmocked fetch
    // would otherwise reach Node's real network fetch implementation.
    const Controller = loadAmdModule(CONTROLLER, {}, {
        fetch: function () { return Promise.resolve({ ok: false, status: 404 }); }
    });
    const identity = loadAmdModule(IDENTITY)();
    const prefetchCalls = [];
    const host = {};
    Controller.HOST_CONTRACT.forEach(function (member) {
        host[member] = function () { return undefined; };
    });
    Object.assign(host, {
        config: config,
        Panel: function () {},
        SoleTraderFlow: function () {
            this.listenForSignupResult = function () {};
            this.prefetchBuyer = function () {
                prefetchCalls.push(true);
                return Promise.resolve(null);
            };
            this.forgetAdoptions = function () {};
            this.forgetAutofilledBuyer = function () {};
            this.autofilledSoleTrader = function () { return null; };
            this.focusSignupPopup = function () { return false; };
            this.launchSignup = function () { return null; };
        },
        identity: identity,
        search: {},
        addressFieldSelector: '',
        tileFieldSelector: '',
        fieldExists: function () { return false; },
        isVirtualCart: function () { return false; },
        getAdjacentCountry: function () { return null; },
        getQuoteCountry: function () { return 'gb'; },
        getFallbackCountry: function () { return ''; },
        watchCountryChanges: function () {},
        supportedCompanyTypesUrl: function (country) { return `https://registry.example/${country}`; }
    });
    return { Controller: Controller, host: host, prefetchCalls: prefetchCalls };
}

describe('the merchant-level mint gate resolves once, off the merchant alone', () => {
    test.each([
        [
            undefined,
            { gb: ['LIMITED_COMPANY'] },
            true,
            'absent restriction: unrestricted, mints unconditionally regardless of the selected country\'s own registry answer'
        ],
        [
            [],
            { gb: ['SOLE_TRADER'] },
            false,
            'explicit empty restriction: the merchant accepts no buyer country, so nothing to mint for'
        ],
        [
            ['NO', 'SE'],
            { no: ['SOLE_TRADER'], se: ['LIMITED_COMPANY'] },
            true,
            'restricted list intersects the registry\'s sole-trader countries via NO: mints'
        ],
        [
            ['ES', 'FR'],
            { es: ['LIMITED_COMPANY'], fr: ['LIMITED_COMPANY'] },
            false,
            'restricted list has no intersection with the registry\'s sole-trader countries: does not mint'
        ]
    ])('restriction=%p -> mints=%p (%s)', async (restriction, registryTypes, expectMint) => {
        const { Controller, host, prefetchCalls } = makeHost({
            isCompanySearchEnabled: false,
            soleTraderCountryRestriction: restriction,
            supportedCompanyTypes: registryTypes
        });
        const component = new Controller(host);

        component.start();
        await flush();

        expect(prefetchCalls.length).toBe(expectMint ? 1 : 0);
        expect(component._mintGateValue).toBe(expectMint);
    });
});

describe('the gate never re-runs on a country change', () => {
    test('a country change after boot mints nothing a second time, either direction', async () => {
        const { Controller, host, prefetchCalls } = makeHost({
            isCompanySearchEnabled: false,
            soleTraderCountryRestriction: ['NO'],
            supportedCompanyTypes: { no: ['SOLE_TRADER'], es: ['LIMITED_COMPANY'], gb: ['LIMITED_COMPANY'] }
        });
        const component = new Controller(host);

        component.start();
        await flush();
        expect(prefetchCalls.length).toBe(1);

        // Neither direction — into a country the registry has no sole trader
        // for, nor back to one it does — moves the gate again. It was
        // resolved once, off the merchant's OWN restriction, at boot.
        component.onCountryChanged('es');
        await flush();
        component.onCountryChanged('no');
        await flush();

        expect(prefetchCalls.length).toBe(1);
    });

    test('a merchant gated to nothing mints nothing however the country changes', async () => {
        const { Controller, host, prefetchCalls } = makeHost({
            isCompanySearchEnabled: false,
            soleTraderCountryRestriction: [],
            supportedCompanyTypes: { no: ['SOLE_TRADER'], gb: ['SOLE_TRADER'] }
        });
        const component = new Controller(host);

        component.start();
        await flush();
        expect(prefetchCalls.length).toBe(0);

        // Both countries genuinely support sole traders in the registry —
        // proving this stays at zero because of the merchant's OWN gate,
        // not because the registry happened to answer no everywhere.
        component.onCountryChanged('no');
        await flush();

        expect(prefetchCalls.length).toBe(0);
    });
});
