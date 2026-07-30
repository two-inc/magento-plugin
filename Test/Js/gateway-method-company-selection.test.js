/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25253, second half. Guarding `national_identifier` in
 * `company-search.js` made a new state reachable: a company hit that renders
 * with an EMPTY `companyId`. These tests cover what the payment step does when
 * the buyer actually picks one.
 *
 * The failure mode being pinned is a mis-submitted order, not a crash.
 * `fillCompanyData()` early-returns unless BOTH name and id are non-empty, so
 * picking an identifier-less company after a valid one used to leave the
 * previous company's organisation number in `companyId()` while select2
 * displayed the new company's name — `getData()` / `placeOrderIntent()` then
 * sent company A's number under company B's name. A selection has to be
 * authoritative.
 *
 * The editability half of that fix is GONE, and so are the tests for it:
 * TWO-25288 removed the tile's company-number input outright, because a
 * hand-typed organisation number is not an accepted source. There is no field
 * to enable or disable on this surface any more, and an identifier-less
 * selection is refused server-side by Model/Two.php::authorize(). What is
 * pinned below is the observable state a selection leaves behind — see
 * tile-company-number-removed.test.js for the removal itself.
 *
 * LIMITATION OF THE jQuery DOUBLE BELOW — read before trusting a passing test
 * here. `node.on()` stores ONE handler per event name and `node.off()` is a
 * no-op, so two handlers bound to the same event on the same node are
 * unmodellable and the LAST bind silently wins. Nothing in this file can
 * therefore say anything about handler ORDERING or handler COEXISTENCE.
 *
 * That is not academic: it is exactly how a `change` handler on the tile's
 * former company-number input got here looking correct. The template bound
 * `value: companyId`, so ko's `value` binding was already listening on that
 * same `change` event and was registered first (at applyBindings, before
 * `$.async` runs) — ko wrote `companyId()` before any later handler saw the
 * event, which made the later handler's "did the value change?" check trivially
 * false. The double could not express the ko handler at all, so the test passed
 * against a no-op. If a question depends on more than one listener for an
 * event, make the double faithful (a real handler list plus a working `off()`)
 * first.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

/**
 * jQuery double that keeps one persistent node per selector, so a write made
 * through `$(sel).val(...)` is visible to a later `$(sel).val()` read. The
 * default harness jQuery is inert (every setter returns the same empty object
 * and records nothing), which would let a broken implementation pass.
 */
function makeDom() {
    const nodes = {};

    function node(selector) {
        if (nodes[selector]) return nodes[selector];
        const n = {
            selector: selector,
            length: 1,
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
            // ONE handler per event name, and `off()` does nothing. See the
            // "LIMITATION OF THE jQuery DOUBLE" note at the top of this file
            // before writing anything that depends on handler ordering or on
            // two handlers sharing an event.
            on: function (event, fn) {
                // Strip the `.twoCompanySearch` namespace so tests can fire by
                // plain event name.
                n.handlers[String(event).split('.')[0]] = fn;
                return n;
            },
            off: function () {
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
            attr: function () {
                return n;
            },
            data: function () {
                return null;
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
    // `$.async` is a MutationObserver in Magento; the node is already present
    // here, so resolve immediately with the selector (the renderer re-wraps it
    // with `$(...)`, which lands on the same node).
    $.async = function (selector, cb) {
        cb(selector);
    };
    $.each = function (xs, fn) {
        (xs || []).forEach(function (x, i) {
            fn(i, x);
        });
    };
    $.ajax = function () {
        return { done: () => this, fail: () => this, always: () => this };
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

/**
 * Load the renderer against the recording jQuery. `Component.extend(spec)` in
 * the harness returns an object carrying the spec's own properties, so the
 * returned value doubles as the `this` the methods run against — including its
 * own `companyName` / `companyId` observables.
 */
function loadRenderer() {
    const dom = makeDom();
    const renderer = loadAmdModule(RENDERER, { jquery: dom.$ });
    return { renderer: renderer, node: dom.node, $: dom.$ };
}

/**
 * What the two selection paths pass. Only a selection may clear a company that
 * is already selected; the one-shot `companyData` section read on init may not
 * (see 'a stale name-only section read does not clobber a live pick').
 */
const AS_SELECTION = { authoritative: true };
const COMPANY_NAME_FIELD = 'input#company_name';

describe('picking a company with no national identifier', () => {
    test('applyCompanyData overwrites a previously selected company id', () => {
        const { renderer, node } = loadRenderer();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            AS_SELECTION
        );
        expect(renderer.companyName()).toBe('First Example Ltd');
        expect(renderer.companyId()).toBe('12345678');

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            AS_SELECTION
        );

        // The name moved, so the id MUST have moved with it.
        expect(renderer.companyName()).toBe('Second Example Ltd');
        expect(renderer.companyId()).toBe('');
        expect(node(COMPANY_NAME_FIELD).val()).toBe('Second Example Ltd');
    });

    test('a normal pick after an identifier-less one restores the identifier', () => {
        // Driven through the real select2:select handler. Editability is no
        // longer part of this: TWO-25288 removed the tile's company-number
        // input, so the only thing a pick can get wrong is the observable.
        const { renderer, node } = loadRenderer();

        renderer.enableCompanySearch();
        const select = node(COMPANY_NAME_FIELD).handlers['select2:select'];

        select({
            params: {
                data: { id: 'Second Example Ltd', text: 'Second Example Ltd', companyId: '' }
            }
        });
        // Asserting the NAME moved, not that the id is '' — the id is the
        // observable's initial value at this point, so an `toBe('')` here would
        // restate the default and could not fail.
        expect(renderer.companyName()).toBe('Second Example Ltd');

        select({
            params: {
                data: { id: 'First Example Ltd', text: 'First Example Ltd', companyId: '12345678' }
            }
        });

        expect(renderer.companyId()).toBe('12345678');
    });

    test('the real select2:select handler routes an empty companyId authoritatively', () => {
        // Through the actual selection path, not the helper: a regression that
        // reverted the handler to fillCompanyData() has to fail here.
        const { renderer, node } = loadRenderer();

        renderer.enableCompanySearch();
        const select = node(COMPANY_NAME_FIELD).handlers['select2:select'];
        expect(typeof select).toBe('function');

        select({
            params: {
                data: { id: 'First Example Ltd', text: 'First Example Ltd', companyId: '12345678' }
            }
        });
        expect(renderer.companyId()).toBe('12345678');

        select({
            params: {
                data: { id: 'Second Example Ltd', text: 'Second Example Ltd', companyId: '' }
            }
        });

        expect(renderer.companyName()).toBe('Second Example Ltd');
        expect(renderer.companyId()).toBe('');
    });

    test('a non-string companyId is kept, not treated as identifier-less', () => {
        // The registry's `national_identifier.id` is not guaranteed to be a
        // string. A typeof test coerced a numeric one to '' and routed a
        // company that HAS an identifier down the identifier-less branch,
        // actively CLEARING it.
        const { renderer, node } = loadRenderer();

        renderer.applyCompanyData({ companyName: 'First Example Ltd', companyId: 12345678 });

        expect(renderer.companyId()).toBe('12345678');
    });

    test('an empty customer-data section on init does not blank live state', () => {
        // Why applyCompanyData() routes on "name set, id empty" rather than
        // just dropping fillCompanyData()'s guard: the guard is load-bearing
        // for the init call in fillCustomerData(), which passes whatever the
        // `companyData` section happens to hold.
        const { renderer, node } = loadRenderer();

        renderer.applyCompanyData({ companyName: 'First Example Ltd', companyId: '12345678' });
        renderer.applyCompanyData({});
        renderer.applyCompanyData(undefined);
        renderer.applyCompanyData({ companyName: '', companyId: '' });

        expect(renderer.companyName()).toBe('First Example Ltd');
        expect(renderer.companyId()).toBe('12345678');
    });
});

describe('a company picked on the shipping step reaches the payment step', () => {
    /**
     * Minimal observable with real subscribers, so `fillCustomerData()`'s
     * `companyData` subscription can be driven the way the shipping-step
     * picker drives it (`customerData.set('companyData', ...)`).
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
     * Load the renderer with a customer-data double whose sections the test
     * writes, and a quote whose addresses are inert but well-formed enough for
     * fillCustomerData()'s other subscriptions.
     */
    function loadWithSections(initialCompanyData) {
        const dom = makeDom();
        const sections = { companyData: observable(initialCompanyData) };
        const address = { getCacheKey: () => 'k', countryId: 'GB' };
        const billingAddress = observable(address);
        const renderer = loadAmdModule(RENDERER, {
            jquery: dom.$,
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
        // Normally seeded by initialize(), which the harness's Component
        // double doesn't run. Pre-seeded for 'gb' so the supported-company-types
        // lookup resolves from the memo instead of reaching for fetch().
        renderer.supportedCompanyTypes = { gb: [] };
        return {
            renderer: renderer,
            sections: sections,
            dom: dom,
            billingAddress: billingAddress
        };
    }

    test('the companyData subscription clears the previous company id', () => {
        // The shipping-step picker publishes {companyName, companyId: ''} to
        // the `companyData` customer-data section. Routing that subscription
        // through fillCompanyData() dropped it on the empty id, leaving the
        // payment step holding the previously picked company's organisation
        // number under the newly picked company's name.
        const { renderer, sections, dom } = loadWithSections({});

        renderer.fillCustomerData();
        sections.companyData({ companyName: 'First Example Ltd', companyId: '12345678' });
        expect(renderer.companyId()).toBe('12345678');

        sections.companyData({ companyName: 'Second Example Ltd', companyId: '' });

        expect(renderer.companyName()).toBe('Second Example Ltd');
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
        // declared '' at this point, so asserting that would restate the
        // default. The `enableCompanySearch()` call that used to precede this
        // was setup for a disabled-field precondition that no longer exists.
        expect(renderer.companyName()).toBe('Second Example Ltd');
    });

    test('a stale name-only section read does not clobber a live pick', () => {
        // `companyData` is a localStorage customer-data section, so a
        // `{companyName, companyId: ''}` row outlives page loads and previous
        // orders — and fillCustomerData() is re-callable (applyPrefetch() →
        // registeredOrganisationMode()). Treating the one-shot READ as a
        // selection therefore let a stale row overwrite a live payment-step
        // pick's name and blank its organisation number. Before the routing
        // existed this shape was a harmless no-op on the read path; it has to
        // stay one. Only a change NOTIFICATION on the section is a selection.
        const { renderer } = loadWithSections({
            companyName: 'Stale Example Ltd',
            companyId: ''
        });

        renderer.applyCompanyData(
            { companyName: 'Live Example Ltd', companyId: '99999999' },
            { authoritative: true }
        );

        renderer.fillCustomerData();

        expect(renderer.companyName()).toBe('Live Example Ltd');
        expect(renderer.companyId()).toBe('99999999');
    });

    test('an address notification carrying company_id reaches the observable', () => {
        // updateAddress()'s custom-attribute parsing is the address step's route
        // into `companyId()`, and it is one of the three accepted sources. Its
        // only coverage used to live in a describe block about the editable
        // state of the tile's company-number field; that field is gone, but THIS
        // subject is not, so the assertion is restored here on its own terms.
        //
        // Without it, `if (item.attribute_code == 'company_id')` in
        // updateAddress() had no test anywhere in the repo.
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

describe('order intent for a company with no registry identifier', () => {
    /**
     * `initialize()` never runs under the harness's Component double, so
     * `isOrderIntentEnabled` is undefined and the intent branch is dead in
     * every test that does not set it explicitly. Set it, and count the calls.
     */
    function loadWithIntent() {
        const dom = makeDom();
        const renderer = loadAmdModule(RENDERER, { jquery: dom.$ });
        renderer.isOrderIntentEnabled = true;
        const chain = {
            always: () => chain,
            done: () => chain,
            fail: () => chain
        };
        renderer.placeOrderIntent = jest.fn(() => chain);
        return { renderer: renderer, node: dom.node, intent: renderer.placeOrderIntent };
    }

    test('a normal pick places exactly one intent', () => {
        const { renderer, node, intent } = loadWithIntent();

        renderer.enableCompanySearch();
        node(COMPANY_NAME_FIELD).handlers['select2:select']({
            params: {
                data: { id: 'First Example Ltd', text: 'First Example Ltd', companyId: '12345678' }
            }
        });

        expect(intent).toHaveBeenCalledTimes(1);
    });

    test('an identifier-less pick places no intent — there is no number yet', () => {
        const { renderer, node, intent } = loadWithIntent();

        renderer.enableCompanySearch();
        node(COMPANY_NAME_FIELD).handlers['select2:select']({
            params: {
                data: { id: 'Second Example Ltd', text: 'Second Example Ltd', companyId: '' }
            }
        });

        expect(intent).not.toHaveBeenCalled();
    });

});

describe('the shipping-step picker agrees with the payment step', () => {
    test('setCompanyData writes the empty company id straight through', () => {
        // The address-step picker is already authoritative — it writes both the
        // `companyData` customer-data section and the DOM field unconditionally
        // — and it never disables its own company_id input. This pins that,
        // because the payment step now trusts the section it publishes.
        const dom = makeDom();
        const sections = {};
        const autocomplete = loadAmdModule('view/frontend/web/js/view/address-autocomplete.js', {
            jquery: dom.$,
            'Magento_Customer/js/customer-data': {
                get: function () {
                    return function () {
                        return {};
                    };
                },
                set: function (key, value) {
                    sections[key] = value;
                },
                reload: function () {}
            }
        });

        autocomplete.setCompanyData('12345678', 'First Example Ltd');
        expect(sections.companyData).toEqual({
            companyId: '12345678',
            companyName: 'First Example Ltd'
        });
        // `toEqual` treats a key holding `undefined` as equal to the key being
        // absent, so it alone would pass if `companyId` stopped being written.
        // `toStrictEqual` is not usable here — the harness runs modules in a
        // `vm` context, so every strict compare fails cross-realm with
        // "serializes to the same string". Assert the key set instead.
        expect(Object.keys(sections.companyData).sort()).toEqual(['companyId', 'companyName']);

        autocomplete.setCompanyData('', 'Second Example Ltd');
        expect(sections.companyData).toEqual({ companyId: '', companyName: 'Second Example Ltd' });
        expect(Object.keys(sections.companyData).sort()).toEqual(['companyId', 'companyName']);
        expect(dom.node(autocomplete.companyIdSelector).val()).toBe('');
    });
});
