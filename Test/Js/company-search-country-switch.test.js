/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-24867: switching country mid-checkout must not leave the previous
 * country's company behind.
 *
 * The buyer mis-clicks GB, searches a company, then corrects the country to
 * ES. Before this change three copies of the GB company survived that
 * correction — the picker's own selection, the `companyData` customer-data
 * section the payment tile credit-checks, and the address autofilled from the
 * GB registry entry — and none of them describes an ES buyer. The organisation
 * number is the one that bites: it reaches the API paired with an ES billing
 * country, is refused there, and surfaces to the buyer as a generic failure
 * with nothing on screen saying which field is wrong.
 *
 * The picker is deliberately left bound on a country change rather than
 * recreated, a divergence pinned below: it reads the country per request, so
 * the NEXT search simply carries the new country.
 *
 * LIMITATIONS OF THE DOUBLES BELOW — read before trusting a pass here.
 *
 *  1. `node.on()` keeps ONE handler per event name and `off()` is a no-op, so
 *     nothing here can speak to handler ordering or coexistence. The country
 *     select's `change` binding is the only handler these tests fire.
 *  2. There is no select2. `enableCompanySearch()` runs against a `select2()`
 *     stub, so what is checked is the wiring the module does around it — the
 *     bind token it records, the ajax options it builds — never select2's own
 *     behaviour.
 *  3. `customerData` here is a plain in-memory section store. Its real
 *     counterpart is backed by localStorage, which is the entire reason the
 *     country stamp exists; that persistence is not modelled, only the read
 *     and write shapes are.
 */

'use strict';

const { loadAmdModule, loadCompanySearchControl } = require('./amd-harness');

const MODEL = 'view/frontend/web/js/model/company-search.js';
const ADDRESS_STEP = 'view/frontend/web/js/view/address-autocomplete.js';
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

const NAME_FIELD = '#shipping-new-address-form input[name="company"]';
const ID_FIELD = '#shipping-new-address-form input[name="custom_attributes[company_id]"]';
const COUNTRY_FIELD = '#shipping-new-address-form select[name="country_id"]';

const CITY = 'input[name="city"]';
const POSTCODE = 'input[name="postcode"]';
const STREET = 'input[name="street[0]"]';

const MARKER = 'data-two-autofilled-value';

/**
 * jQuery double with one persistent node per selector and, unlike the doubles
 * in the neighbouring files, a REAL attribute store.
 *
 * That is load-bearing here rather than incidental: the autofill marker is an
 * attribute, so against an inert `attr()` (which is what
 * address-company-id.test.js and gateway-method-company-selection.test.js
 * ship) every assertion about reverting an autofilled address would pass
 * whether or not the marker was ever written.
 */
function makeDom() {
    const nodes = {};
    const triggered = [];

    function node(selector) {
        if (nodes[selector]) return nodes[selector];
        const n = {
            selector: selector,
            length: 1,
            value: '',
            attrs: {},
            props: {},
            textValue: '',
            dataValues: {},
            handlers: {},
            appended: [],
            val: function (next) {
                if (!arguments.length) return n.value;
                n.value = next;
                return n;
            },
            attr: function (name, next) {
                if (arguments.length < 2) return n.attrs[name];
                n.attrs[name] = next;
                return n;
            },
            removeAttr: function (name) {
                delete n.attrs[name];
                return n;
            },
            prop: function (name, next) {
                if (arguments.length < 2) return n.props[name];
                n.props[name] = next;
                return n;
            },
            text: function (next) {
                if (!arguments.length) return n.textValue;
                n.textValue = next;
                return n;
            },
            data: function (key, next) {
                if (arguments.length < 2) return n.dataValues[key];
                n.dataValues[key] = next;
                return n;
            },
            on: function (event, fn) {
                n.handlers[String(event).split('.')[0]] = fn;
                return n;
            },
            off: function () {
                return n;
            },
            trigger: function (event) {
                triggered.push([selector, event]);
                return n;
            },
            closest: function (sel) {
                return node(selector + ' >closest> ' + sel);
            },
            find: function (sel) {
                return node(selector + ' >find> ' + sel);
            },
            append: function (html) {
                n.appended.push(html);
                return n;
            },
            addClass: function (cls) {
                n.classes = (n.classes || []).concat(cls);
                return n;
            },
            remove: function () {
                n.removed = true;
                return n;
            },
            hide: function () {
                return n;
            },
            show: function () {
                return n;
            },
            select2: function () {
                return n;
            }
        };
        nodes[selector] = n;
        return n;
    }

    function $(selector) {
        return node(typeof selector === 'string' ? selector : String(selector));
    }
    // `$.async` is a MutationObserver in Magento; every node here already
    // exists, so resolve immediately with the selector — the modules re-wrap
    // it with `$(...)`, which lands on the same node.
    $.async = function (selector, cb) {
        cb(selector);
    };
    $.each = function (xs, fn) {
        (xs || []).forEach(function (x, i) {
            fn(i, x);
        });
    };
    $.ajax = function () {
        const r = { done: () => r, fail: () => r, always: () => r };
        return r;
    };
    $.Deferred = function () {
        const d = {
            resolve: () => d,
            reject: () => d,
            promise: () => d,
            done: () => d,
            fail: () => d,
            always: () => d
        };
        return d;
    };
    $.mage = { cookies: { get: () => null, set: () => {} }, redirect: () => {} };
    $.extend = Object.assign;
    $.fn = {};

    return { $: $, node: node, triggered: triggered };
}

/** Customer-data double recording every section write, in order. */
function makeCustomerData() {
    const writes = [];
    const sections = {};

    function observable(initial) {
        let value = initial;
        const subscribers = [];
        function obs(next) {
            if (!arguments.length) return value;
            value = next;
            subscribers.forEach(function (s) {
                s(value);
            });
            return obs;
        }
        obs.subscribe = function (fn) {
            subscribers.push(fn);
            return { dispose: function () {} };
        };
        return obs;
    }

    return {
        writes: writes,
        lastWriteTo: function (key) {
            for (let i = writes.length - 1; i >= 0; i--) {
                if (writes[i].key === key) return writes[i].value;
            }
            return undefined;
        },
        api: {
            get: function (key) {
                if (!sections[key]) sections[key] = observable(undefined);
                return sections[key];
            },
            set: function (key, value) {
                writes.push({ key: key, value: value });
                if (!sections[key]) sections[key] = observable(undefined);
                sections[key](value);
            },
            reload: function () {}
        }
    };
}

/** Load the real shared model against a jQuery whose DOM the test owns. */
function loadModel() {
    const dom = makeDom();
    return { model: loadAmdModule(MODEL, { jquery: dom.$ }), node: dom.node, dom: dom };
}

/**
 * Load the address step.
 *
 * `companySearch` is a RECORDING double rather than the real module: what is
 * being pinned on this surface is that the country-change handler CALLS the
 * abort and the revert, with the live bind token, in the right circumstances.
 * The revert's own behaviour is pinned directly against the real module in the
 * first describe block below.
 */
function loadAddressStep(options) {
    const opts = options || {};
    const dom = makeDom();
    const cd = makeCustomerData();
    const calls = { abort: [], revert: 0, applyAddress: [] };

    dom.node(COUNTRY_FIELD).val(opts.country || 'GB');

    const companySearch = {
        REQUEST_TIMEOUT_MS: 30000,
        SEARCH_DEBOUNCE_MS: 300,
        MIN_INPUT_LENGTH: 3,
        DROPDOWN_CSS_CLASS: 'two-company-search-dropdown',
        EVENT_NS: '.twoCompanySearch',
        AUTOFILL_MARKER_ATTR: MARKER,
        buildLanguageOptions: function () {
            return {};
        },
        buildSearchAjaxOptions: function (o) {
            calls.ajaxOptions = o;
            return {};
        },
        lookupCompanyAddress: function () {
            return null;
        },
        applyAddress: function (address) {
            calls.applyAddress.push(address);
        },
        revertAutofilledAddress: function () {
            calls.revert += 1;
            return 0;
        },
        abortActiveRequest: function (token) {
            calls.abort.push(token);
            return false;
        },
        isDegradedResponse: function () {
            return false;
        },
        clearResultCache: function () {},
        attachOpenOnType: function () {},
        getSearchFieldContainer: function () {
            return null;
        },
        attachManualEntryButton: function () {},
        detachManualEntryButton: function () {},
        markSearchBinding: function () {},
        clearSearchChrome: function () {},
        setSearching: function () {},
        setUnavailable: function () {},
        minInputLengthMessage: function () {
            return '';
        },
        noResultsMessage: function () {
            return '';
        }
    };

    const component = loadAmdModule(ADDRESS_STEP, {
        jquery: dom.$,
        'Magento_Customer/js/customer-data': cd.api,
        'Two_Gateway/js/model/company-search': companySearch,
        'Two_Gateway/js/model/company-search-control': loadCompanySearchControl(dom.$, companySearch),
        'Two_Gateway/js/model/brand-config': {
            getActiveTwoBrandConfig: function () {
                return {
                    isCompanySearchEnabled: !!opts.searchEnabled,
                    isAddressSearchEnabled: true,
                    checkoutApiUrl: 'https://api.example.test',
                    companySearchLimit: 10
                };
            }
        }
    });
    component._super = function () {};
    component.initialize();

    return { component: component, node: dom.node, cd: cd, calls: calls };
}

/** Fire the `change` handler the address step bound to the country select. */
function switchCountryTo(ctx, iso) {
    ctx.node(COUNTRY_FIELD).val(iso);
    ctx.node(COUNTRY_FIELD).handlers.change();
}

function loadRenderer() {
    const dom = makeDom();
    const renderer = loadAmdModule(RENDERER, { jquery: dom.$ });
    // `initialize()` seeds this per-country memo and is not run here (it wires
    // subscriptions these tests do not want). `getSupportedCompanyTypes()`
    // reads it on the first `fillCountryCode()`, so seed it by hand rather
    // than leave every country test failing on the memo rather than on the
    // behaviour it is about.
    renderer.supportedCompanyTypes = {};
    return { renderer: renderer, node: dom.node };
}

describe('reverting an address autofilled from the previous country', () => {
    test('applyAddress records exactly what it wrote, on every field', () => {
        const { model, node } = loadModel();

        model.applyAddress({
            city: 'London',
            postal_code: 'EC1A 1BB',
            street_address: '1 Example Street'
        });

        expect(node(CITY).val()).toBe('London');
        expect(node(CITY).attr(MARKER)).toBe('London');
        expect(node(POSTCODE).attr(MARKER)).toBe('EC1A 1BB');
        expect(node(STREET).attr(MARKER)).toBe('1 Example Street');
    });

    test('the revert clears an untouched autofill and fires change for it', () => {
        const { model, node, dom } = loadModel();
        model.applyAddress({
            city: 'London',
            postal_code: 'EC1A 1BB',
            street_address: '1 Example Street'
        });
        dom.triggered.length = 0;

        expect(model.revertAutofilledAddress()).toBe(3);

        expect(node(CITY).val()).toBe('');
        expect(node(POSTCODE).val()).toBe('');
        expect(node(STREET).val()).toBe('');
        // Magento's address-form bookkeeping listens for `change`, so a cleared
        // field that never fired one would stay in the quote.
        expect(dom.triggered.filter((t) => t[1] === 'change')).toHaveLength(3);
    });

    test('a field the buyer edited after the autofill survives the revert', () => {
        // The whole reason the marker records the VALUE rather than a boolean.
        // Over-clearing here deletes buyer input on a keystroke they may have
        // made minutes earlier — worse than leaving a stale value they can see.
        const { model, node } = loadModel();
        model.applyAddress({
            city: 'London',
            postal_code: 'EC1A 1BB',
            street_address: '1 Example Street'
        });
        node(CITY).val('Madrid');

        expect(model.revertAutofilledAddress()).toBe(2);

        expect(node(CITY).val()).toBe('Madrid');
        expect(node(POSTCODE).val()).toBe('');
    });

    test('a hand-filled form with no autofill behind it is left entirely alone', () => {
        const { model, node } = loadModel();
        node(CITY).val('Madrid');
        node(POSTCODE).val('28001');
        node(STREET).val('Calle Example 1');

        expect(model.revertAutofilledAddress()).toBe(0);

        expect(node(CITY).val()).toBe('Madrid');
        expect(node(POSTCODE).val()).toBe('28001');
        expect(node(STREET).val()).toBe('Calle Example 1');
    });

    test('a second autofill of an unchanged value still re-records the marker', () => {
        // Two companies sharing a postcode: without the refresh the second
        // write leaves no recording, and the field reads as buyer-typed — so
        // the revert would strand it — for the rest of the page's life.
        const { model, node } = loadModel();
        model.applyAddress({ city: 'London', postal_code: 'EC1A 1BB', street_address: 'One' });
        node(POSTCODE).removeAttr(MARKER);

        model.applyAddress({ city: 'London', postal_code: 'EC1A 1BB', street_address: 'Two' });

        expect(node(POSTCODE).attr(MARKER)).toBe('EC1A 1BB');
        expect(model.revertAutofilledAddress()).toBe(3);
    });

    test('an empty registry value is a recording, not an absence', () => {
        // `''` is what the API sends for a field the registry has nothing for.
        // A falsiness test here would leave the marker unread and the field
        // permanently un-revertable.
        const { model, node } = loadModel();
        model.applyAddress({ city: 'London', postal_code: '', street_address: 'One' });

        expect(node(POSTCODE).attr(MARKER)).toBe('');
        expect(model.revertAutofilledAddress()).toBe(3);
    });
});

describe('the address step on a country change', () => {
    test('the company in play is discarded, in every place it lives', () => {
        const ctx = loadAddressStep({ country: 'GB' });
        ctx.component.setCompanyData('12345678', 'Example Ltd');
        expect(ctx.node(NAME_FIELD).val()).toBe('Example Ltd');

        switchCountryTo(ctx, 'ES');

        expect(ctx.node(NAME_FIELD).val()).toBe('');
        expect(ctx.node(ID_FIELD).val()).toBe('');
        expect(ctx.cd.lastWriteTo('companyData')).toEqual({
            companyId: '',
            companyName: '',
            companyCountry: 'es'
        });
    });

    test('an address autofilled from the previous country is reverted', () => {
        const ctx = loadAddressStep({ country: 'GB' });

        switchCountryTo(ctx, 'ES');

        expect(ctx.calls.revert).toBe(1);
    });

    test('a search still on the wire for the old country is cancelled', () => {
        // Up to 30s of window (REQUEST_TIMEOUT_MS) in which a GB response can
        // land and repaint a dropdown the buyer is now reading as ES results.
        // Search has to be ENABLED for a real control (and a real bind
        // token) to exist at all — see the "no re-bind" test below for the
        // token identity itself.
        const ctx = loadAddressStep({ country: 'GB', searchEnabled: true });
        const token = ctx.component._companySearchControl.getBindToken();

        switchCountryTo(ctx, 'ES');

        expect(ctx.calls.abort).toEqual([token]);
    });

    test('the live bind token is the one recorded by enableCompanySearch', () => {
        // Without this the test above would pass against `undefined` — the
        // handler would be cancelling nothing, in a shape indistinguishable
        // from cancelling correctly.
        const ctx = loadAddressStep({ country: 'GB', searchEnabled: true });
        const token = ctx.component._companySearchControl.getBindToken();

        expect(typeof token).toBe('object');
        expect(token).not.toBeNull();
        expect(ctx.calls.ajaxOptions.token).toBe(token);
    });

    test('the next search carries the new country, with no re-bind', () => {
        // The deliberate divergence from PrestaShop, which recreates its
        // autocomplete here. `getCountryCode` reads the select per request, so
        // the bound widget already searches the new country — and a re-bind
        // would drag a buyer in manual-entry mode back into search mode.
        const ctx = loadAddressStep({ country: 'GB', searchEnabled: true });
        const tokenBefore = ctx.component._companySearchControl.getBindToken();

        switchCountryTo(ctx, 'ES');

        expect(ctx.calls.ajaxOptions.getCountryCode()).toBe('ES');
        expect(ctx.component._companySearchControl.getBindToken()).toBe(tokenBefore);
    });

    test('a change event that re-selects the same country discards nothing', () => {
        // Magento fires `change` as the form initialises, and again on a
        // re-render that re-selects the same country. Treating those as a
        // switch would blank a returning customer's prefilled company on load.
        const ctx = loadAddressStep({ country: 'GB' });
        ctx.component.setCompanyData('12345678', 'Example Ltd');

        switchCountryTo(ctx, 'GB');

        expect(ctx.node(NAME_FIELD).val()).toBe('Example Ltd');
        expect(ctx.node(ID_FIELD).val()).toBe('12345678');
        expect(ctx.calls.abort).toEqual([]);
        expect(ctx.calls.revert).toBe(0);
    });

    test('the published company records the country it was captured in', () => {
        const ctx = loadAddressStep({ country: 'GB' });

        ctx.component.setCompanyData('12345678', 'Example Ltd');

        expect(ctx.cd.lastWriteTo('companyData')).toEqual({
            companyId: '12345678',
            companyName: 'Example Ltd',
            companyCountry: 'gb'
        });
    });
});

describe('the payment tile on a country change', () => {
    test('a company selected under the previous country is dropped', () => {
        const { renderer } = loadRenderer();
        renderer.fillCountryCode('gb');
        renderer.applyCompanyData(
            { companyName: 'Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        renderer.fillCountryCode('es');

        // The organisation number is the one that matters: paired with an ES
        // billing country it is refused upstream as a generic failure.
        expect(renderer.companyId()).toBe('');
        expect(renderer.companyName()).toBe('');
    });

    test('the first country resolution keeps the company it arrived with', () => {
        // updateAddress() calls fillCountryCode() immediately before
        // fillCompanyData(), both off the SAME address, and the observable
        // starts empty — so treating the first fill as a change would discard
        // the quote's own company on every page load.
        const { renderer } = loadRenderer();

        renderer.updateAddress({
            countryId: 'GB',
            company: 'Example Ltd',
            telephone: '+44 20 7946 0000',
            customAttributes: [{ attribute_code: 'company_id', value: '12345678' }]
        });

        expect(renderer.countryCode()).toBe('gb');
        expect(renderer.companyId()).toBe('12345678');
        expect(renderer.companyName()).toBe('Example Ltd');
    });

    test('re-resolving the same country keeps the company', () => {
        const { renderer } = loadRenderer();
        renderer.fillCountryCode('gb');
        renderer.applyCompanyData(
            { companyName: 'Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        renderer.fillCountryCode('gb');

        expect(renderer.companyId()).toBe('12345678');
    });
});

describe('a company restored from a previous visit', () => {
    test('one captured in another country is refused', () => {
        // `companyData` is a localStorage section: it outlives the page and the
        // order, so an in-page reset cannot reach this. Nothing changed in THIS
        // page — the record simply belongs to a country the buyer is no longer
        // in.
        const { renderer } = loadRenderer();
        renderer.fillCountryCode('es');

        renderer.applyCompanyData(
            { companyName: 'Example Ltd', companyId: '12345678', companyCountry: 'gb' },
            { authoritative: true }
        );

        expect(renderer.companyId()).toBe('');
        expect(renderer.companyName()).toBe('');
    });

    test('one captured in the current country is applied', () => {
        const { renderer } = loadRenderer();
        renderer.fillCountryCode('es');

        renderer.applyCompanyData(
            { companyName: 'Ejemplo SL', companyId: 'B12345678', companyCountry: 'es' },
            { authoritative: true }
        );

        expect(renderer.companyId()).toBe('B12345678');
    });

    test('the stamp is matched case-insensitively', () => {
        // The address step lower-cases; the quote's `countryId` is upper-case
        // and reaches the observable through updateAddress(). A case-sensitive
        // compare would refuse every company on the tile.
        const { renderer } = loadRenderer();
        renderer.fillCountryCode('ES');

        renderer.applyCompanyData(
            { companyName: 'Ejemplo SL', companyId: 'B12345678', companyCountry: 'es' },
            { authoritative: true }
        );

        expect(renderer.companyId()).toBe('B12345678');
    });

    test('an unstamped record is applied rather than refused', () => {
        // Records written before the stamp existed carry no country. Failing
        // closed on those would drop a legitimate company on the first load
        // after an upgrade; they gain a stamp on the next write.
        const { renderer } = loadRenderer();
        renderer.fillCountryCode('es');

        renderer.applyCompanyData(
            { companyName: 'Ejemplo SL', companyId: 'B12345678' },
            { authoritative: true }
        );

        expect(renderer.companyId()).toBe('B12345678');
    });
});
