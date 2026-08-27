/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Returning from manual company entry to registered-company search.
 *
 * Returning used to only re-bind the picker, leaving the buyer looking at a
 * CLOSED control they had to click a second time before they could type. This
 * pins that the return opens the panel and lands the caret in its query field.
 *
 * Mutation-resistance notes, because this repo's AMD harness makes vacuous
 * assertions easy to write:
 *
 *  - The assertions are on the REAL jsdom DOM, not on call records:
 *    `document.activeElement` and the panel's own `hidden` attribute. A spy on
 *    `open()` would pass for an implementation that opened a panel nothing
 *    could type into.
 *  - The REAL panel class is loaded, so the query field the caret has to land
 *    in is one production actually built.
 *  - Every case first drives the real journey (mount -> manual entry -> return)
 *    and asserts the pre-return state is closed and unfocused. A mount that
 *    never built the panel would fail there rather than presenting as green.
 *  - Two negative pins guard the obvious over-reach: the FIRST bind (initial
 *    checkout render) must not open anything, and a later `$.async` re-fire
 *    must not re-open it under a buyer who has moved on.
 */

'use strict';

const $ = require('jquery');
const {
    loadAmdModule,
    defaultMocks,
    loadCompanySearchPanel,
    installAsyncSimulation, dispatchNative } = require('./amd-harness');

const COMPONENT_PATH = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY_PATH = 'view/frontend/web/js/model/company-identity.js';

const GLOBALS = { document: document, window: window };
const FIELD_SELECTOR = '#two_gateway_form input#company_name';
const PANEL = '.two-company-dropdown';
const QUERY = '.two-company-dropdown__query';
const CHIP = '.two-company-mode-chip';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true,
    supportedCompanyTypes: {}
};

/**
 * Boot the page-level component over the fixture with the real panel.
 *
 * Loaded fresh per test: the component and the identity are page-level
 * singletons, so a shared load would carry one case's bind into the next.
 *
 * @returns {object} `{ component, identity }`
 */
function mount() {
    const identity = loadAmdModule(IDENTITY_PATH, {}, GLOBALS);
    const SoleTraderStub = function () {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.focusSignupPopup = function () { return false; };
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
            'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel(
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
    return { component: component, identity: identity };
}

function panelIsOpen() {
    const panel = document.querySelector(PANEL);
    return !!panel && !panel.hasAttribute('hidden');
}

function focusedQueryField() {
    const active = document.activeElement;
    if (!active || !active.classList) return null;
    return active.classList.contains('two-company-dropdown__query') ? active : null;
}

/**
 * Drive the real journey up to the point of returning to search mode, and
 * assert the panel is genuinely closed and unfocused first.
 *
 * @param {object} mounted the result of `mount()`
 */
function reachManualMode(mounted) {
    // Guard: without a built panel nothing below could be meaningful.
    expect(document.querySelectorAll(PANEL)).toHaveLength(1);

    mounted.component.manualEntryMode();

    expect(mounted.identity.captureMode()).toBe('manual');
    expect(panelIsOpen()).toBe(false);
    expect(focusedQueryField()).toBeNull();
}

let mounted;

beforeEach(() => {
    document.body.innerHTML =
        '<form id="two_gateway_form"><div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" />' +
        '</div></div></form>';
    $(document).off('.twoCompanyCapture');
    installAsyncSimulation($);
    $.async.reset();
    mounted = mount();
});

describe('returning to registered-company search', () => {
    test('the panel re-opens with the caret in its query field', () => {
        reachManualMode(mounted);

        mounted.component.registeredMode({ openDropdown: true });

        expect(mounted.identity.captureMode()).toBe('registered');
        expect(panelIsOpen()).toBe(true);
        expect(document.querySelectorAll(QUERY)).toHaveLength(1);
        expect(document.activeElement).toBe(document.querySelector(QUERY));
    });

    test('the field is a trigger again, not the plain input manual entry left', () => {
        reachManualMode(mounted);
        mounted.component.registeredMode({ openDropdown: true });
        mounted.component._panel.close();

        dispatchNative($(FIELD_SELECTOR)[0], 'mousedown');

        expect(panelIsOpen()).toBe(true);
    });

    test('a return with no open request still hands the field back as a trigger', () => {
        // Without `openDropdown` nothing re-binds the mount — the panel is
        // already anchored where it belongs — so the reclaim is the only route
        // from the plain input manual entry left back to a working search.
        reachManualMode(mounted);

        mounted.component.registeredMode();

        dispatchNative($(FIELD_SELECTOR)[0], 'mousedown');
        expect(panelIsOpen()).toBe(true);
    });

    test('the initial mount does not open the panel or steal focus', () => {
        expect(document.querySelectorAll(PANEL)).toHaveLength(1);
        expect(panelIsOpen()).toBe(false);
        expect(focusedQueryField()).toBeNull();
    });

    test('a later re-render does not re-open the panel behind the buyer', () => {
        reachManualMode(mounted);
        mounted.component.registeredMode({ openDropdown: true });
        expect(panelIsOpen()).toBe(true);

        // Closed the way a buyer would, then `$.async` fires again — it is a
        // MutationObserver and a one-page checkout re-renders often.
        mounted.component._panel.close();
        document.querySelector('.control').innerHTML =
            '<input id="company_name" name="company_name" />';
        $.async.fireAll();

        // Re-bound, but NOT re-opened: the open request was one-shot.
        expect(document.querySelectorAll(PANEL)).toHaveLength(1);
        expect(panelIsOpen()).toBe(false);
        expect(focusedQueryField()).toBeNull();
    });
});

/*
 * The chips replaced the "Search for company" return link: they are the only
 * route between modes, and they live INSIDE the panel rather than beside the
 * field. That containment is the whole point of TWO-25503 — a chip that is a
 * sibling of the field is one the open panel draws over, hiding the routes
 * precisely when the buyer opens the thing that offers them.
 */
describe('the route back to search is a chip inside the panel', () => {
    test('every chip is a descendant of the panel, not a sibling of the field', () => {
        const chips = document.querySelectorAll(CHIP);

        expect(chips.length).toBeGreaterThan(0);
        chips.forEach((chip) => {
            expect(chip.closest(PANEL)).not.toBeNull();
        });
    });

    test('clicking the registered chip opens the panel and focuses the query field', () => {
        reachManualMode(mounted);

        $(`${CHIP}[data-two-chip="registered"]`).trigger('click');

        expect(mounted.identity.captureMode()).toBe('registered');
        expect(panelIsOpen()).toBe(true);
        expect(document.activeElement).toBe(document.querySelector(QUERY));
    });

    test('the registered chip focuses the query field from sole-trader mode too', () => {
        // The popover stays OPEN behind the signup popup, and sole-trader mode
        // hides the query row — so this arrives with the panel already up and
        // nothing focusable in it, the state an early-returning open() left
        // with the caret nowhere.
        dispatchNative($('#company_name')[0], 'mousedown');
        mounted.component.soleTraderMode();
        // A browser blurs an input the moment the row holding it is hidden;
        // jsdom applies no stylesheet, so the blur is staged by hand here.
        document.querySelector(QUERY).blur();
        expect(panelIsOpen()).toBe(true);
        expect(document.querySelector(`${PANEL} .two-company-dropdown__search`)
            .classList.contains('two-hidden')).toBe(true);
        expect(focusedQueryField()).toBeNull();

        $(`${CHIP}[data-two-chip="registered"]`).trigger('click');

        expect(mounted.identity.captureMode()).toBe('registered');
        expect(document.activeElement).toBe(document.querySelector(QUERY));
    });

    test('the chip for the mode in play reads as selected', () => {
        mounted.component.manualEntryMode();

        const selected = document.querySelectorAll(`${CHIP}--selected`);

        expect(selected).toHaveLength(1);
        expect(selected[0].getAttribute('data-two-chip')).toBe('manual');
        expect(selected[0].getAttribute('aria-pressed')).toBe('true');
    });
});
