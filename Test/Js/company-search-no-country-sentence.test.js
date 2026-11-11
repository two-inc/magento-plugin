/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-27235: with no country to search in, company search says "Select your
 * country above to search for your company." instead of searching, and
 * instead of hiding the search row with nothing said. That holds on load with
 * no country and when the buyer clears the select; re-selecting a country
 * brings normal search back. No request with an empty country leaves the
 * browser.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel, dispatchNative } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const CONTROLLER = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const COUNTRIES_URL = 'https://registry.example/supported-countries';
const GLOBALS = { document: document, window: window };

const SENTENCE = 'Select your country above to search for your company.';
const NO_MATCHES = 'No matches found';

function flush() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** `$.ajax` replaced by a recorder whose requests the test settles by hand. */
function installAjaxRecorder() {
    const requests = [];
    $.ajax = function (options) {
        const done = [];
        const jqxhr = {
            options: options,
            done: function (fn) { done.push(fn); return jqxhr; },
            fail: function () { return jqxhr; },
            always: function () { return jqxhr; },
            abort: function () {},
            settle: function (body) {
                const payload = JSON.stringify({ ok: true, status: 200, body: body });
                done.forEach(function (fn) { fn(payload); });
            }
        };
        requests.push(jqxhr);
        return jqxhr;
    };
    return requests;
}

function loadCompanySearch() {
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    companySearch.clearResultCache();
    return companySearch;
}

function search(companySearch, country) {
    return companySearch.searchCompanies({
        config: { isCompanySearchEnabled: true },
        token: {},
        scope: {},
        term: 'acme',
        getCountryCode: function () { return country; }
    });
}

describe('the search itself answers a missing country with the sentence', () => {
    test.each([
        ['', 0, SENTENCE, 'an empty country sends nothing and words the empty answer as the sentence'],
        [undefined, 0, SENTENCE, 'an unresolved country sends nothing and words the empty answer as the sentence'],
        ['gb', 1, NO_MATCHES, 'a selected country searches, and an empty answer is still "no matches"'],
    ])('country %j', async (country, requestCount, message, description) => {
        const requests = installAjaxRecorder();
        const companySearch = loadCompanySearch();

        const answer = search(companySearch, country);
        requests.forEach(function (request) { request.settle({ items: [] }); });
        const result = await answer;

        expect({ requests: requests.length, description }).toEqual({ requests: requestCount, description });
        expect({ result, description }).toEqual({
            result: { items: [], unavailable: false, aborted: false },
            description
        });
        expect({ message: companySearch.noResultsMessage(), description }).toEqual({ message, description });
    });

    test('re-selecting a country after a no-country answer restores the ordinary wording', async () => {
        const requests = installAjaxRecorder();
        const companySearch = loadCompanySearch();

        await search(companySearch, '');
        expect(companySearch.noResultsMessage()).toBe(SENTENCE);

        const answer = search(companySearch, 'no');
        requests[0].settle({ items: [] });
        await answer;

        expect(requests[0].options.data).toContain('"country":"NO"');
        expect(companySearch.noResultsMessage()).toBe(NO_MATCHES);
    });
});

/**
 * A started component on a fake panel that records `setDisabled` and aborts,
 * with the country read from `state.country`, the way the host's select reads.
 */
function makeStartedComponent(state, overrides) {
    const Controller = loadAmdModule(CONTROLLER, {}, {
        fetch: function () {
            const envelope = JSON.stringify({ ok: true, status: 200, body: { supported_countries: ['GB', 'NO'] } });
            return Promise.resolve({ ok: true, json: () => Promise.resolve(envelope) });
        }
    });
    const identity = loadAmdModule(IDENTITY)();
    const calls = { disabled: [], aborts: 0 };
    const host = {};
    Controller.HOST_CONTRACT.forEach(function (member) {
        host[member] = function () { return undefined; };
    });
    Object.assign(host, {
        config: { isCompanySearchEnabled: true, supportedCompanyTypes: { gb: [], no: [], fr: [] } },
        Panel: function () {
            return {
                bind: function () {},
                isBound: function () { return true; },
                releaseField: function () {},
                syncChips: function () {},
                abortActiveRequest: function () { calls.aborts++; },
                setDisabled: function (disabled) { calls.disabled.push(disabled); }
            };
        },
        SoleTraderFlow: function () {
            this.listenForSignupResult = function () {};
            this.prefetchBuyer = function () { return Promise.resolve(null); };
            this.autofilledSoleTrader = function () { return null; };
            this.matchingSoleTrader = function () { return null; };
        },
        identity: identity,
        search: {},
        addressFieldSelector: '#company',
        tileFieldSelector: '',
        fieldExists: function (selector) { return selector === '#company'; },
        isVirtualCart: function () { return false; },
        getAdjacentCountry: function () { return null; },
        getQuoteCountry: function () { return state.country; },
        getFallbackCountry: function () { return ''; },
        watchCountryChanges: function () {},
        supportedCompanyTypesUrl: function (country) { return `https://registry.example/types/${country}`; },
        supportedCountriesUrl: function () { return COUNTRIES_URL; }
    }, overrides || {});
    const component = new Controller(host);
    return { component, calls };
}

describe('the search row stays up for a missing country, and comes back on re-selection', () => {
    // Each step sets the select (`null` leaves it), notifies the component the
    // way the host's change listener does, and states whether the row is
    // withdrawn afterwards. The registry covers GB and NO, not FR.
    test.each([
        [[['', false]], 'on load with no country the row is offered, not hidden'],
        [[['gb', false], ['', false]], 'clearing a covered country keeps the row up'],
        [[['fr', true], ['', false]], 'clearing an uncovered country brings the row back for the sentence'],
        [[['gb', false], ['', false], ['gb', false]], 're-selecting the same covered country restores search'],
        [[['fr', true], ['', false], ['fr', true]], 're-selecting the same uncovered country withdraws the row again'],
        [[['', false], ['no', false]], 'selecting a country after loading without one restores search'],
    ])('%j', async (steps, description) => {
        const state = { country: steps[0][0] };
        const { component, calls } = makeStartedComponent(state);
        component.start();
        await flush();
        // Before any notification, so the load row answers for start() alone.
        expect({ withdrawn: calls.disabled[calls.disabled.length - 1], description, at: 'start' })
            .toEqual({ withdrawn: steps[0][1], description, at: 'start' });

        for (const [country, withdrawn] of steps) {
            state.country = country;
            component.onCountryChanged(country);
            await flush();
            expect({ withdrawn: calls.disabled[calls.disabled.length - 1], description })
                .toEqual({ withdrawn, description });
        }
    });

    // Each step notifies the component of a country, starting from GB, and
    // the row states how many searches on the wire the whole run aborts.
    test.each([
        [[''], 1, 'clearing the country aborts a search still on the wire for the old one'],
        [['', ''], 1, 'a second empty notification aborts nothing'],
        [['', 'gb', ''], 2, 'clearing again after re-selecting a country aborts again'],
    ])('%j', async (steps, aborts, description) => {
        const state = { country: 'gb' };
        const { component, calls } = makeStartedComponent(state);
        component.start();
        await flush();

        for (const country of steps) {
            state.country = country;
            component.onCountryChanged(country);
        }

        expect({ aborts: calls.aborts, description }).toEqual({ aborts, description });
    });

    test('a repeated empty-country notification leaves a typed search to answer with the sentence', async () => {
        document.body.innerHTML = '<div class="field"><div class="control"><input id="company" /></div></div>';
        const requests = installAjaxRecorder();
        const companySearch = loadCompanySearch();
        companySearch.SEARCH_DEBOUNCE_MS = 20;
        const state = { country: 'gb' };
        const { component } = makeStartedComponent(state, {
            Panel: loadCompanySearchPanel($, companySearch, GLOBALS),
            search: companySearch
        });
        component.start();
        await flush();

        state.country = '';
        component.onCountryChanged('');
        component.panel().open();
        dispatchNative(document.querySelector('.two-company-dropdown__query'), 'input', 'acme');
        // The checkout re-notifies with the country still empty (a totals
        // reload, a re-render) while the typed search waits out its debounce.
        component.onCountryChanged('');
        await new Promise((resolve) => setTimeout(resolve, 40));

        expect(requests).toHaveLength(0);
        expect(document.querySelector('.two-company-dropdown__spinner')
            .classList.contains('two-company-dropdown__spinner--active')).toBe(false);
        expect(document.querySelector('.two-company-dropdown__message').textContent).toBe(SENTENCE);
    });
});
