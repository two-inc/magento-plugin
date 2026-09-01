/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25554: each capture panel renders its OWN company number and its OWN
 * "select a different sole trader" link, under its own field, off its own
 * identity.
 *
 * Every case runs in both directions off one table: the assertion that matters
 * is as much "the other panel's field is untouched" as "this one's is painted".
 *
 * Driven through the REAL company-capture adapter and the REAL popover over a
 * jsdom checkout, so what is asserted is what the buyer would see.
 */

'use strict';

const $ = require('jquery');
const {
    loadAmdModule,
    loadCompanyCapture,
    loadCompanySearchPanel,
    defaultMocks,
    brandConfigMock,
    installAsyncSimulation,
    tagged
} = require('./amd-harness');

const SEARCH = 'view/frontend/web/js/model/company-search.js';

const FORMS = {
    shipping: '#shipping-new-address-form',
    billing: '[data-form="billing-new-address"]'
};
const FIELDS = {
    shipping: `${FORMS.shipping} input[name="company"]`,
    billing: `${FORMS.billing} input[name="company"]`
};
const TILE_FIELD = '#two_gateway_form input#company_name';

const NUMBER_CLASS = 'two-company-id-text';
const LINK_CLASS = 'two-select-different-sole-trader';
const NOTICE_CLASS = 'two-company-address-notice';

/** The other panel, for a table row naming one. */
const OTHER = { shipping: 'billing', billing: 'shipping' };

const DIRECTIONS = [
    ['shipping', 'the shipping panel paints its own field and no other'],
    ['billing', 'the billing panel paints its own field and no other']
];

const GLOBALS = { document: document, window: window };

const SUPPORTED_COMPANY_TYPES = { gb: ['SOLE_TRADER'], no: ['SOLE_TRADER'] };

const COMPANIES = {
    shipping: { text: 'Shipping Co', companyId: '111111111', lookupId: 'l1' },
    billing: { text: 'Billing Co', companyId: '222222222', lookupId: 'l2' }
};

/** A sole trader the registry holds no public number for. */
const BUYERS = {
    shipping: { company_name: 'Shipping Trader', organization_number: 'TWO:1' },
    billing: { company_name: 'Billing Trader', organization_number: 'TWO:2' }
};

function addressFields(country, restoredNumber, withCompanyIdField) {
    return (
        '<input name="company" type="text">' +
        (withCompanyIdField === false
            ? ''
            : `<input name="custom_attributes[company_id]" type="text" value="${restoredNumber || ''}">`) +
        '<input name="street[0]" type="text"><input name="city" type="text">' +
        '<select name="country_id">' +
        ['GB', 'NO']
            .map((c) => `<option value="${c}"${c === country ? ' selected' : ''}>${c}</option>`)
            .join('') +
        '</select>'
    );
}

function renderCheckout(options) {
    const shipping = options.shippingForm === false
        ? ''
        : '<form id="shipping-new-address-form">'
            + addressFields('GB', options.shippingNumber, options.shippingCompanyIdField)
            + '</form>';
    // A second per-method billing fieldset, as Amasty's one-step layout renders
    // one; LayoutProcessorPlugin injects `company_id` into each.
    const extraBilling = options.extraBillingNumber
        ? `<div data-form="billing-new-address-other">${addressFields('NO', options.extraBillingNumber)}</div>`
        : '';
    document.body.innerHTML =
        shipping +
        '<div class="checkout-billing-address">' +
        '<input type="checkbox" name="billing-address-same-as-shipping">' +
        `<div data-form="billing-new-address"${options.billingHidden ? ' data-test-hidden' : ''}>` +
        addressFields('NO', options.billingNumber, options.billingCompanyIdField) +
        '</div>' +
        extraBilling +
        '</div>' +
        '<form id="two_gateway_form"><input id="company_name" name="company_name" /></form>';
}

/**
 * Both panels booted over the real modules.
 *
 * @param {object} [options] `{ shippingNumber, billingNumber }` — a number
 *        already in a form's own field, as a reload restores it — plus
 *        `{ shippingForm: false, billingHidden }` for the shape that sends the
 *        shipping panel to its tile fallback
 * @returns {object} `{ panels, identities, soleTraderCalls }`
 */
function boot(options) {
    renderCheckout(options || {});
    installAsyncSimulation($);
    // jsdom has no layout, so jQuery's `:visible` answers false for everything;
    // the billing panel's mount asks for it, so answer it off the fixture.
    $.expr.pseudos.visible = function (elem) {
        return !elem.hasAttribute('data-test-hidden')
            && !(elem.closest && elem.closest('[data-test-hidden]'));
    };

    const soleTraderCalls = [];

    function SoleTraderStub(component) {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.focusSignupPopup = function () { return false; };
        this.launchSignup = function () { return null; };
        this.forgetAdoptions = function () {};
        this.showSignupPrompt = function () {};
        this.selectDifferentSoleTrader = function () {
            soleTraderCalls.push(component);
            return 'popup';
        };
    }

    const search = loadAmdModule(SEARCH, { jquery: $ }, GLOBALS);
    search.clearResultCache();

    const capture = loadCompanyCapture({
        jquery: $,
        'Magento_Checkout/js/model/quote': Object.assign(
            {},
            defaultMocks()['Magento_Checkout/js/model/quote'],
            {
                billingAddress: function () { return { countryId: 'GB' }; },
                isVirtual: function () { return false; }
            }
        ),
        'Two_Gateway/js/model/company-search': search,
        'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel($, search, GLOBALS),
        'Two_Gateway/js/model/sole-trader': SoleTraderStub,
        'Two_Gateway/js/model/brand-config': brandConfigMock({
            isCompanySearchEnabled: true,
            isAddressSearchEnabled: false,
            checkoutApiUrl: 'https://api.example.test',
            checkoutPageUrl: 'https://checkout.example.test',
            supportedCompanyTypes: SUPPORTED_COMPANY_TYPES
        })
    }, GLOBALS);
    capture.start();

    return {
        panels: { shipping: capture.shipping, billing: capture.billing },
        identities: {
            shipping: capture.shipping.identity(),
            billing: capture.billing.identity()
        },
        soleTraderCalls: soleTraderCalls
    };
}

/** What the popover does on a result click. */
function picks(panel, item) {
    panel.panel().setDisplayText(item.text);
    panel.selectCompany(item);
}

/**
 * One macrotask. `watchCapturedIdentity` publishes on `setTimeout(0)`, so a
 * negative assertion made before it has run is vacuous.
 *
 * @returns {Promise}
 */
function flushCapture() {
    return new Promise(function (resolve) { setTimeout(resolve, 0); });
}

/** @returns {Array<string>} the number labels rendered inside one panel's form */
function numbersIn(which) {
    return Array.prototype.map.call(
        document.querySelectorAll(`${FORMS[which]} .${NUMBER_CLASS}`),
        (node) => node.textContent
    );
}

/** @returns {number} how many sole-trader links are rendered in one panel's form */
function linksIn(which) {
    return document.querySelectorAll(`${FORMS[which]} .${LINK_CLASS}`).length;
}

/** @returns {Array<string>} the address notices rendered in one panel's form */
function noticesIn(which) {
    return Array.prototype.map.call(
        document.querySelectorAll(`${FORMS[which]} .${NOTICE_CLASS}`),
        (node) => node.textContent
    );
}

beforeEach(() => {
    document.body.innerHTML = '';
    $(document).off('.twoCompanyCapture');
    $(document).off('.twoCompanySourceResolver');
    $(document).off('.twoCompanyCaptureMount');
});

describe('the company number is painted under its own panel\'s field', () => {
    test.each(DIRECTIONS)('%s picks a company (%s)', (actor, description) => {
        const other = OTHER[actor];
        const { panels } = boot();

        picks(panels[actor], COMPANIES[actor]);

        expect(tagged(description, numbersIn(actor)))
            .toEqual(tagged(description, [COMPANIES[actor].companyId]));
        expect(tagged(description, numbersIn(other))).toEqual(tagged(description, []));
    });

    test.each(DIRECTIONS)('%s is in manual entry (%s)', (actor, description) => {
        const { panels } = boot();
        picks(panels[actor], COMPANIES[actor]);

        panels[actor].manualEntryMode();

        // Name-only capture: a number here would claim a registry identity the
        // buyer never picked.
        expect(tagged(description, numbersIn(actor))).toEqual(tagged(description, []));
    });

    test.each(DIRECTIONS)('%s captures an internally-prefixed number (%s)', (actor, description) => {
        const { panels } = boot();

        picks(panels[actor], { text: 'No Public Number Ltd', companyId: 'TWO:abc', lookupId: 'l3' });

        expect(tagged(description, numbersIn(actor))).toEqual(tagged(description, []));
    });

    test.each(DIRECTIONS)('%s clears its capture (%s)', (actor, description) => {
        const { panels, identities } = boot();
        picks(panels[actor], COMPANIES[actor]);
        expect(tagged(description, numbersIn(actor)))
            .toEqual(tagged(description, [COMPANIES[actor].companyId]));

        identities[actor].clear();

        expect(tagged(description, numbersIn(actor))).toEqual(tagged(description, []));
    });

    test.each(DIRECTIONS)('%s renders a number restored by a reload (%s)', (actor, description) => {
        const other = OTHER[actor];
        const restored = { shipping: 'shippingNumber', billing: 'billingNumber' };

        // Nothing captured in-page: the number is in the form's own field, as
        // the checkoutProvider restores it.
        const { identities } = boot({ [restored[actor]]: '999999999' });

        expect(tagged(description, identities[actor].companyId())).toEqual(tagged(description, ''));
        expect(tagged(description, numbersIn(actor))).toEqual(tagged(description, ['999999999']));
        expect(tagged(description, numbersIn(other))).toEqual(tagged(description, []));
    });

    test('a tile-mounted panel claims no number restored into the billing form', () => {
        // With no shipping form the shipping panel falls back to the tile, which
        // carries no address fields of its own — so the only restored number
        // reachable by walking up from it is the unmounted billing form's.
        const { panels } = boot({
            shippingForm: false,
            billingHidden: true,
            billingNumber: '999999999'
        });
        expect(panels.shipping.mountSelector()).toBe(TILE_FIELD);

        expect(panels.shipping.displayCompanyNumber()).toBe('');
        expect(document.querySelectorAll(`.${NUMBER_CLASS}`)).toHaveLength(0);
    });

    test.each([
        [
            'shipping',
            'the shipping form has no number field and one billing neighbour has one',
            { shippingCompanyIdField: false, billingNumber: '555555555' }
        ],
        [
            'billing',
            'the billing form has no number field and one shipping neighbour has one',
            { billingCompanyIdField: false, shippingNumber: '777777777' }
        ]
    ])(
        '%s claims nothing from the single neighbour holding a number (%s)',
        async (actor, description, fixture) => {
            const other = OTHER[actor];
            const restored = { shipping: fixture.shippingNumber, billing: fixture.billingNumber };
            const { panels } = boot(fixture);
            expect(panels[actor].mountSelector()).toBe(FIELDS[actor]);

            // `watchCapturedIdentity` publishes on a macrotask, so an assertion
            // made before it has run denies a propagation that had not happened.
            await flushCapture();
            await flushCapture();

            expect(tagged(description, panels[actor].displayCompanyNumber()))
                .toEqual(tagged(description, ''));
            expect(tagged(description, numbersIn(actor))).toEqual(tagged(description, []));
            // The neighbour still paints the number in its OWN field.
            expect(tagged(description, numbersIn(other)))
                .toEqual(tagged(description, [restored[other]]));
        }
    );

    test('a panel whose own form carries no number field claims neither neighbour\'s', () => {
        // Two billing fieldsets, a number restored into each. The shipping form
        // has no number field, so the nearest ancestor holding any spans both —
        // and neither is answerable as this panel's.
        const { panels } = boot({
            shippingCompanyIdField: false,
            billingNumber: '999999999',
            extraBillingNumber: '888888888'
        });
        expect(panels.shipping.mountSelector()).toBe(FIELDS.shipping);

        expect(panels.shipping.displayCompanyNumber()).toBe('');
        expect(numbersIn('shipping')).toEqual([]);
    });
});

describe('chrome never enters the popover\'s positioning context', () => {
    /*
     * `.two-company-dropdown` is `position: absolute; top: 100%` against
     * `.two-company-field-wrap` (css/style.css), so anything in the wrap's flow
     * moves the popover off the field by its own height. jsdom has no layout, so
     * the constraint is pinned where it is decided: chrome is a following
     * SIBLING of the wrap and never a descendant.
     */
    /** A sole trader the registry DOES hold a public number for, so both
     *  pieces of chrome are on the page at once. */
    const NUMBERED_TRADER = { company_name: 'Numbered Trader', organization_number: '333333333' };

    test.each(DIRECTIONS)('%s renders both pieces of chrome (%s)', (actor, description) => {
        const { panels } = boot();

        panels[actor].adoptSoleTrader(NUMBERED_TRADER);

        const wrap = document.querySelector(`${FORMS[actor]} .two-company-field-wrap`);
        expect(wrap).not.toBeNull();
        expect(tagged(description, wrap.querySelectorAll(`.${NUMBER_CLASS}, .${LINK_CLASS}`).length))
            .toEqual(tagged(description, 0));

        const number = document.querySelector(`${FORMS[actor]} .${NUMBER_CLASS}`);
        const link = document.querySelector(`${FORMS[actor]} .${LINK_CLASS}`);
        expect(tagged(description, number.parentElement))
            .toEqual(tagged(description, wrap.parentElement));
        expect(tagged(description, link.parentElement))
            .toEqual(tagged(description, wrap.parentElement));
        expect(tagged(description, wrap.nextElementSibling)).toEqual(tagged(description, number));
        expect(tagged(description, number.nextElementSibling)).toEqual(tagged(description, link));
    });

    test('the billing panel takes its chrome down when it loses its mount', () => {
        // Hidden rather than removed — "billing same as shipping" re-checked.
        // The wrapper goes with the mount, and chrome sits outside the wrapper,
        // so nothing else would take it down.
        const { panels } = boot();
        panels.billing.adoptSoleTrader(NUMBERED_TRADER);
        expect(numbersIn('billing')).toHaveLength(1);
        expect(linksIn('billing')).toBe(1);

        document.querySelector(FORMS.billing).setAttribute('data-test-hidden', '');
        panels.billing.refreshMount();
        expect(panels.billing.mountSelector()).toBe('');

        expect(numbersIn('billing')).toEqual([]);
        expect(linksIn('billing')).toBe(0);
    });

    test.each(DIRECTIONS)('%s keeps that order across a repaint (%s)', (actor, description) => {
        const { panels } = boot();
        panels[actor].adoptSoleTrader(NUMBERED_TRADER);

        panels[actor].renderChrome();

        const wrap = document.querySelector(`${FORMS[actor]} .two-company-field-wrap`);
        expect(tagged(description, wrap.querySelectorAll(`.${NUMBER_CLASS}, .${LINK_CLASS}`).length))
            .toEqual(tagged(description, 0));
        expect(tagged(description, wrap.nextElementSibling.classList.contains(NUMBER_CLASS)))
            .toEqual(tagged(description, true));
        expect(tagged(description, numbersIn(actor).length)).toEqual(tagged(description, 1));
        expect(tagged(description, linksIn(actor))).toEqual(tagged(description, 1));
    });
});

describe('the "select a different sole trader" link belongs to its own panel', () => {
    test.each(DIRECTIONS)('%s adopts a sole trader (%s)', (actor, description) => {
        const other = OTHER[actor];
        const { panels } = boot();

        panels[actor].adoptSoleTrader(BUYERS[actor]);

        expect(tagged(description, linksIn(actor))).toEqual(tagged(description, 1));
        expect(tagged(description, linksIn(other))).toEqual(tagged(description, 0));
    });

    test.each(DIRECTIONS)('%s picks a registered company instead (%s)', (actor, description) => {
        const { panels } = boot();

        picks(panels[actor], COMPANIES[actor]);

        expect(tagged(description, linksIn(actor))).toEqual(tagged(description, 0));
    });

    test.each(DIRECTIONS)('%s has its adoption withdrawn (%s)', (actor, description) => {
        const { panels, identities } = boot();
        panels[actor].adoptSoleTrader(BUYERS[actor]);

        identities[actor].soleTraderAdopted(false);

        expect(tagged(description, linksIn(actor))).toEqual(tagged(description, 0));
    });

    test.each(DIRECTIONS)('%s link is clicked (%s)', (actor, description) => {
        const other = OTHER[actor];
        const { panels, soleTraderCalls } = boot();
        panels[actor].adoptSoleTrader(BUYERS[actor]);
        panels[other].adoptSoleTrader(BUYERS[other]);

        document.querySelector(`${FORMS[actor]} .${LINK_CLASS}__link`).click();

        expect(tagged(description, soleTraderCalls)).toEqual(tagged(description, [panels[actor]]));
    });
});

/*
 * TWO-25554: an address-lookup failure is the panel's own, rendered at the
 * panel's own field. It used to travel through the resolved identity into the
 * payment tile, where a shipping failure was displayed only while shipping
 * happened to win and a billing failure told the buyer to "enter it below" a
 * form that was not theirs.
 */
describe('an address notice is painted under its own panel\'s field', () => {
    const NOTICE = 'We could not fetch this company\'s address. Please enter it below.';
    const OTHER_NOTICE = 'We could not fill in this company\'s address on this page.';

    test.each(DIRECTIONS)('%s raises a notice (%s)', (actor, description) => {
        const other = OTHER[actor];
        const { identities } = boot();

        identities[actor].addressNotice(NOTICE);

        expect(tagged(description, noticesIn(actor))).toEqual(tagged(description, [NOTICE]));
        expect(tagged(description, noticesIn(other))).toEqual(tagged(description, []));
    });

    test.each(DIRECTIONS)('%s withdraws its notice (%s)', (actor, description) => {
        const { identities } = boot();
        identities[actor].addressNotice(NOTICE);

        identities[actor].addressNotice('');

        expect(tagged(description, noticesIn(actor))).toEqual(tagged(description, []));
    });

    test('both panels can hold their own notice at once, each at its own field', () => {
        const { identities } = boot();

        identities.shipping.addressNotice(NOTICE);
        identities.billing.addressNotice(OTHER_NOTICE);

        expect(noticesIn('shipping')).toEqual([NOTICE]);
        expect(noticesIn('billing')).toEqual([OTHER_NOTICE]);
    });

    test.each(DIRECTIONS)('%s repaints rather than stacking notices (%s)', (actor, description) => {
        const { identities } = boot();
        identities[actor].addressNotice(NOTICE);

        identities[actor].addressNotice(OTHER_NOTICE);

        expect(tagged(description, noticesIn(actor))).toEqual(tagged(description, [OTHER_NOTICE]));
    });

    test('a tile-mounted panel paints its notice at the tile, and the other panel\'s form stays clean', () => {
        const { panels, identities } = boot({ shippingForm: false, billingHidden: true });
        expect(panels.shipping.mountSelector()).toBe(TILE_FIELD);

        identities.shipping.addressNotice(NOTICE);

        expect(
            Array.prototype.map.call(
                document.querySelectorAll(`#two_gateway_form .${NOTICE_CLASS}`),
                (node) => node.textContent
            )
        ).toEqual([NOTICE]);
        expect(noticesIn('billing')).toEqual([]);
    });

    test.each(DIRECTIONS)('%s announces its notice to a screen reader (%s)', (actor, description) => {
        const { identities } = boot();

        identities[actor].addressNotice(NOTICE);

        const box = document.querySelector(`${FORMS[actor]} .${NOTICE_CLASS}`);
        expect(tagged(description, box.getAttribute('role'))).toEqual(tagged(description, 'alert'));
    });
});

describe('a panel that loses its mount leaves no chrome behind', () => {
    const WRAP_CLASS = 'two-company-field-wrap';
    /** Numbered, so both pieces of chrome are on the page at once. */
    const TRADER = { company_name: 'Billing Trader', organization_number: '333333333' };

    /** What re-checking "same as shipping" leaves: the fieldset, hidden. */
    function hideBillingFieldset() {
        document.querySelector(FORMS.billing).setAttribute('data-test-hidden', '');
    }

    test('the billing form keeps neither the number nor a link to a torn-down flow', async () => {
        const { panels, soleTraderCalls } = boot();
        panels.billing.adoptSoleTrader(TRADER);
        expect(linksIn('billing')).toBe(1);
        expect(numbersIn('billing')).toHaveLength(1);

        hideBillingFieldset();
        panels.billing.refreshMount();
        await flushCapture();
        await flushCapture();

        const form = document.querySelector(FORMS.billing);
        expect(form.querySelectorAll(`.${WRAP_CLASS}`)).toHaveLength(0);
        expect(numbersIn('billing')).toEqual([]);
        expect(linksIn('billing')).toBe(0);
        expect(soleTraderCalls).toEqual([]);
    });

    test('the shipping panel keeps its own chrome through the other\'s loss', async () => {
        const { panels } = boot();
        panels.shipping.adoptSoleTrader(TRADER);
        panels.billing.adoptSoleTrader(TRADER);

        hideBillingFieldset();
        panels.billing.refreshMount();
        await flushCapture();
        await flushCapture();

        expect(numbersIn('shipping')).toHaveLength(1);
        expect(linksIn('shipping')).toBe(1);
    });
});

/*
 * TWO-25554: `onCountryChanged()` reads the mode BEFORE `clear()` resets it, so
 * the retirement can still tell that sole-trader mode is what it is leaving.
 * Read after the clear, the answer is always "no" and the panel is never handed
 * back as a search trigger — leaving the buyer on a released field with no route
 * back into the search.
 */
describe('a country change out of sole-trader mode hands the field back to the search', () => {
    const BACK_CLASS = 'two-company-search-back';

    test.each(DIRECTIONS)('%s switches country while in sole-trader mode (%s)', async (actor, description) => {
        const { panels } = boot();
        // Manual entry first, so the field is RELEASED — a plain input with the
        // return link beside it — and only registeredMode() reclaims it.
        panels[actor].manualEntryMode();
        panels[actor].soleTraderMode();
        const field = document.querySelector(FIELDS[actor]);
        expect(field.getAttribute('role')).toBeNull();
        expect(document.querySelectorAll(`${FORMS[actor]} .${BACK_CLASS}`)).toHaveLength(1);

        panels[actor].onCountryChanged(actor === 'shipping' ? 'no' : 'gb');
        await flushCapture();
        await flushCapture();

        expect(tagged(description, field.getAttribute('role')))
            .toEqual(tagged(description, 'combobox'));
        expect(tagged(description, document.querySelectorAll(`${FORMS[actor]} .${BACK_CLASS}`).length))
            .toEqual(tagged(description, 0));
    });
});

describe('a panel that MOVES its mount leaves no chrome behind at the old host', () => {
    /** Numbered, so both pieces of chrome are on the page at once. */
    const TRADER = { company_name: 'Tile Trader', organization_number: '333333333' };

    const TILE_FORM = '#two_gateway_form';

    /**
     * The shipping form arriving after the panel already fell back to the tile
     * — a buyer switching off a saved address, which is the move this covers.
     */
    function addShippingForm() {
        const form = document.createElement('form');
        form.id = 'shipping-new-address-form';
        form.innerHTML = addressFields('GB');
        document.body.insertBefore(form, document.body.firstChild);
    }

    /** @returns {Array<string>} number labels rendered inside a container */
    function numbersUnder(root) {
        return Array.prototype.map.call(
            document.querySelectorAll(`${root} .${NUMBER_CLASS}`),
            (node) => node.textContent
        );
    }

    /**
     * Tile-mounted, sole trader adopted, then the shipping form arrives and the
     * mount moves off the tile.
     *
     * @returns {Promise<object>} `{ panels, soleTraderCalls, tileInput }`
     */
    async function movesOffTheTile() {
        const booted = boot({ shippingForm: false, billingHidden: true });
        expect(booted.panels.shipping.mountSelector()).toBe(TILE_FIELD);
        booted.panels.shipping.adoptSoleTrader(TRADER);
        const tileInput = document.querySelector(TILE_FIELD);
        expect(numbersUnder(TILE_FORM)).toEqual([TRADER.organization_number]);
        expect(document.querySelectorAll(`${TILE_FORM} .${LINK_CLASS}`)).toHaveLength(1);
        expect(tileInput.getAttribute('role')).toBe('combobox');

        addShippingForm();
        booted.panels.shipping.refreshMount();
        await flushCapture();
        await flushCapture();

        expect(booted.panels.shipping.mountSelector()).toBe(FIELDS.shipping);
        return Object.assign({ tileInput: tileInput }, booted);
    }

    test('the tile keeps neither the number nor a link to a flow it no longer hosts', async () => {
        const { soleTraderCalls } = await movesOffTheTile();

        expect(numbersUnder(TILE_FORM)).toEqual([]);
        expect(document.querySelectorAll(`${TILE_FORM} .${LINK_CLASS}`)).toHaveLength(0);
        expect(soleTraderCalls).toEqual([]);
    });

    test.each([
        ['role', 'the abandoned field still announces itself as a combobox'],
        ['aria-haspopup', 'it still claims a listbox'],
        ['aria-controls', 'it still points at the moved popover'],
        ['aria-expanded', 'it still reports the moved popover\'s open state']
    ])('the tile field keeps no %s (%s)', async (attribute, description) => {
        const { tileInput } = await movesOffTheTile();

        expect(tagged(description, tileInput.getAttribute(attribute)))
            .toEqual(tagged(description, null));
    });

    test('the chrome the move carries lands at the new host, once', async () => {
        const { panels } = boot({ shippingForm: false, billingHidden: true });
        panels.shipping.adoptSoleTrader(TRADER);

        addShippingForm();
        panels.shipping.refreshMount();
        await flushCapture();
        await flushCapture();

        expect(numbersIn('shipping')).toEqual([TRADER.organization_number]);
        expect(linksIn('shipping')).toBe(1);
    });
});
