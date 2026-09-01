/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-24867: switching country mid-checkout must not leave the previous
 * country's company behind.
 *
 * The buyer mis-clicks GB, searches a company, then corrects the country to
 * ES. Three copies of the GB company would otherwise survive that correction —
 * the captured identity, the `companyData` customer-data section the payment
 * tile credit-checks, and the address autofilled from the GB registry entry —
 * and none of them describes an ES buyer. The organisation number is the one
 * that bites: it reaches the API paired with an ES billing country, is refused
 * there, and surfaces to the buyer as a generic failure with nothing on screen
 * saying which field is wrong.
 *
 * The picker is deliberately left BOUND on a country change rather than
 * recreated, a divergence pinned below: it reads the country per request, so
 * the next search simply carries the new country.
 *
 * LIMITATIONS OF THE DOUBLES BELOW — read before trusting a pass here.
 *
 *  1. `node.on()` in makeDom() keeps ONE handler per event name and `off()` is
 *     a no-op, so nothing driven through it can speak to handler ordering or
 *     coexistence. The component's own specs use real jQuery instead.
 *  2. `customerData` here is a plain in-memory section store. Its real
 *     counterpart is backed by localStorage, which is the entire reason the
 *     country stamp exists; that persistence is not modelled, only the read
 *     and write shapes are.
 */

'use strict';

const jq = require('jquery');
const { loadAmdModule, defaultMocks, loadCompanyCapture, brandConfigMock } = require('./amd-harness');

const MODEL = 'view/frontend/web/js/model/company-search.js';
const ADDRESS_STEP = 'view/frontend/web/js/view/address-autocomplete.js';
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';

const NAME_FIELD = '#shipping-new-address-form input[name="company"]';
const ID_FIELD = '#shipping-new-address-form input[name="custom_attributes[company_id]"]';
const COUNTRY_FIELD = '#shipping-new-address-form select[name="country_id"]';

const CITY = 'input[name="city"]';
const POSTCODE = 'input[name="postcode"]';
const STREET = 'input[name="street[0]"]';

const MARKER = 'data-two-autofilled-value';

const PRIMARY_ROOT = '#shipping-new-address-form';
const SECONDARY_ROOT = '[data-form="billing-new-address"]';

/**
 * jQuery double with one persistent node per selector and, unlike the doubles
 * in the neighbouring files, a REAL attribute store.
 *
 * That is load-bearing here rather than incidental: the autofill marker is an
 * attribute, so against an inert `attr()` every assertion about reverting an
 * autofilled address would pass whether or not the marker was ever written.
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
            // One node per selector in this double, so the set is always this
            // node — enough for the production revert's per-element loop.
            eq: function () {
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
            },
            first: function () {
                return n;
            },
            each: function (fn) {
                if (n.length) fn.call(n, 0, n);
                return n;
            }
        };
        // The shipping address form is the SCOPE the autofill and the revert
        // resolve their fields inside (TWO-25461 §2), so a lookup through it has
        // to land on the same node the plain selector does — otherwise every
        // assertion below would be watching a node production never writes.
        if (selector === PRIMARY_ROOT) {
            n.find = function (sel) {
                return node(sel);
            };
        }
        // No billing address form in these fixtures. Modelled as genuinely
        // ABSENT rather than as another length-1 node: these tests cover the
        // shipping step alone, and a billing form that answered every selector
        // would have the mirror writing into the same nodes the assertions
        // watch, which would make a scoping regression invisible here.
        if (selector === SECONDARY_ROOT) {
            n.length = 0;
        }
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
    return {
        model: loadAmdModule(MODEL, { jquery: dom.$ }),
        node: dom.node,
        dom: dom,
        // Every write and revert is scoped to the calling panel's own form
        // (TWO-25554); there is no page-wide path.
        root: dom.$(PRIMARY_ROOT)
    };
}

/**
 * Load the address step.
 *
 * `companySearch` is a RECORDING double rather than the real module: what is
 * pinned on this surface is that the country-change handler asks for the
 * revert and the propagation, in the right order and in the right
 * circumstances. The revert's own behaviour is pinned directly against the
 * real module in the first describe block below.
 */
function loadAddressStep(options) {
    const opts = options || {};
    const dom = makeDom();
    const cd = makeCustomerData();
    const calls = {
        revert: 0,
        applyAddress: [],
        touched: [],
        sequence: []
    };

    dom.node(COUNTRY_FIELD).val(opts.country || 'GB');

    // A Proxy rather than a plain object: `calls.touched` is then every member
    // of the model the address step reaches for, which is what makes "it asks
    // the model for nothing beyond its own form" assertable.
    const companySearch = new Proxy(
        Object.assign({}, defaultMocks()['Two_Gateway/js/model/company-search'], {
            AUTOFILL_MARKER_ATTR: MARKER,
            SECONDARY_ADDRESS_ROOT_SELECTOR: SECONDARY_ROOT,
            applyAddress: function (address) {
                calls.applyAddress.push(address);
            },
            revertAutofilledAddress: function () {
                calls.revert += 1;
                calls.sequence.push('revert');
                return 0;
            }
        }),
        {
            get: function (target, name) {
                calls.touched.push(String(name));
                return target[name];
            }
        }
    );

    const component = loadAmdModule(ADDRESS_STEP, {
        jquery: dom.$,
        'Magento_Customer/js/customer-data': cd.api,
        'Two_Gateway/js/model/company-search': companySearch
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

/**
 * The capture component, its identity singleton and recording doubles for the
 * three collaborators a country change reaches.
 *
 * Real jQuery against the real jsdom document, so the delegated country watcher
 * is genuinely delegated rather than reimplemented.
 *
 * @param {object} [options] `{ billingCountry }`
 */
function loadCaptureComponent(options) {
    const opts = options || {};
    const calls = { reverts: 0, aborts: 0, binds: [], forgotten: 0, destroys: 0 };

    function PanelStub() {
        this.bind = function (bindOptions) { calls.binds.push(bindOptions || {}); };
        this.destroy = function () { calls.destroys += 1; return true; };
        this.abortActiveRequest = function () { calls.aborts += 1; };
        this.isBound = function () { return calls.binds.length > 0; };
        this.getField = function () { return jq(); };
        this.close = function () {};
        this.syncChips = function () {};
        this.setDisplayText = function () {};
        this.releaseField = function () {};
        this.reclaimField = function () {};
        this.unmount = function () {};
    }
    function SoleTraderStub() {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.focusSignupPopup = function () { return false; };
        this.launchSignup = function () { return null; };
        this.forgetAdoptions = function () { calls.forgotten += 1; };
    }

    let billing = 'billingCountry' in opts ? opts.billingCountry : 'GB';
    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: function () {
            return billing === null ? null : { countryId: billing };
        },
        isVirtual: function () { return false; }
    });

    const companySearch = Object.assign(
        {},
        defaultMocks()['Two_Gateway/js/model/company-search'],
        {
            currentAddressFormCountry: function () {
                const value = jq('select[name="country_id"]').first().val();
                return typeof value === 'string' ? value.toLowerCase() : '';
            },
            revertAutofilledAddress: function () { calls.reverts += 1; return 0; }
        }
    );

    const component = loadCompanyCapture(
        {
            jquery: jq,
            'Magento_Checkout/js/model/quote': quote,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-panel': PanelStub,
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': brandConfigMock({
                isCompanySearchEnabled: true,
                checkoutApiUrl: 'https://api.example.test',
                checkoutPageUrl: 'https://checkout.example.test',
                supportedCompanyTypes: { es: [], gb: ['SOLE_TRADER'], no: [] }
            })
        },
        { document: document, window: window }
    ).shipping;

    return {
        component: component,
        identity: component.identity(),
        calls: calls,
        setBillingCountry: function (iso) { billing = iso; }
    };
}

/**
 * The payment renderer on a checkout with no address form — a saved address or
 * a virtual cart. Its country for the TWO-24867 stamp check comes from the
 * capture component, mounted on the tile, which has no adjacent country select
 * and so reads the quote.
 *
 * @param {string} billingCountry `quote.billingAddress().countryId`
 */
function loadRenderer(billingCountry) {
    const dom = makeDom();
    // makeDom() answers `length: 1` for every selector; the address company
    // field has to be absent or the component reads a country select that no
    // such checkout renders.
    dom.node('#shipping-new-address-form input[name="company"]').length = 0;
    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: function () { return { countryId: billingCountry }; }
    });
    const renderer = loadAmdModule(RENDERER, {
        jquery: dom.$,
        'Magento_Checkout/js/model/quote': quote
    });
    return { renderer: renderer, node: dom.node };
}

beforeEach(() => {
    document.body.innerHTML = '';
    jq(document).off('.twoCompanyCapture');
});

describe('reverting an address autofilled from the previous country', () => {
    test('applyAddress records exactly what it wrote, on every field', () => {
        const { model, node, root } = loadModel();

        model.applyAddress({
            city: 'London',
            postal_code: 'EC1A 1BB',
            street_address: '1 Example Street'
        }, root);

        expect(node(CITY).val()).toBe('London');
        expect(node(CITY).attr(MARKER)).toBe('London');
        expect(node(POSTCODE).attr(MARKER)).toBe('EC1A 1BB');
        expect(node(STREET).attr(MARKER)).toBe('1 Example Street');
    });

    test('the revert clears an untouched autofill and fires change for it', () => {
        const { model, node, dom, root } = loadModel();
        model.applyAddress({
            city: 'London',
            postal_code: 'EC1A 1BB',
            street_address: '1 Example Street'
        }, root);
        dom.triggered.length = 0;

        expect(model.revertAutofilledAddress(root)).toBe(3);

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
        const { model, node, root } = loadModel();
        model.applyAddress({
            city: 'London',
            postal_code: 'EC1A 1BB',
            street_address: '1 Example Street'
        }, root);
        node(CITY).val('Madrid');

        expect(model.revertAutofilledAddress(root)).toBe(2);

        expect(node(CITY).val()).toBe('Madrid');
        expect(node(POSTCODE).val()).toBe('');
    });

    test('a hand-filled form with no autofill behind it is left entirely alone', () => {
        const { model, node, root } = loadModel();
        node(CITY).val('Madrid');
        node(POSTCODE).val('28001');
        node(STREET).val('Calle Example 1');

        expect(model.revertAutofilledAddress(root)).toBe(0);

        expect(node(CITY).val()).toBe('Madrid');
        expect(node(POSTCODE).val()).toBe('28001');
        expect(node(STREET).val()).toBe('Calle Example 1');
    });

    test('a second autofill of an unchanged value still re-records the marker', () => {
        // Two companies sharing a postcode: without the refresh the second
        // write leaves no recording, and the field reads as buyer-typed — so
        // the revert would strand it — for the rest of the page's life.
        const { model, node, root } = loadModel();
        model.applyAddress({ city: 'London', postal_code: 'EC1A 1BB', street_address: 'One' }, root);
        node(POSTCODE).removeAttr(MARKER);

        model.applyAddress({ city: 'London', postal_code: 'EC1A 1BB', street_address: 'Two' }, root);

        expect(node(POSTCODE).attr(MARKER)).toBe('EC1A 1BB');
        expect(model.revertAutofilledAddress(root)).toBe(3);
    });

    test('an empty registry value is a recording, not an absence', () => {
        // `''` is what the API sends for a field the registry has nothing for.
        // A falsiness test here would leave the marker unread and the field
        // permanently un-revertable.
        const { model, node, root } = loadModel();
        model.applyAddress({ city: 'London', postal_code: '', street_address: 'One' }, root);

        expect(node(POSTCODE).attr(MARKER)).toBe('');
        expect(model.revertAutofilledAddress(root)).toBe(3);
    });
});

describe('the capture component on a country change', () => {
    /**
     * A checkout with a live mount, a captured GB company and a resolved
     * country — the state a switch has something to retract from.
     *
     * The mount matters: the abort below is the panel's, so a fixture with no
     * host node would have nothing to cancel and would pass for the wrong reason.
     */
    function inPlay(ctx) {
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '<div class="field"><input name="company" /></div>' +
            '<select name="country_id"><option value="GB" selected>GB</option>' +
            '<option value="ES">ES</option></select></form>';
        ctx.component.start();
        ctx.component.onCountryChanged('gb');
        ctx.identity.write({ companyName: 'Example Ltd', companyId: '12345678' });
        ctx.calls.reverts = 0;
        ctx.calls.forgotten = 0;
        ctx.calls.aborts = 0;
    }

    /**
     * Notify a switch the way the delegated watcher does — off a select that
     * already carries the new value, which is what the component reads.
     */
    function notifyCountry(ctx, iso) {
        jq('#shipping-new-address-form select[name="country_id"]').val(iso.toUpperCase());
        ctx.component.onCountryChanged(iso);
    }

    test('the captured company, the autofilled address and the adoption guard all go', () => {
        const ctx = loadCaptureComponent({ billingCountry: 'GB' });
        inPlay(ctx);

        notifyCountry(ctx, 'es');

        // The organisation number is the one that matters: paired with an ES
        // billing country it is refused upstream as a generic failure.
        expect(ctx.identity.companyId()).toBe('');
        expect(ctx.identity.companyName()).toBe('');
        expect(ctx.calls.reverts).toBe(1);
        expect(ctx.calls.forgotten).toBe(1);
    });

    test('a search still on the wire for the old country is cancelled', () => {
        // Up to REQUEST_TIMEOUT_MS of window in which a GB response can land and
        // repopulate exactly what the clear above just removed.
        const ctx = loadCaptureComponent({ billingCountry: 'GB' });
        inPlay(ctx);

        notifyCountry(ctx, 'es');

        expect(ctx.calls.aborts).toBe(1);
    });

    test('the picker is left bound — the next search simply carries the new country', () => {
        // The deliberate divergence from PrestaShop, which recreates its
        // autocomplete here. `getCountryCode` is read per request, and a re-bind
        // would drag a buyer in manual-entry mode back into search mode.
        const ctx = loadCaptureComponent({ billingCountry: 'GB' });
        inPlay(ctx);
        const bindsBefore = ctx.calls.binds.length;

        notifyCountry(ctx, 'es');

        expect(ctx.calls.binds).toHaveLength(bindsBefore);
    });

    test('sole-trader mode is left, because the registry that offered it has changed', () => {
        const ctx = loadCaptureComponent({ billingCountry: 'GB' });
        inPlay(ctx);
        ctx.identity.captureMode('soletrader');
        ctx.identity.soleTraderAdopted(true);

        notifyCountry(ctx, 'es');

        expect(ctx.identity.captureMode()).toBe('registered');
        expect(ctx.identity.soleTraderAdopted()).toBe(false);
    });

    test.each([
        ['gb', 'a re-notification of the same country'],
        ['GB', 'the same country in another case'],
        ['', 'no country at all']
    ])('%p discards nothing (%s)', (country) => {
        // A change event also fires as the form initialises and on a re-render
        // that re-selects the same country. Treating those as a switch would
        // blank a returning customer's prefilled company on load.
        const ctx = loadCaptureComponent({ billingCountry: 'GB' });
        inPlay(ctx);

        ctx.component.onCountryChanged(country);

        expect(ctx.identity.companyId()).toBe('12345678');
        expect(ctx.calls.reverts).toBe(0);
        expect(ctx.calls.aborts).toBe(0);
    });

    test('the first country to resolve keeps the company it arrived with', () => {
        // The quote's own country arrives after load, off the same address that
        // carried the company — so treating the first resolution as a change
        // would discard it on every page load.
        const ctx = loadCaptureComponent({ billingCountry: 'GB' });
        ctx.component.start();
        ctx.identity.write({ companyName: 'Example Ltd', companyId: '12345678' });

        ctx.component.onCountryChanged('gb');

        expect(ctx.identity.companyId()).toBe('12345678');
        expect(ctx.calls.reverts).toBe(0);
    });

    test('the buyer\'s own switch in the address form clears the company', () => {
        // The end-to-end path, off the real delegated watcher: everything above
        // calls onCountryChanged() directly, so without this the whole block
        // would stay green while nothing on a real checkout ever reached it.
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '<div class="field"><input name="company" /></div>' +
            '<select name="country_id">' +
            '<option value="GB" selected>GB</option><option value="ES">ES</option>' +
            '</select></form>';
        const ctx = loadCaptureComponent({ billingCountry: 'GB' });
        ctx.component.start();
        ctx.identity.write({ companyName: 'Example Ltd', companyId: '12345678' });

        jq('select[name="country_id"]').val('ES').trigger('change');

        expect(ctx.identity.companyId()).toBe('');
        expect(ctx.calls.reverts).toBe(1);
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

    test('a change event that re-selects the same country discards nothing', () => {
        // Magento fires `change` as the form initialises, and again on a
        // re-render that re-selects the same country. Treating those as a
        // switch would blank a returning customer's prefilled company on load.
        const ctx = loadAddressStep({ country: 'GB' });
        ctx.component.setCompanyData('12345678', 'Example Ltd');

        switchCountryTo(ctx, 'GB');

        expect(ctx.node(NAME_FIELD).val()).toBe('Example Ltd');
        expect(ctx.node(ID_FIELD).val()).toBe('12345678');
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

describe('a company restored from a previous visit', () => {
    test.each([
        ['gb', 'ES', '', 'one captured in another country is refused'],
        ['es', 'ES', 'B12345678', 'one captured in the current country is applied'],
        ['ES', 'es', 'B12345678', 'the stamp is matched case-insensitively'],
        [undefined, 'ES', 'B12345678', 'an unstamped record is applied rather than refused']
    ])('stamp=%p billing=%p -> id %p (%s)', (stamp, billingCountry, expectedId) => {
        // `companyData` is a localStorage section: it outlives the page and the
        // order, so an in-page reset cannot reach this. Nothing changed in THIS
        // page — the record simply belongs to a country the buyer left. An
        // unstamped record predates the stamp and must fail OPEN, or the first
        // load after an upgrade drops a legitimate company.
        const { renderer } = loadRenderer(billingCountry);

        renderer.applyCompanyData(
            {
                companyName: 'Ejemplo SL',
                companyId: 'B12345678',
                companyCountry: stamp
            },
            { authoritative: true }
        );

        expect(renderer.companyId()).toBe(expectedId);
    });
});

describe('the address step reaches into its OWN form and nowhere else', () => {
    /**
     * The wirings under test live in `address-autocomplete.js`, which no model
     * suite exercises: a cross-panel write reintroduced there is invisible to
     * every suite that drives the model directly.
     */
    /**
     * Every member of the model the address step may reach for. An ALLOWLIST,
     * not a pattern over the writers already removed: a cross-panel writer
     * added later under any name at all fails this.
     *
     * `SECONDARY_ADDRESS_ROOT_SELECTOR` is where the BILLING panel's own mount
     * lives, and the step reads it to stay OUT of that form — so reading it is
     * the point.
     */
    const ALLOWED_MODEL_MEMBERS = ['SECONDARY_ADDRESS_ROOT_SELECTOR', 'revertAutofilledAddress'];

    /**
     * @param {object} calls the Proxy recorder
     * @returns {Array<string>} members reached for that are not allowed
     */
    function disallowedMembers(calls) {
        return calls.touched.filter(function (name) {
            return ALLOWED_MODEL_MEMBERS.indexOf(name) === -1;
        });
    }

    test('a country change retracts its own address fields and propagates nothing', () => {
        const ctx = loadAddressStep({ country: 'GB' });
        ctx.calls.touched.length = 0;

        switchCountryTo(ctx, 'ES');

        expect(ctx.calls.revert).toBe(1);
        expect(disallowedMembers(ctx.calls)).toEqual([]);
    });

    test('a company write propagates nothing — each panel owns its own company field', () => {
        const ctx = loadAddressStep({ country: 'GB' });
        ctx.calls.touched.length = 0;

        ctx.component.setCompanyData('12345678', 'Example Ltd');

        expect(disallowedMembers(ctx.calls)).toEqual([]);
    });

    test('booting reaches for no cross-panel writer at all', () => {
        const ctx = loadAddressStep({ country: 'GB' });

        expect(disallowedMembers(ctx.calls)).toEqual([]);
    });
});
