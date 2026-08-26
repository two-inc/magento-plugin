/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25193: the picker dropped `lookup_id` when mapping search results and
 * never fired the company-detail request, so picking a company filled nothing.
 *
 * There is ONE mount now (TWO-25503) — the page-level capture component owns
 * the single `CompanySearchControl` and re-points it between the address step
 * and the payment tile — so the shared model's behaviour and the one call site
 * that drives it are what these tests pin.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

const COMPONENT = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';
const CONTROL = 'view/frontend/web/js/model/company-search-control.js';

/** The node the component mounts its one control at in these fixtures. */
const TILE_FIELD_SELECTOR = '#two_gateway_form input#company_name';

/**
 * jQuery test double that records $.ajax calls and the values written to
 * address inputs, and lets a test drive select2 option/handler capture.
 *
 * `TILE_FIELD_SELECTOR` is the one selector reported as PRESENT, which is what
 * makes the component resolve its mount there — every other lookup answers an
 * empty set, so `billingRoleFormRoot()` finds no form and the address write
 * takes its unscoped, document-wide branch.
 */
function makeSpyJQuery(recorder) {
    function $(selector) {
        const obj = {
            length: selector === TILE_FIELD_SELECTOR ? 1 : 0,
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
            eq: function () { return obj; },
            is: function () { return false; },
            // A length-0 set for every address field: this fixture has no
            // address form as a node, so the writes go through the module's
            // document-wide branch and `each` never calls back.
            each: function () { return obj; },
            append: function () { return obj; },
            appendTo: function () { return obj; },
            insertAfter: function () { return obj; },
            prev: function () { return obj; },
            addClass: function () { return obj; },
            removeClass: function () { return obj; },
            toggleClass: function () { return obj; },
            hide: function () { return obj; },
            show: function () { return obj; },
            get: function () { return { style: {} }; },
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
    return loadAmdModule(SEARCH, { jquery: $ });
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

const SECOND_SEARCH_RESPONSE = {
    items: [
        {
            name: 'Second Company AB',
            highlight: 'Second Company AB',
            national_identifier: { id: '87654321' },
            lookup_id: 'lookup-def-456'
        }
    ]
};

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true,
    supportedCompanyTypes: { gb: [] },
    orderIntentConfig: {
        extensionPlatformName: 'magento2',
        extensionDBVersion: '1.0.0'
    }
};

const LOOKUP_URL =
    'https://api.example.test/companies/v2/company/lookup-abc-123?client=magento2&client_v=1.0.0';
const SECOND_LOOKUP_URL =
    'https://api.example.test/companies/v2/company/lookup-def-456?client=magento2&client_v=1.0.0';

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
        expect(recorder.ajax[0].url).toBe(LOOKUP_URL);

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

    test.each([
        [{ isAddressSearchEnabled: false }, { lookupId: 'lookup-abc-123' }, 'address search is off'],
        [{}, {}, 'the picked result carries no lookupId']
    ])('lookupCompanyAddress is a no-op when %p / %p (%s)', (configOverride, selected) => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        const result = companySearch.lookupCompanyAddress(
            Object.assign({}, BASE_CONFIG, configOverride),
            selected
        );

        expect(result).toBeNull();
        expect(recorder.ajax).toHaveLength(0);
        expect(recorder.written).toHaveLength(0);
    });
});

/**
 * Load the capture component with the REAL search model and the REAL control,
 * so the select2 wiring under test is production's own rather than a double's.
 *
 * @param {object} [configOverride] merged over BASE_CONFIG
 * @returns {object} `{ component, identity, recorder, pick }`
 */
function loadMountedComponent(configOverride) {
    const recorder = makeRecorder();
    const $ = makeSpyJQuery(recorder);
    const companySearch = loadCompanySearch($);
    const identity = loadAmdModule(IDENTITY, {});
    const config = Object.assign({}, BASE_CONFIG, configOverride || {});

    function SoleTraderStub() {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.launchSignup = function () { return null; };
        this.forgetAdoptions = function () {};
    }

    const component = loadAmdModule(COMPONENT, {
        jquery: $,
        'Two_Gateway/js/model/company-identity': identity,
        'Two_Gateway/js/model/company-search': companySearch,
        'Two_Gateway/js/model/company-search-control': loadAmdModule(CONTROL, {
            jquery: $,
            'Two_Gateway/js/model/company-search': companySearch
        }),
        'Two_Gateway/js/model/sole-trader': SoleTraderStub,
        'Two_Gateway/js/model/brand-config': {
            getActiveTwoBrandConfig: function () { return config; }
        },
        'Magento_Checkout/js/model/quote': Object.assign(
            {},
            defaultMocks()['Magento_Checkout/js/model/quote'],
            { billingAddress: function () { return { countryId: 'GB' }; } }
        )
    });
    component.start();

    /** Map a search response the way select2 would, then pick its first hit. */
    function pick(response) {
        const mapped = recorder.select2Options.ajax.processResults(response).results[0];
        recorder.handlers['select2:select']({ params: { data: mapped } });
        return mapped;
    }

    /** Settle the outstanding company-detail request with an address. */
    function settle(address) {
        recorder.doneCallbacks.forEach(function (cb) { cb({ addresses: [address] }); });
        recorder.doneCallbacks.length = 0;
    }

    return {
        component: component,
        identity: identity,
        recorder: recorder,
        pick: pick,
        settle: settle
    };
}

describe('the one control the capture component mounts', () => {
    test('the ajax block it hands select2 keeps lookup_id', () => {
        const { recorder } = loadMountedComponent();

        expect(recorder.select2Options).not.toBeNull();
        const mapped = recorder.select2Options.ajax.processResults(SEARCH_RESPONSE).results[0];

        expect(mapped.lookupId).toBe('lookup-abc-123');
    });

    test('picking a company captures it and fills the address from the registry', () => {
        const { identity, recorder, pick, settle } = loadMountedComponent();

        pick(SEARCH_RESPONSE);

        expect(identity.companyName()).toBe('Example Trading Ltd');
        expect(identity.companyId()).toBe('12345678');
        expect(recorder.ajax).toHaveLength(1);
        expect(recorder.ajax[0].url).toBe(LOOKUP_URL);

        settle({ city: 'London', postal_code: 'EC1A 1BB', street_address: '1 Example Street' });

        expect(recorder.written).toEqual(
            expect.arrayContaining([['input[name="city"]', 'London']])
        );
    });

    test('the dedicated address-search setting is the only gate — the company is still captured', () => {
        // The JS layer adds no gate of its own beyond
        // `config.isAddressSearchEnabled`: its coupling with "Enable company
        // search in address entry" is resolved server-side
        // (Model\Config\Repository::isAddressSearchEnabled, TWO-25503), so the
        // flag ConfigProvider hands down is already the answer.
        const { identity, recorder, pick } = loadMountedComponent({ isAddressSearchEnabled: false });

        pick(SEARCH_RESPONSE);

        expect(identity.companyId()).toBe('12345678');
        expect(recorder.ajax).toHaveLength(0);
        expect(recorder.written).toHaveLength(0);
    });

    test('re-searching overwrites the previous company and its address', () => {
        // TWO-25202 regression pin, matching the PrestaShop reference: a second
        // pick replaces the first outright, never merges with it.
        const { identity, recorder, pick, settle } = loadMountedComponent();

        pick(SEARCH_RESPONSE);
        settle({ city: 'London', postal_code: 'EC1A 1BB', street_address: '1 Example Street' });
        pick(SECOND_SEARCH_RESPONSE);
        settle({ city: 'Stockholm', postal_code: '111 22', street_address: '2 Second Street' });

        expect(recorder.ajax.map(function (call) { return call.url; })).toEqual([
            LOOKUP_URL,
            SECOND_LOOKUP_URL
        ]);
        expect(identity.companyName()).toBe('Second Company AB');
        expect(identity.companyId()).toBe('87654321');

        const lastWrite = function (selector) {
            const writes = recorder.written.filter(function (w) { return w[0] === selector; });
            return writes[writes.length - 1][1];
        };
        expect(lastWrite('input[name="city"]')).toBe('Stockholm');
        expect(lastWrite('input[name="postcode"]')).toBe('111 22');
        expect(lastWrite('input[name="street[0]"]')).toBe('2 Second Street');
    });
});
