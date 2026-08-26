/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — the three company-capture options as peers in ONE control, owned
 * by the company-capture component.
 *
 * The chips are DOM, built by the component as a sibling of the field they act
 * on, the way WooCommerce (`syncSoleTraderChip`/`syncManualEntryButton`) and
 * PrestaShop (`renderChipSelection`) both build theirs. They are asserted here
 * against the real nodes the component produces, not against template markup:
 * a payment-tile template is destroyed on every totals change, which is the
 * whole reason chip ownership moved.
 *
 * Mutation-resistance notes:
 *
 *  - selected state is read off the real class after a real mode transition,
 *    so hardcoding a selection on one chip fails;
 *  - each chip's gate is asserted by the node's own hidden state under both
 *    values of its gate, so making sole trader offerable in a country whose
 *    registry has none fails rather than reading as green;
 *  - the click assertions drive the real handler and read the resulting mode,
 *    never that a method exists. Emptying `manualEntryMode()` fails them.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const COMPONENT = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const LAYOUT = 'view/frontend/layout/checkout_index_index.xml';

const CHIP_SELECTOR = '.two-company-mode-chip';
const SELECTED_CLASS = 'two-company-mode-chip--selected';
const HIDDEN_CLASS = 'two-hidden';

/**
 * Load the component and its identity singleton together, against the real
 * jsdom document.
 *
 * Loaded fresh per test on purpose: both modules are page-level singletons, so
 * a shared load would carry one case's captured company into the next.
 *
 * @param {object} [options] `{ isCompanySearchEnabled }` on the brand config
 * @returns {object} `{ component, identity, control, soleTrader }`
 */
function load(options) {
    const opts = options || {};
    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });
    const control = { binds: [], destroys: 0, aborts: 0 };
    const soleTrader = { launches: [], ensured: 0 };

    const ControlStub = function () {
        this.bind = function (bindOptions) { control.binds.push(bindOptions || {}); };
        this.destroy = function () { control.destroys += 1; return true; };
        this.abortActiveRequest = function () { control.aborts += 1; };
        this.isBound = function () { return control.binds.length > 0; };
        this.getField = function () { return $(); };
    };
    const SoleTraderStub = function () {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { soleTrader.ensured += 1; return Promise.resolve(true); };
        this.launchSignup = function (o) { soleTrader.launches.push(o || null); return {}; };
        this.forgetAdoptions = function () {};
    };

    const component = loadAmdModule(
        COMPONENT,
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search-control': ControlStub,
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandConfig: function () {
                    return {
                        isCompanySearchEnabled: opts.isCompanySearchEnabled !== false,
                        checkoutApiUrl: 'https://api.example',
                        checkoutPageUrl: 'https://checkout.example',
                        supportedCompanyTypes: {}
                    };
                }
            },
            'Two_Gateway/js/model/company-search': Object.assign(
                {},
                defaultMocks()['Two_Gateway/js/model/company-search'],
                {
                    currentAddressFormCountry: function () { return 'gb'; },
                    revertAutofilledAddress: function () {},
                    billingRoleFormRoot: function () { return null; },
                    applyAddress: function () {},
                    lookupCompanyAddress: function () {}
                }
            )
        },
        { document: document, window: window }
    );
    return {
        component: component,
        identity: identity,
        control: control,
        soleTrader: soleTrader
    };
}

/** A field for the chips to anchor beside, in the payment tile's shape. */
function mountTileField() {
    document.body.innerHTML =
        '<form id="two_gateway_form">' +
        '<div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" />' +
        '</div></div></form>';
}

function chips() {
    return Array.from(document.querySelectorAll(CHIP_SELECTOR));
}

function chip(mode) {
    return document.querySelector(`${CHIP_SELECTOR}[data-two-chip="${mode}"]`);
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('the company-capture component owns one mode control', () => {
    test('there is exactly one chips group, holding three chips in sibling order', () => {
        mountTileField();
        const { component } = load();
        component.start();

        expect(document.querySelectorAll('.two-company-mode-chips')).toHaveLength(1);
        expect(chips().map((node) => node.getAttribute('data-two-chip'))).toEqual([
            'registered',
            'soletrader',
            'manual'
        ]);
    });

    test('the chips land as a sibling of the field they act on, never inside it', () => {
        mountTileField();
        const { component } = load();
        component.start();

        const group = document.querySelector('.two-company-mode-chips');
        const fieldWrapper = document.querySelector('#two_gateway_form .field');
        expect(group.previousElementSibling).toBe(fieldWrapper);
        expect(fieldWrapper.contains(group)).toBe(false);
    });

    test('a second start() does not build a second group', () => {
        mountTileField();
        const { component } = load();
        component.start();
        component.start();

        expect(document.querySelectorAll('.two-company-mode-chips')).toHaveLength(1);
    });

    test('re-syncing moves the one group rather than building another', () => {
        mountTileField();
        const { component } = load();
        component.start();
        component.refreshMount();
        component.syncChips();

        expect(document.querySelectorAll('.two-company-mode-chips')).toHaveLength(1);
        expect(chips()).toHaveLength(3);
    });
});

describe('selected state tracks captureMode, never a static choice', () => {
    test.each([
        ['registered', 'registered company'],
        ['soletrader', 'sole trader'],
        ['manual', 'manual entry']
    ])('only the %s chip carries the selected class in %s mode', (mode) => {
        mountTileField();
        const { component, identity } = load();
        component.start();

        identity.captureMode(mode);
        component.syncChips();

        const selected = chips()
            .filter((node) => node.classList.contains(SELECTED_CLASS))
            .map((node) => node.getAttribute('data-two-chip'));
        expect(selected).toEqual([mode]);
    });
});

describe('each chip carries its own gate', () => {
    test.each([
        [true, false, 'the registry offers sole traders'],
        [false, true, 'the registry offers none']
    ])('soleTraderAvailable=%p -> sole-trader chip hidden=%p (%s)', (available, hidden) => {
        mountTileField();
        const { component, identity } = load();
        component.start();

        identity.soleTraderAvailable(available);
        component.syncChips();

        expect(chip('soletrader').classList.contains(HIDDEN_CLASS)).toBe(hidden);
    });

    test.each([
        [true, false, 'company search is offered in address entry, so a number can still be captured'],
        [false, true, 'no address-step lookup exists, so a typed name is a dead end']
    ])('isCompanySearchEnabled=%p -> manual chip hidden=%p (%s)', (enabled, hidden) => {
        mountTileField();
        const { component } = load({ isCompanySearchEnabled: enabled });
        component.start();
        component.syncChips();

        expect(chip('manual').classList.contains(HIDDEN_CLASS)).toBe(hidden);
    });

    test('the registered chip is never gated — it is the way out of the other two', () => {
        mountTileField();
        const { component, identity } = load({ isCompanySearchEnabled: false });
        component.start();
        identity.soleTraderAvailable(false);
        component.syncChips();

        expect(chip('registered').classList.contains(HIDDEN_CLASS)).toBe(false);
    });
});

describe('clicking a chip performs the real transition', () => {
    test('the manual chip abandons the number and leaves a typeable field', () => {
        mountTileField();
        const { component, identity, control } = load();
        component.start();
        identity.write({ companyName: 'Example Ltd', companyId: '12345678' });

        chip('manual').click();

        expect(identity.captureMode()).toBe('manual');
        expect(identity.companyId()).toBe('');
        expect(control.destroys).toBeGreaterThan(0);
        expect(control.aborts).toBeGreaterThan(0);
        expect(document.querySelector('#company_name').getAttribute('type')).toBe('text');
    });

    test('the registered chip returns to search and opens the picker', () => {
        mountTileField();
        const { component, identity, control } = load();
        component.start();
        identity.captureMode('manual');

        chip('registered').click();

        expect(identity.captureMode()).toBe('registered');
        expect(control.binds.some((options) => options.openDropdown === true)).toBe(true);
    });

    test('the sole-trader chip enters the mode and launches signup', () => {
        mountTileField();
        const { component, identity, soleTrader } = load();
        component.start();

        chip('soletrader').click();

        expect(identity.captureMode()).toBe('soletrader');
        expect(soleTrader.launches).toHaveLength(1);
    });

    test('re-clicking the sole-trader chip once adopted asks for a replacement', () => {
        mountTileField();
        const { component, identity, soleTrader } = load();
        component.start();
        identity.captureMode('soletrader');
        identity.soleTraderAdopted(true);

        chip('soletrader').click();

        expect(soleTrader.launches).toEqual([{ autoselect: false }]);
    });

    test.each([
        [13, 'Enter'],
        [32, 'Space']
    ])('%s activates a chip from the keyboard (%s)', (which) => {
        mountTileField();
        const { component, identity } = load();
        component.start();

        const event = $.Event('keydown', { which: which });
        $(chip('manual')).trigger(event);

        expect(identity.captureMode()).toBe('manual');
    });

    test('a chip click does not reach a collapse handler bound above it', () => {
        // One-page checkouts bind their own handlers on ancestors of the field;
        // a chip click that propagated would collapse the section it lives in.
        mountTileField();
        const { component } = load();
        component.start();

        let bubbled = 0;
        document.querySelector('#two_gateway_form').addEventListener('click', () => {
            bubbled += 1;
        });
        chip('manual').click();

        expect(bubbled).toBe(0);
    });
});

describe('the component is constructed once per page', () => {
    test('the layout declares the boot component exactly once', () => {
        const layout = fs.readFileSync(path.resolve(__dirname, '..', '..', LAYOUT), 'utf8');
        const declarations = layout.match(/Two_Gateway\/js\/view\/company-search-boot/g) || [];
        expect(declarations).toHaveLength(1);
    });

    test('no payment renderer constructs it — only the boot component does', () => {
        const boot = fs.readFileSync(
            path.resolve(__dirname, '..', '..', 'view/frontend/web/js/view/company-search-boot.js'),
            'utf8'
        );
        const renderer = fs.readFileSync(
            path.resolve(
                __dirname,
                '..',
                '..',
                'view/frontend/web/js/view/payment/method-renderer/gateway_method.js'
            ),
            'utf8'
        );
        expect(boot).toContain('.start()');
        expect(renderer).not.toContain('.start()');
    });
});
