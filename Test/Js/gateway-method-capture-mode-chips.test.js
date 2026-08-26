/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — the three company-capture options as peers INSIDE the one
 * popover, owned by the company-capture component and rendered by
 * `company-search-panel.js`.
 *
 * The containment is the point of the change, so it is asserted structurally:
 * chips that are siblings of the company field are drawn over by the very
 * dropdown that offers them, which is what select2 did.
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
const {
    loadAmdModule,
    loadCompanySearchPanel,
    defaultMocks,
    installAsyncSimulation
} = require('./amd-harness');

// Real jQuery has no `$.async`; installed up front so the per-test reset below
// can clear observers one test registered before the next one loads anything.
installAsyncSimulation($);

const COMPONENT = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const LAYOUT = 'view/frontend/layout/checkout_index_index.xml';

const CHIP_SELECTOR = '.two-company-mode-chip';
const SELECTED_CLASS = 'two-company-mode-chip--selected';
const HIDDEN_CLASS = 'two-hidden';

/**
 * Load the component, its identity singleton and the REAL panel together,
 * against the real jsdom document.
 *
 * The panel is real because the chips are its markup: a stub would leave every
 * assertion here reading a DOM no production code builds.
 *
 * Loaded fresh per test on purpose: both modules are page-level singletons, so
 * a shared load would carry one case's captured company into the next.
 *
 * @param {object} [options] `{ isCompanySearchEnabled }` on the brand config
 * @returns {object} `{ component, identity, search, soleTrader }`
 */
function load(options) {
    const opts = options || {};
    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });
    const search = { aborts: 0, lookups: [] };
    const soleTrader = { launches: [], ensured: 0 };

    const companySearchMock = Object.assign(
        {},
        defaultMocks()['Two_Gateway/js/model/company-search'],
        {
            currentAddressFormCountry: function () { return 'gb'; },
            revertAutofilledAddress: function () {},
            billingRoleFormRoot: function () { return null; },
            applyAddress: function () {},
            lookupCompanyAddress: function (config, item) { search.lookups.push(item); },
            abortActiveRequest: function () { search.aborts += 1; return true; },
            searchCompanies: function () {
                return Promise.resolve({
                    items: opts.searchResults || [],
                    unavailable: false,
                    aborted: false
                });
            },
            // Nothing here tests the debounce, and the real one is a 300ms sleep.
            SEARCH_DEBOUNCE_MS: 0
        }
    );

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
            'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel(
                $,
                companySearchMock,
                { document: document, window: window }
            ),
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
            'Two_Gateway/js/model/company-search': companySearchMock
        },
        { document: document, window: window }
    );
    return {
        component: component,
        identity: identity,
        search: search,
        soleTrader: soleTrader
    };
}

/** A field for the panel to anchor to, in the payment tile's shape. */
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

function dropdown() {
    return document.querySelector('.two-company-dropdown');
}

beforeEach(() => {
    document.body.innerHTML = '';
    $.async.reset();
});

describe('the company-capture component owns one mode control', () => {
    test('there is exactly one chips group, holding three chips in order', () => {
        mountTileField();
        load().component.start();

        expect(document.querySelectorAll('.two-company-mode-chips')).toHaveLength(1);
        expect(chips().map((node) => node.getAttribute('data-two-chip'))).toEqual([
            'registered',
            'soletrader',
            'manual'
        ]);
    });

    test('every chip is a DESCENDANT of the dropdown, never a sibling of the field', () => {
        mountTileField();
        load().component.start();

        const panel = dropdown();
        const group = document.querySelector('.two-company-mode-chips');
        const wrap = document.querySelector('#company_name').parentElement;

        expect(panel.contains(group)).toBe(true);
        chips().forEach((node) => {
            expect(panel.contains(node)).toBe(true);
        });
        expect(wrap.contains(panel)).toBe(true);
        expect(group.parentElement).not.toBe(wrap);
    });

    test('the chips are the last thing in the panel, after the results host', () => {
        mountTileField();
        load().component.start();

        const order = Array.from(dropdown().children).map((node) => node.className);
        expect(order).toEqual([
            'two-company-dropdown__search',
            'two-company-dropdown__results',
            'two-company-mode-chips'
        ]);
    });

    test('a second start() does not build a second group', () => {
        mountTileField();
        const { component } = load();
        component.start();
        component.start();

        expect(document.querySelectorAll('.two-company-mode-chips')).toHaveLength(1);
    });

    test('re-syncing repaints the one group rather than building another', () => {
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
        expect(chip(mode).getAttribute('aria-pressed')).toBe('true');
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
        const { component, identity, search } = load();
        component.start();
        identity.write({ companyName: 'Example Ltd', companyId: '12345678' });

        chip('manual').click();

        expect(identity.captureMode()).toBe('manual');
        expect(identity.companyId()).toBe('');
        expect(search.aborts).toBeGreaterThan(0);
        expect(dropdown().hasAttribute('hidden')).toBe(true);

        // The field is the buyer's own again: focusing it no longer reopens the
        // panel it used to trigger.
        $('#company_name').trigger('focus');
        expect(dropdown().hasAttribute('hidden')).toBe(true);
    });

    test('the registered chip returns to search and opens the panel', () => {
        mountTileField();
        const { component, identity } = load();
        component.start();
        chip('manual').click();

        chip('registered').click();

        expect(identity.captureMode()).toBe('registered');
        expect(dropdown().hasAttribute('hidden')).toBe(false);
        expect(document.querySelector('.two-company-dropdown__query')).not.toBeNull();
    });

    test('the sole-trader chip enters the mode, closes the panel and launches signup', () => {
        mountTileField();
        const { component, identity, soleTrader } = load();
        component.start();
        chip('registered').click();

        chip('soletrader').click();

        expect(identity.captureMode()).toBe('soletrader');
        expect(soleTrader.launches).toHaveLength(1);
        expect(dropdown().hasAttribute('hidden')).toBe(true);
        // Closed, not destroyed — the chips are the route back out of a signup
        // the buyer abandons.
        expect(chip('registered')).not.toBeNull();
    });

    test('re-clicking the sole-trader chip once adopted asks for a replacement', () => {
        mountTileField();
        const { component, identity, soleTrader } = load();
        component.start();
        identity.captureMode('soletrader');
        identity.soleTraderAdopted(true);
        component.syncChips();

        chip('soletrader').click();

        expect(soleTrader.launches).toEqual([{ autoselect: false }]);
    });

    test('a chip is a real button, so the browser activates it from the keyboard', () => {
        mountTileField();
        load().component.start();

        chips().forEach((node) => {
            expect(node.tagName).toBe('BUTTON');
            expect(node.getAttribute('type')).toBe('button');
        });
    });

    test('a chip click does not reach a collapse handler bound above it', () => {
        // One-page checkouts bind their own handlers on ancestors of the field;
        // a chip click that propagated would collapse the section it lives in.
        mountTileField();
        load().component.start();

        let bubbled = 0;
        document.querySelector('#two_gateway_form').addEventListener('click', () => {
            bubbled += 1;
        });
        chip('manual').click();

        expect(bubbled).toBe(0);
    });
});

describe('picking a result row is what captures the company', () => {
    test('a row click reaches the component and asks the registry for the address', async () => {
        // The panel-to-component wiring itself: gateway-method-company-selection
        // drives `onSelect` directly, so without this nothing proves a click on a
        // rendered row ever calls it.
        mountTileField();
        const { component, identity, search } = load({
            searchResults: [
                {
                    id: 'Acme Ltd',
                    text: 'Acme Ltd',
                    html: '<em>Acme</em> Ltd',
                    companyId: '12345678',
                    lookupId: 'lookup-1'
                }
            ]
        });
        component.start();
        chip('registered').click();

        $('.two-company-dropdown__query').val('acme').trigger('input');
        await new Promise((resolve) => { setTimeout(resolve, 0); });

        const rows = document.querySelectorAll('.two-company-dropdown__row');
        expect(rows).toHaveLength(1);
        $(rows[0]).trigger('mousedown');

        expect(identity.companyName()).toBe('Acme Ltd');
        expect(identity.companyId()).toBe('12345678');
        expect(search.lookups.map((item) => item.lookupId)).toEqual(['lookup-1']);
        expect(dropdown().hasAttribute('hidden')).toBe(true);
        expect(document.querySelector('#company_name').value).toBe('Acme Ltd');
    });

    test('the pick is announced on the field, which is how it reaches the quote', async () => {
        // Magento's `value:` binding reads the input on `change` only, so a
        // silent `.val()` write leaves the quote carrying nothing.
        mountTileField();
        const { component } = load({
            searchResults: [{ id: 'Acme Ltd', text: 'Acme Ltd', html: 'Acme Ltd', companyId: '1' }]
        });
        component.start();
        chip('registered').click();

        let changes = 0;
        $('#company_name').on('change', () => { changes += 1; });

        $('.two-company-dropdown__query').val('acme').trigger('input');
        await new Promise((resolve) => { setTimeout(resolve, 0); });
        $(document.querySelector('.two-company-dropdown__row')).trigger('mousedown');

        expect(changes).toBe(1);
        expect(document.querySelector('#company_name').value).toBe('Acme Ltd');
    });
});

describe('an adopted sole trader is shown in the company field', () => {
    test("the authenticated buyer's company name is painted and announced", () => {
        mountTileField();
        const { component, identity } = load();
        component.start();

        let changes = 0;
        $('#company_name').on('change', () => { changes += 1; });
        identity.captureMode('soletrader');

        component.adoptSoleTrader({
            organization_number: 'TWO:ST:GB:1',
            company_name: 'Jane Smith Trading'
        });

        // The signup happens in a popup, so the field is the only place the
        // checkout tells the buyer which identity came back.
        expect(document.querySelector('#company_name').value).toBe('Jane Smith Trading');
        expect(changes).toBe(1);
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

describe('the chips are the only route to manual entry', () => {
    test('manual entry is offered once, as a chip, and nowhere else in the panel', () => {
        mountTileField();
        load().component.start();

        expect(document.querySelectorAll('[data-two-chip="manual"]')).toHaveLength(1);
        // Two routes to the same mode would put a differently-worded escape
        // hatch inside the picker, competing with the chip beside it.
        expect(dropdown().querySelectorAll('button')).toHaveLength(chips().length);
    });
});
