/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * AMD-in-Jest harness.
 *
 * Magento JS files are AMD modules: `define([deps], factory)` where
 * `deps` are paths like `Magento_Checkout/js/view/payment/default` that
 * the in-browser RequireJS loader resolves against Magento's pubstatic
 * tree. Under Node + Jest those paths can't load, so this harness
 * captures the `define(...)` call, resolves each dep to a unit-test
 * mock from `mocks()`, and returns whatever the factory returns.
 *
 * The harness deliberately doesn't try to be a faithful RequireJS
 * implementation — it loads exactly one file in isolation, with all
 * deps stubbed. That's enough for "did this file load without
 * throwing and return something with the right shape" smoke tests
 * across the whole module's JS surface.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

/**
 * Default mock implementations of Magento RequireJS modules used
 * across the Two_Gateway frontend/adminhtml JS. Each test file may
 * override individual entries via the `extraMocks` parameter of
 * `loadAmdModule()`.
 */
/**
 * Lazily-loaded REAL `company-search.js`, for the default mock's pure display
 * helpers to delegate to (see the `formatCompanyNumber` entry below).
 *
 * Lazy and memoised: `defaultMocks()` runs for every `loadAmdModule()` call, and
 * this must not load the module — nor recurse into `defaultMocks()` — unless one
 * of those helpers is actually reached. Given its own jQuery double rather than
 * the caller's: the delegated members are pure string functions that touch no
 * DOM, so the double is never used, and closing over the caller's would make the
 * memoised copy depend on whichever test loaded first.
 *
 * @returns {object} the real company-search module
 */
let realCompanySearchModule = null;
function realCompanySearch() {
    if (!realCompanySearchModule) {
        realCompanySearchModule = loadAmdModule(
            'view/frontend/web/js/model/company-search.js',
            { jquery: makeJQueryMock(), 'mage/translate': function (s) { return s; } }
        );
    }
    return realCompanySearchModule;
}

function defaultMocks() {
    const ko = makeKnockoutMock();
    const $ = makeJQueryMock();
    const Component = makeComponentMock();

    return {
        ko: ko,
        knockout: ko,
        jquery: $,
        underscore: makeUnderscoreMock(),
        prototype: {},
        loader: {},
        'mage/translate': function (s) { return s; },
        'mage/url': { build: function (u) { return u; }, setBaseUrl: function () {} },
        'mage/utils/wrapper': { wrap: function (target, wrapper) { return wrapper.bind(null, target); } },
        'mage/validation': {},
        'mage/cookies': {},
        'jquery/jquery-storageapi': {},
        'jquery/jstree/jquery.jstree': {},
        'domReady!': null,
        'Magento_Checkout/js/view/payment/default': Component,
        'Magento_Checkout/js/model/quote': {
            shippingAddress: makeObservable({}),
            billingAddress: makeObservable({}),
            getTotals: function () { return makeObservable({}); },
            getQuoteId: function () { return null; },
            paymentMethod: makeObservable(null),
            shippingMethod: makeObservable({ carrier_code: 'freeshipping' }),
            isVirtual: function () { return false; }
        },
        'Magento_Customer/js/customer-data': {
            get: function () { return makeObservable({}); },
            set: function () {},
            reload: function () {}
        },
        'Magento_Checkout/js/model/payment/additional-validators': {
            registerValidator: function () {},
            validate: function () { return true; }
        },
        'Magento_Checkout/js/model/payment/renderer-list': {
            push: function () {},
            asArray: function () { return []; }
        },
        'Magento_Checkout/js/model/full-screen-loader': {
            startLoader: function () {},
            stopLoader: function () {}
        },
        'Magento_Checkout/js/action/redirect-on-success': { execute: function () {} },
        'Magento_Checkout/js/model/step-navigator': {
            registerStep: function () {},
            navigateTo: function () {},
            next: function () {}
        },
        'Magento_Checkout/js/view/summary/abstract-total': Component,
        'Magento_Checkout/js/model/totals': {
            isLoading: makeObservable(false),
            totals: makeObservable({})
        },
        'Magento_Checkout/js/model/payment-service': {
            setPaymentMethods: function () {}
        },
        'Magento_Checkout/js/model/cart/totals-processor/default': {
            estimateTotals: function () {}
        },
        'Magento_Checkout/js/action/set-shipping-information': function () { return {}; },
        'Magento_Ui/js/lib/view/utils/async': {},
        'Magento_Ui/js/form/form': Component,
        'Magento_Ui/js/modal/modal': function () {},
        'uiComponent': Component,
        'uiRegistry': {
            get: function () {},
            set: function () {},
            create: function () {},
            async: function () { return function () {}; }
        },
        'Magento_Catalog/js/price-utils': { formatPrice: function (n) { return String(n); } },
        'Two_Gateway/js/model/surcharge': makeSurchargeMock(),
        'Two_Gateway/js/model/minimum-order-visibility': function () { return true; },
        // Inert default. Tests that exercise the real company-search
        // behaviour load the real module and pass it via extraMocks so
        // they control the jQuery it closes over.
        'Two_Gateway/js/model/company-search': {
            REQUEST_TIMEOUT_MS: 30000,
            SEARCH_DEBOUNCE_MS: 300,
            buildSearchAjaxOptions: function () { return {}; },
            lookupCompanyAddress: function () { return null; },
            applyAddress: function () {},
            // Address write-back surface (TWO-25461). Inert, like applyAddress()
            // above: a spec that cares about what the write or the revert
            // actually does loads the real module.
            revertAutofilledAddress: function () { return 0; },
            billingRoleFormRoot: function () { return null; },
            isDegradedResponse: function () { return false; },
            clearResultCache: function () {},
            EVENT_NS: '.twoCompanySearch',
            MIN_INPUT_LENGTH: 3,
            // Derived from this mock's own MIN_INPUT_LENGTH so the harness
            // does not reintroduce the literal the real module centralises.
            // Both production call sites invoke this as
            // `companySearch.minInputLengthMessage()`, so `this` is the mock.
            minInputLengthMessage: function () {
                return 'Please enter ' + this.MIN_INPUT_LENGTH + ' or more characters';
            },
            // TWO-25326 §1 wording, mirrored here so a call site that reads
            // it through the mock gets the same string the real module
            // returns rather than select2's vendored "No results found".
            noResultsMessage: function () {
                return 'No matches found';
            },
            buildLanguageOptions: function () {
                const self = this;
                return {
                    inputTooShort: function () { return self.minInputLengthMessage(); },
                    noResults: function () { return self.noResultsMessage(); }
                };
            },
            attachOpenOnType: function () {},
            getSearchFieldContainer: function () { return null; },
            abortActiveRequest: function () { return false; },
            attachManualEntryButton: function () {},
            detachManualEntryButton: function () {},
            syncManualEntryButton: function () { return null; },
            buildManualEntryButton: function () { return null; },
            markSearchBinding: function () {},
            // TWO-25326 display helpers. DELEGATED to the real module, not
            // reimplemented: call sites READ their return value to decide
            // whether to render a label or brackets at all, so an inert '' would
            // make those assertions pass vacuously — and a hand-copied
            // reimplementation would leave every suite that reaches them (e.g.
            // gateway-method-intent-approved-notice) green against stale logic
            // the moment the production rule changes.
            get HIDDEN_COMPANY_NUMBER_PREFIX() {
                return realCompanySearch().HIDDEN_COMPANY_NUMBER_PREFIX;
            },
            formatCompanyNumber: function (value) {
                return realCompanySearch().formatCompanyNumber(value);
            },
            stripBracketedToken: function (text, token) {
                return realCompanySearch().stripBracketedToken(text, token);
            },
            // No DOM in the inert default: a spec that wants the live
            // address-form country read has to supply the real module (or its
            // own double) the same way it already does for the search itself.
            currentAddressFormCountry: function () { return ''; },
            clearSearchChrome: function () {},
            setSearching: function () {},
            setUnavailable: function () {}
        },
        // Inert default, same convention as the company-search mock above:
        // a constructor whose instances no-op every method. Tests that
        // exercise the real select2 wiring load the real
        // company-search-control.js module and pass it via extraMocks so
        // they control the jQuery/select2 it closes over.
        'Two_Gateway/js/model/company-search-control': (function () {
            function CompanySearchControlMock() {
                this._field = null;
            }
            CompanySearchControlMock.prototype.bind = function () {};
            CompanySearchControlMock.prototype.destroy = function () { return false; };
            CompanySearchControlMock.prototype.abortActiveRequest = function () { return false; };
            CompanySearchControlMock.prototype.isBound = function () { return false; };
            CompanySearchControlMock.prototype.getField = function () { return this._field || {}; };
            CompanySearchControlMock.prototype.showSearchForCompanyLink = function () {};
            CompanySearchControlMock.prototype.getSearchForCompanyLink = function () {
                return { length: 0 };
            };
            return CompanySearchControlMock;
        })(),
        'Two_Gateway/js/model/brand-config': (function () {
            function getBrandConfig(code) {
                return ((typeof window !== 'undefined' && window.checkoutConfig && window.checkoutConfig.payment) || {})[code] || {};
            }
            getBrandConfig.getActiveTwoBrandCode = function () {
                var payment = (typeof window !== 'undefined' && window.checkoutConfig && window.checkoutConfig.payment) || {};
                for (var code in payment) {
                    if (Object.prototype.hasOwnProperty.call(payment, code)
                        && payment[code]
                        && payment[code].redirectUrlCookieCode) {
                        return code;
                    }
                }
                return null;
            };
            getBrandConfig.getActiveTwoBrandConfig = function () {
                var code = getBrandConfig.getActiveTwoBrandCode();
                return code ? getBrandConfig(code) : {};
            };
            return getBrandConfig;
        }()),
        'Two_Gateway/select2-4.1.0/js/select2.min': function () {}
    };
}

function makeKnockoutMock() {
    function pureComputed(fn) {
        const o = makeObservable(fn());
        return o;
    }
    return {
        observable: makeObservable,
        observableArray: function (init) { return makeObservable(init || []); },
        pureComputed: pureComputed,
        computed: pureComputed,
        applyBindings: function () {},
        bindingHandlers: {}
    };
}

function makeObservable(initial) {
    let value = initial;
    const subscribers = [];
    function obs(next) {
        if (arguments.length === 0) return value;
        value = next;
        subscribers.forEach(function (s) { s(value); });
        return obs;
    }
    obs.subscribe = function (fn) {
        subscribers.push(fn);
        return { dispose: function () {} };
    };
    obs.extend = function () { return obs; };
    obs.peek = function () { return value; };
    return obs;
}

function makeJQueryMock() {
    function $() {
        // Return a chainable empty jQuery object
        const obj = {
            length: 0,
            on: function () { return obj; },
            off: function () { return obj; },
            click: function () { return obj; },
            val: function () { return obj; },
            text: function () { return obj; },
            html: function () { return obj; },
            attr: function () { return obj; },
            removeAttr: function () { return obj; },
            data: function () { return obj; },
            find: function () { return obj; },
            closest: function () { return obj; },
            first: function () { return obj; },
            last: function () { return obj; },
            eq: function () { return obj; },
            each: function () { return obj; },
            addClass: function () { return obj; },
            removeClass: function () { return obj; },
            append: function () { return obj; },
            prepend: function () { return obj; },
            after: function () { return obj; },
            before: function () { return obj; },
            empty: function () { return obj; },
            hide: function () { return obj; },
            show: function () { return obj; },
            css: function () { return obj; },
            ready: function (fn) { if (typeof fn === 'function') fn(); return obj; },
            trigger: function () { return obj; },
            valid: function () { return true; },
            validate: function () { return obj; },
            select2: function () { return obj; },
            modal: function () { return obj; },
            mage: function () { return obj; }
        };
        return obj;
    }
    $.fn = {};
    $.extend = Object.assign;
    $.ajax = function () { return { done: function () { return this; }, fail: function () { return this; }, always: function () { return this; } }; };
    $.mage = {
        cookies: { get: function () { return null; }, set: function () {} },
        redirect: function () {},
        // Locale-aware number parse: comma decimals normalise, so '0,5' is
        // 0.5 rather than parseFloat's 0. Modules rely on exactly that
        // difference, so the mock must reproduce it — and reproduce the
        // both-separators case too ('1.234,56' is 1234.56), or the mock buys
        // false confidence in the locale behaviour it exists to cover.
        parseNumber: function (v) {
            var str = String(v).trim(),
                lastComma = str.lastIndexOf(','),
                lastDot = str.lastIndexOf('.'),
                decimalAt = Math.max(lastComma, lastDot);

            if (decimalAt === -1) {
                return parseFloat(str);
            }

            // Whichever separator comes last is the decimal point; every
            // earlier separator is a grouping mark and is stripped.
            return parseFloat(
                str.slice(0, decimalAt).replace(/[.,]/g, '') + '.' + str.slice(decimalAt + 1)
            );
        }
    };
    // `mage/validation` decorates jQuery with the validator registry. Any
    // module that registers a rule depends on it being there, so the mock has
    // to carry it — a jQuery without it is a shape no browser presents, and a
    // smoke load failing on that tests the mock, not the module.
    $.validator = {
        methods: {},
        addMethod: function (name, fn, message) {
            $.validator.methods[name] = fn;
            $.validator.messages = $.validator.messages || {};
            $.validator.messages[name] = message;
        }
    };
    $.Deferred = function () {
        return { resolve: function () { return this; }, reject: function () { return this; }, promise: function () { return this; }, done: function () { return this; }, fail: function () { return this; }, always: function () { return this; } };
    };
    // Magento_Ui/js/lib/view/utils/async's `$.async` decorator — a
    // MutationObserver wrapper real modules call as `$.async(selector, cb)`
    // to defer against a node that may not exist yet. This default runs the
    // callback synchronously against the (inert) mock node so a module that
    // reaches this call path incidentally — e.g. gateway_method.js's
    // enableCompanySearch(), now called from address-change handlers — does
    // not throw "not a function" in specs that never meant to exercise the
    // company-search widget itself. Specs that DO care about the widget's
    // real async/MutationObserver behaviour already supply their own richer
    // jquery double (see makeRecordingDom() in tile-company-readonly-fields.test.js).
    $.async = function (selector, cb) {
        cb($(selector));
    };
    return $;
}

function makeUnderscoreMock() {
    return {
        each: function (xs, fn) { (xs || []).forEach(fn); },
        map: function (xs, fn) { return (xs || []).map(fn); },
        filter: function (xs, fn) { return (xs || []).filter(fn); },
        find: function (xs, fn) { return (xs || []).find(fn); },
        extend: Object.assign,
        keys: Object.keys,
        values: Object.values,
        isObject: function (x) { return typeof x === 'object' && x !== null; },
        isArray: Array.isArray,
        isFunction: function (x) { return typeof x === 'function'; },
        isString: function (x) { return typeof x === 'string'; },
        isUndefined: function (x) { return x === undefined; },
        bind: function (fn, ctx) { return fn.bind(ctx); }
    };
}

function makeComponentMock() {
    function extend(spec) {
        function Ctor() {
            Object.assign(this, spec || {});
            if (typeof this.initialize === 'function') {
                this.initialize();
            }
            return this;
        }
        Ctor.extend = extend;
        Ctor.prototype = Object.assign({}, spec || {});
        Ctor.prototype._super = function () { return this; };
        // Also expose extend on the spec so chained .extend().extend() works
        return Object.assign(Ctor, spec || {}, { extend: extend });
    }
    return { extend: extend };
}

function makeSurchargeMock() {
    return {
        selectedTerm: makeObservable(null),
        termSurcharges: makeObservable({}),
        currencySymbol: '€',
        selectTerm: function () {},
        fetchSurcharges: function () {}
    };
}

/**
 * Load an AMD module file and return whatever its factory returned.
 *
 * @param {string} relPath path relative to repo root
 * @param {object} extraMocks per-test overrides keyed by AMD dep name
 * @param {object} extraGlobals sandbox globals to add or replace. The default
 *        `document` is an inert stub with no query methods, so a test that
 *        needs the module's direct DOM calls (`document.querySelector(...)`,
 *        `.focus()`) to hit real nodes passes jsdom's own `document` here.
 * @returns {*} the factory's return value (KO component, mixin wrap, etc)
 */
function loadAmdModule(relPath, extraMocks, extraGlobals) {
    const absPath = path.resolve(__dirname, '..', '..', relPath);
    const src = fs.readFileSync(absPath, 'utf8');
    const mocks = Object.assign({}, defaultMocks(), extraMocks || {});

    let captured;
    const define = function (deps, factory) {
        // Anonymous define(deps, factory) is the shape we care about.
        // Some files use define(factory) (no deps array) — handle both.
        if (typeof deps === 'function') {
            captured = deps();
        } else if (Array.isArray(deps)) {
            const resolved = deps.map(function (name) {
                if (!(name in mocks)) {
                    throw new Error(
                        `AMD harness: unmocked dep "${name}" required by ${relPath}. ` +
                        `Add a mock entry to defaultMocks() or pass via extraMocks.`
                    );
                }
                return mocks[name];
            });
            captured = factory.apply(null, resolved);
        } else {
            throw new Error('AMD harness: unrecognised define() shape in ' + relPath);
        }
    };
    define.amd = {};

    // Some files start with bare top-level require() calls (notably
    // button-functions.js). Stub require to behave like define for that
    // pattern. require() factories are run for side effects so we still
    // mark the load as successful even if the factory returns nothing.
    let requireCalled = false;
    const require = function (deps, factory) {
        if (Array.isArray(deps) && typeof factory === 'function') {
            requireCalled = true;
            const resolved = deps.map(function (name) { return mocks[name] || {}; });
            const ret = factory.apply(null, resolved);
            if (ret !== undefined) {
                captured = ret;
            }
        }
    };

    const sandbox = {
        define: define,
        require: require,
        window: { checkoutConfig: { payment: {} } },
        document: {
            addEventListener: function () {},
            createElement: function () { return {}; },
            // Enough of a node for the focus call the company-search
            // pickers (address-step and payment-tile) make when their
            // dropdown opens. Without it, any test that triggers
            // `select2:open` dies inside the harness rather than exercising
            // the handler.
            querySelector: function () { return { focus: function () {} }; }
        },
        // Passed through from the jsdom test environment so a module that
        // watches a results list can actually watch one. The sandbox is a
        // separate vm context and does not inherit browser globals, so a
        // module guarding on `typeof MutationObserver === 'function'` would
        // otherwise take its no-observer fallback in every test.
        MutationObserver: typeof MutationObserver === 'function' ? MutationObserver : undefined,
        console: { log: function () {}, debug: function () {}, warn: function () {}, error: function () {} },
        setTimeout: setTimeout,
        clearTimeout: clearTimeout,
        // Browser globals the module sources use directly.
        URLSearchParams: URLSearchParams,
        unescape: global.unescape,
        Promise: Promise,
        fetch: typeof fetch === 'function' ? fetch : function () { return Promise.resolve(); },
        // requirejs-config.js files just assign a top-level `var config`.
        // The harness loads them only to verify they parse; the assignment
        // is captured via the wider context.
        config: undefined
    };
    Object.assign(sandbox, extraGlobals || {});
    sandbox.global = sandbox;

    vm.createContext(sandbox);
    vm.runInContext(src, sandbox, { filename: absPath });

    // requirejs-config.js shape: top-level `var config = {...};` — no
    // define() call. Surface the assignment via sandbox.config.
    if (captured === undefined && sandbox.config !== undefined) {
        captured = sandbox.config;
    }

    // Side-effect-only require() callers (e.g. button-functions.js) don't
    // return anything. Treat the load as successful by returning a
    // sentinel so toBeDefined() asserts pass.
    if (captured === undefined && requireCalled) {
        captured = { __loaded: true };
    }

    return captured;
}

/**
 * Load the REAL `company-search-control.js` class, closed over the given
 * jQuery (usually a test's own recording/spy double) and the given
 * `company-search.js` mock/real-module.
 *
 * Every test that wants to observe the actual select2 wiring — the
 * `.select2({...})` call, its `select2:open`/`select2:close`/`select2:select`
 * handlers, the manual-entry button, the "Search for company" link — has to
 * load this for real, exactly as it already has to load the real
 * `company-search.js` for the same reason: TWO-25326's rebuild moved that
 * wiring out of `address-autocomplete.js` / `gateway_method.js` and into
 * this one shared class, so the default inert mock (a no-op stub, same
 * convention as the `company-search` default) proves nothing about it.
 *
 * @param {object} $ jQuery (real or a test double)
 * @param {object} [companySearchMock] `company-search.js` module/mock —
 *        defaults to the harness's own inert mock, same as any other dep.
 * @param {object} [extraGlobals] forwarded to `loadAmdModule` — pass
 *        `{ document: document, window: window }` for any test that expects
 *        the `select2:open` handler's real focus call (`document
 *        .querySelector('.select2-search__field').focus()`) to land on the
 *        REAL jsdom document, the same real-globals requirement this line
 *        had before TWO-25326 moved it out of the two surface files.
 * @returns {Function} the CompanySearchControl constructor
 */
function loadCompanySearchControl($, companySearchMock, extraGlobals) {
    const extraMocks = { jquery: $ };
    if (companySearchMock) {
        extraMocks['Two_Gateway/js/model/company-search'] = companySearchMock;
    }
    return loadAmdModule(
        'view/frontend/web/js/model/company-search-control.js',
        extraMocks,
        extraGlobals
    );
}

module.exports = {
    loadAmdModule: loadAmdModule,
    defaultMocks: defaultMocks,
    loadCompanySearchControl: loadCompanySearchControl
};
