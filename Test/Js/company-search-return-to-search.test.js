/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Returning from manual company entry to registered-company search.
 *
 * Returning used to only re-bind select2, leaving the buyer looking at a
 * CLOSED picker they had to click a second time before they could type. This
 * pins that the return opens the dropdown and lands the caret in the search
 * box.
 *
 * Mutation-resistance notes, because this repo's AMD harness makes vacuous
 * assertions easy to write:
 *
 *  - The assertions are on the REAL jsdom DOM, not on call records:
 *    `document.activeElement` and the presence of an open container /
 *    search input. `toHaveBeenCalledWith` on a select2 spy would pass for an
 *    implementation that opened a dropdown nothing could type into.
 *  - The select2 double is DOM-backed: `select2('open')` really attaches a
 *    dropdown containing a real `<input class="select2-search__field">` and
 *    really marks the container open, so the production
 *    `document.querySelector('.select2-search__field').focus()` has to run
 *    against a live node for the focus assertion to pass.
 *  - `select2('open')` on a destroyed/absent widget THROWS in the double, as
 *    it does in select2 4.1. Opening before the re-bind cannot pass.
 *  - Every case first drives the real journey (mount → manual entry → return)
 *    and asserts the pre-return state is closed and unfocused. A mount that
 *    never bound the picker would fail there rather than presenting as green.
 *  - Two negative pins guard the obvious over-reach: the FIRST bind (initial
 *    checkout render) must not open anything, and a later `$.async` re-fire
 *    must not re-open it under a buyer who has moved on.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks, loadCompanySearchControl } = require('./amd-harness');

const COMPONENT_PATH = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY_PATH = 'view/frontend/web/js/model/company-identity.js';

const GLOBALS = { document: document, window: window };
const FIELD_SELECTOR = '#two_gateway_form input#company_name';
const RETURN_LINK = '.search_for_company';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true,
    supportedCompanyTypes: {}
};

/**
 * A select2 stand-in on the real jQuery. Faithful on the three points these
 * cases turn on: a container that carries the open marker class, a dropdown
 * holding a real search input attached BEFORE `select2:open` fires (which is
 * why the production focus call can find it), and a method call on an unbound
 * element that throws rather than no-opping.
 */
function installSelect2Double() {
    $.fn.select2 = function (arg) {
        return this.each(function () {
            const $node = $(this);
            const instance = $node.data('select2');

            if (typeof arg === 'object' && arg !== null) {
                // select2 4.1's constructor destroys any existing instance on
                // the same node, so re-init is not additive.
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
                    '<span class="select2-results"><ul class="select2-results__options"></ul></span>' +
                    '</span>'
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
                if (!instance) throw new Error('select2: no instance bound to this element');
                if (instance.open) return;
                instance.open = true;
                $('body').append(instance.$dropdown);
                instance.$container.addClass('select2-container--open');
                // Only now, with the dropdown live, does select2 relay the event.
                $node.trigger('select2:open');
                return;
            }
            if (arg === 'close') {
                if (!instance || !instance.open) return;
                instance.open = false;
                instance.$dropdown.detach();
                instance.$container.removeClass('select2-container--open');
                $node.trigger('select2:close');
                return;
            }
            throw new Error('select2 double: unsupported command ' + arg);
        });
    };
}

/** Magento's `$.async` decorator, recording every registration. */
function installAsync() {
    $.asyncCalls = [];
    $.async = function (selector, fn) {
        $.asyncCalls.push({ selector: selector, fn: fn });
        const node = document.querySelector(selector);
        if (node) fn(node);
    };
}

/**
 * Boot the page-level component over the fixture with the real control.
 *
 * Loaded fresh per test: the component and the identity are page-level
 * singletons, so a shared load would carry one case's bind into the next.
 *
 * @returns {object} `{ component, identity, control }`
 */
function mount() {
    const identity = loadAmdModule(IDENTITY_PATH, {}, GLOBALS);
    const SoleTraderStub = function () {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.launchSignup = function () { return {}; };
        this.forgetAdoptions = function () {};
    };
    const companySearch = Object.assign(
        {},
        defaultMocks()['Two_Gateway/js/model/company-search'],
        { currentAddressFormCountry: function () { return 'gb'; } }
    );

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
    return { component: component, identity: identity, control: component._control };
}

function openMarkerPresent() {
    return document.querySelectorAll('.select2-container--open').length > 0;
}

function focusedSearchField() {
    const active = document.activeElement;
    if (!active || !active.classList) return null;
    return active.classList.contains('select2-search__field') ? active : null;
}

function boundWidget() {
    return $(FIELD_SELECTOR).data('select2');
}

/**
 * Drive the real journey up to the point of returning to search mode, and
 * assert the picker is genuinely closed and unfocused first.
 *
 * @param {object} mounted the result of `mount()`
 */
function reachManualMode(mounted) {
    // Guard: without a bound widget nothing below could be meaningful.
    expect(boundWidget()).toBeTruthy();

    // Open once, so whatever the bind wired on `select2:open` is live.
    $(FIELD_SELECTOR).select2('open');

    mounted.component.manualEntryMode();

    // Manual mode: widget gone, so nothing open and nothing focused.
    expect(mounted.identity.captureMode()).toBe('manual');
    expect(boundWidget()).toBeUndefined();
    expect(openMarkerPresent()).toBe(false);
    expect(focusedSearchField()).toBeNull();
}

let mounted;

beforeEach(() => {
    document.body.innerHTML =
        '<form id="two_gateway_form"><div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" />' +
        '</div></div></form>';
    $(document).off('.twoCompanyCapture');
    installSelect2Double();
    installAsync();
    mounted = mount();
});

describe('returning to registered-company search', () => {
    test('the picker re-opens with the caret in its search input', () => {
        reachManualMode(mounted);

        mounted.component.registeredMode({ openDropdown: true });

        expect(mounted.identity.captureMode()).toBe('registered');
        // (a) the dropdown is really open …
        expect(openMarkerPresent()).toBe(true);
        expect(document.querySelectorAll('.select2-search__field')).toHaveLength(1);
        // … and (b) the caret is really in its text input.
        expect(focusedSearchField()).not.toBeNull();
        expect(document.activeElement).toBe(document.querySelector('.select2-search__field'));
    });

    test('the widget is re-bound before it is opened', () => {
        reachManualMode(mounted);

        // The double throws on `open` without an instance, so reaching an open
        // dropdown at all proves the re-bind happened first.
        expect(function () {
            mounted.component.registeredMode({ openDropdown: true });
        }).not.toThrow();
        expect(boundWidget()).toBeTruthy();
    });

    test('the initial mount does not open the picker or steal focus', () => {
        expect(boundWidget()).toBeTruthy();
        expect(openMarkerPresent()).toBe(false);
        expect(focusedSearchField()).toBeNull();
    });

    test('a later re-render does not re-open the dropdown behind the buyer', () => {
        reachManualMode(mounted);
        mounted.component.registeredMode({ openDropdown: true });
        expect(openMarkerPresent()).toBe(true);

        // Close it the way a buyer would, then let `$.async` fire again — it is
        // a MutationObserver and a one-page checkout re-renders often.
        $(FIELD_SELECTOR).select2('destroy');
        expect(openMarkerPresent()).toBe(false);

        const fires = $.asyncCalls.filter(function (call) {
            return call.selector === FIELD_SELECTOR;
        });
        expect(fires.length).toBeGreaterThan(0);
        fires[fires.length - 1].fn(document.querySelector(FIELD_SELECTOR));

        // Re-bound, but NOT re-opened: the open request was one-shot.
        expect(boundWidget()).toBeTruthy();
        expect(openMarkerPresent()).toBe(false);
        expect(focusedSearchField()).toBeNull();
    });
});

/*
 * The control's own "Search for company" return link. It is built and wired on
 * every bind but starts hidden, so these cases reveal it through the control's
 * own API before driving it.
 *
 * Keyboard reachability is the subject: the link is a bare `<div>` with a click
 * handler, no native semantics a keyboard user could exploit. `role="button"` +
 * `tabindex="0"` make it focus-reachable; the Enter/Space keydown handler makes
 * it activatable once focused. Both are load-bearing on their own — a
 * focusable-but-inert div is as much a trap as a native-looking one Tab skips.
 */
describe('the return link the control owns', () => {
    function revealLink() {
        reachManualMode(mounted);
        mounted.control.showSearchForCompanyLink();
        const $link = $(RETURN_LINK);
        expect($link.length).toBe(1);
        expect($link.get(0).style.display).not.toBe('none');
        return $link;
    }

    test('it carries role="button" and tabindex="0"', () => {
        const $link = revealLink();

        expect($link.attr('role')).toBe('button');
        expect($link.attr('tabindex')).toBe('0');
    });

    test('clicking it opens the picker, focuses the search input and retires itself', () => {
        const $link = revealLink();

        $link.trigger('click');

        expect(openMarkerPresent()).toBe(true);
        expect(focusedSearchField()).not.toBeNull();
        expect($link.get(0).style.display).toBe('none');
    });

    test.each([
        ['Enter', 13, true, 'the documented activation key'],
        [' ', 32, true, 'the other one role="button" implies'],
        ['a', 65, false, 'plain text entry, which must fall through']
    ])('key %p (which %p) activates the link: %p — %s', (key, which, activates) => {
        const $link = revealLink();

        $link.trigger($.Event('keydown', { key: key, which: which }));

        expect(openMarkerPresent()).toBe(activates);
        expect($link.get(0).style.display === 'none').toBe(activates);
    });

    /*
     * This is `role="button"` on a plain div, not a native `<button>`, and some
     * assistive-tech/browser combinations forward a synthetic `click` in
     * addition to the Enter keydown for exactly that shape of widget. Without a
     * guard, that would re-open a dropdown the buyer already opened.
     */
    test('a synthetic click right behind the Enter keydown does not re-activate', () => {
        const $link = revealLink();
        const control = mounted.control;
        control.bind = jest.fn(control.bind.bind(control));

        $link.trigger($.Event('keydown', { key: 'Enter', which: 13 }));
        expect(control.bind).toHaveBeenCalledTimes(1);

        $link.trigger('click');

        expect(control.bind).toHaveBeenCalledTimes(1);
    });
});
