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
 * matches no such selector, so the country reaching the search request was empty.
 *
 * Where the control is mounted decides which address form answers for it:
 *
 *  1. mounted in the address form — the country selector in that same form,
 *     which is the only thing that can answer "what has the buyer chosen";
 *  2. mounted on the payment tile, which has no address fields of its own —
 *     still the shipping form's own selector, never the billing form's, which
 *     belongs to the billing panel (TWO-25554);
 *  3. no shipping form with a country selector at all — the quote's billing
 *     address, and nothing else. There is no document-wide DOM read: it
 *     answered for whichever form the page offered first, which is the other
 *     panel's as often as not (TWO-25554).
 */

'use strict';

const $ = require('jquery');
const {
    loadAmdModule,
    defaultMocks,
    loadCompanyCapture,
    brandConfigMock,
    tagged
} = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';

/**
 * Every country these fixtures resolve to, seeded so the availability lookup
 * answers from the config memo instead of reaching for `fetch`.
 */
const SUPPORTED_COMPANY_TYPES = {
    dk: [], es: [], gb: [], nl: [], no: ['SOLE_TRADER'], se: [], us: []
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
        this.unmount = function () {};
    }
    function SoleTraderStub() {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.focusSignupPopup = function () { return false; };
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

    const component = loadCompanyCapture(
        {
            jquery: $,
            'Magento_Checkout/js/model/quote': quote,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-panel': PanelStub,
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': brandConfigMock({
                isCompanySearchEnabled: opts.isCompanySearchEnabled !== false,
                checkoutApiUrl: 'https://api.example.test',
                checkoutPageUrl: 'https://checkout.example.test',
                companySearchLimit: 10,
                supportedCompanyTypes: SUPPORTED_COMPANY_TYPES
            })
        },
        { document: document, window: window }
    ).shipping;

    return {
        component: component,
        identity: component.identity(),
        companySearch: companySearch,
        panel: panel
    };
}

/**
 * Record every country the delegation reports, still running the real handler.
 *
 * Installed BEFORE `start()`, which binds the handler once and holds that
 * reference — a later replacement would never be reached. The log is cleared
 * after boot so only the buyer's own changes are asserted on.
 *
 * @param {object} component
 * @returns {Array<string>} live, in order
 */
function recordCountryReports(component) {
    const reports = [];
    const real = component.onCountryChanged.bind(component);
    component.onCountryChanged = function (country) {
        reports.push(country);
        return real(country);
    };
    return reports;
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

describe('a country read is scoped to ONE form, never document-wide (TWO-25554)', () => {
    test.each([
        ['shipping-new-address-form', 'se'],
        ['billing-new-address-form', 'us']
    ])('a read scoped to #%s answers %s, and nothing else', (formId, expected) => {
        // A document-wide read answered for whichever form the page offered
        // first, which is the other panel's as often as not.
        mountAddressForm(
            selectMarkup('shipping-new-address-form', 'SE') +
            selectMarkup('billing-new-address-form', 'US')
        );
        const companySearch = loadCompanySearch();

        expect(companySearch.currentAddressFormCountry($('#' + formId))).toBe(expected);
    });

    test.each([
        [undefined, 'no argument at all'],
        [null, 'an explicit null'],
        [$(), 'an empty set — a panel whose form is not on this checkout']
    ])('a read with no form answers nothing (%p — %s)', (root, description) => {
        mountAddressForm(
            '<div id="firecheckout-address">' +
            '<select name="country_id"><option value="NO" selected>NO</option></select></div>'
        );

        expect(tagged(description, loadCompanySearch().currentAddressFormCountry(root)))
            .toEqual(tagged(description, ''));
    });

    test('a one-page checkout supplying its own address markup falls to the quote', () => {
        // The shape TWO-25326 was reported on: the core form id is absent, so
        // nothing on the page is the shipping panel's own form. The quote's
        // billing country is the answer — never a stray select belonging to
        // whatever else the checkout rendered.
        mountAddressForm(
            '<div id="firecheckout-address">' +
            '<select name="country_id"><option value="NO" selected>NO</option></select></div>'
        );

        expect(load({ billingCountry: 'GB' }).component.countryCode()).toBe('gb');
        expect(load({ billingCountry: null }).component.countryCode()).toBe('');
    });

    test('a select with no value chosen contributes nothing', () => {
        mountAddressForm(
            '<form id="shipping-new-address-form">' +
            '<select name="country_id"><option value="" selected></option></select></form>'
        );

        expect(
            loadCompanySearch().currentAddressFormCountry($('#shipping-new-address-form'))
        ).toBe('');
        expect(load({ billingCountry: null }).component.countryCode()).toBe('');
    });

    test('the resolved country is what reaches the search request', () => {
        // Through a real searchCompanies() call, so the assertion covers the
        // whole path from the component's getter to the wire, not the getter.
        mountAddressForm(
            '<form id="shipping-new-address-form"><input name="company" />' +
            '<select name="country_id"><option value="GB" selected>GB</option></select></form>'
        );
        const companySearch = loadCompanySearch();
        const { component } = load({ billingCountry: null, companySearch: companySearch });

        const requested = captureAjax(function () {
            companySearch.searchCompanies({
                config: { checkoutApiUrl: 'https://api.example.test' },
                token: {},
                scope: {},
                term: 'acme',
                getCountryCode: function () { return component.countryCode(); }
            });
        });

        expect(JSON.parse(requested[0].data).country).toBe('GB');
    });
});

describe('with no control mounted, the quote\'s BILLING address decides (TWO-25461 §1(a.3))', () => {
    test.each([
        ['NO', 'GB', 'no', 'billing beats a country select the control is not mounted beside'],
        ['no', 'GB', 'no', 'an already-lower-cased billing country is unchanged'],
        [null, 'SE', 'se', 'no billing address yet: the DOM fallback stands'],
        ['', 'SE', 'se', 'an empty billing country is not an answer'],
        [null, '', '', 'neither source has anything: no country, rather than a wrong one']
    ])('billing=%p dom=%p -> %p (%s)', (billingCountry, domCountry, expected) => {
        // No company field in this markup, so there is no mount and no adjacent
        // selector — the state `start()` resolves a country in, before any host
        // has rendered.
        mountAddressForm(domCountry ? selectMarkup('shipping-new-address-form', domCountry) : '');

        expect(load({ billingCountry: billingCountry }).component.countryCode()).toBe(expected);
    });

    test.each([
        ['', 'no company field, so the control is not mounted there'],
        ['<input name="company" />', 'the company field core really renders inside that modal']
    ])('an UNTOUCHED hidden select never beats the quote — %s (%s)', (companyField) => {
        // Core renders `#shipping-new-address-form` inside the hidden
        // new-address modal for a customer with saved addresses: the select
        // exists, holds the store default, and the buyer has never seen it.
        mountAddressForm(
            '<div id="opc-new-shipping-address" style="display:none">' +
            '<form id="shipping-new-address-form">' + companyField +
            '<select name="country_id"><option value="US" selected>US</option></select>' +
            '</form></div>'
        );

        expect(
            loadCompanySearch().currentAddressFormCountry($('#shipping-new-address-form'))
        ).toBe('us');
        const { component } = load({ billingCountry: 'NO' });
        component.start();

        expect(component.countryCode()).toBe('no');
    });

    test('a null billing address does not take the search down with it', () => {
        // The observable is legitimately null for a transient window — the same
        // window placeOrderIntent() carries its own guard for.
        mountAddressForm('');

        expect(load({ billingCountry: null }).component.countryCode()).toBe('');
    });
});

describe('the country follows the selector adjacent to the mounted control', () => {
    /** The address step: core's company field and its country select, one form. */
    function addressMount(country) {
        return (
            '<form id="shipping-new-address-form"><input name="company" />' +
            '<select name="country_id">' +
            `<option value="${country}" selected>${country}</option>` +
            '<option value="NO">NO</option><option value="SE">SE</option>' +
            '</select></form>'
        );
    }

    /** The payment tile: a company field with no address fields near it. */
    const TILE_MOUNT =
        '<form id="two_gateway_form"><input id="company_name" name="company_name" /></form>';

    function markupFor(host, selectorCountry) {
        return (
            (host === 'tile' ? '' : addressMount(selectorCountry)) +
            (host === 'address' ? '' : TILE_MOUNT)
        );
    }

    test.each([
        ['address', 'SE', 'US', 'se', 'the buyer\'s selection, not the store default the quote carries'],
        ['both', 'SE', 'US', 'se', 'both hosts rendered: the address form is the mount, so its selector answers'],
        ['tile', '', 'NO', 'no', 'the tile has no adjacent selector, so the quote decides'],
        ['address', 'NO', null, 'no', 'no billing address at all: the adjacent selector still answers'],
        ['tile', '', null, '', 'the tile with nothing behind it: no country rather than a wrong one']
    ])('host=%p selector=%p billing=%p -> %p (%s)', (host, selectorCountry, billingCountry, expected) => {
        // No change event anywhere: this is what the component resolves on the
        // buyer's first sight of the page, which is where US was reaching the
        // search URL (TWO-25461).
        mountAddressForm(markupFor(host, selectorCountry));
        const { component } = load({ billingCountry: billingCountry });
        component.start();

        expect(component.countryCode()).toBe(expected);
    });

    test('a second switch tracks the buyer, and availability answers for the same country', async () => {
        // Two switches, because the first is also the component's first
        // resolution and takes a different branch from every one after it.
        mountAddressForm(markupFor('address', 'US'));
        const { component, identity } = load({ billingCountry: 'US' });
        component.start();

        $('select[name="country_id"]').val('SE').trigger('change');
        expect(component.countryCode()).toBe('se');

        $('select[name="country_id"]').val('NO').trigger('change');
        await component.refreshSoleTraderAvailability();

        expect(component.countryCode()).toBe('no');
        // NO is the only seeded country offering sole traders, so this is false
        // unless availability resolved for the country the search runs against.
        expect(identity.soleTraderAvailable()).toBe(true);
    });

    test('a country select the control is NOT mounted beside cannot decide the country', () => {
        // A per-payment-method billing form carrying the store default, firing
        // `change` as it renders. Treating that as the buyer's switch would
        // search US and clear the company they just picked (TWO-24867).
        mountAddressForm(
            markupFor('address', 'SE') + selectMarkup('billing-new-address-form', 'US')
        );
        const { component, identity } = load({ billingCountry: 'US' });
        component.start();
        identity.write({ companyName: 'Example AB', companyId: '5560000000' });

        $('#billing-new-address-form select[name="country_id"]').trigger('change');

        expect(component.countryCode()).toBe('se');
        expect(identity.companyId()).toBe('5560000000');
    });

    test('an address form carrying no country select at all falls back to the quote', () => {
        // A custom checkout that splits the two apart: there is nothing adjacent
        // to read, which is not the same as the buyer having chosen nothing.
        mountAddressForm('<form id="shipping-new-address-form"><input name="company" /></form>');
        const { component } = load({ billingCountry: 'NO' });
        component.start();

        expect(component.countryCode()).toBe('no');
    });

    test('with company search off in address entry the tile is the mount, and the shipping form answers', () => {
        // The buyer's only address form on the page is the shipping one, which
        // is their invoice address too while "same as shipping" stands.
        mountAddressForm(markupFor('both', 'SE'));
        const { component } = load({ billingCountry: 'NO', isCompanySearchEnabled: false });
        component.start();

        expect(component.countryCode()).toBe('se');
    });
});

describe('the shipping panel reads its OWN form, never the billing form (TWO-25554)', () => {
    /** Core's billing form: no id, matched on `data-form`, one per payment method. */
    function billingForm(country) {
        return (
            '<div data-form="billing-new-address">' +
            `<select name="country_id"><option value="${country}" selected>${country}</option>` +
            '</select></div>'
        );
    }

    /** The shipping form, with no company field, so the tile stays the mount. */
    function shippingForm(country) {
        return selectMarkup('shipping-new-address-form', country);
    }

    const TILE = '<form id="two_gateway_form"><input id="company_name" name="company_name" /></form>';

    test.each([
        [
            'GB', 'SE', 'US', 'se',
            'a billing form alongside cannot outrank the shipping panel\'s own form'
        ],
        [
            '', 'SE', 'US', 'se',
            'no billing form at all: unchanged, the shipping form answers'
        ],
        [
            'GB', '', 'US', 'us',
            'no shipping form to read: the quote, never the billing form beside it'
        ],
        [
            '', '', 'NO', 'no',
            'no address form with a country select at all: the quote is the last resort'
        ]
    ])(
        'billing=%p shipping=%p quote=%p -> %p (%s)',
        (billingFormCountry, shippingFormCountry, quoteCountry, expected) => {
            mountAddressForm(
                (billingFormCountry ? billingForm(billingFormCountry) : '') +
                (shippingFormCountry ? shippingForm(shippingFormCountry) : '') +
                TILE
            );
            const { component } = load({ billingCountry: quoteCountry });
            component.start();

            expect(component.countryCode()).toBe(expected);
        }
    );

    test.each([
        [false, 'no billing form to fall to'],
        [true, 'a billing form the buyer did open is still not shipping\'s to read']
    ])(
        'a shipping form inside the hidden new-address modal answers for nothing '
        + '(billing form: %p — %s)',
        (hasBilling) => {
            // Core renders it there, holding store defaults, for the whole of a
            // checkout completed against a saved address.
            mountAddressForm(
                (hasBilling ? billingForm('GB') : '') +
                '<div id="opc-new-shipping-address" style="display:none">' +
                shippingForm('US') +
                '</div>' + TILE
            );
            const { component } = load({ billingCountry: 'NO' });
            component.start();

            expect(component.countryCode()).toBe('no');
        }
    );

    test('unchecking "same as shipping" leaves the shipping panel\'s country alone', () => {
        // The switch the buyer makes on the payment step: a billing form simply
        // appears carrying a different country. Following it here invalidated
        // the company the buyer had already picked on the shipping step.
        mountAddressForm(shippingForm('SE') + TILE);
        const { component, identity } = load({ billingCountry: 'US' });
        component.start();
        identity.write({ companyName: 'Example AB', companyId: '5560000000' });

        $(document.body).prepend(billingForm('NO'));
        $('[data-form="billing-new-address"] select[name="country_id"]').trigger('change');

        expect(component.countryCode()).toBe('se');
        expect(identity.companyId()).toBe('5560000000');
    });

    test('the country reaching the search request is the shipping form\'s', () => {
        // Through a real searchCompanies() call: the getter is only half the
        // path, and the wire is what the buyer sees results for.
        mountAddressForm(billingForm('GB') + shippingForm('SE') + TILE);
        const companySearch = loadCompanySearch();
        const { component } = load({ billingCountry: 'US', companySearch: companySearch });
        component.start();

        const requested = captureAjax(function () {
            companySearch.searchCompanies({
                config: { checkoutApiUrl: 'https://api.example.test' },
                token: {},
                scope: {},
                term: 'acme',
                getCountryCode: function () { return component.countryCode(); }
            });
        });

        expect(JSON.parse(requested[0].data).country).toBe('SE');
    });
});

describe('the country watcher is delegated, and reads the buyer\'s own selection', () => {
    test('one document-level delegation covers a form that re-renders', () => {
        mountAddressForm(selectMarkup('shipping-new-address-form', 'GB'));
        const { component } = load({ billingCountry: 'GB' });
        const observed = recordCountryReports(component);
        component.start();
        observed.length = 0;

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
        const observed = recordCountryReports(component);
        component.start();
        observed.length = 0;

        $('select[name="country_id"]').val('ES').trigger('change');

        expect(component.countryCode()).toBe('gb');
        expect(observed).toEqual(['es']);
    });

    test('starting twice does not stack a second watcher', () => {
        mountAddressForm(selectMarkup('shipping-new-address-form', 'GB'));
        const { component } = load({ billingCountry: 'GB' });
        const observed = recordCountryReports(component);
        component.start();
        component.start();
        observed.length = 0;

        $('select[name="country_id"]').trigger('change');

        expect(observed).toHaveLength(1);
    });
});

describe('the panel the component constructs resolves the country per request', () => {
    test('getCountryCode answers the buyer\'s selection, not a value frozen at bind time', () => {
        // End to end through the mount, not a direct call to the getter: the
        // wiring is the part that regressed, and a spec calling countryCode()
        // itself would pass against a panel constructed with a stale value.
        mountAddressForm(
            '<form id="shipping-new-address-form"><input name="company" />' +
            '<select name="country_id">' +
            '<option value="NO" selected>NO</option><option value="SE">SE</option>' +
            '</select></form>' +
            '<form id="two_gateway_form"><input id="company_name" name="company_name" /></form>'
        );
        const { component, panel } = load({ billingCountry: null });
        component.start();

        expect(panel.constructed).toHaveLength(1);
        const getCountryCode = panel.constructed[0].getCountryCode;
        expect(getCountryCode()).toBe('no');

        $('#shipping-new-address-form select[name="country_id"]').val('SE');

        expect(getCountryCode()).toBe('se');
    });
});
