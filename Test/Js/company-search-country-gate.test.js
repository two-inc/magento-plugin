/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25668 — the ordinary company-search control greys out on a billing
 * country the registry search does not cover, instead of letting the buyer
 * search and fail. Fail-soft direction is INVERTED from sole trader's: an
 * unknown/errored answer here means "leave the search enabled", never
 * "disable it".
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const CONTROLLER = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const COUNTRIES_URL = 'https://registry.example/supported-countries';

function flush() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

function envelope(countries) {
    return { ok: true, status: 200, body: { supported_countries: countries } };
}

/**
 * A started component bound to a fake panel that only records `setDisabled`
 * calls, with sole-trader availability seeded so it never fetches.
 *
 * @param {function} fetchImpl
 * @param {boolean} [omitUrl] build a host with no `supportedCountriesUrl` at
 *        all, the way a host that has not wired this up yet does
 * @returns {object} `{ component, setDisabledCalls, fetchCalls }`
 */
function makeStartedComponent(fetchImpl, omitUrl) {
    const fetchCalls = [];
    const fetchSpy = function (url, opts) {
        fetchCalls.push(url);
        return fetchImpl(url, opts);
    };
    const Controller = loadAmdModule(CONTROLLER, {}, { fetch: fetchSpy });
    const identity = loadAmdModule(IDENTITY)();
    const setDisabledCalls = [];

    const host = {};
    Controller.HOST_CONTRACT.forEach(function (member) {
        host[member] = function () { return undefined; };
    });
    Object.assign(host, {
        config: { isCompanySearchEnabled: true, supportedCompanyTypes: { gb: [], no: [], es: [] } },
        Panel: function () {
            return {
                bind: function () {},
                isBound: function () { return true; },
                releaseField: function () {},
                syncChips: function () {},
                abortActiveRequest: function () {},
                setDisabled: function (disabled) { setDisabledCalls.push(disabled); }
            };
        },
        SoleTraderFlow: function () {
            this.listenForSignupResult = function () {};
            this.prefetchBuyer = function () { return Promise.resolve(null); };
            this.forgetAdoptions = function () {};
            this.forgetAutofilledBuyer = function () {};
            this.autofilledSoleTrader = function () { return null; };
            this.focusSignupPopup = function () { return false; };
            this.launchSignup = function () { return null; };
        },
        identity: identity,
        search: {},
        addressFieldSelector: '#company',
        tileFieldSelector: '',
        fieldExists: function (selector) { return selector === '#company'; },
        isVirtualCart: function () { return false; },
        getAdjacentCountry: function () { return null; },
        getQuoteCountry: function () { return 'gb'; },
        getFallbackCountry: function () { return ''; },
        watchCountryChanges: function () {},
        supportedCompanyTypesUrl: function (country) { return `https://registry.example/types/${country}`; }
    });
    if (!omitUrl) host.supportedCountriesUrl = function () { return COUNTRIES_URL; };

    const component = new Controller(host);
    return { component: component, setDisabledCalls: setDisabledCalls, fetchCalls: fetchCalls };
}

describe.each([
    ['gb', ['GB', 'NO'], false, 'a country in the supported list stays enabled'],
    ['fr', ['GB', 'NO'], true, 'a country outside the supported list is disabled'],
    ['gb', ['gb', 'no'], false, 'the comparison is case-insensitive']
])('country %s vs supported list %j', (country, supportedCountries, expectDisabled, description) => {
    test(description, async () => {
        const { component, setDisabledCalls } = makeStartedComponent(function () {
            return Promise.resolve({ ok: true, json: () => Promise.resolve(envelope(supportedCountries)) });
        });
        component.start();
        await flush();

        component.onCountryChanged(country);
        await flush();

        expect(setDisabledCalls[setDisabledCalls.length - 1]).toBe(expectDisabled);
    });
});

describe('fail-open: an unknown or errored answer never disables the search', () => {
    test('no supportedCountriesUrl wired up at all', async () => {
        const { component, setDisabledCalls, fetchCalls } = makeStartedComponent(function () {
            throw new Error('must not fetch with no URL builder');
        }, true);
        component.start();
        await flush();

        expect(fetchCalls.length).toBe(0);
        expect(setDisabledCalls[setDisabledCalls.length - 1]).toBe(false);
    });

    test('the fetch rejects (network failure)', async () => {
        const { component, setDisabledCalls } = makeStartedComponent(function () {
            return Promise.reject(new Error('network down'));
        });
        component.start();
        await flush();

        expect(setDisabledCalls[setDisabledCalls.length - 1]).toBe(false);
    });

    test('the response is a non-ok HTTP status', async () => {
        const { component, setDisabledCalls } = makeStartedComponent(function () {
            return Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) });
        });
        component.start();
        await flush();

        expect(setDisabledCalls[setDisabledCalls.length - 1]).toBe(false);
    });

    test('the response body is malformed', async () => {
        const { component, setDisabledCalls } = makeStartedComponent(function () {
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, status: 200, body: {} }) });
        });
        component.start();
        await flush();

        expect(setDisabledCalls[setDisabledCalls.length - 1]).toBe(false);
    });
});

describe('the countries list is fetched once and memoised for the page lifetime', () => {
    test('a country change does not re-fetch', async () => {
        const { component, fetchCalls } = makeStartedComponent(function () {
            return Promise.resolve({ ok: true, json: () => Promise.resolve(envelope(['GB'])) });
        });
        component.start();
        await flush();
        expect(fetchCalls.length).toBe(1);

        component.onCountryChanged('no');
        await flush();
        component.onCountryChanged('gb');
        await flush();

        expect(fetchCalls.length).toBe(1);
    });

    test('an errored fetch is NOT memoised and retries on the next country change', async () => {
        let attempt = 0;
        const { component, fetchCalls } = makeStartedComponent(function () {
            attempt++;
            return Promise.reject(new Error('down'));
        });
        component.start();
        await flush();
        expect(fetchCalls.length).toBe(1);

        component.onCountryChanged('no');
        await flush();

        expect(fetchCalls.length).toBe(2);
        expect(attempt).toBe(2);
    });
});

describe('a supported -> unsupported -> supported round trip', () => {
    test('setDisabled tracks each transition', async () => {
        const { component, setDisabledCalls } = makeStartedComponent(function () {
            return Promise.resolve({ ok: true, json: () => Promise.resolve(envelope(['GB'])) });
        });
        component.start();
        await flush();
        expect(setDisabledCalls[setDisabledCalls.length - 1]).toBe(false);

        component.onCountryChanged('fr');
        await flush();
        expect(setDisabledCalls[setDisabledCalls.length - 1]).toBe(true);

        component.onCountryChanged('gb');
        await flush();
        expect(setDisabledCalls[setDisabledCalls.length - 1]).toBe(false);
    });
});
