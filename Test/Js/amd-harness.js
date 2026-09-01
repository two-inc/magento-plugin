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

/** Storefront base the default `mage/url` mock builds against. */
const HARNESS_BASE_URL = 'https://store.example.test/';

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
        'mage/url': {
            // Absolute, like the real builder: an identity mock lets a route
            // assertion pass without the module reaching the builder at all.
            build: function (u) { return HARNESS_BASE_URL + String(u == null ? '' : u).replace(/^\//, ''); },
            setBaseUrl: function () {}
        },
        'mage/utils/wrapper': { wrap: function (target, wrapper) { return wrapper.bind(null, target); } },
        'mage/validation': {},
        'mage/cookies': {},
        'jquery/jquery-storageapi': {},
        'jquery/jstree/jquery.jstree': {},
        'domReady!': null,
        'Magento_Checkout/js/view/payment/default': Component,
        'Magento_Checkout/js/model/quote': {
            // One cache key for both: the quote is what answers "is billing a
            // distinct address", so a double with no key at all cannot model
            // either answer. Same key means billing IS shipping.
            shippingAddress: quoteAddress(),
            billingAddress: quoteAddress(),
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
        'Magento_Ui/js/model/messageList': {
            addErrorMessage: function () {},
            addSuccessMessage: function () {}
        },
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
            searchCompanies: function () {
                return Promise.resolve({ items: [], unavailable: false, aborted: false });
            },
            lookupCompanyAddress: function () { return null; },
            applyAddress: function () {},
            applyTelephone: function () { return false; },
            // Address write-back surface (TWO-25461). Inert, like applyAddress()
            // above: a spec that cares about what the write or the revert
            // actually does loads the real module.
            revertAutofilledAddress: function () { return 0; },
            announceAddressUndeliverable: function (identity) {
                identity.addressNotice('address undeliverable');
            },
            hasPrimaryAddressForm: function () { return true; },
            isDegradedResponse: function () { return false; },
            // DELEGATED, like the display helpers below: an inert stub would
            // make every proxy pass/fail assertion vacuous.
            unwrapProxyResponse: function (raw) { return realCompanySearch().unwrapProxyResponse(raw); },
            clearResultCache: function () {},
            MIN_INPUT_LENGTH: 3,
            // Derived from this mock's own MIN_INPUT_LENGTH so the harness
            // does not reintroduce the literal the real module centralises.
            // Both production call sites invoke this as
            // `companySearch.minInputLengthMessage()`, so `this` is the mock.
            minInputLengthMessage: function () {
                return 'Enter ' + this.MIN_INPUT_LENGTH + ' or more characters';
            },
            // TWO-25326 §1 wording, mirrored here so a call site that reads it
            // through the mock gets the same string the real module returns.
            noResultsMessage: function () {
                return 'No matches found';
            },
            abortActiveRequest: function () { return false; },
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
            // NOT inert — company-capture.js builds the billing panel's own
            // mount selectors from it, so a mock returning undefined would
            // exercise selectors production never uses.
            SECONDARY_ADDRESS_ROOT_SELECTOR: '[data-form="billing-new-address"]'
        },
        // Inert default, same convention as the company-search mock above: a
        // constructor whose instances no-op every method. Tests that exercise
        // the real panel load company-search-panel.js and pass it via
        // extraMocks, so they control the jQuery it closes over.
        'Two_Gateway/js/model/company-search-panel': (function () {
            function CompanySearchPanelMock() {
                this._field = null;
            }
            CompanySearchPanelMock.prototype.bind = function () {};
            CompanySearchPanelMock.prototype.open = function () {};
            CompanySearchPanelMock.prototype.close = function () {};
            CompanySearchPanelMock.prototype.isOpen = function () { return false; };
            CompanySearchPanelMock.prototype.destroy = function () {};
            CompanySearchPanelMock.prototype.unmount = function () { this._field = null; };
            CompanySearchPanelMock.prototype.syncChips = function () {};
            CompanySearchPanelMock.prototype.setDisplayText = function () {};
            CompanySearchPanelMock.prototype.releaseField = function () {};
            CompanySearchPanelMock.prototype.reclaimField = function () {};
            CompanySearchPanelMock.prototype.abortActiveRequest = function () { return false; };
            CompanySearchPanelMock.prototype.isBound = function () { return false; };
            CompanySearchPanelMock.prototype.getField = function () { return this._field || {}; };
            CompanySearchPanelMock.prototype.getBindToken = function () { return null; };
            return CompanySearchPanelMock;
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
        }())
    };
}

/**
 * The computed currently evaluating, so an observable read inside it registers
 * as a dependency. Knockout's caching is the point being modelled: a `visible:`
 * binding over a live DOM read re-evaluates ONLY when an observable it read
 * changes, so a spec that calls such a function directly cannot see a missing
 * notification at all.
 */
let evaluatingComputed = null;

function makeKnockoutMock() {
    /**
     * A computed over a live read, close enough to model the caching that makes
     * a missing notification observable — and no closer. Three divergences from
     * real Knockout, all of which a spec must not lean on: the dependency set
     * only ever grows, `makeObservable` notifies on every write whether or not
     * the value changed, so a re-publish can come from an observable the
     * computed no longer reads; and there is no re-entrancy guard.
     *
     * @param {function} fn
     * @returns {function} the computed's value accessor
     */
    function computed(fn) {
        const out = makeObservable(undefined);
        const dependencies = [];
        function evaluate() {
            const outer = evaluatingComputed;
            evaluatingComputed = function (dependency) {
                if (dependencies.indexOf(dependency) !== -1) return;
                dependencies.push(dependency);
                dependency.subscribe(evaluate);
            };
            try {
                out(fn());
            } finally {
                evaluatingComputed = outer;
            }
        }
        evaluate();
        return out;
    }
    return {
        observable: makeObservable,
        observableArray: function (init) { return makeObservable(init || []); },
        pureComputed: computed,
        computed: computed,
        applyBindings: function () {},
        bindingHandlers: {}
    };
}

/** The cache key both default quote addresses answer with: billing IS shipping. */
const ONE_ADDRESS_KEY = 'one-address';

/**
 * A quote address observable of the shape `company-capture.js` reads it in: a
 * cache key it can be compared with the other address on, and a `subscribe` the
 * predicate's invalidation is wired to. A double supplying neither cannot model
 * "is billing a distinct address" at all, and throws where production asks.
 *
 * @param {object} [fields] address fields the spec itself needs
 * @param {string} [cacheKey] defaults to the key shippingAddress also answers
 * @returns {function} Knockout-shaped observable
 */
function quoteAddress(fields, cacheKey) {
    return makeObservable(quoteAddressValue(fields, cacheKey));
}

/**
 * The value inside a quoteAddress() observable, for a spec that writes a NEW
 * address into one mid-test.
 *
 * @param {object} [fields] address fields the spec itself needs
 * @param {string} [cacheKey] defaults to the key shippingAddress also answers
 * @returns {object}
 */
function quoteAddressValue(fields, cacheKey) {
    const key = cacheKey || ONE_ADDRESS_KEY;
    return Object.assign({ getCacheKey: function () { return key; } }, fields || {});
}

function makeObservable(initial) {
    let value = initial;
    const subscribers = [];
    function obs(next) {
        if (arguments.length === 0) {
            if (evaluatingComputed) evaluatingComputed(obs);
            return value;
        }
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
            filter: function () { return obj; },
            is: function () { return false; },
            parent: function () { return obj; },
            next: function () { return obj; },
            insertAfter: function () { return obj; },
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
    installAsyncSimulation($);
    return $;
}

/**
 * Magento_Ui/js/lib/view/utils/async's `$.async` decorator, simulated.
 *
 * The real one is a MutationObserver that is never disconnected, so it keeps
 * firing for the life of the page and every registration is permanent. A stub
 * that only ran the callback once made observer STACKING invisible — which is
 * how a re-bind loop that freezes checkout survived three review rounds and a
 * green suite.
 *
 * `$.async.registrations` counts live observers so a test can assert a control
 * registers one per selector, and `$.async.fireAll()` replays them the way a
 * DOM mutation would.
 *
 * @param {object} $ the jQuery (or double) to install onto
 * @returns {object} the same $
 */
function installAsyncSimulation($) {
    const registry = [];
    $.async = function (selector, cb) {
        const entry = { selector: selector, cb: cb, delivered: $(selector)[0] || null };
        registry.push(entry);
        cb(entry.delivered || $(selector));
    };
    $.async.registrations = function (selector) {
        return selector
            ? registry.filter(function (r) { return r.selector === selector; }).length
            : registry.length;
    };
    /**
     * Deliver every observer whose selector now matches a node it has not seen.
     *
     * A real observer answers a node APPEARING, so replaying a registration
     * whose node has not changed lets one watcher stand in for another and hides
     * a missing one.
     */
    $.async.fireAll = function () {
        registry.slice().forEach(function (r) {
            const node = $(r.selector)[0];
            if (!node || node === r.delivered) return;
            r.delivered = node;
            cbSafe(r.cb, node);
        });
    };
    $.async.reset = function () {
        registry.length = 0;
    };
    return $;
}

/**
 * A replayed observer whose callback throws must not stop the rest, the same
 * way one observer's exception does not disconnect its siblings.
 *
 * @param {Function} cb
 * @param {*} node
 */
function cbSafe(cb, node) {
    try {
        cb(node);
    } catch (error) {
        // Surfaced by whatever the test asserts on, not by aborting the replay.
    }
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
/**
 * Repo-relative path of the file behind a `Two_Gateway/...` AMD name, or null
 * when there is no such file.
 *
 * @param {string} name AMD dep name
 * @returns {?string}
 */
function resolveTwoGatewayModule(name) {
    const match = /^Two_Gateway\/(js\/.+)$/.exec(name);
    if (!match) return null;
    const relPath = `view/frontend/web/${match[1]}.js`;
    return fs.existsSync(path.resolve(__dirname, '..', '..', relPath)) ? relPath : null;
}

function loadAmdModule(relPath, extraMocks, extraGlobals, siblingCache) {
    // One instance of a given sibling per top-level load, the way RequireJS
    // gives one per page. Without it two siblings requiring the same third
    // module would each get their own copy, splitting module-scope state that
    // production shares — `adoptedSoleTraderIds`, `countryWatcherSeq`,
    // company-search.js's caches — and the tests would stay green while no
    // longer modelling production. Deliberately per-call and not a process-wide
    // memo: a fresh `loadAmdModule()` must still yield fresh module state,
    // which is what the specs that pin module-scope behaviour rely on.
    const siblings = siblingCache || new Map();
    const absPath = path.resolve(__dirname, '..', '..', relPath);
    const src = fs.readFileSync(absPath, 'utf8');
    const mocks = Object.assign({}, defaultMocks(), extraMocks || {});
    // A test passing REAL jQuery gets the observer simulation too: real jQuery
    // has no `$.async`, and modules that wait for a node to appear call it. A
    // suite that only meant to use real DOM should not have to know that.
    if (mocks.jquery && typeof mocks.jquery.async !== 'function') {
        installAsyncSimulation(mocks.jquery);
    }

    let captured;
    const define = function (deps, factory) {
        // Anonymous define(deps, factory) is the shape we care about.
        // Some files use define(factory) (no deps array) — handle both.
        if (typeof deps === 'function') {
            captured = deps();
        } else if (Array.isArray(deps)) {
            const resolved = deps.map(function (name) {
                if (!(name in mocks)) {
                    // A sibling Two_Gateway model with no mock entry is loaded
                    // for real, under this call's own mocks and globals. A
                    // collaborator the module under test delegates to has to
                    // see the same doubles the test set up — its own `window`,
                    // `fetch` and quote — or a spy the test installed is
                    // invisible to it. Anything with a `defaultMocks()` entry
                    // still resolves to that entry, so this only reaches
                    // modules nothing has deliberately stubbed.
                    const sibling = resolveTwoGatewayModule(name);
                    if (sibling) {
                        if (!siblings.has(sibling)) {
                            siblings.set(
                                sibling,
                                loadAmdModule(sibling, extraMocks, extraGlobals, siblings)
                            );
                        }
                        return siblings.get(sibling);
                    }
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
 * Load Luma's WIRED capture component — `company-capture.js`, the adapter that
 * hands the framework-free controller its Magento shapes — and boot nothing.
 *
 * Specs load this rather than `company-capture-component.js` because that file
 * exports a bare constructor with no host: loading it alone would prove the
 * controller works against a host the spec invented, not against the one the
 * checkout ships.
 *
 * @param {object} [extraMocks] merged over `defaultMocks()`
 * @param {object} [extraGlobals] forwarded to `loadAmdModule`
 * @returns {object} the single `CompanyCaptureComponent` instance
 */
function loadCompanyCapture(extraMocks, extraGlobals) {
    return loadAmdModule(
        'view/frontend/web/js/model/company-capture.js',
        extraMocks,
        extraGlobals
    );
}

/**
 * A `brand-config.js` double answering for exactly one active brand.
 *
 * Callable as well as carrying the two statics: the adapter resolves the active
 * code and then calls the module itself for that code's subtree.
 *
 * @param {?object} config the brand's checkout config subtree, or null for a
 *        checkout carrying no Two-family method at all
 * @param {string} [brandCode]
 * @returns {Function}
 */
function brandConfigMock(config, brandCode) {
    const code = config ? (brandCode || 'two_payment') : null;
    const brandConfig = function () {
        return config;
    };
    brandConfig.getActiveTwoBrandCode = function () {
        return code;
    };
    brandConfig.getActiveTwoBrandConfig = function () {
        return config || {};
    };
    return brandConfig;
}

/**
 * Load the REAL `company-search-panel.js` class, closed over the given jQuery
 * (usually real jQuery over a jsdom fixture) and the given `company-search.js`
 * mock/real-module.
 *
 * Every test that wants to observe the actual popover — the panel DOM it
 * builds, its open/close, the query field, the result rows, the chips inside
 * it — has to load this for real: the harness's default is an inert no-op
 * stub, same convention as the `company-search` default, and proves nothing
 * about any of that.
 *
 * @param {object} $ jQuery (real or a test double)
 * @param {object} [companySearchMock] `company-search.js` module/mock —
 *        defaults to the harness's own inert mock, same as any other dep.
 * @param {object} [extraGlobals] forwarded to `loadAmdModule` — pass
 *        `{ document: document, window: window }` for any test that builds the
 *        panel against the REAL jsdom document.
 * @returns {Function} the CompanySearchPanel constructor
 */
function loadCompanySearchPanel($, companySearchMock, extraGlobals) {
    const CompanySearchPanel = loadAmdModule(
        'view/frontend/web/js/model/company-search-panel.js',
        {},
        extraGlobals
    );
    const search = companySearchMock
        || defaultMocks()['Two_Gateway/js/model/company-search'];

    /**
     * The panel takes its platform from the host, and Magento's host is
     * `company-capture-component.js`. Applying the same three here is what
     * keeps every existing construction site — `new CompanySearchPanel({
     * fieldSelector, config, ... })` — meaning what it meant when the panel
     * resolved them through RequireJS itself.
     *
     * @param {object} options
     */
    function MagentoHostedPanel(options) {
        CompanySearchPanel.call(this, Object.assign(
            {
                search: search,
                // Resolved per call, not captured: `installAsyncSimulation()`
                // replaces `$.async` after this loader has already run.
                observe: function (fieldSelector, onNode) {
                    if (typeof $.async === 'function') $.async(fieldSelector, onNode);
                }
            },
            options || {}
        ));
    }
    MagentoHostedPanel.prototype = CompanySearchPanel.prototype;
    Object.assign(MagentoHostedPanel, CompanySearchPanel);
    return MagentoHostedPanel;
}

/**
 * Dispatch a real DOM event at a node, setting an input's value first when one
 * is given.
 *
 * jQuery's `.trigger()` cannot stand in for this. It walks jQuery's own handler
 * store and then calls `elem[type]()` — which exists for `click` and `focus`,
 * and does NOT for `input` or `mousedown`. The panel binds with
 * `addEventListener`, so a jQuery trigger for either of those two reaches
 * nothing it bound and the test asserts against a control that was never told.
 *
 * @param {Element} node
 * @param {string} type
 * @param {string} [value] written to `node.value` before the event
 */
function dispatchNative(node, type, value) {
    if (typeof value === 'string') node.value = value;
    const view = node.ownerDocument.defaultView;
    const Ctor = type === 'mousedown' ? view.MouseEvent : view.Event;
    node.dispatchEvent(new Ctor(type, { bubbles: true, cancelable: true }));
}

/** Proxy-route responses arrive as an `{ok, status, body}` envelope. */
function isProxyRoute(url) {
    return typeof url === 'string' && url.indexOf('rest/V1/two/') !== -1;
}

/** The envelope JSON-encoded inside the array Magento wraps a `: string` return in. */
function proxyEnvelope(body, options) {
    const opts = options || {};
    return [JSON.stringify({
        ok: opts.ok !== false,
        status: opts.status || 200,
        body: body
    })];
}

/**
 * Pair a value with its row's description. Jest IGNORES a second argument to
 * `toBe()`/`toEqual()`, so a row description only ever reaches a failure diff
 * by being part of the compared value.
 *
 * @param {string} description
 * @param {*} value
 * @returns {Array}
 */
function tagged(description, value) {
    return [description, value];
}

module.exports = {
    tagged: tagged,
    quoteAddress: quoteAddress,
    quoteAddressValue: quoteAddressValue,
    makeObservable: makeObservable,
    dispatchNative: dispatchNative,
    isProxyRoute: isProxyRoute,
    HARNESS_BASE_URL: HARNESS_BASE_URL,
    proxyEnvelope: proxyEnvelope,
    loadAmdModule: loadAmdModule,
    defaultMocks: defaultMocks,
    loadCompanySearchPanel: loadCompanySearchPanel,
    loadCompanyCapture: loadCompanyCapture,
    brandConfigMock: brandConfigMock,
    installAsyncSimulation: installAsyncSimulation
};
