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
 * Second half of the fix: `company_id` is disabled while company search owns
 * it, and jQuery Validation's `elements()` skips `:disabled`, so the
 * template's `required="true"` is NOT enforced on a disabled field. An
 * identifier-less pick therefore has to RE-ENABLE the field, or the buyer has
 * neither a way to supply the number nor a validation error telling them to.
 *
 * LIMITATION OF THE jQuery DOUBLE BELOW — read before trusting a passing test
 * here. `node.on()` stores ONE handler per event name and `node.off()` is a
 * no-op, so two handlers bound to the same event on the same node are
 * unmodellable and the LAST bind silently wins. Nothing in this file can
 * therefore say anything about handler ORDERING or handler COEXISTENCE.
 *
 * That is not academic: it is exactly how a `change` handler on `input#company_id`
 * got here looking correct. In the browser the template binds `value: companyId`,
 * so ko's `value` binding is already listening on that same `change` event and
 * was registered first (at applyBindings, before `$.async` runs) — ko writes
 * `companyId()` before any later handler sees the event, which made the later
 * handler's "did the value change?" check trivially false. The double could not
 * express the ko handler at all, so the test passed against a no-op. If a
 * question depends on more than one listener for an event, make the double
 * faithful (a real handler list plus a working `off()`) first.
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

const COMPANY_ID_FIELD = 'input#company_id';
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
        expect(node(COMPANY_ID_FIELD).val()).toBe('12345678');

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            AS_SELECTION
        );

        // The name moved, so the id MUST have moved with it.
        expect(renderer.companyName()).toBe('Second Example Ltd');
        expect(renderer.companyId()).toBe('');
        expect(node(COMPANY_ID_FIELD).val()).toBe('');
        expect(node(COMPANY_NAME_FIELD).val()).toBe('Second Example Ltd');
    });

    test('applyCompanyData re-enables company_id so the buyer can supply it', () => {
        const { renderer, node } = loadRenderer();

        // Company search owns the field until then. This assertion is a
        // precondition, not the property under test — enableCompanySearch()
        // disables the field itself, so it holds trivially here.
        renderer.enableCompanySearch();
        expect(node(COMPANY_ID_FIELD).prop('disabled')).toBe(true);

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            AS_SELECTION
        );

        expect(node(COMPANY_ID_FIELD).prop('disabled')).toBe(false);
    });

    test('a normal pick after an identifier-less one re-disables company_id', () => {
        // Driven through the real select2:select handler, with NO hand-call of
        // syncCompanyIdEditable() — production has no such call on this path,
        // and a test that made one asserted a property the code did not have.
        // The state being ruled out is a registry organisation number sitting
        // in an ENABLED field, which the buyer could overwrite by hand.
        const { renderer, node } = loadRenderer();

        renderer.enableCompanySearch();
        const select = node(COMPANY_NAME_FIELD).handlers['select2:select'];

        select({
            params: {
                data: { id: 'Second Example Ltd', text: 'Second Example Ltd', companyId: '' }
            }
        });
        expect(node(COMPANY_ID_FIELD).prop('disabled')).toBe(false);

        select({
            params: {
                data: { id: 'First Example Ltd', text: 'First Example Ltd', companyId: '12345678' }
            }
        });

        expect(renderer.companyId()).toBe('12345678');
        expect(node(COMPANY_ID_FIELD).prop('disabled')).toBe(true);
    });

    test('a later enableCompanySearch does not re-disable the field', () => {
        // In the browser the `require()` wrapper puts enableCompanySearch()'s
        // field handling after the synchronous fillCustomerData() that follows
        // it in registeredOrganisationMode(). The harness's require() and
        // $.async doubles are both SYNCHRONOUS, so this test does not model
        // that ordering — what it pins is narrower and still worth pinning:
        // enableCompanySearch() derives the disabled state from the selected
        // company instead of hard-coding `true`, so a revert to the literal
        // fails here.
        const { renderer, node } = loadRenderer();

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            AS_SELECTION
        );
        renderer.enableCompanySearch();

        expect(node(COMPANY_ID_FIELD).prop('disabled')).toBe(false);
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
        expect(node(COMPANY_ID_FIELD).val()).toBe('');
        expect(node(COMPANY_ID_FIELD).prop('disabled')).toBe(false);
    });

    test('a non-string companyId is kept, not treated as identifier-less', () => {
        // The registry's `national_identifier.id` is not guaranteed to be a
        // string. A typeof test coerced a numeric one to '' and routed a
        // company that HAS an identifier down the identifier-less branch,
        // actively CLEARING it.
        const { renderer, node } = loadRenderer();

        renderer.applyCompanyData({ companyName: 'First Example Ltd', companyId: 12345678 });

        expect(renderer.companyId()).toBe('12345678');
        expect(node(COMPANY_ID_FIELD).val()).toBe('12345678');
        expect(node(COMPANY_ID_FIELD).prop('disabled')).toBe(true);
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
        expect(node(COMPANY_ID_FIELD).val()).toBe('12345678');
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
                billingAddress: observable(address),
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
        return { renderer: renderer, sections: sections, dom: dom };
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
        expect(dom.node(COMPANY_ID_FIELD).val()).toBe('');
        expect(dom.node(COMPANY_ID_FIELD).prop('disabled')).toBe(false);
    });

    test('the section read on init also leaves company_id editable', () => {
        // Not just the subscription: the payment renderer may initialise AFTER
        // the shipping-step pick (a fresh renderer, or a re-render), in which
        // case the name-only company arrives through the one-shot read rather
        // than through a change notification. Reading it with fillCompanyData()
        // dropped it and left the buyer facing an empty, disabled, required
        // company number with no company name to explain it.
        const { renderer, dom } = loadWithSections({
            companyName: 'Second Example Ltd',
            companyId: ''
        });

        renderer.enableCompanySearch();
        // Precondition, not the property under test — enableCompanySearch()
        // disables the field itself with nothing selected, so this holds
        // trivially (same as at 'applyCompanyData re-enables company_id').
        expect(dom.node(COMPANY_ID_FIELD).prop('disabled')).toBe(true);

        renderer.fillCustomerData();

        expect(renderer.companyName()).toBe('Second Example Ltd');
        expect(renderer.companyId()).toBe('');
        expect(dom.node(COMPANY_ID_FIELD).prop('disabled')).toBe(false);
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
        const { renderer, dom } = loadWithSections({
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
        expect(dom.node(COMPANY_ID_FIELD).val()).toBe('99999999');
        expect(dom.node(COMPANY_ID_FIELD).prop('disabled')).toBe(true);
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

    test('a hand-typed organisation number is never intent-checked', () => {
        // Current, deliberate behaviour, stated so nobody reads the absence of
        // a test as an oversight: nothing re-fires the intent once an
        // identifier-less company is picked. The buyer can type the number and
        // the order goes out carrying it, with no credit check behind it.
        //
        // The `change`-handler version of this does NOT work — see the
        // "LIMITATION OF THE jQuery DOUBLE" note at the top of this file — and
        // is split to its own ticket. Asserting on the renderer's own API here
        // rather than through the DOM, because the double cannot model the ko
        // `value` binding that shares the event.
        const { renderer, node, intent } = loadWithIntent();

        renderer.enableCompanySearch();
        node(COMPANY_NAME_FIELD).handlers['select2:select']({
            params: {
                data: { id: 'Second Example Ltd', text: 'Second Example Ltd', companyId: '' }
            }
        });
        expect(intent).not.toHaveBeenCalled();

        // No handler is bound to the field's `change` at all any more.
        expect(node(COMPANY_ID_FIELD).handlers['change']).toBeUndefined();

        // What ko's `value` binding does when the buyer commits a number.
        renderer.companyId('99999999');

        expect(intent).not.toHaveBeenCalled();
    });
});

describe('company_id editability is derived, not set per caller', () => {
    /**
     * Same sections harness as above, so updateAddress() can be driven the way
     * fillCustomerData() drives it — through a billing-address notification.
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

    test('an address notify carrying company_id cannot leave a registry number in an enabled field', () => {
        // The desync 7f243b5 argued could not happen. Its reasoning was that
        // fillCompanyData()'s other callers "run in modes where company search
        // does not own the field" — false for updateAddress(), which is
        // subscribed inside fillCustomerData() and fires on every
        // billing/shipping notification, i.e. in registered-organisation mode
        // with the picker live. So: pick an identifier-less company (field
        // enabled), then let one address notification arrive carrying a
        // `company_id` custom attribute. Before the derived subscription the
        // registry number landed in a still-ENABLED field — MAJOR 1's exact
        // state, which the buyer can hand-overwrite.
        const dom = makeDom();
        const address = { getCacheKey: () => 'k', countryId: 'GB' };
        const billingAddress = observable(address);
        const renderer = loadAmdModule(RENDERER, {
            jquery: dom.$,
            'Magento_Customer/js/customer-data': {
                get: function () {
                    return observable('');
                },
                set: function () {},
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
        renderer.supportedCompanyTypes = { gb: [] };

        renderer.enableCompanySearch();
        renderer.fillCustomerData();

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            AS_SELECTION
        );
        expect(dom.node(COMPANY_ID_FIELD).prop('disabled')).toBe(false);

        // One billing-address notification, carrying a company_id attribute.
        billingAddress({
            getCacheKey: () => 'k2',
            countryId: 'GB',
            company: 'First Example Ltd',
            customAttributes: [{ attribute_code: 'company_id', value: '12345678' }]
        });

        // Whatever the notification does to the selection, it must not leave a
        // registry organisation number sitting in a hand-editable field.
        expect(dom.node(COMPANY_ID_FIELD).prop('disabled')).toBe(true);
        expect(renderer.companyId()).toBe('12345678');
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
