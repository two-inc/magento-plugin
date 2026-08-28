/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25253, second half. Guarding `national_identifier` in
 * `company-search.js` made a new state reachable: a company hit that renders
 * with an EMPTY `companyId`. These tests cover what happens when the buyer
 * actually picks one.
 *
 * The failure mode being pinned is a mis-submitted order, not a crash.
 * `fillCompanyData()` early-returns unless BOTH name and id are non-empty, so
 * picking an identifier-less company after a valid one used to leave the
 * previous company's organisation number in `companyId()` while the picker
 * displayed the new company's name — `getData()` / `placeOrderIntent()` then
 * sent company A's number under company B's name. A selection has to be
 * AUTHORITATIVE; a passive read of a stored section must not be.
 *
 * There is no field to enable or disable on this surface: the tile's
 * company-number input is gone (TWO-25288) and an identifier-less selection is
 * refused server-side by Model/Two.php::authorize(). What is pinned below is
 * the observable state a selection leaves behind — see
 * tile-company-readonly-fields.test.js for the fields themselves.
 */

'use strict';

const { loadAmdModule, defaultMocks, loadCompanyCapture, brandConfigMock } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';

/** The node the capture component mounts its one panel at in these fixtures. */
const TILE_FIELD_SELECTOR = '#two_gateway_form input#company_name';

/**
 * jQuery double that keeps one persistent node per selector, so a write made
 * through `$(sel).val(...)` is visible to a later `$(sel).val()` read. The
 * default harness jQuery is inert (every setter returns the same empty object
 * and records nothing), which would let a broken implementation pass.
 *
 * LIMITATION — `node.on()` stores ONE handler per event name and `node.off()`
 * is a no-op, so two handlers bound to the same event on the same node are
 * unmodellable and the LAST bind silently wins. Nothing in this file can
 * therefore say anything about handler ORDERING or handler COEXISTENCE. If a
 * question depends on more than one listener for an event, make the double
 * faithful (a real handler list plus a working `off()`) first.
 */
function makeDom() {
    const nodes = {};

    function node(selector) {
        if (nodes[selector]) return nodes[selector];
        const n = {
            selector: selector,
            // Only the mount host reports as present, which is what makes the
            // component resolve its mount there.
            length: selector === TILE_FIELD_SELECTOR ? 1 : 0,
            value: '',
            props: {},
            textValue: '',
            handlers: {},
            appended: [],
            val: function (next) {
                if (!arguments.length) return n.value;
                n.value = next;
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
            on: function (event, fn) {
                // Strip the `.twoCompanySearch` namespace so tests can fire by
                // plain event name.
                n.handlers[String(event).split('.')[0]] = fn;
                return n;
            },
            off: function () { return n; },
            closest: function (sel) { return node(selector + ' >closest> ' + sel); },
            find: function (sel) { return node(selector + ' >find> ' + sel); },
            append: function (html) { n.appended.push(html); return n; },
            appendTo: function () { return n; },
            insertAfter: function () { return n; },
            prev: function () { return n; },
            attr: function () { return n; },
            addClass: function (cls) { n.classes = (n.classes || []).concat(cls); return n; },
            removeClass: function () { return n; },
            toggleClass: function () { return n; },
            remove: function () { n.removed = true; return n; },
            data: function () { return null; },
            eq: function () { return n; },
            first: function () { return n; },
            is: function () { return false; },
            each: function () { return n; },
            get: function () { return { style: {} }; },
            hide: function () { return n; },
            show: function () { return n; },
            trigger: function () { return n; },
            select2: function (opts) {
                if (typeof opts === 'object') n.select2Options = opts;
                return n;
            }
        };
        nodes[selector] = n;
        return n;
    }

    function $(selector) {
        return node(typeof selector === 'string' ? selector : String(selector));
    }
    // `$.async` is a MutationObserver in Magento; the node is already present
    // here, so resolve immediately with the selector (the modules re-wrap it
    // with `$(...)`, which lands on the same node).
    $.async = function (selector, cb) { cb(selector); };
    $.each = function (xs, fn) { (xs || []).forEach(function (x, i) { fn(i, x); }); };
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

    return { $: $, node: node };
}

function SoleTraderStub() {
    this.listenForSignupResult = function () {};
    this.ensureTokens = function () { return Promise.resolve(true); };
    this.focusSignupPopup = function () { return false; };
    this.launchSignup = function () { return null; };
    this.forgetAdoptions = function () {};
}

const BRAND_CONFIG = {
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: false,
    checkoutApiUrl: 'https://api.example.test',
    checkoutPageUrl: 'https://checkout.example.test',
    companySearchLimit: 10,
    supportedCompanyTypes: { gb: [] },
    orderIntentConfig: { extensionPlatformName: 'magento2', extensionDBVersion: '1.0.0' }
};

/**
 * The renderer, the capture component and the identity singleton all three
 * share — the real production wiring, where the picker writes the identity and
 * the renderer reads it.
 *
 * `pick()` drives the component's OWN `onSelect` — the callback it hands the
 * panel — so a regression that routed selection through fillCompanyData() fails
 * here. That the panel actually calls it when the buyer clicks a result row is
 * pinned against real DOM in gateway-method-capture-mode-chips.test.js.
 */
function loadRenderer() {
    const dom = makeDom();
    const identity = loadAmdModule(IDENTITY, {});
    const panel = { options: null };

    function PanelStub(panelOptions) {
        panel.options = panelOptions;
        this.bind = function () {};
        this.isBound = function () { return true; };
        this.getField = function () { return dom.$(TILE_FIELD_SELECTOR); };
        this.close = function () {};
        this.syncChips = function () {};
        this.setDisplayText = function () {};
        this.releaseField = function () {};
        this.reclaimField = function () {};
        this.abortActiveRequest = function () {};
    }

    const companySearch = loadAmdModule(SEARCH, { jquery: dom.$ });
    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: function () { return { countryId: 'GB' }; }
    });
    const shared = {
        jquery: dom.$,
        'Magento_Checkout/js/model/quote': quote,
        'Two_Gateway/js/model/company-identity': identity,
        'Two_Gateway/js/model/company-search': companySearch
    };
    const component = loadCompanyCapture(
        Object.assign({}, shared, {
            'Two_Gateway/js/model/company-search-panel': PanelStub,
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': brandConfigMock(BRAND_CONFIG)
        })
    );
    component.start();

    const renderer = loadAmdModule(
        RENDERER,
        Object.assign({}, shared, {
            'Two_Gateway/js/model/company-capture': component
        })
    );

    /** Hand one picked row to the component's own selection handler. */
    function pick(selected) {
        panel.options.onSelect(selected);
    }

    return { renderer: renderer, component: component, identity: identity, node: dom.node, pick };
}

/**
 * What the two selection paths pass. Only a selection may clear a company that
 * is already selected; the one-shot `companyData` section read on init may not
 * (see 'a stale name-only section read does not clobber a live pick').
 */
const AS_SELECTION = { authoritative: true };

describe('picking a company with no national identifier', () => {
    test('the real selection handler writes the pick authoritatively', () => {
        // Through the actual selection path, not a helper: a regression that
        // routed the handler through fillCompanyData() has to fail here.
        const { renderer, pick } = loadRenderer();

        pick({ id: 'First Example Ltd', text: 'First Example Ltd', companyId: '12345678' });
        expect(renderer.companyId()).toBe('12345678');

        pick({ id: 'Second Example Ltd', text: 'Second Example Ltd', companyId: '' });

        // The name moved, so the id MUST have moved with it.
        expect(renderer.companyName()).toBe('Second Example Ltd');
        expect(renderer.companyId()).toBe('');
    });

    test('a normal pick after an identifier-less one restores the identifier', () => {
        const { renderer, pick } = loadRenderer();

        pick({ id: 'Second Example Ltd', text: 'Second Example Ltd', companyId: '' });
        // Asserting the NAME moved, not that the id is '' — the id is the
        // observable's initial value at this point, so a `toBe('')` here would
        // restate the default and could not fail.
        expect(renderer.companyName()).toBe('Second Example Ltd');

        pick({ id: 'First Example Ltd', text: 'First Example Ltd', companyId: '12345678' });

        expect(renderer.companyId()).toBe('12345678');
    });

    test('applyCompanyData overwrites a previously selected company id', () => {
        const { renderer } = loadRenderer();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            AS_SELECTION
        );
        expect(renderer.companyId()).toBe('12345678');

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            AS_SELECTION
        );

        expect(renderer.companyName()).toBe('Second Example Ltd');
        expect(renderer.companyId()).toBe('');
    });

    test('a non-string companyId is kept, not treated as identifier-less', () => {
        // The registry's `national_identifier.id` is not guaranteed to be a
        // string. A typeof test coerced a numeric one to '' and routed a
        // company that HAS an identifier down the identifier-less branch,
        // actively CLEARING it.
        const { renderer } = loadRenderer();

        renderer.applyCompanyData({ companyName: 'First Example Ltd', companyId: 12345678 });

        expect(renderer.companyId()).toBe('12345678');
    });

    test.each([
        [{}, 'an empty section object'],
        [undefined, 'no section at all'],
        [{ companyName: '', companyId: '' }, 'a section holding two empty strings']
    ])('%p does not blank live state (%s)', (section) => {
        // Why applyCompanyData() routes on "name set, id empty" rather than
        // just dropping fillCompanyData()'s guard: the guard is load-bearing
        // for the init call in fillCustomerData(), which passes whatever the
        // `companyData` section happens to hold.
        const { renderer } = loadRenderer();

        renderer.applyCompanyData({ companyName: 'First Example Ltd', companyId: '12345678' });
        renderer.applyCompanyData(section);

        expect(renderer.companyName()).toBe('First Example Ltd');
        expect(renderer.companyId()).toBe('12345678');
    });
});

describe('a company picked on the shipping step reaches the payment step', () => {
    /**
     * Minimal observable with real subscribers, so `fillCustomerData()`'s
     * `companyData` subscription can be driven the way the shipping step drives
     * it (`customerData.set('companyData', ...)`).
     */
    function observable(initial) {
        let value = initial;
        const subs = [];
        function obs(next) {
            if (!arguments.length) return value;
            value = next;
            subs.forEach((fn) => fn(value));
            return obs;
        }
        obs.subscribe = function (fn) {
            subs.push(fn);
            return { dispose: function () {} };
        };
        return obs;
    }

    /**
     * The renderer with a customer-data double whose sections the test writes,
     * and a quote whose addresses are well-formed enough for
     * fillCustomerData()'s other subscriptions.
     */
    function loadWithSections(initialCompanyData) {
        const dom = makeDom();
        const identity = loadAmdModule(IDENTITY, {});
        const sections = { companyData: observable(initialCompanyData) };
        const address = { getCacheKey: () => 'k', countryId: 'GB' };
        const billingAddress = observable(address);
        const intents = [];
        const renderer = loadAmdModule(RENDERER, {
            jquery: dom.$,
            'Two_Gateway/js/model/company-identity': identity,
            'Magento_Customer/js/customer-data': {
                get: function (key) {
                    if (!sections[key]) sections[key] = observable('');
                    return sections[key];
                },
                set: function (key, value) {
                    sections[key] = sections[key] || observable('');
                    sections[key](value);
                },
                reload: function () {}
            },
            'Magento_Checkout/js/model/quote': {
                shippingAddress: observable(address),
                billingAddress: billingAddress,
                getTotals: () => observable({}),
                getQuoteId: () => null,
                paymentMethod: observable(null),
                shippingMethod: observable({ carrier_code: 'freeshipping' }),
                isVirtual: () => false
            }
        });
        renderer.isOrderIntentEnabled = true;
        renderer.placeOrderIntent = function () {
            intents.push(renderer.companyId());
            return { always: () => ({ done: () => ({ fail: () => {} }) }) };
        };
        return { renderer, sections, dom, billingAddress, intents };
    }

    test('the companyData subscription clears the previous company id', () => {
        // The shipping step publishes {companyName, companyId: ''} to the
        // `companyData` section. Routing that subscription through
        // fillCompanyData() dropped it on the empty id, leaving the payment step
        // holding the previous company's number under the new company's name.
        const { renderer, sections } = loadWithSections({});

        renderer.fillCustomerData();
        sections.companyData({ companyName: 'First Example Ltd', companyId: '12345678' });
        expect(renderer.companyId()).toBe('12345678');

        sections.companyData({ companyName: 'Second Example Ltd', companyId: '' });

        expect(renderer.companyName()).toBe('Second Example Ltd');
        expect(renderer.companyId()).toBe('');
    });

    test('a company with a number places exactly one order intent; one without places none', () => {
        // The intent is what makes the routing choice observable: it is fired
        // only from inside fillCompanyData(), past its both-halves-present
        // guard, and selectCompanyWithoutIdentifier() has no intent call at all.
        const { renderer, sections, intents } = loadWithSections({});

        renderer.fillCustomerData();
        sections.companyData({ companyName: 'First Example Ltd', companyId: '12345678' });
        expect(intents).toEqual(['12345678']);

        sections.companyData({ companyName: 'Second Example Ltd', companyId: '' });

        expect(intents).toEqual(['12345678']);
        expect(renderer.companyId()).toBe('');
    });

    test('the section read on init carries a name-only company through', () => {
        // Not just the subscription: the payment renderer may initialise AFTER
        // the shipping-step pick (a fresh renderer, or a re-render), in which
        // case the name-only company arrives through the one-shot read rather
        // than through a change notification. Reading it with fillCompanyData()
        // dropped it, leaving the payment step with no company at all.
        const { renderer } = loadWithSections({
            companyName: 'Second Example Ltd',
            companyId: ''
        });

        renderer.fillCustomerData();

        // Only the name assertion can fail here: `companyId` is still at its
        // declared '' at this point, so asserting that would restate the default.
        expect(renderer.companyName()).toBe('Second Example Ltd');
    });

    test('a stale name-only section read does not clobber a live pick', () => {
        // `companyData` is a localStorage section, so a
        // `{companyName, companyId: ''}` row outlives page loads and previous
        // orders — and fillCustomerData() is re-callable. Treating the one-shot
        // READ as a selection therefore let a stale row overwrite a live pick's
        // name and blank its organisation number. Only a change NOTIFICATION on
        // the section is a selection.
        const { renderer } = loadWithSections({
            companyName: 'Stale Example Ltd',
            companyId: ''
        });

        renderer.applyCompanyData(
            { companyName: 'Live Example Ltd', companyId: '99999999' },
            AS_SELECTION
        );
        renderer.fillCustomerData();

        expect(renderer.companyName()).toBe('Live Example Ltd');
        expect(renderer.companyId()).toBe('99999999');
    });

    test('an address notification carrying company_id reaches the observable', () => {
        // updateAddress()'s custom-attribute parsing is a writer path of its
        // own, and the one most easily conflated with the `companyData`
        // notification — this one fires from the quote subscriptions with no
        // address-step interaction at all. Without it,
        // `if (item.attribute_code == 'company_id')` has no test anywhere.
        const { renderer, billingAddress } = loadWithSections({});

        renderer.fillCustomerData();

        billingAddress({
            getCacheKey: () => 'k2',
            countryId: 'GB',
            company: 'First Example Ltd',
            customAttributes: [{ attribute_code: 'company_id', value: '12345678' }]
        });

        expect(renderer.companyName()).toBe('First Example Ltd');
        expect(renderer.companyId()).toBe('12345678');
    });
});

describe('the shipping step agrees with the payment step', () => {
    test('setCompanyData writes the empty company id straight through', () => {
        // The address step is already authoritative — it writes both the
        // `companyData` section and the DOM field unconditionally. This pins
        // that, because the payment step trusts the section it publishes.
        const dom = makeDom();
        const sections = {};
        const autocomplete = loadAmdModule('view/frontend/web/js/view/address-autocomplete.js', {
            jquery: dom.$,
            'Magento_Customer/js/customer-data': {
                get: function () {
                    return function () { return {}; };
                },
                set: function (key, value) { sections[key] = value; },
                reload: function () {}
            }
        });

        autocomplete.setCompanyData('12345678', 'First Example Ltd');
        expect(sections.companyData).toEqual({
            companyId: '12345678',
            companyName: 'First Example Ltd',
            // TWO-24867. The double's country select is empty, so the stamp is
            // '' here; what it says under a real country is pinned in
            // Test/Js/company-search-country-switch.test.js.
            companyCountry: ''
        });
        // `toEqual` treats a key holding `undefined` as equal to the key being
        // absent, so it alone would pass if `companyId` stopped being written.
        // `toStrictEqual` is not usable here — the harness runs modules in a
        // `vm` context, so every strict compare fails cross-realm with
        // "serializes to the same string". Assert the key set instead.
        expect(Object.keys(sections.companyData).sort()).toEqual([
            'companyCountry',
            'companyId',
            'companyName'
        ]);

        autocomplete.setCompanyData('', 'Second Example Ltd');
        expect(sections.companyData).toEqual({
            companyId: '',
            companyName: 'Second Example Ltd',
            companyCountry: ''
        });
        expect(Object.keys(sections.companyData).sort()).toEqual([
            'companyCountry',
            'companyId',
            'companyName'
        ]);
        expect(dom.node(autocomplete.companyIdSelector).val()).toBe('');
    });
});
