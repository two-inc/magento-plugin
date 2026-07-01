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
            getQuoteId: function () { return null; }
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
            data: function () { return obj; },
            find: function () { return obj; },
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
        redirect: function () {}
    };
    $.Deferred = function () {
        return { resolve: function () { return this; }, reject: function () { return this; }, promise: function () { return this; }, done: function () { return this; }, fail: function () { return this; }, always: function () { return this; } };
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
 * @returns {*} the factory's return value (KO component, mixin wrap, etc)
 */
function loadAmdModule(relPath, extraMocks) {
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
        document: { addEventListener: function () {}, createElement: function () { return {}; } },
        console: { log: function () {}, debug: function () {}, warn: function () {}, error: function () {} },
        setTimeout: setTimeout,
        clearTimeout: clearTimeout,
        // requirejs-config.js files just assign a top-level `var config`.
        // The harness loads them only to verify they parse; the assignment
        // is captured via the wider context.
        config: undefined
    };
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

module.exports = { loadAmdModule: loadAmdModule, defaultMocks: defaultMocks };
