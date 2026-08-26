/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326 / TWO-25461 §1(a.3): which country the company search and the
 * sole-trader registry run against.
 *
 * The reported failure was Fire Checkout only: the search ran against the API's
 * own default country whatever the buyer had selected. It is a SOURCING gap
 * rather than a hardcoded country — the only feed was a customer-data section
 * written by `address-autocomplete.js` once `#shipping-new-address-form`
 * resolves, and a one-page checkout that supplies its own address markup
 * matches no such selector, so the country reaching the search URL was empty.
 *
 * Resolution order, and both halves matter:
 *
 *  1. the quote's BILLING address — the tile has no address fields of its own
 *     and captures as the invoice role, so a buyer shipping to one country and
 *     invoicing in another must search the one they invoice in;
 *  2. the buyer's live address-form `<select>`, via a priority list of
 *     selectors ending in a catch-all — the fallback for the window before the
 *     quote holds an address at all, which is the TWO-25326 state.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const COMPONENT = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';

/**
 * Every country these fixtures resolve to, seeded so the availability lookup
 * answers from the config memo instead of reaching for `fetch`.
 */
const SUPPORTED_COMPANY_TYPES = {
    dk: [], es: [], gb: [], nl: [], no: [], se: [], us: []
};

/** The REAL company-search module, closed over real jQuery and the real document. */
function loadCompanySearch() {
    return loadAmdModule(SEARCH, { jquery: $ }, { document: document, window: window });
}

/**
 * Load the capture component and its identity singleton fresh.
 *
 * Fresh per test on purpose — both are page-level singletons, so a shared load
 * would carry one case's country and captured company into the next.
 *
 * @param {object} [options] `{ billingCountry, isVirtual, companySearch }`
 * @returns {object} `{ component, identity, companySearch, control }`
 */
function load(options) {
    const opts = options || {};
    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });
    const companySearch = opts.companySearch || loadCompanySearch();
    const panel = { constructed: [], binds: 0, aborts: 0 };

    function PanelStub(panelOptions) {
        panel.constructed.push(panelOptions);
        this.bind = function () { panel.binds += 1; };
        this.destroy = function () { return true; };
        this.abortActiveRequest = function () { panel.aborts += 1; };
        this.isBound = function () { return panel.binds > 0; };
        this.getField = function () { return $(); };
        this.close = function () {};
        this.syncChips = function () {};
        this.setDisplayText = function () {};
        this.releaseField = function () {};
        this.reclaimField = function () {};
    }
    function SoleTraderStub() {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.launchSignup = function () { return null; };
        this.forgetAdoptions = function () { panel.adoptionsForgotten = true; };
    }

    const billing = 'billingCountry' in opts ? opts.billingCountry : null;
    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: function () {
            return billing === null ? null : { countryId: billing };
        },
        isVirtual: function () { return !!opts.isVirtual; }
    });

    const component = loadAmdModule(
        COMPONENT,
        {
            jquery: $,
            'Magento_Checkout/js/model/quote': quote,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-panel': PanelStub,
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandConfig: function () {
                    return {
                        isCompanySearchEnabled: opts.isCompanySearchEnabled !== false,
                        checkoutApiUrl: 'https://api.example.test',
                        checkoutPageUrl: 'https://checkout.example.test',
                        companySearchLimit: 10,
                        supportedCompanyTypes: SUPPORTED_COMPANY_TYPES
                    };
                }
            }
        },
        { document: document, window: window }
    );

    return {
        component: component,
        identity: identity,
        companySearch: companySearch,
        panel: panel
    };
}

/**
 * Run `body` with `$.ajax` swapped for a recorder, and hand back what it asked
 * for. Real jQuery is what the module under test closes over, so the request
 * has to be intercepted at the jQuery it actually calls.
 *
 * @param {Function} body
 * @returns {Array<object>} the `$.ajax` option objects, in order
 */
function captureAjax(body) {
    const requested = [];
    const original = $.ajax;
    $.ajax = function (options) {
        requested.push(options);
        const handle = {
            done: function () { return handle; },
            fail: function () { return handle; },
            always: function () { return handle; },
            abort: function () {}
        };
        return handle;
    };
    try {
        body();
    } finally {
        $.ajax = original;
    }
    return requested;
}

/** An address form whose country select is the only country source on the page. */
function mountAddressForm(markup) {
    document.body.innerHTML = markup;
}

function selectMarkup(formId, country) {
    return (
        `<form id="${formId}">` +
        `<select name="country_id"><option value="${country}" selected>${country}</option>` +
        '</select></form>'
    );
}

beforeEach(() => {
    document.body.innerHTML = '';
    // The watcher is delegated off the document, which outlives a test; without
    // this every earlier test's component would still be listening.
    $(document).off('.twoCompanyCapture');
});

describe('the DOM fallback reads the country the buyer actually selected', () => {
    test('a one-page checkout matching no `#shipping-new-address-form` selector still resolves', () => {
        // The shape the bug was reported on: the core form id is absent, so the
        // address-area component never mounts and never publishes a country.
        mountAddressForm(
            '<div id="firecheckout-address">' +
            '<select name="country_id"><option value="US">US</option>' +
            '<option value="NO" selected>NO</option></select></div>'
        );

        expect(loadCompanySearch().currentAddressFormCountry()).toBe('no');
        expect(load({ billingCountry: null }).component.countryCode()).toBe('no');
    });

    test('the core form\'s select is the highest-priority DOM source', () => {
        // A second select carrying a different country — a per-payment-method
        // billing form the buyer has not touched. The core shipping form must
        // win, or an untouched form's store default decides the search.
        mountAddressForm(
            selectMarkup('shipping-new-address-form', 'SE') +
            selectMarkup('billing-new-address-form', 'US')
        );

        expect(loadCompanySearch().currentAddressFormCountry()).toBe('se');
    });

    test('a select with no value chosen contributes nothing', () => {
        mountAddressForm(
            '<form id="shipping-new-address-form">' +
            '<select name="country_id"><option value="" selected></option></select></form>'
        );

        expect(loadCompanySearch().currentAddressFormCountry()).toBe('');
        expect(load({ billingCountry: null }).component.countryCode()).toBe('');
    });

    test('the resolved country is what reaches the search URL', () => {
        // Through a real searchCompanies() call, so the assertion covers the
        // whole path from the component's getter to the wire, not the getter.
        mountAddressForm(
            '<div id="firecheckout-address">' +
            '<select name="country_id"><option value="GB" selected>GB</option></select></div>'
        );
        const companySearch = loadCompanySearch();
        const { component } = load({ billingCountry: null, companySearch: companySearch });

        const requested = captureAjax(function () {
            companySearch.searchCompanies({
                config: { checkoutApiUrl: 'https://api.example.test', companySearchLimit: 10 },
                token: {},
                term: 'acme',
                getCountryCode: function () { return component.countryCode(); }
            });
        });

        expect(requested[0].url).toContain('country=GB');
        expect(requested[0].url).not.toContain('country=&');
    });
});

describe('the quote\'s BILLING address is preferred to the live DOM (TWO-25461 §1(a.3))', () => {
    test.each([
        ['NO', 'GB', 'no', 'billing beats the shipping form the buyer is looking at'],
        ['no', 'GB', 'no', 'an already-lower-cased billing country is unchanged'],
        [null, 'SE', 'se', 'no billing address yet: the DOM fallback stands'],
        ['', 'SE', 'se', 'an empty billing country is not an answer'],
        [null, '', '', 'neither source has anything: no country, rather than a wrong one']
    ])('billing=%p dom=%p -> %p (%s)', (billingCountry, domCountry, expected) => {
        // A shipping form whose country DIFFERS from the billing address: a
        // buyer shipping to one country and invoicing in another is ordinary,
        // and this country gates both the search and sole-trader availability.
        mountAddressForm(domCountry ? selectMarkup('shipping-new-address-form', domCountry) : '');

        expect(load({ billingCountry: billingCountry }).component.countryCode()).toBe(expected);
    });

    test('an UNTOUCHED hidden select can never beat the quote — the Luma/Amasty non-regression', () => {
        // Core renders `#shipping-new-address-form` inside the hidden
        // new-address modal for a customer with saved addresses: the select
        // exists, holds the store default, and the buyer has never seen it.
        mountAddressForm(
            '<div id="opc-new-shipping-address" style="display:none">' +
            selectMarkup('shipping-new-address-form', 'US') +
            '</div>'
        );

        expect(loadCompanySearch().currentAddressFormCountry()).toBe('us');
        expect(load({ billingCountry: 'NO' }).component.countryCode()).toBe('no');
    });

    test('a null billing address does not take the search down with it', () => {
        // The observable is legitimately null for a transient window — the same
        // window placeOrderIntent() carries its own guard for.
        mountAddressForm('');

        expect(load({ billingCountry: null }).component.countryCode()).toBe('');
    });
});

describe('the country watcher is delegated, and reads the buyer\'s own selection', () => {
    test('one document-level delegation covers a form that re-renders', () => {
        mountAddressForm(selectMarkup('shipping-new-address-form', 'GB'));
        const { component } = load({ billingCountry: 'GB' });
        component.start();

        const observed = [];
        component.onCountryChanged = function (country) { observed.push(country); };

        // The form is replaced wholesale, as every checkout re-render does, and
        // the delegation still reaches the new node with no re-binding.
        mountAddressForm(selectMarkup('shipping-new-address-form', 'GB'));
        $('select[name="country_id"]').val('').append('<option value="NO">NO</option>');
        $('select[name="country_id"]').val('NO').trigger('change');

        expect(observed).toEqual(['no']);
    });

    test('the change is read off the DOM, not the quote that still holds the old country', () => {
        // The whole reason onCountryChanged() takes an argument: core saves the
        // address asynchronously, so at the moment of the `change` the quote
        // still holds the country the buyer just left — and a change detected
        // off it would read as no change at all and skip the TWO-24867 clear.
        mountAddressForm(
            '<form id="shipping-new-address-form"><select name="country_id">' +
            '<option value="GB" selected>GB</option><option value="ES">ES</option>' +
            '</select></form>'
        );
        const { component } = load({ billingCountry: 'GB' });
        component.start();

        const observed = [];
        component.onCountryChanged = function (country) { observed.push(country); };

        $('select[name="country_id"]').val('ES').trigger('change');

        expect(component.countryCode()).toBe('gb');
        expect(observed).toEqual(['es']);
    });

    test('starting twice does not stack a second watcher', () => {
        mountAddressForm(selectMarkup('shipping-new-address-form', 'GB'));
        const { component } = load({ billingCountry: 'GB' });
        component.start();
        component.start();

        let fired = 0;
        component.onCountryChanged = function () { fired += 1; };
        $('select[name="country_id"]').trigger('change');

        expect(fired).toBe(1);
    });
});

describe('the panel the component constructs resolves the country per request', () => {
    test('getCountryCode answers the buyer\'s selection, not a value frozen at bind time', () => {
        // End to end through the mount, not a direct call to the getter: the
        // wiring is the part that regressed, and a spec calling countryCode()
        // itself would pass against a panel constructed with a stale value.
        mountAddressForm(
            '<div id="firecheckout-address"><select name="country_id">' +
            '<option value="NO" selected>NO</option><option value="SE">SE</option>' +
            '</select></div>' +
            '<form id="two_gateway_form"><input id="company_name" name="company_name" /></form>'
        );
        const { component, panel } = load({ billingCountry: null });
        component.start();

        expect(panel.constructed).toHaveLength(1);
        const getCountryCode = panel.constructed[0].getCountryCode;
        expect(getCountryCode()).toBe('no');

        $('#firecheckout-address select[name="country_id"]').val('SE');

        expect(getCountryCode()).toBe('se');
    });
});
