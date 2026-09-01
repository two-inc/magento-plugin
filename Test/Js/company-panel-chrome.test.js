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
    installAsyncSimulation
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
        addressFields('NO', options.billingNumber) +
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

beforeEach(() => {
    document.body.innerHTML = '';
    $(document).off('.twoCompanyCapture');
    $(document).off('.twoCompanySourceResolver');
    $(document).off('.twoCompanyCaptureMount');
});

describe('the company number is painted under its own panel\'s field', () => {
    test.each(DIRECTIONS)('%s picks a company (%s)', (actor) => {
        const other = OTHER[actor];
        const { panels } = boot();

        picks(panels[actor], COMPANIES[actor]);

        expect(numbersIn(actor)).toEqual([COMPANIES[actor].companyId]);
        expect(numbersIn(other)).toEqual([]);
    });

    test.each(DIRECTIONS)('%s is in manual entry (%s)', (actor) => {
        const { panels } = boot();
        picks(panels[actor], COMPANIES[actor]);

        panels[actor].manualEntryMode();

        // Name-only capture: a number here would claim a registry identity the
        // buyer never picked.
        expect(numbersIn(actor)).toEqual([]);
    });

    test.each(DIRECTIONS)('%s captures an internally-prefixed number (%s)', (actor) => {
        const { panels } = boot();

        picks(panels[actor], { text: 'No Public Number Ltd', companyId: 'TWO:abc', lookupId: 'l3' });

        expect(numbersIn(actor)).toEqual([]);
    });

    test.each(DIRECTIONS)('%s clears its capture (%s)', (actor) => {
        const { panels, identities } = boot();
        picks(panels[actor], COMPANIES[actor]);
        expect(numbersIn(actor)).toEqual([COMPANIES[actor].companyId]);

        identities[actor].clear();

        expect(numbersIn(actor)).toEqual([]);
    });

    test.each(DIRECTIONS)('%s renders a number restored by a reload (%s)', (actor) => {
        const other = OTHER[actor];
        const restored = { shipping: 'shippingNumber', billing: 'billingNumber' };

        // Nothing captured in-page: the number is in the form's own field, as
        // the checkoutProvider restores it.
        const { identities } = boot({ [restored[actor]]: '999999999' });

        expect(identities[actor].companyId()).toBe('');
        expect(numbersIn(actor)).toEqual(['999999999']);
        expect(numbersIn(other)).toEqual([]);
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

describe('the "select a different sole trader" link belongs to its own panel', () => {
    test.each(DIRECTIONS)('%s adopts a sole trader (%s)', (actor) => {
        const other = OTHER[actor];
        const { panels } = boot();

        panels[actor].adoptSoleTrader(BUYERS[actor]);

        expect(linksIn(actor)).toBe(1);
        expect(linksIn(other)).toBe(0);
    });

    test.each(DIRECTIONS)('%s picks a registered company instead (%s)', (actor) => {
        const { panels } = boot();

        picks(panels[actor], COMPANIES[actor]);

        expect(linksIn(actor)).toBe(0);
    });

    test.each(DIRECTIONS)('%s has its adoption withdrawn (%s)', (actor) => {
        const { panels, identities } = boot();
        panels[actor].adoptSoleTrader(BUYERS[actor]);

        identities[actor].soleTraderAdopted(false);

        expect(linksIn(actor)).toBe(0);
    });

    test.each(DIRECTIONS)('%s link is clicked (%s)', (actor) => {
        const other = OTHER[actor];
        const { panels, soleTraderCalls } = boot();
        panels[actor].adoptSoleTrader(BUYERS[actor]);
        panels[other].adoptSoleTrader(BUYERS[other]);

        document.querySelector(`${FORMS[actor]} .${LINK_CLASS}__link`).click();

        expect(soleTraderCalls).toEqual([panels[actor]]);
    });
});
