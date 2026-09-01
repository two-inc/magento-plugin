/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25554: the shipping and billing capture panels are COMPLETELY
 * INDEPENDENT. A pick, an adoption or a country switch in one leaves the
 * other's field, identity and notices exactly as they were.
 *
 * Every case runs in both directions off one table, because every coupling
 * point removed here had a direction: the shipping step used to mirror its
 * company onto the billing address, the quote's billing address used to feed
 * the shipping identity, one country delegation drove both panels, and one
 * rate-limit counter parked both (that last one is pinned in
 * proxy-rate-limit-backoff.test.js, where the wire is already doubled).
 *
 * Driven through the REAL company-capture adapter, the REAL popover and the
 * REAL company-search module over a jsdom checkout, so what is asserted is
 * what the buyer would see rather than what a host double was asked for.
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
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

const SHIPPING_FORM = '#shipping-new-address-form';
const BILLING_FORM = '[data-form="billing-new-address"]';
const FIELDS = {
    shipping: `${SHIPPING_FORM} input[name="company"]`,
    billing: `${BILLING_FORM} input[name="company"]`
};
const COUNTRIES = {
    shipping: `${SHIPPING_FORM} select[name="country_id"]`,
    billing: `${BILLING_FORM} select[name="country_id"]`
};

/** The other panel, for a table row naming one. */
const OTHER = { shipping: 'billing', billing: 'shipping' };

const GLOBALS = { document: document, window: window };

/** Seeded so availability answers from the config memo instead of `fetch`. */
const SUPPORTED_COMPANY_TYPES = { gb: ['SOLE_TRADER'], no: ['SOLE_TRADER'], se: [], us: [] };

const COMPANIES = {
    shipping: { text: 'Shipping Co', companyId: '111', lookupId: 'l1' },
    billing: { text: 'Billing Co', companyId: '222', lookupId: 'l2' }
};

const BUYERS = {
    shipping: { company_name: 'Shipping Trader', organization_number: 'TWO:1' },
    billing: { company_name: 'Billing Trader', organization_number: 'TWO:2' }
};

/** Both directions, for every case below. */
const DIRECTIONS = [
    ['shipping', 'a shipping-panel action never reaches the billing panel'],
    ['billing', 'a billing-panel action never reaches the shipping panel']
];

function addressFields(country) {
    return (
        '<input name="company" type="text">' +
        '<input name="custom_attributes[company_id]" type="text">' +
        '<input name="street[0]" type="text"><input name="street[1]" type="text">' +
        '<input name="city" type="text"><input name="postcode" type="text">' +
        '<input name="region" type="text"><select name="region_id"></select>' +
        '<input name="telephone" type="text">' +
        '<select name="country_id">' +
        ['GB', 'NO', 'SE', 'US']
            .map((c) => `<option value="${c}"${c === country ? ' selected' : ''}>${c}</option>`)
            .join('') +
        '</select>'
    );
}

/**
 * A checkout rendering BOTH address forms and the payment tile: the buyer has
 * unchecked "my billing address is the same as shipping".
 *
 * The billing form sits inside the block carrying core's own checkbox, which is
 * what the address mirror keys its per-address record on.
 */
function renderCheckout(options) {
    const shipping = options.shippingForm === false
        ? ''
        : `<form id="shipping-new-address-form">${addressFields(options.shippingCountry)}</form>`;
    document.body.innerHTML =
        shipping +
        '<div class="checkout-billing-address">' +
        '<input type="checkbox" id="billing-address-same-as-shipping-two_payment"' +
        ' name="billing-address-same-as-shipping">' +
        `<div data-form="billing-new-address">${addressFields(options.billingCountry)}</div>` +
        '</div>' +
        '<form id="two_gateway_form"><input id="company_name" name="company_name" /></form>';
}

/**
 * Both panels booted over the real modules.
 *
 * @param {object} [options] `{ shippingForm, shippingCountry, billingCountry }`
 * @returns {object} `{ capture, search, panels, identities }`
 */
function boot(options) {
    renderCheckout(Object.assign({ shippingCountry: 'GB', billingCountry: 'NO' }, options || {}));
    installAsyncSimulation($);
    // jsdom has no layout, so jQuery's `:visible` answers false for every
    // element. The billing panel's mount and the resolver's "billing is a
    // distinct address" test both ask for it, so it is answered off the
    // fixture's own markup instead.
    $.expr.pseudos.visible = function (elem) {
        return !elem.hasAttribute('data-test-hidden');
    };

    function SoleTraderStub() {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.focusSignupPopup = function () { return false; };
        this.launchSignup = function () { return null; };
        this.forgetAdoptions = function () {};
        this.showSignupPrompt = function () {};
    }

    const search = loadAmdModule(SEARCH, { jquery: $ }, GLOBALS);
    search.clearResultCache();
    search.resetMirrorState();

    const mocks = {
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
    };

    const capture = loadCompanyCapture(mocks, GLOBALS);
    capture.start();

    return {
        capture: capture,
        search: search,
        panels: { shipping: capture.shipping, billing: capture.billing },
        identities: {
            shipping: capture.shipping.identity(),
            billing: capture.billing.identity()
        }
    };
}

/**
 * What the popover does when the buyer clicks a result row: paint the field,
 * then hand the row to the component (`CompanySearchPanel._selectIndex`).
 *
 * @param {object} panel a booted capture component
 * @param {object} item search-result row
 */
function picks(panel, item) {
    panel.panel().setDisplayText(item.text);
    panel.selectCompany(item);
}

/** @returns {string} what one panel's company field is displaying */
function displayed(which) {
    return document.querySelector(FIELDS[which]).value;
}

/** The buyer's own country switch in one panel's form. */
function switchCountry(which, iso) {
    $(COUNTRIES[which]).val(iso).trigger('change');
}

/** The baseline `view/address-autocomplete.js` takes at the form's own render. */
function captureBillingBaseline(search) {
    search.captureSecondaryAddressBaseline(document.querySelector(BILLING_FORM));
}

beforeEach(() => {
    document.body.innerHTML = '';
    // The country watchers are delegated off the document, which outlives a
    // test; without this every earlier boot's panels would still be listening.
    $(document).off('.twoCompanyCapture');
    $(document).off('.twoCompanySourceResolver');
    $(document).off('.twoCompanyCaptureMount');
});

describe('a registered pick lands on one panel only', () => {
    test.each(DIRECTIONS)('%s picks (%s)', (actor) => {
        const other = OTHER[actor];
        const { panels, identities } = boot();

        picks(panels[actor], COMPANIES[actor]);

        expect(identities[actor].companyId()).toBe(COMPANIES[actor].companyId);
        expect(displayed(actor)).toBe(COMPANIES[actor].text);
        expect(identities[other].companyId()).toBe('');
        expect(identities[other].companyName()).toBe('');
        expect(displayed(other)).toBe('');
    });

    test.each(DIRECTIONS)('%s picks, and the other panel\'s notice stands (%s)', (actor) => {
        const other = OTHER[actor];
        const { panels, identities } = boot();
        identities[other].addressNotice('We could not fetch this address.');

        picks(panels[actor], COMPANIES[actor]);

        expect(identities[other].addressNotice()).toBe('We could not fetch this address.');
    });
});

describe('a sole-trader adoption lands on one panel only', () => {
    test.each(DIRECTIONS)('%s adopts (%s)', (actor) => {
        const other = OTHER[actor];
        const { panels, identities } = boot();

        panels[actor].adoptSoleTrader(BUYERS[actor]);

        expect(identities[actor].soleTraderAdopted()).toBe(true);
        expect(displayed(actor)).toBe(BUYERS[actor].company_name);
        expect(identities[other].soleTraderAdopted()).toBe(false);
        expect(identities[other].companyName()).toBe('');
        expect(displayed(other)).toBe('');
    });
});

describe('a country switch invalidates its own panel\'s company and nothing else', () => {
    test.each(DIRECTIONS)('%s switches country (%s)', (actor) => {
        const other = OTHER[actor];
        const { panels, identities } = boot();
        picks(panels.shipping, COMPANIES.shipping);
        picks(panels.billing, COMPANIES.billing);

        switchCountry(actor, 'SE');

        expect(identities[actor].companyId()).toBe('');
        expect(identities[other].companyId()).toBe(COMPANIES[other].companyId);
        expect(displayed(other)).toBe(COMPANIES[other].text);
    });
});

describe('the address prefill stops at a billing address the buyer has answered', () => {
    test('a pristine billing address still takes the shipping country', () => {
        const { search } = boot();
        captureBillingBaseline(search);

        expect(search.mirrorFieldsToSecondaryAddresses(['country'])).toBe(1);
    });

    test('a billing capture pins the billing address against it', () => {
        // TWO-25461's own rule — pin once the buyer edits it — applied to a
        // company pick as an edit.
        const { panels, search } = boot();
        captureBillingBaseline(search);

        picks(panels.billing, COMPANIES.billing);

        expect(search.secondaryAddressIsPinned(document.querySelector(BILLING_FORM))).toBe(true);
        expect(search.mirrorFieldsToSecondaryAddresses(['country'])).toBe(0);
    });

    test('a mirrored country is not read as the buyer choosing a country', () => {
        // The mirror writes with a `change`, because Knockout's `value:` binding
        // reads the DOM on nothing else, and that event is indistinguishable at
        // the listener from a buyer's own switch. Reachable whenever the pin is
        // not standing — a rebuild that changes the form's mirror key re-takes
        // its baseline from the render, which is what is set up here.
        const { panels, search } = boot();
        picks(panels.billing, COMPANIES.billing);
        search.resetMirrorState();
        captureBillingBaseline(search);
        expect(search.secondaryAddressIsPinned(document.querySelector(BILLING_FORM))).toBe(false);

        expect(search.mirrorFieldsToSecondaryAddresses(['country'])).toBe(1);

        expect(document.querySelector(COUNTRIES.billing).value).toBe('GB');
        expect(identityOf(panels.billing).companyId()).toBe(COMPANIES.billing.companyId);
    });
});

describe('the payment tile writes only the panel it is mounted at', () => {
    /**
     * The tile is the SHIPPING panel's own fallback mount, and its input is
     * bound to the RESOLVED company — which is billing's whenever billing wins.
     *
     * @returns {object} `{ renderer, panels, identities }`
     */
    function bootTile() {
        const booted = boot({ shippingForm: false });
        const renderer = loadAmdModule(RENDERER, Object.assign({}, defaultMocks(), {
            jquery: $,
            'Two_Gateway/js/model/company-capture': booted.capture
        }), GLOBALS);
        renderer.getCode = function () { return 'two_payment'; };
        renderer.isOrderIntentEnabled = false;
        return { renderer: renderer, panels: booted.panels, identities: booted.identities };
    }

    test('typing while billing is the resolved company writes neither identity', () => {
        const { renderer, panels, identities } = bootTile();
        picks(panels.billing, COMPANIES.billing);

        renderer.companyName('Typed By Hand');

        expect(identities.shipping.companyName()).toBe('');
        expect(identities.billing.companyName()).toBe(COMPANIES.billing.text);
    });

    test('typing while the shipping panel is the resolved company writes the shipping identity', () => {
        const { renderer, identities } = bootTile();

        renderer.companyName('Typed By Hand');

        expect(identities.shipping.companyName()).toBe('Typed By Hand');
        expect(identities.billing.companyName()).toBe('');
    });
});

/** @returns {object} one panel's own identity */
function identityOf(panel) {
    return panel.identity();
}
