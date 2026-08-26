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

const { loadAmdModule, defaultMocks } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const CAPTURE = 'view/frontend/web/js/model/company-capture.js';
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
    // Delegated handlers registered through `$(document).on(events, sel, fn)`,
    // recorded rather than really delegated — the spec fires them by hand, so
    // what is under test is the renderer's wiring and effect rather than a
    // reimplementation of jQuery's delegation.
    const delegated = [];

    function wrap(nodes) {
        return {
            length: nodes.length,
            first: function () {
                return wrap(nodes.slice(0, 1));
            },
            val: function () {
                return nodes.length ? nodes[0].value : undefined;
            },
            on: function (events, selector, handler) {
                delegated.push({ events: events, selector: selector, handler: handler });
                return this;
            },
            off: function (events) {
                for (let i = delegated.length - 1; i >= 0; i--) {
                    if (delegated[i].events.indexOf(events) !== -1) delegated.splice(i, 1);
                }
                return this;
            }
        };
    }
    function $(selector) {
        if (typeof selector !== 'string') return wrap([]);
        return wrap(Array.prototype.slice.call(document.querySelectorAll(selector)));
    }
    $.delegated = delegated;
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
        countryCode: plainObservable(observableCountry || ''),
        // fillCountryCode()'s downstream work, stubbed so the specs below can
        // observe the country landing without also driving the
        // supported-company-types fetch and the sole-trader tab.
        getSupportedCompanyTypes: function () {
            return { then: function () {} };
        },
        clearCompanyForCountryChange: function () {
            this._cleared = true;
        }
    });
}

afterEach(() => {
    document.body.innerHTML = '';
    $.delegated.length = 0;
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
        expect(ctx.searchCountryCode()).toBe('no');
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
                return ctx.searchCountryCode();
            }
        });
        const url = ajax.url({ term: 'acme', page: 1 });
        expect(url).toContain('country=GB');
        expect(url).not.toContain('country=&');
    });

    test('the core form\'s select is the highest-priority DOM source', () => {
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '  <select name="country_id"><option value="SE" selected>SE</option></select>' +
            '</form>' +
            // A second select carrying a different country — a per-payment-method
            // billing form the buyer has not touched. The core shipping form must
            // win, or an untouched form's store default decides the search.
            '<form id="billing-new-address-form">' +
            '  <select name="country_id"><option value="US" selected>US</option></select>' +
            '</form>';

        expect(loadCompanySearch().currentAddressFormCountry()).toBe('se');
    });

    test('an UNTOUCHED DOM select can never beat a resolved country — the Luma/Amasty non-regression', () => {
        // Core renders `#shipping-new-address-form` inside the HIDDEN
        // new-address modal for a customer who has saved addresses: the select
        // exists, holds the store default, and the buyer has never seen it. The
        // quote-derived country must win, or this fix reintroduces the very bug
        // it exists to close on the platforms that already worked.
        document.body.innerHTML =
            '<div id="opc-new-shipping-address" style="display:none">' +
            '  <form id="shipping-new-address-form">' +
            '    <select name="country_id"><option value="US" selected>US</option></select>' +
            '  </form>' +
            '</div>';

        const companySearch = loadCompanySearch();
        expect(companySearch.currentAddressFormCountry()).toBe('us');
        expect(makeCtx(companySearch, 'no').searchCountryCode()).toBe('no');
    });

    test('a live selection change reaches countryCode() through the delegated watcher', () => {
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '  <select name="country_id">' +
            '    <option value="GB" selected>GB</option><option value="NO">NO</option>' +
            '  </select>' +
            '</form>';

        const companySearch = loadCompanySearch();
        const ctx = makeCtx(companySearch, 'gb');
        ctx.watchAddressFormCountry();

        // Delegated off the document, on the country select, so a re-rendered
        // address form needs no re-binding.
        expect($.delegated).toHaveLength(1);
        expect($.delegated[0].events).toMatch(/^change\./);
        expect($.delegated[0].selector).toBe('select[name="country_id"]');

        // The buyer switches country; the handler fires.
        document.querySelector('select[name="country_id"]').value = 'NO';
        $.delegated[0].handler();

        expect(ctx.countryCode()).toBe('no');
        expect(ctx.searchCountryCode()).toBe('no');
        // A country change must also retract the company captured under the
        // previous one (TWO-24867) — silently absent on this checkout before.
        expect(ctx._cleared).toBe(true);
    });

    test('each instance gets its own watcher namespace, and dispose() detaches only its own', () => {
        const companySearch = loadCompanySearch();
        // ONE module load, two instances built from it — the real shape (one
        // RequireJS module, a renderer pushed per Two-family brand). Two
        // separate loads would each get their own module-scope sequence and the
        // namespaces would collide at 1, testing the harness rather than the
        // renderer.
        const component = loadAmdModule(RENDERER, {
            jquery: $,
            'Two_Gateway/js/model/company-search': companySearch
        });
        const instance = function () {
            return Object.assign({}, component, {
                countryCode: plainObservable(''),
                getSupportedCompanyTypes: function () {
                    return { then: function () {} };
                }
            });
        };
        const a = instance();
        const b = instance();
        a.watchAddressFormCountry();
        b.watchAddressFormCountry();

        expect($.delegated).toHaveLength(2);
        expect(a.companyCapture()._countryWatcherNs).not.toBe(
            b.companyCapture()._countryWatcherNs
        );

        Object.assign(a, { _super: function () {} });
        a.dispose();
        expect($.delegated).toHaveLength(1);
        expect($.delegated[0].events).toContain(b.companyCapture()._countryWatcherNs);
    });

    test('falls back to the DOM where there is NO resolved country yet, and to the observable where there is no select', () => {
        // The two carve-outs isTileCompanySearchActive() documents — a saved
        // address and a virtual cart — render no address form at all, so the
        // quote-derived country is the only country there is.
        document.body.innerHTML = '<div class="payment-method"></div>';
        const companySearch = loadCompanySearch();
        expect(companySearch.currentAddressFormCountry()).toBe('');
        expect(makeCtx(companySearch, 'nl').searchCountryCode()).toBe('nl');

        // Neither source has anything: no country, rather than a wrong one.
        expect(makeCtx(companySearch, '').searchCountryCode()).toBe('');
    });

    test('an address-form select with no value chosen contributes nothing', () => {
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '  <select name="country_id"><option value="" selected></option></select>' +
            '</form>';

        const companySearch = loadCompanySearch();
        expect(companySearch.currentAddressFormCountry()).toBe('');
        expect(makeCtx(companySearch, 'dk').searchCountryCode()).toBe('dk');
        expect(makeCtx(companySearch, '').searchCountryCode()).toBe('');
    });

    test('the getCountryCode the CONTROL is actually constructed with resolves the selected country', () => {
        // End to end through enableCompanySearch(), not a direct call to the
        // getter: the wiring is the part that regressed, and a spec that only
        // calls searchCountryCode() itself would pass against a control still
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
            _brandConfig: { checkoutApiUrl: 'https://api.example.test', companySearchLimit: 10 }
        });
        // On the capture, not the host: enableCompanySearch() gates on its own
        // isTileCompanySearchActive(), so an override on the renderer is never
        // read and the tile-active path would be reached only incidentally.
        ctx.companyCapture().isTileCompanySearchActive = function () {
            return true;
        };

        ctx.enableCompanySearch();
        expect(captured).not.toBeNull();
        expect(typeof captured.getCountryCode).toBe('function');
        expect(captured.getCountryCode()).toBe('no');
    });

    test('the renderer wires searchCountryCode() into the control, not the raw observable', () => {
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');
        const captureSrc = fs.readFileSync(path.resolve(__dirname, '..', '..', CAPTURE), 'utf8');
        // The tile's CompanySearchControl construction — pinned so a drift
        // back to `self.countryCode()` fails here rather than only showing up
        // as a wrong country on one checkout variant nobody re-tests.
        const getter = captureSrc.match(
            /getCountryCode:\s*function\s*\(\)\s*\{\s*return\s+([^;]+);/
        );
        expect(getter).not.toBeNull();
        expect(getter[1].trim()).toBe('self.searchCountryCode()');

        // …and the watcher is actually armed on init. Every behavioural spec
        // above calls watchAddressFormCountry() itself, so without this a drop
        // of the initialize() call would leave the whole suite green while
        // nothing on a real checkout ever attached the handler.
        const initialize = src.match(/initialize:\s*function\s*\(\)\s*\{[\s\S]*?\n {8}\},/);
        expect(initialize).not.toBeNull();
        expect(initialize[0]).toMatch(/this\.watchAddressFormCountry\(\);/);
    });
});

/**
 * A renderer context whose quote carries a billing address, so the
 * billing-ROLE read can be observed against the shipping-biased feeds it now
 * precedes.
 *
 * @param {object} companySearch real company-search module
 * @param {string} observableCountry value for `countryCode()`
 * @param {?string} billingCountry `quote.billingAddress().countryId`, or null
 *        for a quote with no billing address yet
 * @returns {object}
 */
function makeCtxWithBilling(companySearch, observableCountry, billingCountry) {
    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: plainObservable(
            billingCountry === null ? null : { countryId: billingCountry }
        )
    });
    const component = loadAmdModule(RENDERER, {
        jquery: $,
        'Two_Gateway/js/model/company-search': companySearch,
        'Magento_Checkout/js/model/quote': quote
    });
    return Object.assign({}, component, {
        countryCode: plainObservable(observableCountry || '')
    });
}

describe('the tile resolves the BILLING-role country (TWO-25461 §1(a.3))', () => {
    test.each([
        ['NO', 'gb', 'gb', 'no', 'billing beats a shipping-fed observable'],
        ['NO', '', 'gb', 'no', 'billing beats the shipping-first DOM fallback'],
        ['no', 'gb', 'gb', 'no', 'an already-lower-cased billing country is unchanged'],
        [null, 'gb', 'se', 'gb', 'no billing address yet: the observable still wins'],
        ['', 'gb', 'se', 'gb', 'an empty billing country is not an answer'],
        [undefined, '', 'se', 'se', 'neither quote nor observable: the DOM fallback stands']
    ])(
        'billing=%p observable=%p dom=%p -> %p (%s)',
        (billingCountry, observableCountry, domCountry, expected) => {
            // A shipping form whose country DIFFERS from the billing address —
            // the case every pre-existing feed gets wrong. A buyer shipping to
            // one country and invoicing in another is ordinary, and the tile's
            // country gates sole-trader availability and the company search.
            document.body.innerHTML =
                '<form id="shipping-new-address-form">' +
                `  <select name="country_id"><option value="${domCountry.toUpperCase()}" ` +
                'selected></option></select>' +
                '</form>';

            const companySearch = loadCompanySearch();
            const ctx = makeCtxWithBilling(
                companySearch,
                observableCountry,
                billingCountry === undefined ? '' : billingCountry
            );

            expect(ctx.searchCountryCode()).toBe(expected);
        }
    );

    test('billingRoleCountryCode() survives a null billing address', () => {
        // placeOrderIntent()'s own guard exists because this observable can be
        // null for a transient window; a getter that threw there would take the
        // whole search down with it.
        const ctx = makeCtxWithBilling(loadCompanySearch(), '', null);
        expect(ctx.billingRoleCountryCode()).toBe('');
        expect(ctx.searchCountryCode()).toBe('');
    });

    test('the billing country comes from the quote, not from a mirror of its own', () => {
        // §1 asks for ONE resolution reused everywhere. The quote double is the
        // only place this value exists in the spec, so a getter that re-derived
        // the country from anywhere else answers ''.
        document.body.innerHTML = '';
        const ctx = makeCtxWithBilling(loadCompanySearch(), '', 'DK');
        expect(ctx.billingRoleCountryCode()).toBe('dk');
        expect(ctx.searchCountryCode()).toBe('dk');
    });
});
