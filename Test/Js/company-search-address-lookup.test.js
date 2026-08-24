/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25193: the payment-step company picker dropped `lookup_id` when
 * mapping search results and never fired the company-detail request, so
 * picking a company on the payment step filled nothing. The shipping-step
 * picker did both. These tests pin the shared behaviour and both call sites.
 */

'use strict';

const { loadAmdModule, loadCompanySearchControl } = require('./amd-harness');

/**
 * jQuery test double that records $.ajax calls and the values written to
 * address inputs, and lets a test drive select2 option/handler capture.
 */
function makeSpyJQuery(recorder) {
    function $(selector) {
        const obj = {
            length: 0,
            val: function (v) {
                if (arguments.length) {
                    recorder.written.push([selector, v]);
                    return obj;
                }
                return recorder.values[selector];
            },
            trigger: function (evt) {
                recorder.triggered.push([selector, evt]);
                return obj;
            },
            prop: function () { return obj; },
            text: function () { return obj; },
            // A REAL attribute store, keyed by selector. An inert getter here
            // returned a truthy object for every read, which made the autofill
            // marker (and therefore the retraction of fields a payload says
            // nothing about) unobservable and every assertion about it vacuous.
            attr: function (name, value) {
                if (arguments.length > 1) {
                    recorder.attrs[selector] = recorder.attrs[selector] || {};
                    recorder.attrs[selector][name] = value;
                    return obj;
                }
                const bag = recorder.attrs[selector];
                return bag ? bag[name] : undefined;
            },
            removeAttr: function (name) {
                if (recorder.attrs[selector]) delete recorder.attrs[selector][name];
                return obj;
            },
            off: function () { return obj; },
            data: function (key, value) {
                if (arguments.length > 1) {
                    recorder.data[key] = value;
                    return obj;
                }
                return recorder.data[key];
            },
            closest: function () { return obj; },
            find: function () { return obj; },
            first: function () { return obj; },
            // A length-0 set: this fixture models the payment-tile case, where
            // no address form exists as a node and the writes go through the
            // module's document-wide branch. `each` therefore never calls back.
            each: function () { return obj; },
            append: function () { return obj; },
            hide: function () { return obj; },
            show: function () { return obj; },
            select2: function (opts) {
                if (typeof opts === 'object') {
                    recorder.select2Options = opts;
                }
                return obj;
            },
            on: function (evt, handler) {
                // The module namespaces its bindings ('select2:select.twoCompanySearch')
                // so it can clear only its own handlers on re-init; key on the
                // bare event name.
                recorder.handlers[evt.split('.')[0]] = handler;
                return obj;
            }
        };
        return obj;
    }
    $.async = function (selector, fn) { fn(selector); };
    $.ajax = function (opts) {
        recorder.ajax.push(opts);
        const jqxhr = {
            done: function (cb) { recorder.doneCallbacks.push(cb); return jqxhr; },
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
    return {
        ajax: [],
        doneCallbacks: [],
        written: [],
        triggered: [],
        values: {},
        attrs: {},
        data: {},
        handlers: {},
        select2Options: null
    };
}

function loadCompanySearch($) {
    return loadAmdModule('view/frontend/web/js/model/company-search.js', { jquery: $ });
}

const SEARCH_RESPONSE = {
    items: [
        {
            name: 'Example Trading Ltd',
            highlight: '<em>Example</em> Trading Ltd',
            national_identifier: { id: '12345678' },
            lookup_id: 'lookup-abc-123'
        }
    ]
};

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true,
    orderIntentConfig: {
        extensionPlatformName: 'magento2',
        extensionDBVersion: '1.0.0'
    }
};

const LOOKUP_URL =
    'https://api.example.test/companies/v2/company/lookup-abc-123?client=magento2&client_v=1.0.0';

describe('company-search shared module', () => {
    test('processResults carries lookup_id through as lookupId', () => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        const ajaxOptions = companySearch.buildSearchAjaxOptions({
            config: BASE_CONFIG,
            getCountryCode: function () { return 'gb'; }
        });
        const results = ajaxOptions.processResults(SEARCH_RESPONSE).results;

        expect(results).toHaveLength(1);
        expect(results[0].lookupId).toBe('lookup-abc-123');
        expect(results[0].companyId).toBe('12345678');
    });

    test('search url carries the uppercased country and paging window', () => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        const ajaxOptions = companySearch.buildSearchAjaxOptions({
            config: BASE_CONFIG,
            getCountryCode: function () { return 'gb'; }
        });
        const url = ajaxOptions.url({ page: 2, term: 'example' });

        expect(url).toContain('https://api.example.test/companies/v2/company?');
        expect(url).toContain('country=GB');
        expect(url).toContain('limit=50');
        expect(url).toContain('offset=50');
    });

    test('lookupCompanyAddress fetches the company and fills the address form', () => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        companySearch.lookupCompanyAddress(BASE_CONFIG, { lookupId: 'lookup-abc-123' });

        expect(recorder.ajax).toHaveLength(1);
        expect(recorder.ajax[0].url).toBe(
            LOOKUP_URL
        );

        recorder.doneCallbacks.forEach(function (cb) {
            cb({
                addresses: [
                    { city: 'London', postal_code: 'EC1A 1BB', street_address: '1 Example Street' }
                ]
            });
        });

        expect(recorder.written).toEqual(
            expect.arrayContaining([
                ['input[name="city"]', 'London'],
                ['input[name="postcode"]', 'EC1A 1BB'],
                ['input[name="street[0]"]', '1 Example Street']
            ])
        );
        // One `change` PER FIELD since TWO-25461, fired only once every value
        // has landed — the region can be appended to the city, so a listener
        // must never see the address half-written.
        expect(recorder.triggered).toEqual(
            expect.arrayContaining([
                ['input[name="city"]', 'change'],
                ['input[name="postcode"]', 'change'],
                ['input[name="street[0]"]', 'change']
            ])
        );
    });

    test('lookupCompanyAddress is a no-op when address search is disabled', () => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        const result = companySearch.lookupCompanyAddress(
            Object.assign({}, BASE_CONFIG, { isAddressSearchEnabled: false }),
            { lookupId: 'lookup-abc-123' }
        );

        expect(result).toBeNull();
        expect(recorder.ajax).toHaveLength(0);
        expect(recorder.written).toHaveLength(0);
    });

    test('lookupCompanyAddress is a no-op when the result has no lookupId', () => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        expect(companySearch.lookupCompanyAddress(BASE_CONFIG, {})).toBeNull();
        expect(recorder.ajax).toHaveLength(0);
    });
});

describe('payment-step company picker (gateway_method.js)', () => {
    function loadRenderer(recorder, $) {
        const companySearch = loadCompanySearch($);
        return {
            component: loadAmdModule(
                'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
                {
                    jquery: $,
                    'Two_Gateway/js/model/company-search': companySearch,
                    'Two_Gateway/js/model/company-search-control': loadCompanySearchControl(
                        $,
                        companySearch
                    )
                }
            )
        };
    }

    /**
     * Minimal renderer `this` for enableCompanySearch(): it only touches
     * selectors, the brand config, countryCode() and fillCompanyData().
     *
     * `isAddressAreaCompanySearchEnabled` defaults to true — the
     * saved-address / virtual-cart fallback, where "Enable company search in
     * address entry" is ON but there is no address-area form to host the
     * control. Pass false for the other tile case, where the setting is OFF
     * and the tile is the control's primary home. Since TWO-25503 neither
     * value gates autofill; it still decides whether the tile binds a picker.
     */
    function makeRendererContext(component, config, filled, addressAreaEnabled) {
        return Object.assign(Object.create(component.prototype || {}), {
            isAddressAreaCompanySearchEnabled:
                addressAreaEnabled === undefined ? true : addressAreaEnabled,
            companyNameSelector: 'input#company_name',
            enterDetailsManuallyButton: '#billing_enter_details_manually',
            enterDetailsManuallyText: 'Enter details manually',
            searchForCompanyButton: '#billing_search_for_company',
            searchForCompanyText: 'Search for company',
            _brandConfig: config,
            countryCode: function () { return 'gb'; },
            companyName: Object.assign(function () { return ''; }, {
                subscribe: function () { return { dispose: function () {} }; }
            }),
            fillCompanyData: function (data) { filled.push(data); },
            addressLookup: component.addressLookup,
            enableCompanySearch: component.enableCompanySearch
        });
    }

    test('captures lookupId and fills the address when both flags are on', () => {
        const recorder = makeRecorder();
        const $ = makeSpyJQuery(recorder);
        const { component } = loadRenderer(recorder, $);
        const filled = [];
        const ctx = makeRendererContext(component, BASE_CONFIG, filled);

        ctx.enableCompanySearch();

        // The renderer must hand select2 an ajax block that keeps lookup_id.
        expect(recorder.select2Options).not.toBeNull();
        const mapped = recorder.select2Options.ajax.processResults(SEARCH_RESPONSE).results[0];
        expect(mapped.lookupId).toBe('lookup-abc-123');

        // Picking that result must fire the detail lookup and fill the form.
        recorder.handlers['select2:select']({ params: { data: mapped } });

        expect(filled).toEqual([
            { companyId: '12345678', companyName: 'Example Trading Ltd' }
        ]);
        expect(recorder.ajax).toHaveLength(1);
        expect(recorder.ajax[0].url).toBe(
            LOOKUP_URL
        );

        recorder.doneCallbacks.forEach(function (cb) {
            cb({ addresses: [{ city: 'London', postal_code: 'EC1A 1BB', street_address: '1 Example Street' }] });
        });
        expect(recorder.written).toEqual(
            expect.arrayContaining([['input[name="city"]', 'London']])
        );
    });

    test('makes no detail call when address search is disabled', () => {
        const recorder = makeRecorder();
        const $ = makeSpyJQuery(recorder);
        const { component } = loadRenderer(recorder, $);
        const filled = [];
        const ctx = makeRendererContext(
            component,
            Object.assign({}, BASE_CONFIG, { isAddressSearchEnabled: false }),
            filled
        );

        ctx.enableCompanySearch();
        const mapped = recorder.select2Options.ajax.processResults(SEARCH_RESPONSE).results[0];
        recorder.handlers['select2:select']({ params: { data: mapped } });

        // Company still selected, but no company-detail request and no writes.
        expect(filled).toHaveLength(1);
        expect(recorder.ajax).toHaveLength(0);
        expect(recorder.written).toHaveLength(0);
    });

    /**
     * This module and the renderer add no gate of their own beyond
     * `config.isAddressSearchEnabled` — the coupling with "Enable company
     * search in address entry" is enforced upstream, server-side
     * (Model\Config\Repository::isAddressSearchEnabled, TWO-25503), so the
     * flag ConfigProvider hands down is already the resolved value. This
     * fixture sets it `true` directly to pin that the JS layer itself does
     * not re-derive or second-guess it from `isAddressAreaCompanySearchEnabled`.
     */
    test('still fires the detail call when company search sits in the tile', () => {
        const recorder = makeRecorder();
        const $ = makeSpyJQuery(recorder);
        const { component } = loadRenderer(recorder, $);
        const filled = [];
        const ctx = makeRendererContext(component, BASE_CONFIG, filled, false);

        expect(BASE_CONFIG.isAddressSearchEnabled).toBe(true);
        expect(ctx.isAddressAreaCompanySearchEnabled).toBe(false);

        ctx.enableCompanySearch();
        const mapped = recorder.select2Options.ajax.processResults(SEARCH_RESPONSE).results[0];
        recorder.handlers['select2:select']({ params: { data: mapped } });

        expect(filled).toEqual([
            { companyId: '12345678', companyName: 'Example Trading Ltd' }
        ]);
        expect(recorder.ajax).toHaveLength(1);
        expect(recorder.ajax[0].url).toBe(
            LOOKUP_URL
        );

        recorder.doneCallbacks.forEach(function (cb) {
            cb({ addresses: [{ city: 'London' }] });
        });
        expect(recorder.written).toEqual(
            expect.arrayContaining([['input[name="city"]', 'London']])
        );
    });

    /**
     * The companion to the test above: with the placement gate gone, the
     * dedicated setting is the ONLY thing left holding autofill back, and it
     * has to hold in the tile-hosted case too.
     */
    test('makes no detail call in the tile when address search is disabled', () => {
        const recorder = makeRecorder();
        const $ = makeSpyJQuery(recorder);
        const { component } = loadRenderer(recorder, $);
        const filled = [];
        const ctx = makeRendererContext(
            component,
            Object.assign({}, BASE_CONFIG, { isAddressSearchEnabled: false }),
            filled,
            false
        );

        ctx.enableCompanySearch();
        const mapped = recorder.select2Options.ajax.processResults(SEARCH_RESPONSE).results[0];
        recorder.handlers['select2:select']({ params: { data: mapped } });

        expect(filled).toHaveLength(1);
        expect(recorder.ajax).toHaveLength(0);
        expect(recorder.written).toHaveLength(0);
    });
});

describe('shipping-step company picker (address-autocomplete.js)', () => {
    test('still routes selection through the shared address lookup', () => {
        const recorder = makeRecorder();
        const $ = makeSpyJQuery(recorder);
        const companySearch = loadCompanySearch($);

        // address-autocomplete.js reads its config at module scope via
        // brandConfig.getActiveTwoBrandConfig().
        const brandConfig = function () { return BASE_CONFIG; };
        brandConfig.getActiveTwoBrandCode = function () { return 'two_payment'; };
        brandConfig.getActiveTwoBrandConfig = function () { return BASE_CONFIG; };

        const component = loadAmdModule('view/frontend/web/js/view/address-autocomplete.js', {
            jquery: $,
            'Two_Gateway/js/model/brand-config': brandConfig,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-control': loadCompanySearchControl($, companySearch)
        });

        const ctx = Object.assign(Object.create(component.prototype || {}), {
            countrySelector: '#shipping-new-address-form select[name="country_id"]',
            companyNameSelector: '#shipping-new-address-form input[name="company"]',
            companyIdSelector:
                '#shipping-new-address-form input[name="custom_attributes[company_id]"]',
            searchForCompanyButton: '#shipping_search_for_company',
            searchForCompanyText: 'Search for company',
            companyNamePlaceholder: 'Enter company name to search',
            setCompanyData: function () {},
            addressLookup: component.addressLookup,
            enableCompanySearch: component.enableCompanySearch
        });

        ctx.enableCompanySearch();

        const mapped = recorder.select2Options.ajax.processResults(SEARCH_RESPONSE).results[0];
        expect(mapped.lookupId).toBe('lookup-abc-123');

        recorder.handlers['select2:select']({ params: { data: mapped } });

        expect(recorder.ajax).toHaveLength(1);
        expect(recorder.ajax[0].url).toBe(
            LOOKUP_URL
        );
    });

    /**
     * TWO-25202 regression pin. Re-searching and picking a second company
     * must overwrite the address AND the company-id field, matching the
     * PrestaShop reference — never merge or keep the first company's data.
     */
    test('re-searching overwrites the previous address and company id', () => {
        const recorder = makeRecorder();
        const $ = makeSpyJQuery(recorder);
        const companySearch = loadCompanySearch($);

        const brandConfig = function () { return BASE_CONFIG; };
        brandConfig.getActiveTwoBrandCode = function () { return 'two_payment'; };
        brandConfig.getActiveTwoBrandConfig = function () { return BASE_CONFIG; };

        const component = loadAmdModule('view/frontend/web/js/view/address-autocomplete.js', {
            jquery: $,
            'Magento_Customer/js/customer-data': { set: function () {}, get: function () { return function () {}; } },
            'Two_Gateway/js/model/brand-config': brandConfig,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-control': loadCompanySearchControl($, companySearch)
        });

        const companyIdSelector =
            '#shipping-new-address-form input[name="custom_attributes[company_id]"]';
        const ctx = Object.assign(Object.create(component.prototype || {}), {
            countrySelector: '#shipping-new-address-form select[name="country_id"]',
            companyNameSelector: '#shipping-new-address-form input[name="company"]',
            companyIdSelector: companyIdSelector,
            searchForCompanyButton: '#shipping_search_for_company',
            searchForCompanyText: 'Search for company',
            companyNamePlaceholder: 'Enter company name to search',
            setCompanyData: component.setCompanyData,
            addressLookup: component.addressLookup,
            enableCompanySearch: component.enableCompanySearch
        });

        ctx.enableCompanySearch();
        const pick = function (searchResponse, address) {
            const mapped = recorder.select2Options.ajax.processResults(searchResponse).results[0];
            recorder.handlers['select2:select']({ params: { data: mapped } });
            recorder.doneCallbacks.forEach(function (cb) {
                cb({ addresses: [address] });
            });
            recorder.doneCallbacks.length = 0;
        };

        pick(SEARCH_RESPONSE, {
            city: 'London',
            postal_code: 'EC1A 1BB',
            street_address: '1 Example Street'
        });
        pick(
            {
                items: [
                    {
                        name: 'Second Company AB',
                        highlight: 'Second Company AB',
                        national_identifier: { id: '87654321' },
                        lookup_id: 'lookup-def-456'
                    }
                ]
            },
            { city: 'Stockholm', postal_code: '111 22', street_address: '2 Second Street' }
        );

        // Both companies were looked up — the second pick is not skipped.
        expect(recorder.ajax.map(function (call) { return call.url; })).toEqual([
            LOOKUP_URL,
            'https://api.example.test/companies/v2/company/lookup-def-456?client=magento2&client_v=1.0.0'
        ]);

        // Last write per field is the second company's data, unconditionally.
        const lastWrite = function (selector) {
            const writes = recorder.written.filter(function (w) { return w[0] === selector; });
            return writes[writes.length - 1][1];
        };
        expect(lastWrite('input[name="city"]')).toBe('Stockholm');
        expect(lastWrite('input[name="postcode"]')).toBe('111 22');
        expect(lastWrite('input[name="street[0]"]')).toBe('2 Second Street');
        expect(lastWrite(companyIdSelector)).toBe('87654321');
    });
});
