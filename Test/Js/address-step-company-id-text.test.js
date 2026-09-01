/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326 §5 and §7, address step (Luma / Amasty OneStepCheckout / Fire
 * Checkout — one code path).
 *
 * The captured organisation number must appear as PLAIN TEXT under the
 * company-name field once a search result has been selected, and must appear
 * in no other state:
 *
 *  - never before a selection ("not visible before a result is selected");
 *  - never in manual-entry mode, which is name-only capture, so a number
 *    rendered there would assert a registry identity the buyer never picked;
 *  - never as an editable control at any point.
 *
 * The separate `custom_attributes[company_id]` INPUT is a different object
 * and stays in the DOM (it still submits) — hidden by CSS, pinned by
 * address-step-company-id-hidden.test.js. This file is about the text label
 * that replaced it visually, and about its shape and position; that the label
 * belongs to one panel alone is company-panel-chrome.test.js.
 *
 * Backed by a real jsdom tree rather than a recording double: every
 * assertion here is about what is actually in the document and what it says,
 * which is the property that kept being claimed and kept not holding.
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
const NAME_SELECTOR = '#shipping-new-address-form input[name="company"]';
const ID_SELECTOR =
    '#shipping-new-address-form input[name="custom_attributes[company_id]"]';
const TEXT_CLASS = 'two-company-id-text';

const GLOBALS = { document: document, window: window };

/** The shipping panel booted over the real popover and the real search. */
function load() {
    document.body.innerHTML =
        '<form id="shipping-new-address-form">' +
        '<div class="field">' +
        '<div class="control">' +
        '<input name="company" type="text">' +
        '</div>' +
        '</div>' +
        '<div class="field two-company-id-hidden">' +
        '<div class="control">' +
        '<input name="custom_attributes[company_id]" type="text">' +
        '</div>' +
        '</div>' +
        '<select name="country_id"><option value="NO" selected>NO</option></select>' +
        '</form>';

    installAsyncSimulation($);

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

    const capture = loadCompanyCapture({
        jquery: $,
        'Magento_Checkout/js/model/quote': Object.assign(
            {},
            defaultMocks()['Magento_Checkout/js/model/quote'],
            {
                billingAddress: function () { return { countryId: 'NO' }; },
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
            supportedCompanyTypes: { no: [] }
        })
    }, GLOBALS);
    capture.start();

    return { panel: capture.shipping, identity: capture.shipping.identity() };
}

/** What the popover does on a result click. */
function picks(panel, companyId, text) {
    panel.panel().setDisplayText(text);
    panel.selectCompany({ text: text, companyId: companyId, lookupId: 'l1' });
}

function labels() {
    return document.querySelectorAll('.' + TEXT_CLASS);
}

beforeEach(() => {
    document.body.innerHTML = '';
    $(document).off('.twoCompanyCapture');
    $(document).off('.twoCompanySourceResolver');
    $(document).off('.twoCompanyCaptureMount');
});

describe('TWO-25326 §5: the company number is a plain text label, and only after selection', () => {
    test('nothing is rendered before a result has been selected', () => {
        load();

        expect(labels()).toHaveLength(0);
    });

    test('selecting a result renders the number as text under the name field', () => {
        const { panel } = load();

        picks(panel, '919300894', 'Example Trading AS');

        expect(labels()).toHaveLength(1);
        const label = labels()[0];
        expect(label.textContent).toBe('919300894');
        // Under the NAME field specifically — inside that field's own
        // `.control`, after the input. §5 pins the position, not just the
        // existence, because a number rendered somewhere else on the form is
        // exactly the "visible in the address area" defect §7 forbids.
        const nameControl = document.querySelector(NAME_SELECTOR).closest('.control');
        expect(label.closest('.control')).toBe(nameControl);
        expect(
            label.compareDocumentPosition(document.querySelector(NAME_SELECTOR)) &
                window.Node.DOCUMENT_POSITION_PRECEDING
        ).toBeTruthy();
    });

    test('it is not an input, and carries nothing the buyer could type into', () => {
        const { panel } = load();

        picks(panel, '919300894', 'Example Trading AS');

        const label = labels()[0];
        expect(label.tagName).toBe('DIV');
        expect(label.querySelector('input, textarea, select, [contenteditable]')).toBeNull();
        // `isContentEditable` is unimplemented in jsdom (always undefined),
        // so assert the attribute that would set it instead.
        expect(label.hasAttribute('contenteditable')).toBe(false);
    });

    test('it has an accessible name, since the visible text is a bare number', () => {
        // §7 forbids an extra VISIBLE caption in the address area, so the
        // caption has to be an accessible one — a bare number with no
        // accessible name is unreadable to a screen reader.
        const { panel } = load();

        picks(panel, '919300894', 'Example Trading AS');

        expect(labels()[0].getAttribute('aria-label')).toBe('Company Number');
    });

    test('a company with no registry identifier renders no label at all', () => {
        const { panel } = load();

        picks(panel, '', 'Identifier-less Example AS');

        expect(labels()).toHaveLength(0);
    });

    test('re-selecting replaces the number rather than stacking a second label', () => {
        const { panel } = load();

        picks(panel, '919300894', 'Example Trading AS');
        picks(panel, '811912312', 'Other Example AS');

        expect(labels()).toHaveLength(1);
        expect(labels()[0].textContent).toBe('811912312');
    });

    /**
     * The manual-entry case, and the reason the paint decides on the capture
     * mode rather than only on whether a number is present: the hidden input
     * can still be holding the previous pick's number at the moment the buyer
     * switches to typing a name by hand. Rendering it there would attach a
     * registry identity to a company the buyer typed themselves.
     */
    test('manual-entry mode shows no number even when one is still in the hidden input', () => {
        const { panel } = load();
        picks(panel, '919300894', 'Example Trading AS');
        expect(labels()).toHaveLength(1);

        panel.manualEntryMode();
        $(ID_SELECTOR).val('919300894');
        panel.renderChrome();

        expect(labels()).toHaveLength(0);
    });
});
