/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Proves that the searching state actually reaches visible spinner
 * markup at the mount, and leaves again when the request settles.
 *
 * The spinner is driven through a shared model, so it is easy to assume "the
 * model is tested, therefore the mount works". That does not follow: the mount
 * wires its own `onSearching` callback and stamps its own bind token, and a
 * mount whose wiring is present but not connected to markup would show no
 * spinner at all while every model-level test stayed green. The default AMD
 * harness stubs `setSearching` to a no-op, so no other suite covers this path.
 *
 * Method: mount the REAL component onto real jsdom nodes with the REAL control
 * and the REAL model, then drive the select2 `transport` the control built —
 * which is what fires `onSearching(true)` internally. The spinner is then read
 * off the document, not off a call log: a mount that reported "searching" to
 * nothing would fail here.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchControl } = require('./amd-harness');

const COMPONENT_PATH = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY_PATH = 'view/frontend/web/js/model/company-identity.js';
const SEARCH_PATH = 'view/frontend/web/js/model/company-search.js';

const GLOBALS = { document: document, window: window };
const SPINNER_SELECTOR = '.two-company-search__spinner';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true,
    supportedCompanyTypes: {}
};

/**
 * `$.ajax` replaced with jqXHRs the test settles by hand, so each outcome is
 * driven explicitly rather than inferred from a timer.
 *
 * @returns {Array} the requests handed out, newest last
 */
function installAjaxDouble() {
    const requests = [];
    $.ajax = function (options) {
        const bound = { done: [], fail: [], always: [] };
        const jqxhr = {
            options: options,
            aborted: false,
            done: function (fn) { bound.done.push(fn); return jqxhr; },
            fail: function (fn) { bound.fail.push(fn); return jqxhr; },
            always: function (fn) { bound.always.push(fn); return jqxhr; },
            abort: function () {
                jqxhr.aborted = true;
                jqxhr.settleFail('abort');
            },
            settleDone: function (data) {
                bound.done.forEach(function (fn) { fn(data); });
                bound.always.forEach(function (fn) { fn(); });
            },
            settleFail: function (textStatus) {
                bound.fail.forEach(function (fn) {
                    fn({ status: textStatus === 'timeout' ? 0 : 500 }, textStatus);
                });
                bound.always.forEach(function (fn) { fn(); });
            }
        };
        requests.push(jqxhr);
        return jqxhr;
    };
    return requests;
}

/**
 * A select2 stand-in on the real jQuery, faithful on the points this suite
 * turns on: the instance is discoverable via `.data('select2')`, its
 * `$dropdown` holds a real search box the chrome can be written into, and
 * re-init destroys the previous instance the way select2 4.1's constructor
 * does.
 */
function installSelect2Double() {
    $.fn.select2 = function (arg) {
        return this.each(function () {
            const $node = $(this);
            const instance = $node.data('select2');

            if (typeof arg === 'object' && arg !== null) {
                if (instance) $node.select2('destroy');
                const $container = $(
                    '<span class="select2 select2-container">' +
                    '<span class="select2-selection" role="combobox" tabindex="0"></span>' +
                    '</span>'
                );
                const $dropdown = $(
                    '<span class="select2-dropdown">' +
                    '<span class="select2-search select2-search--dropdown">' +
                    '<input class="select2-search__field" type="search">' +
                    '</span>' +
                    '<span class="select2-results">' +
                    '<ul class="select2-results__options" role="listbox"></ul>' +
                    '</span></span>'
                );
                $node.after($container);
                $node.data('select2', {
                    options: arg,
                    $container: $container,
                    $dropdown: $dropdown,
                    $selection: $container.find('.select2-selection'),
                    dataAdapter: { _queryTimeout: null }
                });
                return;
            }
            if (arg === 'destroy') {
                if (!instance) return;
                instance.$dropdown.remove();
                instance.$container.remove();
                $node.removeData('select2');
                $node.off('.select2');
                return;
            }
            if (arg === 'open') {
                // Matches select2 4.1: a method call on an unbound element is
                // an error, not a silent no-op.
                if (!instance) throw new Error('select2: no instance bound to this element');
                $('body').append(instance.$dropdown);
                instance.$container.addClass('select2-container--open');
                $node.trigger('select2:open');
                return;
            }
            if (arg === 'close') {
                if (!instance) return;
                instance.$dropdown.detach();
                instance.$container.removeClass('select2-container--open');
                $node.trigger('select2:close');
                return;
            }
            throw new Error('select2 double: unsupported command ' + arg);
        });
    };
}

/** Magento's `$.async` decorator, resolving synchronously against the fixture. */
function installAsync() {
    $.async = function (selector, fn) {
        $.asyncCalls.push({ selector: selector, fn: fn });
        const node = document.querySelector(selector);
        if (node) fn(node);
    };
    $.asyncCalls = [];
}

/**
 * Boot the page-level component over the fixture, with the real control and
 * the real model.
 *
 * Loaded fresh per test: the component and the identity are page-level
 * singletons, so a shared load would carry one case's bind into the next.
 *
 * @returns {object} `{ component, companySearch }`
 */
function mount() {
    const identity = loadAmdModule(IDENTITY_PATH, {}, GLOBALS);
    const companySearch = loadAmdModule(SEARCH_PATH, { jquery: $ }, GLOBALS);
    const SoleTraderStub = function () {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.launchSignup = function () { return {}; };
        this.forgetAdoptions = function () {};
    };

    const component = loadAmdModule(
        COMPONENT_PATH,
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-control': loadCompanySearchControl(
                $,
                companySearch,
                GLOBALS
            ),
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandConfig: function () { return BASE_CONFIG; }
            }
        },
        GLOBALS
    );
    component.start();
    return { component: component, companySearch: companySearch };
}

/** The ajax block the live bind handed select2. */
function boundTransport(component) {
    const instance = component._control.getField().data('select2');
    expect(instance).toBeTruthy();
    return instance.options.ajax.transport;
}

function spinners() {
    return document.querySelectorAll(SPINNER_SELECTOR);
}

/** Start a real search through the transport the live bind built. */
function startSearch(component, requests) {
    const handle = boundTransport(component)(
        { url: 'https://api.example.test/companies/v2/company?q=exa' },
        function () {},
        function () {}
    );
    expect(handle).toBeTruthy();
    expect(requests).toHaveLength(1);
    return requests[0];
}

describe('the searching state reaches visible spinner markup', () => {
    let requests;
    let mounted;

    beforeEach(() => {
        document.body.innerHTML =
            '<form id="two_gateway_form"><div class="field"><div class="control">' +
            '<input id="company_name" name="company_name" />' +
            '</div></div></form>';
        $(document).off('.twoCompanyCapture');
        installSelect2Double();
        installAsync();
        requests = installAjaxDouble();
        mounted = mount();
        mounted.companySearch.clearResultCache();
        $(mounted.component._control.getField()).select2('open');
    });

    test('nothing is painted until a search actually starts', () => {
        expect(spinners()).toHaveLength(0);
    });

    test('a search in flight paints a childless, aria-hidden spinner into the search box', () => {
        startSearch(mounted.component, requests);

        expect(spinners()).toHaveLength(1);
        const spinner = spinners()[0];
        // The animation is a CSS background-image, so there is no inner markup
        // for a translation or a sanitiser to mangle.
        expect(spinner.children).toHaveLength(0);
        expect(spinner.getAttribute('aria-hidden')).toBe('true');
        expect(spinner.closest('.select2-search--dropdown')).not.toBeNull();
    });

    test.each([
        ['done', 'a healthy response'],
        ['timeout', 'a timeout'],
        ['error', 'a network failure']
    ])('outcome %p settles the spinner (%s)', (outcome) => {
        const request = startSearch(mounted.component, requests);
        expect(spinners()).toHaveLength(1);

        if (outcome === 'done') {
            request.settleDone({ items: [] });
        } else {
            request.settleFail(outcome);
        }

        expect(spinners()).toHaveLength(0);
    });
});
