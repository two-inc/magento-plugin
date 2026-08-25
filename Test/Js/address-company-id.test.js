/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288, first half. The address step gains a usable company-number
 * field, so that it can become the single company surface on Luma. What is
 * pinned here:
 *
 *  - the field is editable exactly when a company is in play with no registry
 *    identifier behind it (the derivation the payment tile already applies to
 *    its own copy of the field), and
 *  - whatever the buyer types into it reaches the payment step through the ONE
 *    writer of the `companyData` customer-data section, because the payment
 *    step reads a change notification on that section as an act of selection.
 *
 * LIMITATIONS OF THE DOUBLES BELOW — read before trusting a pass here.
 *
 *  1. `node.on()` keeps ONE handler per event name and `off()` is a no-op, so
 *     nothing here can speak to handler ordering or coexistence.
 *  2. Company search is left OFF in these tests, so the picker's own handlers
 *     are never bound and cannot be driven from here. The manual-entry
 *     affordance is therefore exercised through `setCompanyData()` — what
 *     activating it calls — and not through the activation itself, so a
 *     regression that unwired the row would NOT fail here. Its wiring is
 *     covered in Test/Js/company-search-manual-entry.test.js instead.
 *  3. The shared harness mocks `Two_Gateway/js/model/company-search` to a set
 *     of no-ops (`buildSearchAjaxOptions` returns `{}`, `lookupCompanyAddress`
 *     returns null). Nothing in this file covers real search behaviour, and a
 *     test that loaded the module and asserted on search results would be
 *     asserting against those stubs.
 *  4. The harness's `uiRegistry.get()` returns undefined, so every assertion
 *     below observes the DOM half of `setCompanyIdDisabled()`. The UI-component
 *     half — the authoritative one, see that method's comment — is not
 *     exercised here.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const MODULE = 'view/frontend/web/js/view/address-autocomplete.js';

const NAME_FIELD = '#shipping-new-address-form input[name="company"]';
const ID_FIELD = '#shipping-new-address-form input[name="custom_attributes[company_id]"]';
const COUNTRY_FIELD = '#shipping-new-address-form select[name="country_id"]';

/**
 * jQuery double with one persistent node per selector, so a write made through
 * `$(sel).val(...)` is visible to a later `$(sel).val()` read. The default
 * harness jQuery is inert — every setter returns the same empty object and
 * records nothing — which would let a broken implementation pass.
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
            dataValues: {},
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
            data: function (key, next) {
                if (arguments.length < 2) return n.dataValues[key];
                n.dataValues[key] = next;
                return n;
            },
            // ONE handler per event name; `off()` does nothing. See limitation
            // 1 in the file header.
            on: function (event, fn) {
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
            // TWO-25326 §5's company-number text label builds and tears
            // itself down through these. Recorded rather than inert so a
            // future test can assert on them; this file only needs them not
            // to throw.
            addClass: function (cls) {
                n.classes = (n.classes || []).concat(cls);
                return n;
            },
            remove: function () {
                n.removed = true;
                return n;
            },
            show: function () {
                return n;
            },
            hide: function () {
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
    // `$.async` is a MutationObserver in Magento; the nodes are already present
    // here, so resolve immediately with the selector — the module re-wraps it
    // with `$(...)`, which lands on the same node.
    $.async = function (selector, cb) {
        cb(selector);
    };
    $.extend = Object.assign;
    $.fn = {};

    return { $: $, node: node };
}

/**
 * Customer-data double whose `companyData` section records every write, so the
 * "one writer, and it carries the right payload" claims are checkable.
 */
function makeCustomerData() {
    const writes = [];
    const sections = {};

    function observable(initial) {
        let value = initial;
        function obs(next) {
            if (!arguments.length) return value;
            value = next;
            return obs;
        }
        obs.subscribe = function () {
            return { dispose: function () {} };
        };
        return obs;
    }

    return {
        writes: writes,
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

function load(options) {
    const opts = options || {};
    const dom = makeDom();
    const cd = makeCustomerData();
    // Country has to read as a string: toggleCompanyVisibility() lowercases it.
    dom.node(COUNTRY_FIELD).val(opts.country || 'GB');
    if (opts.prefillName) dom.node(NAME_FIELD).val(opts.prefillName);
    if (opts.prefillId) dom.node(ID_FIELD).val(opts.prefillId);

    const component = loadAmdModule(MODULE, {
        jquery: dom.$,
        'Magento_Customer/js/customer-data': cd.api,
        'Two_Gateway/js/model/brand-config': {
            // Company search off by default: enableCompanySearch() then
            // early-returns and these tests stay about the company-number
            // field, with none of the picker's handlers bound (limitation 2).
            getActiveTwoBrandConfig: function () {
                return { isCompanySearchEnabled: !!opts.searchEnabled };
            }
        }
    });
    return { component: component, node: dom.node, writes: cd.writes };
}

/**
 * Run the component's real `initialize()`. The harness's Component double
 * returns the spec rather than constructing an instance, so `initialize()` is
 * NOT called by loading the module — but running it is what proves the
 * company-number wiring is actually reached on render rather than only when a
 * test calls it by hand.
 */
function initialise(component) {
    component._super = function () {};
    component.initialize();
    return component;
}

/** Make the double report select2 as bound to the company-name input. */
function activateSearch(node) {
    node(NAME_FIELD).data('select2', {});
}

describe('address-step company-number field editability', () => {
    test('a registry pick leaves the field disabled', () => {
        const { component, node } = load();
        activateSearch(node);

        component.setCompanyData('12345678', 'First Example Ltd');

        expect(node(ID_FIELD).val()).toBe('12345678');
        expect(node(ID_FIELD).prop('disabled')).toBe(true);
    });

    test('a pick with no registry identifier enables the field', () => {
        const { component, node } = load();
        activateSearch(node);

        component.setCompanyData('', 'Second Example Ltd');

        expect(node(ID_FIELD).prop('disabled')).toBe(false);
    });

    test('a registry pick after an identifier-less one re-disables the field', () => {
        const { component, node } = load();
        activateSearch(node);

        component.setCompanyData('', 'Second Example Ltd');
        expect(node(ID_FIELD).prop('disabled')).toBe(false);

        component.setCompanyData('12345678', 'First Example Ltd');
        expect(node(ID_FIELD).prop('disabled')).toBe(true);
    });

    test('a manually typed company name enables the field', () => {
        // The manual-entry row calls setCompanyData() with no arguments and
        // destroys the picker; the buyer then types the name into a plain text
        // input. Without the re-derivation on that input the buyer is left with
        // a company and no way to supply its number.
        const { component, node } = load();
        component.enableManualCompanyId();

        component.setCompanyData();
        expect(node(ID_FIELD).prop('disabled')).toBe(true);

        node(NAME_FIELD).val('Hand Typed Ltd');
        node(NAME_FIELD).handlers['input']();

        expect(node(ID_FIELD).prop('disabled')).toBe(false);
    });

    test('editing the company name again does not disable a typed-in number', () => {
        // Manual mode, and the buyer goes back to correct the company name
        // after typing its number. The name handler must not re-derive from the
        // number field's value: the number is non-empty by then, so the full
        // derivation reads "no manual entry needed" and would lock the buyer
        // out of the number they just typed, with nothing clearing it.
        const { component, node } = load();
        component.enableManualCompanyId();

        component.setCompanyData();
        node(NAME_FIELD).val('Hand Typed Ltd');
        node(NAME_FIELD).handlers['input']();
        expect(node(ID_FIELD).prop('disabled')).toBe(false);

        node(ID_FIELD).val('87654321');
        node(ID_FIELD).handlers['change']();

        node(NAME_FIELD).val('Hand Typed Limited');
        node(NAME_FIELD).handlers['input']();

        expect(node(ID_FIELD).prop('disabled')).toBe(false);
    });

    test('typing into the company-number field does not disable it', () => {
        // The derivation reads this very field, so re-deriving on its own
        // events would disable it the instant the buyer finished typing.
        const { component, node } = load();
        component.enableManualCompanyId();

        component.setCompanyData('', 'Second Example Ltd');
        expect(node(ID_FIELD).prop('disabled')).toBe(false);

        node(ID_FIELD).val('87654321');
        node(ID_FIELD).handlers['change']();

        expect(node(ID_FIELD).prop('disabled')).toBe(false);
    });

    test('a form rendered with a company already on it derives on resolve', () => {
        const { component, node } = load({ prefillName: 'Saved Ltd', prefillId: '12345678' });
        initialise(component);

        expect(node(ID_FIELD).prop('disabled')).toBe(true);
    });

    test('a form rendered with a company name but no number derives editable', () => {
        const { component, node } = load({ prefillName: 'Saved Ltd' });
        initialise(component);

        expect(node(ID_FIELD).prop('disabled')).toBe(false);
    });
});

describe('what the buyer types reaches the payment step', () => {
    test('the company-number field publishes to companyData', () => {
        const { component, node, writes } = load();
        component.enableManualCompanyId();

        component.setCompanyData('', 'Second Example Ltd');
        activateSearch(node);
        node(ID_FIELD).val('87654321');
        node(ID_FIELD).handlers['change']();

        const last = writes[writes.length - 1];
        expect(last.key).toBe('companyData');
        // `companyCountry` is TWO-24867's capture stamp — the payment tile
        // refuses a company whose stamp names a country the buyer has left.
        expect(last.value).toEqual({
            companyId: '87654321',
            companyName: 'Second Example Ltd',
            companyCountry: 'gb'
        });
    });

    test('a manually typed name and number publish together', () => {
        const { component, node, writes } = load();
        component.enableManualCompanyId();

        component.setCompanyData();
        node(NAME_FIELD).val('Hand Typed Ltd');
        node(NAME_FIELD).handlers['input']();
        node(ID_FIELD).val('87654321');
        node(ID_FIELD).handlers['change']();

        const last = writes[writes.length - 1];
        expect(last.value).toEqual({
            companyId: '87654321',
            companyName: 'Hand Typed Ltd',
            companyCountry: 'gb'
        });
    });

    test('every companyData write goes through publishCompanyData', () => {
        // The payment step reads a change notification on this section as an
        // act of selection, which is only sound while the section has one
        // writer. Pinned by neutering the one writer and requiring that NO
        // write survives.
        const { component, node, writes } = load();
        component.enableManualCompanyId();
        component.publishCompanyData = function () {};

        component.setCompanyData('12345678', 'First Example Ltd');
        component.setCompanyData();
        node(ID_FIELD).val('87654321');
        node(ID_FIELD).handlers['change']();

        expect(writes.filter((w) => w.key === 'companyData')).toEqual([]);
    });

    test('the company-number field is not bound per keystroke', () => {
        // An order intent fires as soon as the payment step holds both a name
        // and a number, so a keyup/input publish would fire one credit-check
        // request per character.
        const { component, node } = load();
        component.enableManualCompanyId();

        expect(typeof node(ID_FIELD).handlers['change']).toBe('function');
        expect(node(ID_FIELD).handlers['keyup']).toBeUndefined();
        expect(node(ID_FIELD).handlers['input']).toBeUndefined();
    });
});
