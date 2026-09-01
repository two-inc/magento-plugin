/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25554: the shipping and billing capture panels are COMPLETELY
 * INDEPENDENT. A pick, an adoption or a country switch in one leaves the
 * other's identity, field, notices and address fields exactly as they were.
 *
 * Driven through the REAL company-capture adapter, the REAL address step, the
 * REAL popover, the REAL payment renderer and the REAL company-search module
 * over a jsdom checkout — every coupling this pins had a live path through one
 * of those, and a fixture that omits the path proves nothing about it.
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
const ADDRESS_STEP = 'view/frontend/web/js/view/address-autocomplete.js';

const SHIPPING_FORM = '#shipping-new-address-form';
const BILLING_FORM = '[data-form="billing-new-address"]';
const TILE_FIELD = '#two_gateway_form input#company_name';
const FORMS = { shipping: SHIPPING_FORM, billing: BILLING_FORM };
const FIELDS = {
    shipping: `${SHIPPING_FORM} input[name="company"]`,
    billing: `${BILLING_FORM} input[name="company"]`
};
const COUNTRIES = {
    shipping: `${SHIPPING_FORM} select[name="country_id"]`,
    billing: `${BILLING_FORM} select[name="country_id"]`
};

/** The address fields a country switch retracts. */
const RETRACTED_FIELDS = ['street[0]', 'street[1]', 'city', 'postcode'];

/** The other panel, for a table row naming one. */
const OTHER = { shipping: 'billing', billing: 'shipping' };

const GLOBALS = { document: document, window: window };

/** Seeded so availability answers from the config memo instead of `fetch`. */
const SUPPORTED_COMPANY_TYPES = { gb: ['SOLE_TRADER'], no: ['SOLE_TRADER'], se: [], us: [] };

const COMPANIES = {
    shipping: { text: 'Shipping Co', companyId: '111', lookupId: 'l1' },
    billing: { text: 'Billing Co', companyId: '222', lookupId: 'l2' }
};

const ADDRESSES = {
    shipping: { city: 'Ashford', postal_code: 'TN23 1AA', street_address: '1 Shipping Street' },
    billing: { city: 'Bristol', postal_code: 'BS1 4DJ', street_address: '2 Billing Street' }
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
        `<div data-form="billing-new-address"${options.billingHidden ? ' data-test-hidden' : ''}>` +
        addressFields(options.billingCountry) +
        '</div>' +
        '</div>' +
        '<form id="two_gateway_form"><input id="company_name" name="company_name" /></form>';
}

/**
 * Both panels booted over the real modules, plus the real address step.
 *
 * @param {object} [options] `{ shippingForm, shippingCountry, billingCountry,
 *        billingHidden, isVirtual, quoteBillingAddress }`
 * @returns {object} `{ capture, search, panels, identities, addressStep, mocks }`
 */
function boot(options) {
    const opts = Object.assign({ shippingCountry: 'GB', billingCountry: 'NO' }, options || {});
    renderCheckout(opts);
    installAsyncSimulation($);
    // jsdom has no layout, so jQuery's `:visible` answers false for every
    // element. The billing panel's mount and the resolver's "billing is a
    // distinct address" test both ask for it, so it is answered off the
    // fixture's own markup instead.
    $.expr.pseudos.visible = function (elem) {
        return !elem.hasAttribute('data-test-hidden')
            && !(elem.closest && elem.closest('[data-test-hidden]'));
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

    const quote = Object.assign(
        {},
        defaultMocks()['Magento_Checkout/js/model/quote'],
        {
            billingAddress: function () {
                return opts.quoteBillingAddress || { countryId: 'GB' };
            },
            shippingAddress: function () { return null; },
            isVirtual: function () { return !!opts.isVirtual; }
        }
    );

    const mocks = {
        jquery: $,
        'Magento_Checkout/js/model/quote': quote,
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
        quote: quote,
        mocks: mocks,
        panels: { shipping: capture.shipping, billing: capture.billing },
        identities: {
            shipping: capture.shipping.identity(),
            billing: capture.billing.identity()
        }
    };
}

/**
 * The real address step, wired to the same capture instance. Its identity
 * watcher is the production route by which a shipping-step pick propagates.
 *
 * @param {object} booted
 * @returns {object} the address-step view model
 */
function bootAddressStep(booted) {
    const step = loadAmdModule(ADDRESS_STEP, Object.assign({}, defaultMocks(), booted.mocks, {
        'Two_Gateway/js/model/company-capture': booted.capture
    }), GLOBALS);
    step._super = function () {};
    step.initialize();
    return step;
}

/**
 * The real payment renderer, wired to the same capture instance.
 *
 * @param {object} booted
 * @returns {object} the renderer view model
 */
function bootRenderer(booted) {
    const renderer = loadAmdModule(RENDERER, Object.assign({}, defaultMocks(), booted.mocks, {
        'Two_Gateway/js/model/company-capture': booted.capture
    }), GLOBALS);
    renderer.getCode = function () { return 'two_payment'; };
    renderer.isOrderIntentEnabled = false;
    return renderer;
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

/** @returns {string} the organisation number in one panel's own form */
function organisationNumber(which) {
    return document.querySelector(
        `${FORMS[which]} input[name="custom_attributes[company_id]"]`
    ).value;
}

/** The buyer's own country switch in one panel's form. */
function switchCountry(which, iso) {
    $(COUNTRIES[which]).val(iso).trigger('change');
}

/** @returns {object} every retractable address field of one form, by name */
function addressValues(which) {
    const root = document.querySelector(FORMS[which]);
    const values = {};
    RETRACTED_FIELDS.forEach(function (name) {
        values[name] = root.querySelector(`[name="${name}"]`).value;
    });
    return values;
}

/**
 * Let the address step's identity watcher run: it publishes on a `setTimeout(0)`
 * so a name and a number written back to back go out together. Without this
 * flush every assertion about what it did — or did not — propagate is vacuous.
 *
 * @returns {Promise}
 */
function flushAddressStep() {
    return new Promise(function (resolve) { setTimeout(resolve, 0); });
}

/** The baseline `view/address-autocomplete.js` takes at the form's own render. */
function captureBillingBaseline(search) {
    search.captureSecondaryAddressBaseline(document.querySelector(BILLING_FORM));
}

beforeEach(() => {
    document.body.innerHTML = '';
    // The watchers are delegated off the document, which outlives a test;
    // without this every earlier boot's panels would still be listening.
    $(document).off('.twoCompanyCapture');
    $(document).off('.twoCompanySourceResolver');
    $(document).off('.twoCompanyCaptureMount');
    $(document).off('.twoAddressCompanyId');
});

describe('a registered pick lands on one panel only', () => {
    test.each(DIRECTIONS)('%s picks (%s)', async (actor, description) => {
        const other = OTHER[actor];
        const booted = boot();
        // The address step is what propagated a shipping-step company onto
        // every billing address while billing had no picker of its own.
        bootAddressStep(booted);

        picks(booted.panels[actor], COMPANIES[actor]);
        await flushAddressStep();

        expect(booted.identities[actor].companyId()).toBe(COMPANIES[actor].companyId, description);
        expect(displayed(actor)).toBe(COMPANIES[actor].text);
        expect(booted.identities[other].companyId()).toBe('');
        expect(booted.identities[other].companyName()).toBe('');
        expect(displayed(other)).toBe('');
        expect(organisationNumber(other)).toBe('');
    });

    test.each(DIRECTIONS)('%s picks, and the other panel\'s notice stands (%s)', async (actor, description) => {
        const other = OTHER[actor];
        const booted = boot({ billingHidden: actor === 'shipping' });
        bootAddressStep(booted);
        booted.identities[other].addressNotice('We could not fetch this address.');

        picks(booted.panels[actor], COMPANIES[actor]);
        await flushAddressStep();

        expect(booted.identities[other].addressNotice())
            .toBe('We could not fetch this address.', description);
    });

    test('a shipping pick never reaches a billing form whose panel is not mounted', async () => {
        // The state the old ownership gate opened in: the billing fieldset is in
        // the DOM (Amasty pre-renders it; core leaves it there once "same as
        // shipping" is re-checked) with no live panel on it, and the shipping
        // step then mirrored its own company and organisation number into it.
        const booted = boot({ billingHidden: true });
        bootAddressStep(booted);
        expect(booted.panels.billing.mountSelector()).toBe('');

        picks(booted.panels.shipping, COMPANIES.shipping);
        await flushAddressStep();

        expect(displayed('billing')).toBe('');
        expect(organisationNumber('billing')).toBe('');
    });
});

describe('a sole-trader adoption lands on one panel only', () => {
    test.each(DIRECTIONS)('%s adopts (%s)', async (actor, description) => {
        const other = OTHER[actor];
        const booted = boot({ billingHidden: actor === 'shipping' });
        bootAddressStep(booted);

        booted.panels[actor].adoptSoleTrader(BUYERS[actor]);
        await flushAddressStep();

        expect(booted.identities[actor].soleTraderAdopted()).toBe(true, description);
        expect(displayed(actor)).toBe(BUYERS[actor].company_name);
        expect(booted.identities[other].soleTraderAdopted()).toBe(false);
        expect(booted.identities[other].companyName()).toBe('');
        expect(displayed(other)).toBe('');
        expect(organisationNumber(other)).toBe('');
    });

    test.each(DIRECTIONS)(
        'the tile acts on the panel that actually adopted (%s — %s)',
        (actor, description) => {
            // `soleTraderOwner()` is the REAL one here: the tile's link is
            // rendered off the resolved adoption, which either panel can hold.
            const booted = boot();
            const renderer = bootRenderer(booted);
            const acted = [];
            booted.panels[actor].soleTrader().selectDifferentSoleTrader =
                function () { acted.push(actor); return 'popup'; };
            booted.panels[OTHER[actor]].soleTrader().selectDifferentSoleTrader =
                function () { acted.push(OTHER[actor]); return 'popup'; };

            booted.panels[actor].adoptSoleTrader(BUYERS[actor]);
            renderer.selectDifferentSoleTrader();

            expect(acted).toEqual([actor], description);
        }
    );

    test('with both panels adopted the BILLING panel owns the link', () => {
        // The documented precedence, asserted against the real resolver rather
        // than a stub that answers whatever it was told.
        const booted = boot();
        booted.panels.shipping.adoptSoleTrader(BUYERS.shipping);
        booted.panels.billing.adoptSoleTrader(BUYERS.billing);

        expect(booted.capture.soleTraderOwner()).toBe(booted.panels.billing);
    });

    test('with neither panel adopted there is no owner', () => {
        const booted = boot();

        expect(booted.capture.soleTraderOwner()).toBeNull();
    });
});

describe('a country switch invalidates its own panel\'s company and nothing else', () => {
    test.each(DIRECTIONS)('%s switches country (%s)', (actor, description) => {
        const other = OTHER[actor];
        const booted = boot();
        bootAddressStep(booted);
        picks(booted.panels.shipping, COMPANIES.shipping);
        picks(booted.panels.billing, COMPANIES.billing);

        switchCountry(actor, 'SE');

        expect(booted.identities[actor].companyId()).toBe('', description);
        expect(booted.identities[other].companyId()).toBe(COMPANIES[other].companyId);
        expect(displayed(other)).toBe(COMPANIES[other].text);
    });

    test.each(DIRECTIONS)(
        '%s switches country and the OTHER form\'s address fields stand (%s)',
        (actor, description) => {
            // The live Fire-checkout report: changing the BILLING country
            // cleared the SHIPPING address fields, because the retraction was
            // page-wide rather than scoped to the panel that asked for it. The
            // mirror image was live too, on Luma.
            const other = OTHER[actor];
            const booted = boot();
            bootAddressStep(booted);
            captureBillingBaseline(booted.search);
            // Each panel picks, which is what puts an address in its own form
            // and — for the billing form — what pins it.
            picks(booted.panels.shipping, COMPANIES.shipping);
            picks(booted.panels.billing, COMPANIES.billing);
            booted.search.applyAddress(ADDRESSES.shipping, $(SHIPPING_FORM));
            booted.search.applyAddress(ADDRESSES.billing, $(BILLING_FORM));
            const before = addressValues(other);
            const beforeCountry = document.querySelector(COUNTRIES[other]).value;
            expect(before['city']).not.toBe('');

            switchCountry(actor, 'SE');

            expect(addressValues(other)).toEqual(before, description);
            expect(document.querySelector(COUNTRIES[other]).value).toBe(beforeCountry);
        }
    );

    test.each(DIRECTIONS)(
        '%s switches its OWN country and its OWN company and address go (%s)',
        (actor, description) => {
            // Live-verified pre-fix: the BILLING panel did not react to its own
            // country select at all — its company and address survived a switch
            // the shipping panel handled correctly. Not a cross-panel bug: a
            // panel failing to react to its own input.
            const booted = boot();
            bootAddressStep(booted);
            captureBillingBaseline(booted.search);
            picks(booted.panels[actor], COMPANIES[actor]);
            booted.search.applyAddress(ADDRESSES[actor], $(FORMS[actor]));
            expect(addressValues(actor)['city']).toBe(ADDRESSES[actor].city);

            switchCountry(actor, 'SE');

            expect(booted.identities[actor].companyId()).toBe('', description);
            expect(booted.identities[actor].companyName()).toBe('');
            expect(addressValues(actor)['city']).toBe('');
            expect(addressValues(actor)['postcode']).toBe('');
        }
    );

    test('shipping switches country while the billing panel is unmounted', () => {
        // The billing panel keeps its capture through core re-checking "same as
        // shipping" and hiding its fieldset. Unmounted it has no country select
        // to answer for, and one shared delegation then handed it the SHIPPING
        // form's switch as though the buyer had made it there.
        const booted = boot();
        picks(booted.panels.billing, COMPANIES.billing);
        document.querySelector(FIELDS.billing).setAttribute('data-test-hidden', '');
        booted.panels.billing.refreshMount();
        expect(booted.panels.billing.mountSelector()).toBe('');

        switchCountry('shipping', 'SE');

        expect(booted.identities.billing.companyId()).toBe(COMPANIES.billing.companyId);
    });

    test('billing switches country while the shipping panel is on the tile', () => {
        // With no shipping form on the checkout the shipping panel mounts on the
        // payment tile, which has no country select — so it read the billing
        // form's, and a billing country switch invalidated a company the buyer
        // had picked on the tile.
        const booted = boot({ shippingForm: false });
        expect(booted.panels.shipping.mountSelector()).toBe(TILE_FIELD);
        picks(booted.panels.shipping, COMPANIES.shipping);

        switchCountry('billing', 'SE');

        expect(booted.identities.shipping.companyId()).toBe(COMPANIES.shipping.companyId);
    });
});

describe('a tile-mounted shipping panel has no form of its own', () => {
    test('its country switch retracts nothing from the billing form', () => {
        // The only address form on this checkout is the BILLING panel's, and
        // borrowing it as a write root landed shipping's retraction in the
        // other panel's fields. The address and telephone halves of the same
        // borrowing are pinned in gateway-method-sole-trader-address-writeback
        // and company-search-address-lookup.
        const booted = boot({ shippingForm: false });
        booted.search.applyAddress(ADDRESSES.billing, $(BILLING_FORM));
        const before = addressValues('billing');
        expect(before['city']).toBe(ADDRESSES.billing.city);

        // Two switches: the first is the panel's own first resolution, which
        // deliberately retracts nothing.
        booted.panels.shipping.onCountryChanged('gb');
        booted.panels.shipping.onCountryChanged('us');

        expect(addressValues('billing')).toEqual(before);
    });
});

describe('the quote\'s billing address belongs to the billing panel', () => {
    const SAVED = {
        countryId: 'GB',
        telephone: '+44 20 7946 0000',
        customAttributes: [
            { attribute_code: 'company_name', value: 'Saved Billing Co' },
            { attribute_code: 'company_id', value: '555' }
        ],
        getCacheKey: function () { return 'billing'; }
    };

    test.each([
        [false, 'a physical cart'],
        [true, 'a virtual cart, where it is the buyer\'s only address']
    ])('its company seeds the BILLING identity, never shipping\'s (%p — %s)', (isVirtual, description) => {
        // Relaying it through the shipping identity painted the buyer's billing
        // company into the shipping form (TWO-25554). A virtual cart has no
        // shipping address at all, so the billing panel is the only panel that
        // can speak for it.
        const booted = boot({ isVirtual: isVirtual, quoteBillingAddress: SAVED });
        const renderer = bootRenderer(booted);

        renderer.updateBillingAddress(SAVED);

        expect(booted.identities.billing.companyId()).toBe('555', description);
        expect(booted.identities.billing.companyName()).toBe('Saved Billing Co');
        expect(booted.identities.shipping.companyId()).toBe('');
        expect(booted.identities.shipping.companyName()).toBe('');
        expect(renderer.telephone()).toBe('+4420 7946 0000');
    });

    test('a billing address with no shipping panel mounted still leaves shipping empty', () => {
        const booted = boot({ shippingForm: false, quoteBillingAddress: SAVED });
        const renderer = bootRenderer(booted);

        renderer.updateBillingAddress(SAVED);

        expect(booted.identities.shipping.companyName()).toBe('');
        expect(document.querySelector(TILE_FIELD).value).toBe('');
    });
});

describe('the address prefill stops at a billing address the buyer has answered', () => {
    test('a pristine billing address still takes the shipping country', () => {
        const booted = boot();
        captureBillingBaseline(booted.search);

        expect(booted.search.mirrorCountryToSecondaryAddresses()).toBe(1);
    });

    test('a billing capture pins the billing address against it', () => {
        // TWO-25461's own rule — pin once the buyer edits it — applied to a
        // company pick as an edit.
        const booted = boot();
        captureBillingBaseline(booted.search);

        picks(booted.panels.billing, COMPANIES.billing);

        expect(booted.search.secondaryAddressIsPinned(document.querySelector(BILLING_FORM)))
            .toBe(true);
        expect(booted.search.mirrorCountryToSecondaryAddresses()).toBe(0);
    });

    test('a mirrored country does not invalidate the billing panel\'s own capture', () => {
        // The mirror writes with a `change`, because Knockout's `value:` binding
        // reads the DOM on nothing else, and that event is indistinguishable at
        // the listener from a buyer's own switch. The pin is what stops it: a
        // captured billing address is never written into in the first place.
        const booted = boot();
        captureBillingBaseline(booted.search);
        picks(booted.panels.billing, COMPANIES.billing);

        expect(booted.search.mirrorCountryToSecondaryAddresses()).toBe(0);

        expect(document.querySelector(COUNTRIES.billing).value).toBe('NO');
        expect(booted.identities.billing.companyId()).toBe(COMPANIES.billing.companyId);
    });
});

describe('the payment tile writes only the panel it is mounted at', () => {
    test('the tile displays the panel MOUNTED there, not the resolved company', () => {
        // Bound to the resolved identity, the tile showed the billing panel's
        // capture in the shipping panel's own field (TWO-25554).
        const booted = boot({ shippingForm: false });
        const renderer = bootRenderer(booted);
        picks(booted.panels.billing, COMPANIES.billing);

        expect(renderer.companyName()).toBe(COMPANIES.billing.text);
        expect(renderer.tileCompanyName()).toBe('');
    });

    test.each([
        [true, 'billing has resolved'],
        [false, 'the shipping panel has resolved']
    ])('typing in the tile writes the shipping identity, whether or not %p (%s)', (billingWins, description) => {
        const booted = boot({ shippingForm: false });
        const renderer = bootRenderer(booted);
        if (billingWins) picks(booted.panels.billing, COMPANIES.billing);

        renderer.tileCompanyName('Typed By Hand');

        expect(booted.identities.shipping.companyName()).toBe('Typed By Hand', description);
        expect(booted.identities.billing.companyName())
            .toBe(billingWins ? COMPANIES.billing.text : '');
    });
});
