/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326, Fire Checkout: the payment tile's company search ran against the
 * search API's own default country (US) whatever the buyer had actually
 * selected in the address form — on Fire Checkout only, never on Luma or
 * Amasty.
 *
 * Mechanism, and it is a SOURCING gap rather than a hardcoded country
 * anywhere: the tile's picker asked `countryCode()`, a ko observable with
 * exactly two feeds —
 *
 *   1. the `countryCode` customer-data section, written ONLY by
 *      address-autocomplete.js, and only once
 *      `$.async('#shipping-new-address-form select[name="country_id"]')`
 *      resolves;
 *   2. updateAddress(), reading the country off the quote's PERSISTED
 *      billing/shipping address.
 *
 * Luma and Amasty render the core `#shipping-new-address-form` markup, so
 * feed 1 fires on every country change and the observable is current before
 * the buyer can search. A one-page checkout that supplies its own address
 * markup matches neither that selector, nor — until the address is saved —
 * has a persisted quote address to read; `fillCountryCode()` early-returns on
 * an empty value, so the observable stayed at its `''` default and
 * buildSearchAjaxOptions() put an EMPTY `country=` on the search URL.
 *
 * Fixed by sourcing the tile's search country from the buyer's actual
 * `<select>` first (companySearch.currentAddressFormCountry(), a priority list
 * of address-form selectors ending in a catch-all), with the observable kept
 * as the fallback for the checkouts that genuinely have no address-form select
 * — a saved address and a virtual cart.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';

/**
 * Minimal jQuery double backed by the REAL jsdom document.
 *
 * Hand-rolled rather than `require('jquery')` because jQuery is not a
 * devDependency of this module's JS test manifest (package.json declares jest
 * and jsdom only) — the existing specs all supply their own doubles for the
 * same reason. It implements exactly the three things
 * currentAddressFormCountry() uses — `$(selector)`, `.first()`, `.length`,
 * `.val()` — over `document.querySelectorAll`, so the selector list under test
 * is genuinely evaluated against real markup rather than against a recorder
 * that would answer whatever it was told to.
 *
 * @returns {Function} jQuery-shaped selector function
 */
function makeDollar() {
    function wrap(nodes) {
        return {
            length: nodes.length,
            first: function () {
                return wrap(nodes.slice(0, 1));
            },
            val: function () {
                return nodes.length ? nodes[0].value : undefined;
            }
        };
    }
    function $(selector) {
        if (typeof selector !== 'string') return wrap([]);
        return wrap(Array.prototype.slice.call(document.querySelectorAll(selector)));
    }
    $.async = function (selector, cb) {
        cb($(selector));
    };
    $.ajax = function () {
        return {
            done: function () { return this; },
            fail: function () { return this; },
            always: function () { return this; }
        };
    };
    return $;
}

const $ = makeDollar();

/** Plain (non-ko) observable factory, matching the sibling specs. */
function plainObservable(initial) {
    let v = initial;
    const fn = function (next) {
        if (!arguments.length) return v;
        v = next;
        return fn;
    };
    return fn;
}

/**
 * The REAL company-search module, closed over the real jQuery so its DOM
 * reads hit the jsdom document this spec builds. Loading the real module is
 * the point: the harness's inert double returns '' from
 * currentAddressFormCountry(), which would make every assertion below pass
 * without the production selector list existing at all.
 *
 * @returns {object} company-search module
 */
function loadCompanySearch() {
    return loadAmdModule(SEARCH, { jquery: $ });
}

/**
 * A renderer context whose only country state is the (empty, as on a
 * pre-persistence one-page checkout) `countryCode` observable.
 *
 * @param {object} companySearch real company-search module
 * @param {string} observableCountry value for `countryCode()`
 * @returns {object}
 */
function makeCtx(companySearch, observableCountry) {
    const component = loadAmdModule(RENDERER, {
        jquery: $,
        'Two_Gateway/js/model/company-search': companySearch
    });
    return Object.assign({}, component, {
        countryCode: plainObservable(observableCountry || '')
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('payment-tile company search sources the country the buyer selected (TWO-25326)', () => {
    test('reads a one-page checkout\'s own address-form country select, which no `#shipping-new-address-form` selector matches', () => {
        // Deliberately NOT `#shipping-new-address-form`: this is the shape of
        // checkout the bug was reported on — the core form id is absent, so
        // the address-area component never mounts against it and never
        // publishes the `countryCode` section.
        document.body.innerHTML =
            '<div id="firecheckout-address">' +
            '  <select name="country_id"><option value="US">US</option>' +
            '    <option value="NO" selected>NO</option></select>' +
            '</div>';

        const companySearch = loadCompanySearch();
        expect(companySearch.currentAddressFormCountry()).toBe('no');

        const ctx = makeCtx(companySearch, '');
        // The observable — the ONLY source before this fix — is empty, which
        // is exactly the state that produced an empty `country=` on the wire.
        expect(ctx.countryCode()).toBe('');
        expect(ctx.currentCountryCode()).toBe('no');
    });

    test('the country reaching the search URL is the selected one, not an empty default', () => {
        document.body.innerHTML =
            '<div id="firecheckout-address">' +
            '  <select name="country_id"><option value="GB" selected>GB</option></select>' +
            '</div>';

        const companySearch = loadCompanySearch();
        const ctx = makeCtx(companySearch, '');

        // Built the way the control builds it, so the assertion covers the
        // whole path from the renderer's getCountryCode through to the URL —
        // not just the getter in isolation.
        const ajax = companySearch.buildSearchAjaxOptions({
            config: { checkoutApiUrl: 'https://api.example.test', companySearchLimit: 10 },
            token: {},
            getCountryCode: function () {
                return ctx.currentCountryCode();
            }
        });
        const url = ajax.url({ term: 'acme', page: 1 });
        expect(url).toContain('country=GB');
        expect(url).not.toContain('country=&');
    });

    test('Luma/Amasty are unchanged: the core form\'s select is the highest-priority source', () => {
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '  <select name="country_id"><option value="SE" selected>SE</option></select>' +
            '</form>' +
            // A second, lower-priority select carrying a different country —
            // a billing form the buyer has not touched. The shipping form must
            // win, or a store with "my billing address is different" open
            // would search the wrong country.
            '<form id="billing-new-address-form">' +
            '  <select name="country_id"><option value="US" selected>US</option></select>' +
            '</form>';

        const companySearch = loadCompanySearch();
        expect(companySearch.currentAddressFormCountry()).toBe('se');
        expect(makeCtx(companySearch, 'se').currentCountryCode()).toBe('se');
    });

    test('a live selection change is picked up on the NEXT search, with no rebind', () => {
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '  <select name="country_id">' +
            '    <option value="GB" selected>GB</option><option value="NO">NO</option>' +
            '  </select>' +
            '</form>';

        const companySearch = loadCompanySearch();
        const ctx = makeCtx(companySearch, 'gb');
        expect(ctx.currentCountryCode()).toBe('gb');

        // The buyer switches country. Nothing re-binds the picker (see
        // clearCompanyForCountryChange()'s docblock) — the read is per
        // request, so the next search has to carry the new country.
        document.querySelector('select[name="country_id"]').value = 'NO';
        expect(ctx.currentCountryCode()).toBe('no');
    });

    test('falls back to the quote-derived observable where there is NO address-form select at all', () => {
        // The two carve-outs isTileCompanySearchActive() documents: a saved
        // address and a virtual cart both render no address form, so the
        // quote-derived country is the only country there is.
        document.body.innerHTML = '<div class="payment-method"></div>';

        const companySearch = loadCompanySearch();
        expect(companySearch.currentAddressFormCountry()).toBe('');
        expect(makeCtx(companySearch, 'nl').currentCountryCode()).toBe('nl');
    });

    test('an address-form select with no value chosen falls back rather than sending an empty country', () => {
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '  <select name="country_id"><option value="" selected></option></select>' +
            '</form>';

        const companySearch = loadCompanySearch();
        expect(companySearch.currentAddressFormCountry()).toBe('');
        expect(makeCtx(companySearch, 'dk').currentCountryCode()).toBe('dk');
    });

    test('the getCountryCode the CONTROL is actually constructed with resolves the selected country', () => {
        // End to end through enableCompanySearch(), not a direct call to the
        // getter: the wiring is the part that regressed, and a spec that only
        // calls currentCountryCode() itself would pass against a control still
        // constructed with the raw observable.
        document.body.innerHTML =
            '<div id="firecheckout-address">' +
            '  <select name="country_id"><option value="NO" selected>NO</option></select>' +
            '</div>';

        const companySearch = loadCompanySearch();
        let captured = null;
        function RecordingControl(options) {
            captured = options;
        }
        RecordingControl.prototype.bind = function () {};

        const component = loadAmdModule(RENDERER, {
            jquery: $,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-control': RecordingControl
        });
        const ctx = Object.assign({}, component, {
            countryCode: plainObservable(''),
            _brandConfig: { checkoutApiUrl: 'https://api.example.test', companySearchLimit: 10 },
            isTileCompanySearchActive: function () {
                return true;
            }
        });

        ctx.enableCompanySearch();
        expect(captured).not.toBeNull();
        expect(typeof captured.getCountryCode).toBe('function');
        expect(captured.getCountryCode()).toBe('no');
    });

    test('the renderer wires currentCountryCode() into the control, not the raw observable', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');
        // The tile's CompanySearchControl construction — pinned so a drift
        // back to `self.countryCode()` fails here rather than only showing up
        // as a wrong country on one checkout variant nobody re-tests.
        const getter = src.match(/getCountryCode:\s*function\s*\(\)\s*\{\s*return\s+([^;]+);/);
        expect(getter).not.toBeNull();
        expect(getter[1].trim()).toBe('self.currentCountryCode()');
    });
});
