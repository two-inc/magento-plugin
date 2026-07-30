/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Proves, for EACH of the two Luma surfaces independently, that
 * the searching state actually reaches visible spinner markup.
 *
 * Both surfaces drive the spinner through a shared model, so it is easy to
 * assume "the model is tested, therefore both surfaces work". That does not
 * follow: each surface wires its own `onSearching` callback and stamps its
 * own bind token, and a surface whose wiring is present but not connected to
 * markup would show no spinner at all while every model-level test stayed
 * green. The default AMD harness stubs `setSearching` to a no-op, so no other
 * suite covers this path.
 *
 * Method: load the REAL company-search model into the REAL surface module
 * (the injection route amd-harness.js documents), call enableCompanySearch(),
 * then drive the real select2 `transport` the surface built — which is what
 * fires `onSearching(true)` internally. Appends land in a recording stand-in
 * for select2's runtime-created `.select2-search--dropdown`.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true
};

/** Stands in for select2's own search box, recording what gets appended. */
function makeSearchBox() {
    const appended = [];
    const box = {
        length: 1,
        appended: appended,
        append: function (html) {
            appended.push(html);
            return box;
        },
        find: function (selector) {
            const needle = selector.replace(/^\./, '');
            const hits = appended.filter((h) => h.indexOf(needle) !== -1);
            return {
                length: hits.length,
                remove: function () {
                    hits.forEach((h) => appended.splice(appended.indexOf(h), 1));
                }
            };
        }
    };
    return box;
}

function makeJQuery(recorder) {
    function $() {
        const obj = {
            length: 0,
            val: function (v) {
                if (arguments.length) return obj;
                return '';
            },
            trigger: function () { return obj; },
            prop: function () { return obj; },
            text: function () { return obj; },
            attr: function () { return obj; },
            off: function () { return obj; },
            hide: function () { return obj; },
            show: function () { return obj; },
            closest: function () { return obj; },
            append: function () { return obj; },
            data: function (key, value) {
                if (arguments.length > 1) {
                    recorder.data[key] = value;
                    return obj;
                }
                return recorder.data[key];
            },
            // The chrome walks $field.data('select2').$dropdown.find(...).
            find: function (selector) {
                if (selector === '.select2-search--dropdown') return recorder.searchBox;
                return obj;
            },
            select2: function (opts) {
                if (typeof opts === 'object') {
                    recorder.select2Options = opts;
                    // select2 stashes its instance on the node; the dropdown
                    // is created by select2 itself, not by any repo template.
                    recorder.data['select2'] = { $dropdown: obj };
                }
                return obj;
            },
            on: function (evt, handler) {
                recorder.handlers[evt.split('.')[0]] = handler;
                return obj;
            }
        };
        return obj;
    }
    $.async = function (selector, fn) { fn(selector); };
    $.ajax = function () {
        const jqxhr = {
            done: function () { return jqxhr; },
            fail: function () { return jqxhr; },
            always: function () { return jqxhr; }
        };
        return jqxhr;
    };
    $.mage = { cookies: { get: function () { return null; } }, redirect: function () {} };
    $.Deferred = function () {
        const d = {
            resolve: function () { return d; },
            promise: function () { return d; },
            done: function () { return d; },
            fail: function () { return d; },
            always: function () { return d; }
        };
        return d;
    };
    $.extend = Object.assign;
    $.fn = {};
    return $;
}

function makeRecorder() {
    return { data: {}, handlers: {}, select2Options: null, searchBox: makeSearchBox() };
}

function loadCompanySearch($) {
    return loadAmdModule('view/frontend/web/js/model/company-search.js', { jquery: $ });
}

/** The assertion both surfaces must satisfy. */
function assertSpinnerReachesMarkup(recorder, companySearch) {
    companySearch.clearResultCache();
    const transport = recorder.select2Options.ajax.transport;
    expect(typeof transport).toBe('function');

    // Nothing painted until a search actually starts.
    expect(recorder.searchBox.appended).toHaveLength(0);

    // onSearching is closure-internal, so drive the real search path: the
    // transport select2 would call, which fires onSearching(true) itself.
    const handle = transport(
        { url: 'https://api.example.test/companies/v2/company?q=exa' },
        function () {},
        function () {}
    );
    expect(handle).toBeTruthy();

    expect(recorder.searchBox.appended).toHaveLength(1);
    const host = document.createElement('div');
    host.innerHTML = recorder.searchBox.appended[0];
    const spinner = host.querySelector('.two-company-search__spinner');
    expect(spinner).not.toBeNull();
    expect(spinner.children).toHaveLength(0);
    expect(spinner.getAttribute('aria-hidden')).toBe('true');
}

test('SHIPPING surface: searching state reaches visible spinner markup', () => {
    const recorder = makeRecorder();
    const $ = makeJQuery(recorder);
    const companySearch = loadCompanySearch($);

    const brandConfig = function () { return BASE_CONFIG; };
    brandConfig.getActiveTwoBrandCode = function () { return 'two_payment'; };
    brandConfig.getActiveTwoBrandConfig = function () { return BASE_CONFIG; };

    const component = loadAmdModule('view/frontend/web/js/view/address-autocomplete.js', {
        jquery: $,
        'Two_Gateway/js/model/brand-config': brandConfig,
        'Two_Gateway/js/model/company-search': companySearch
    });

    const ctx = Object.assign(Object.create(component.prototype || {}), {
        countrySelector: '#shipping-new-address-form select[name="country_id"]',
        companyNameSelector: '#shipping-new-address-form input[name="company"]',
        companyIdSelector:
            '#shipping-new-address-form input[name="custom_attributes[company_id]"]',
        enterDetailsManuallyButton: '#shipping_enter_details_manually',
        searchForCompanyButton: '#shipping_search_for_company',
        enterDetailsManuallyText: 'Enter details manually',
        searchForCompanyText: 'Search for company',
        companyNamePlaceholder: 'Enter company name to search',
        setCompanyData: function () {},
        addressLookup: component.addressLookup,
        enableCompanySearch: component.enableCompanySearch
    });

    ctx.enableCompanySearch();
    assertSpinnerReachesMarkup(recorder, companySearch);
});

test('PAYMENT surface: searching state reaches visible spinner markup', () => {
    const recorder = makeRecorder();
    const $ = makeJQuery(recorder);
    const companySearch = loadCompanySearch($);

    const component = loadAmdModule(
        'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
        {
            jquery: $,
            'Two_Gateway/js/model/company-search': companySearch
        }
    );

    const ctx = Object.assign(Object.create(component.prototype || {}), {
        companyNameSelector: 'input#company_name',
        enterDetailsManuallyButton: '#billing_enter_details_manually',
        searchForCompanyButton: '#billing_search_for_company',
        enterDetailsManuallyText: 'Enter details manually',
        searchForCompanyText: 'Search for company',
        _brandConfig: BASE_CONFIG,
        countryCode: function () { return 'gb'; },
        companyName: Object.assign(function () { return ''; }, {
            subscribe: function () { return { dispose: function () {} }; }
        }),
        fillCompanyData: function () {},
        addressLookup: component.addressLookup,
        enableCompanySearch: component.enableCompanySearch
    });

    ctx.enableCompanySearch();
    assertSpinnerReachesMarkup(recorder, companySearch);
});
