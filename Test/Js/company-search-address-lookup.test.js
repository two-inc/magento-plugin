/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25193: the picker dropped `lookup_id` when mapping search results and
 * never fired the company-detail request, so picking a company filled nothing.
 *
 * There is ONE mount now (TWO-25503) — the page-level capture component owns
 * the single `CompanySearchPanel` and re-points it between the address step and
 * the payment tile — so the shared model's behaviour and the one call site that
 * drives it are what these tests pin.
 */

'use strict';

const {
    loadAmdModule,
    defaultMocks,
    loadCompanyCapture,
    brandConfigMock,
    isProxyRoute,
    proxyEnvelope,
    HARNESS_BASE_URL
} = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';

/** The node the component mounts its one panel at in these fixtures. */
const TILE_FIELD_SELECTOR = '#two_gateway_form input#company_name';

/**
 * The one address form these fixtures render, so the tile-mounted panel has a
 * scope to write into. Without one the write is refused outright rather than
 * escaping document-wide (TWO-25554).
 */
const BILLING_FORM_SELECTOR = '[data-form="billing-new-address"]';

/**
 * jQuery test double that records $.ajax calls and the values written to
 * address inputs.
 *
 * `TILE_FIELD_SELECTOR` is reported as PRESENT, which is what makes the
 * component resolve its mount there, and so is the billing form it writes
 * into. A scoped `find()` resolves to the field selector alone, so what a write
 * records is the field it landed in rather than the path it took to get there.
 */
function makeSpyJQuery(recorder) {
    function $(selector) {
        const obj = {
            length: (selector === TILE_FIELD_SELECTOR || selector === BILLING_FORM_SELECTOR)
                ? 1
                : 0,
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
            find: function (sel) { return typeof sel === 'string' ? $(sel) : obj; },
            first: function () { return obj; },
            eq: function () { return obj; },
            is: function () { return false; },
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
            on: function () { return obj; }
        };
        return obj;
    }
    $.async = function (selector, fn) { fn(selector); };
    $.ajax = function (opts) {
        recorder.ajax.push(opts);
        const settlers = [];
        recorder.doneByCall.push(settlers);
        const jqxhr = {
            done: function (cb) { settlers.push(cb); return jqxhr; },
            fail: function () { return jqxhr; },
            always: function () { return jqxhr; },
            abort: function () {}
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
        doneByCall: [],
        written: [],
        triggered: [],
        values: {},
        attrs: {},
        data: {}
    };
}

/**
 * Settle the request the spy recorded most recently.
 *
 * Per-request rather than a flat list of every callback: a pick fires a search
 * AND a company-detail lookup, and answering one with the other's payload would
 * make either assertion meaningless.
 *
 * @param {object} recorder
 * @param {object} payload
 */
function settleLatest(recorder, payload) {
    const call = recorder.ajax[recorder.ajax.length - 1];
    // A proxy route answers with the envelope, not the upstream body.
    const wrapped = isProxyRoute(call && call.url) ? proxyEnvelope(payload) : payload;
    recorder.doneByCall[recorder.doneByCall.length - 1].forEach(function (cb) { cb(wrapped); });
}

/** Only the company-detail requests; the search goes to its own route. */
function lookupIds(recorder) {
    return recorder.ajax
        .filter(function (call) { return call.url === LOOKUP_ROUTE; })
        .map(function (call) { return JSON.parse(call.data).lookupId; });
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
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true,
    supportedCompanyTypes: { gb: [] },
    orderIntentConfig: {
        extensionPlatformName: 'magento2',
        extensionDBVersion: '1.0.0'
    }
};

const LOOKUP_ROUTE = HARNESS_BASE_URL + 'rest/V1/two/company';

describe('company-search shared module', () => {
    test('searchCompanies carries lookup_id through as lookupId', async () => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        const search = companySearch.searchCompanies({
            config: BASE_CONFIG,
            token: {},
            term: 'example',
            getCountryCode: function () { return 'gb'; }
        });
        settleLatest(recorder, SEARCH_RESPONSE);
        const result = await search;

        expect(result.items).toHaveLength(1);
        expect(result.items[0].lookupId).toBe('lookup-abc-123');
        expect(result.items[0].companyId).toBe('12345678');
    });

    test('the search url carries the uppercased country and the configured window', () => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        companySearch.searchCompanies({
            config: BASE_CONFIG,
            token: {},
            term: 'example',
            getCountryCode: function () { return 'gb'; }
        });

        expect(recorder.ajax[0].url).toBe(HARNESS_BASE_URL + 'rest/V1/two/company-search');
        expect(JSON.parse(recorder.ajax[0].data)).toEqual({ country: 'GB', query: 'example' });
    });

    test('lookupCompanyAddress fetches the company and fills the address form', () => {
        const recorder = makeRecorder();
        const companySearch = loadCompanySearch(makeSpyJQuery(recorder));

        companySearch.lookupCompanyAddress(BASE_CONFIG, { lookupId: 'lookup-abc-123' });

        expect(recorder.ajax).toHaveLength(1);
        expect(recorder.ajax[0].url).toBe(LOOKUP_ROUTE);
        expect(JSON.parse(recorder.ajax[0].data)).toEqual({ lookupId: 'lookup-abc-123' });

        settleLatest(recorder, {
            addresses: [
                { city: 'London', postal_code: 'EC1A 1BB', street_address: '1 Example Street' }
            ]
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
 * Load the capture component with the REAL search model, capturing the
 * `onSelect` it hands its panel.
 *
 * The panel itself is stubbed here — this fixture has no real DOM for it to
 * build in — but the row that reaches `onSelect` is mapped by production's own
 * `searchCompanies`, so nothing about the pick is hand-rolled.
 *
 * @param {object} [configOverride] merged over BASE_CONFIG
 * @returns {object} `{ component, identity, recorder, pick, settle }`
 */
function loadMountedComponent(configOverride) {
    const recorder = makeRecorder();
    const $ = makeSpyJQuery(recorder);
    const companySearch = loadCompanySearch($);
    const config = Object.assign({}, BASE_CONFIG, configOverride || {});
    const panel = { options: null };

    function PanelStub(panelOptions) {
        panel.options = panelOptions;
        this.bind = function () {};
        this.isBound = function () { return true; };
        this.getField = function () { return $(); };
        this.close = function () {};
        this.syncChips = function () {};
        this.setDisplayText = function () {};
        this.releaseField = function () {};
        this.reclaimField = function () {};
        this.abortActiveRequest = function () {};
    }
    function SoleTraderStub() {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.focusSignupPopup = function () { return false; };
        this.launchSignup = function () { return null; };
        this.forgetAdoptions = function () {};
    }

    const component = loadCompanyCapture({
        jquery: $,
        'Two_Gateway/js/model/company-search': companySearch,
        'Two_Gateway/js/model/company-search-panel': PanelStub,
        'Two_Gateway/js/model/sole-trader': SoleTraderStub,
        'Two_Gateway/js/model/brand-config': brandConfigMock(config),
        'Magento_Checkout/js/model/quote': Object.assign(
            {},
            defaultMocks()['Magento_Checkout/js/model/quote'],
            { billingAddress: function () { return { countryId: 'GB' }; } }
        )
    }).shipping;
    component.start();

    /**
     * Search for `term`, answer it with `response`, and hand the first row to
     * the component's own selection handler.
     *
     * @param {string} term distinct per call — the module caches by request url
     * @param {object} response
     * @returns {Promise<object>} the row that was picked
     */
    async function pick(term, response) {
        const search = companySearch.searchCompanies({
            config: config,
            token: {},
            term: term,
            getCountryCode: function () { return 'gb'; }
        });
        settleLatest(recorder, response);
        const item = (await search).items[0];
        panel.options.onSelect(item);
        return item;
    }

    /** Settle the company-detail request the pick fired. */
    function settle(address) {
        settleLatest(recorder, { addresses: [address] });
    }

    return {
        component: component,
        identity: component.identity(),
        recorder: recorder,
        pick: pick,
        settle: settle
    };
}

describe('the one panel the capture component mounts', () => {
    test('picking a company captures it and fills the address from the registry', async () => {
        const { identity, recorder, pick, settle } = loadMountedComponent();

        const picked = await pick('example', SEARCH_RESPONSE);

        expect(picked.lookupId).toBe('lookup-abc-123');
        expect(identity.companyName()).toBe('Example Trading Ltd');
        expect(identity.companyId()).toBe('12345678');
        expect(lookupIds(recorder)).toEqual(['lookup-abc-123']);

        settle({ city: 'London', postal_code: 'EC1A 1BB', street_address: '1 Example Street' });

        expect(recorder.written).toEqual(
            expect.arrayContaining([['input[name="city"]', 'London']])
        );
    });

    test('the dedicated address-search setting is the only gate — the company is still captured', async () => {
        // The JS layer adds no gate of its own beyond
        // `config.isAddressSearchEnabled`: its coupling with "Enable company
        // search in address entry" is resolved server-side
        // (Model\Config\Repository::isAddressSearchEnabled, TWO-25503), so the
        // flag ConfigProvider hands down is already the answer.
        const { identity, recorder, pick } = loadMountedComponent({ isAddressSearchEnabled: false });

        await pick('example', SEARCH_RESPONSE);

        expect(identity.companyId()).toBe('12345678');
        expect(lookupIds(recorder)).toEqual([]);
        expect(recorder.written).toHaveLength(0);
    });

    test('re-searching overwrites the previous company and its address', async () => {
        // TWO-25202 regression pin, matching the PrestaShop reference: a second
        // pick replaces the first outright, never merges with it.
        const { identity, recorder, pick, settle } = loadMountedComponent();

        await pick('example', SEARCH_RESPONSE);
        settle({ city: 'London', postal_code: 'EC1A 1BB', street_address: '1 Example Street' });
        await pick('second', SECOND_SEARCH_RESPONSE);
        settle({ city: 'Stockholm', postal_code: '111 22', street_address: '2 Second Street' });

        expect(lookupIds(recorder)).toEqual(['lookup-abc-123', 'lookup-def-456']);
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
