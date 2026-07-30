/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Returning from manual company entry to search mode.
 *
 * Both pickers grow a "Search for company" link once the buyer has taken the
 * "Enter details manually" exit. Clicking it used to only re-bind select2 and
 * hide itself, leaving the buyer looking at a CLOSED picker they had to click
 * a second time before they could type. This pins that the link now opens the
 * dropdown and lands the caret in the search box.
 *
 * Mutation-resistance notes, because this repo's AMD harness makes vacuous
 * assertions easy to write:
 *
 *  - The assertions are on the REAL jsdom DOM, not on call records:
 *    `document.activeElement` and the presence of an open container /
 *    search input. `toHaveBeenCalledWith` on a select2 spy would pass for an
 *    implementation that opened a dropdown nothing could type into.
 *  - The jQuery and select2 doubles are DOM-backed: `select2('open')` really
 *    attaches a dropdown containing a real `<input class="select2-search__field">`
 *    and really marks the container open, so the production
 *    `document.querySelector('.select2-search__field').focus()` has to run
 *    against a live node for the focus assertion to pass.
 *  - `select2('open')` on a destroyed/absent widget THROWS in the double, as
 *    it does in select2 4.1. Opening before the re-bind cannot pass.
 *  - Every case first drives the real journey (open picker → manual entry →
 *    click the link) and asserts the pre-click state is closed and unfocused.
 *    A surface that never bound the link would fail there rather than
 *    presenting as green. The two surfaces offer manual entry differently —
 *    a cancellable results row on the shipping step, a link below the results
 *    on the payment step — so each drives its own production route.
 *  - Two negative pins guard the obvious over-reach: the FIRST bind (initial
 *    checkout render) must not open anything, and a later `$.async` re-fire
 *    must not re-open it under a buyer who has moved on.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

/**
 * The shipping surface reaches manual entry through a row INSIDE the results
 * list, so the model decides what counts as that row. Sentinel-based override
 * of that one predicate, everything else inert.
 */
function manualEntryAwareCompanySearch() {
    return Object.assign({}, defaultMocks()['Two_Gateway/js/model/company-search'], {
        isManualEntryOption: function (data) { return !!(data && data.__manualEntry); }
    });
}

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true
};

/* ------------------------------------------------------------------ *
 * A DOM-backed jQuery double.
 *
 * Only the subset both pickers use, but every write lands on real jsdom
 * nodes so the tests can assert on the document rather than on a log.
 * ------------------------------------------------------------------ */

function makeDomJQuery() {
    function nodesOf(arg) {
        if (arg === null || arg === undefined) return [];
        if (arg.__wrapped) return arg.nodes;
        if (typeof arg === 'string') {
            return Array.from(document.querySelectorAll(arg));
        }
        if (arg.nodeType) return [arg];
        if (Array.isArray(arg)) return arg;
        return [];
    }

    function parseEventArg(spec) {
        const dot = spec.indexOf('.');
        if (dot === -1) return { type: spec, ns: '' };
        return { type: spec.slice(0, dot), ns: spec.slice(dot) };
    }

    function handlersOf(node) {
        if (!node.__twoHandlers) node.__twoHandlers = [];
        return node.__twoHandlers;
    }

    function dataOf(node) {
        if (!node.__twoData) node.__twoData = {};
        return node.__twoData;
    }

    function wrap(nodes) {
        const api = {
            __wrapped: true,
            nodes: nodes,
            length: nodes.length,
            get: function (i) { return nodes[i]; },
            first: function () { return wrap(nodes.slice(0, 1)); },
            each: function (fn) { nodes.forEach(function (n, i) { fn.call(n, i, n); }); return api; },
            val: function () {
                if (arguments.length === 0) return nodes.length ? nodes[0].value : undefined;
                const v = arguments[0];
                nodes.forEach(function (n) { n.value = v; });
                return api;
            },
            text: function () {
                if (arguments.length === 0) return nodes.length ? nodes[0].textContent : '';
                const v = arguments[0];
                nodes.forEach(function (n) { n.textContent = v; });
                return api;
            },
            attr: function (name, value) {
                if (arguments.length === 1) {
                    return nodes.length ? nodes[0].getAttribute(name) : undefined;
                }
                nodes.forEach(function (n) { n.setAttribute(name, value); });
                return api;
            },
            data: function (key, value) {
                if (arguments.length === 1) {
                    return nodes.length ? dataOf(nodes[0])[key] : undefined;
                }
                nodes.forEach(function (n) { dataOf(n)[key] = value; });
                return api;
            },
            addClass: function (c) { nodes.forEach(function (n) { n.classList.add(c); }); return api; },
            removeClass: function (c) { nodes.forEach(function (n) { n.classList.remove(c); }); return api; },
            hide: function () { nodes.forEach(function (n) { n.style.display = 'none'; }); return api; },
            show: function () { nodes.forEach(function (n) { n.style.display = ''; }); return api; },
            append: function (content) {
                nodes.forEach(function (n) {
                    if (typeof content === 'string') {
                        n.insertAdjacentHTML('beforeend', content);
                    } else {
                        nodesOf(content).forEach(function (c) { n.appendChild(c); });
                    }
                });
                return api;
            },
            remove: function () {
                nodes.forEach(function (n) { if (n.parentNode) n.parentNode.removeChild(n); });
                return api;
            },
            find: function (selector) {
                const found = [];
                nodes.forEach(function (n) {
                    Array.from(n.querySelectorAll(selector)).forEach(function (m) {
                        if (found.indexOf(m) === -1) found.push(m);
                    });
                });
                return wrap(found);
            },
            closest: function (selector) {
                const found = [];
                nodes.forEach(function (n) {
                    const m = n.closest(selector);
                    if (m && found.indexOf(m) === -1) found.push(m);
                });
                return wrap(found);
            },
            parent: function () {
                const found = [];
                nodes.forEach(function (n) {
                    if (n.parentNode && found.indexOf(n.parentNode) === -1) found.push(n.parentNode);
                });
                return wrap(found);
            },
            on: function (spec, fn) {
                const parsed = parseEventArg(spec);
                nodes.forEach(function (n) {
                    handlersOf(n).push({ type: parsed.type, ns: parsed.ns, fn: fn });
                });
                return api;
            },
            off: function (spec) {
                const parsed = spec ? parseEventArg(spec) : { type: '', ns: '' };
                nodes.forEach(function (n) {
                    n.__twoHandlers = handlersOf(n).filter(function (h) {
                        if (parsed.type && h.type !== parsed.type) return true;
                        if (parsed.ns && h.ns !== parsed.ns) return true;
                        return false;
                    });
                });
                return api;
            },
            trigger: function (type, payload) {
                nodes.forEach(function (n) {
                    handlersOf(n)
                        .filter(function (h) { return h.type === type; })
                        .slice()
                        .forEach(function (h) { h.fn.call(n, payload || { type: type }); });
                });
                return api;
            },
            select2: function (arg) {
                if (typeof arg === 'object' && arg !== null) {
                    nodes.forEach(function (n) { select2Init(n, arg); });
                    return api;
                }
                nodes.forEach(function (n) { select2Command(n, arg); });
                return api;
            }
        };
        return api;
    }

    /* -------------------------------------------------------------- *
     * select2 double. Faithful on the two points these tests turn on:
     * a container that carries the open marker class, and a dropdown
     * holding a real search input, attached BEFORE `select2:open`
     * fires (which is why the production focus call can find it).
     * -------------------------------------------------------------- */

    function select2Init(node, options) {
        // select2 4.1's constructor destroys any existing instance on the
        // same node, so re-init is not additive.
        if (dataOf(node).select2) select2Command(node, 'destroy');

        const container = document.createElement('span');
        container.className = 'select2 select2-container';
        container.innerHTML = '<span class="select2-selection__rendered"></span>';
        node.parentNode.insertBefore(container, node.nextSibling);

        dataOf(node).select2 = { options: options, container: container, dropdown: null };
    }

    function select2Command(node, command) {
        const instance = dataOf(node).select2;
        if (command === 'destroy') {
            if (!instance) return;
            if (instance.dropdown && instance.dropdown.parentNode) {
                instance.dropdown.parentNode.removeChild(instance.dropdown);
            }
            if (instance.container.parentNode) {
                instance.container.parentNode.removeChild(instance.container);
            }
            delete dataOf(node).select2;
            return;
        }
        if (command === 'open') {
            // Matches select2 4.1: calling a method on an unbound element is
            // an error, not a silent no-op.
            if (!instance) throw new Error('select2: no instance bound to this element');
            if (instance.dropdown) return;
            const dropdown = document.createElement('span');
            dropdown.className = 'select2-dropdown';
            dropdown.innerHTML =
                '<span class="select2-search select2-search--dropdown">' +
                '<input class="select2-search__field" type="search">' +
                '</span>' +
                '<span class="select2-results"><ul></ul></span>';
            document.body.appendChild(dropdown);
            instance.dropdown = dropdown;
            instance.container.classList.add('select2-container--open');
            // Only now, with the dropdown live, does select2 relay the event.
            wrap([node]).trigger('select2:open', { type: 'select2:open' });
            return;
        }
        throw new Error('select2 double: unsupported command ' + command);
    }

    function $(arg) { return wrap(nodesOf(arg)); }

    $.asyncCalls = [];
    $.async = function (selector, fn) {
        $.asyncCalls.push({ selector: selector, fn: fn });
        const node = document.querySelector(selector);
        if (node) fn(node);
    };
    $.fn = {};
    $.extend = Object.assign;
    $.ajax = function () {
        return {
            done: function () { return this; },
            fail: function () { return this; },
            always: function () { return this; }
        };
    };
    $.Deferred = function () {
        const d = {
            resolve: function () { return d; },
            reject: function () { return d; },
            promise: function () { return d; },
            done: function () { return d; },
            fail: function () { return d; },
            always: function () { return d; }
        };
        return d;
    };
    $.mage = { cookies: { get: function () { return null; }, set: function () {} }, redirect: function () {} };
    return $;
}

/** Fire the click handlers our double registered on the first match. */
function click($, selector) {
    const $el = $(selector);
    expect($el.length).toBeGreaterThan(0);
    $el.first().trigger('click');
}

function openMarkerPresent() {
    return document.querySelectorAll('.select2-container--open').length > 0;
}

function focusedSearchField() {
    const active = document.activeElement;
    if (!active || !active.classList) return null;
    return active.classList.contains('select2-search__field') ? active : null;
}

/* ------------------------------------------------------------------ *
 * Shipping-step surface (address-autocomplete.js)
 * ------------------------------------------------------------------ */

function buildShippingDom() {
    document.body.innerHTML =
        '<form id="shipping-new-address-form">' +
        '<div class="field">' +
        '<input name="company" type="text" value="">' +
        '</div>' +
        '</form>';
}

function loadShipping($) {
    const brandConfig = function () { return BASE_CONFIG; };
    brandConfig.getActiveTwoBrandCode = function () { return 'two_payment'; };
    brandConfig.getActiveTwoBrandConfig = function () { return BASE_CONFIG; };

    const component = loadAmdModule(
        'view/frontend/web/js/view/address-autocomplete.js',
        {
            jquery: $,
            'Two_Gateway/js/model/brand-config': brandConfig,
            'Two_Gateway/js/model/company-search': manualEntryAwareCompanySearch()
        },
        { document: document, window: window }
    );

    const ctx = Object.assign(Object.create(component.prototype || {}), {
        countrySelector: '#shipping-new-address-form select[name="country_id"]',
        companyNameSelector: '#shipping-new-address-form input[name="company"]',
        enterDetailsManuallyButton: '#shipping_enter_details_manually',
        searchForCompanyButton: '#shipping_search_for_company',
        enterDetailsManuallyText: 'Enter details manually',
        searchForCompanyText: 'Search for company',
        companyNamePlaceholder: 'Enter company name to search',
        setCompanyData: function () {},
        addressLookup: function () { return null; },
        enterDetailsManually: component.enterDetailsManually,
        enableCompanySearch: component.enableCompanySearch
    });
    return { component: component, ctx: ctx };
}

/**
 * Shipping surface: manual entry is a row in the results list, cancelled
 * through select2's preventable `select2:selecting` pre-event. Driving the
 * real handler rather than calling enterDetailsManually() directly keeps the
 * production route in the test.
 */
function enterManualShipping($, ctx) {
    $(ctx.companyNameSelector).trigger('select2:selecting', {
        params: { args: { data: { __manualEntry: true } } },
        preventDefault: function () {}
    });
}

/* ------------------------------------------------------------------ *
 * Payment-step surface (gateway_method.js)
 * ------------------------------------------------------------------ */

function buildPaymentDom() {
    document.body.innerHTML =
        '<div class="field">' +
        '<input id="company_name" name="company_name" type="text" value="">' +
        '</div>';
}

function loadPayment($) {
    const component = loadAmdModule(
        'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
        { jquery: $ },
        { document: document, window: window }
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
        applyCompanyData: function () {},
        addressLookup: function () { return null; },
        clearCompany: component.clearCompany,
        disableCompanySearch: component.disableCompanySearch,
        destroyCompanySearchWidget: component.destroyCompanySearchWidget,
        enableCompanySearch: component.enableCompanySearch
    });
    return { component: component, ctx: ctx };
}

/**
 * Payment surface: manual entry is still a link appended below the results,
 * bound on `select2:open`.
 */
function enterManualPayment($, ctx) {
    click($, ctx.enterDetailsManuallyButton);
}

/**
 * Drive the real journey up to the point of returning to search mode, and
 * assert the picker is genuinely closed and unfocused first.
 *
 * @param {object} $ the jQuery double
 * @param {object} ctx the surface's `this`
 * @param {function} enterManual takes the buyer to manual entry, the way the
 *        surface actually offers it
 * @param {string} searchLink selector for "Search for company"
 */
function reachManualMode($, ctx, enterManual, searchLink) {
    ctx.enableCompanySearch();

    // Guard: without a bound widget nothing below could be meaningful.
    expect($(ctx.companyNameSelector).data('select2')).toBeTruthy();
    expect($(searchLink).length).toBeGreaterThan(0);

    // Open once, so whatever the surface binds on `select2:open` is live.
    $(ctx.companyNameSelector).select2('open');

    enterManual($, ctx);

    // Manual mode: widget gone, so nothing open and nothing focused.
    expect($(ctx.companyNameSelector).data('select2')).toBeUndefined();
    expect(openMarkerPresent()).toBe(false);
    expect(focusedSearchField()).toBeNull();
}

describe.each([
    ['shipping-step picker (address-autocomplete.js)', buildShippingDom, loadShipping,
        enterManualShipping, '#shipping_search_for_company'],
    ['payment-step picker (gateway_method.js)', buildPaymentDom, loadPayment,
        enterManualPayment, '.search_for_company']
])('%s — returning to search mode', (_name, buildDom, load, enterManual, searchLink) => {
    let $;
    let ctx;

    beforeEach(() => {
        buildDom();
        const loaded = load(($ = makeDomJQuery()));
        ctx = loaded.ctx;
    });

    test('clicking "Search for company" opens the dropdown and focuses the search input', () => {
        reachManualMode($, ctx, enterManual, searchLink);

        click($, searchLink);

        // (a) the dropdown is really open …
        expect(openMarkerPresent()).toBe(true);
        expect(document.querySelectorAll('.select2-search__field')).toHaveLength(1);
        // … and (b) the caret is really in its text input.
        expect(focusedSearchField()).not.toBeNull();
        expect(document.activeElement).toBe(document.querySelector('.select2-search__field'));
    });

    test('the widget is re-bound before it is opened', () => {
        reachManualMode($, ctx, enterManual, searchLink);

        // The double throws on `open` without an instance, so reaching an
        // open dropdown at all proves the re-bind happened first.
        expect(function () { click($, searchLink); }).not.toThrow();
        expect($(ctx.companyNameSelector).data('select2')).toBeTruthy();
    });

    test('the link hides itself, so search mode cannot be re-entered from it', () => {
        reachManualMode($, ctx, enterManual, searchLink);

        click($, searchLink);

        expect($(searchLink).first().get(0).style.display).toBe('none');
    });

    test('the initial bind does not open the picker or steal focus', () => {
        ctx.enableCompanySearch();

        expect($(ctx.companyNameSelector).data('select2')).toBeTruthy();
        expect(openMarkerPresent()).toBe(false);
        expect(focusedSearchField()).toBeNull();
    });

    test('a later re-render does not re-open the dropdown behind the buyer', () => {
        reachManualMode($, ctx, enterManual, searchLink);
        click($, searchLink);
        expect(openMarkerPresent()).toBe(true);

        // Close it the way a buyer would, then let `$.async` fire again —
        // it is a MutationObserver and a one-page checkout re-renders often.
        $(ctx.companyNameSelector).select2('destroy');
        expect(openMarkerPresent()).toBe(false);

        const asyncFires = $.asyncCalls.filter(function (call) {
            return call.selector === ctx.companyNameSelector;
        });
        expect(asyncFires.length).toBeGreaterThan(0);
        asyncFires[asyncFires.length - 1].fn(document.querySelector(ctx.companyNameSelector));

        // Re-bound, but NOT re-opened: the open request was one-shot.
        expect($(ctx.companyNameSelector).data('select2')).toBeTruthy();
        expect(openMarkerPresent()).toBe(false);
        expect(focusedSearchField()).toBeNull();
    });
});
