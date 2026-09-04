/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25547 — the sole-trader mint and buyer lookup fire unconditionally as
 * soon as checkout is reached, decoupled from whichever country the buyer
 * currently has selected in the checkout form. Bifrost's registry coverage
 * is global, not merchant-scoped, so there is nothing to gate the mint on —
 * only the sole-trader CHIP's own visibility (`soleTraderAvailable`) stays
 * per-country.
 *
 * Mutation-resistance notes:
 *  - the mint is pinned by COUNT (`prefetchCalls`), not a boolean, so a mint
 *    reintroduced twice — once at boot, once on the first country resolution
 *    — reads as a failure;
 *  - the decoupling case drives a REAL country change after boot and asserts
 *    the count does not move a second time, which is the exact defect this
 *    replaces.
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
 * changes on its own — only `onCountryChanged()` moves it.
 *
 * @param {object} config the brand config subtree; `supportedCompanyTypes`
 *        seeds every country a case cares about, so no case depends on a
 *        live fetch.
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

describe('the mint fires unconditionally, once, at start()', () => {
    test('mints even for a country the registry has no sole trader for', async () => {
        const { Controller, host, prefetchCalls } = makeHost({
            isCompanySearchEnabled: false,
            supportedCompanyTypes: { gb: ['LIMITED_COMPANY'] }
        });
        const component = new Controller(host);

        component.start();
        await flush();

        expect(prefetchCalls.length).toBe(1);
    });

    test('a second start() mints nothing a second time', async () => {
        const { Controller, host, prefetchCalls } = makeHost({
            isCompanySearchEnabled: false,
            supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
        });
        const component = new Controller(host);

        component.start();
        component.start();
        await flush();

        expect(prefetchCalls.length).toBe(1);
    });
});

describe('the mint never re-fires on a country change', () => {
    test('neither direction of a country change mints a second time', async () => {
        const { Controller, host, prefetchCalls } = makeHost({
            isCompanySearchEnabled: false,
            supportedCompanyTypes: { no: ['SOLE_TRADER'], es: ['LIMITED_COMPANY'], gb: ['LIMITED_COMPANY'] }
        });
        const component = new Controller(host);

        component.start();
        await flush();
        expect(prefetchCalls.length).toBe(1);

        component.onCountryChanged('es');
        await flush();
        component.onCountryChanged('no');
        await flush();

        expect(prefetchCalls.length).toBe(1);
    });
});
