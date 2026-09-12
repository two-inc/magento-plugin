/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326: an organisation-number value carrying the literal `TWO:` prefix
 * is an internal reference minted on our side, not a registry number the buyer
 * would recognise, and it must NEVER be displayed — anywhere in the plugin.
 *
 * Three surfaces, one shared formatter
 * (`companySearch.formatCompanyNumber()`), because a per-site patch is a rule
 * the next surface silently opts out of:
 *
 *   (a) the `.two-company-id-text` label each capture panel paints under its
 *       own company-name field (displayCompanyNumber() in
 *       company-capture-component.js);
 *   (b) the search-results rows (searchCompanies());
 *   (c) the order-intent status sentence (resolveCompanyNotice()) — where the
 *       BRACKETS the number normally sits in have to go with it, so the
 *       sentence reads "Company Name", never "Company Name ()".
 *
 * The raw value stays intact on every SUBMITTING path: it is the identifier
 * the API is asked about. Hiding it from the buyer is not the same as not
 * having it, and the specs below pin that distinction rather than only the
 * hiding.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule, defaultMocks, proxyEnvelope } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';
const CONTROLLER = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';

/** Every host member the controller's own contract requires. */
const HOST_MEMBERS = [
    'fieldExists', 'isVirtualCart', 'getAdjacentCountry', 'getQuoteCountry',
    'getFallbackCountry', 'watchCountryChanges', 'supportedCompanyTypesUrl',
    'applyCompanyAddress', 'revertAutofilledAddress', 'clearField', 'tokensUrl',
    'quoteId', 'apiClientParams', 'signupPrefill', 'signupCountry',
    'applyBuyerAddress', 'applyTelephone', 'showError', 'renderSignupPrompt'
];

/** Plain (non-ko) observable factory, matching the sibling specs. */
function plainObservable(initial) {
    let v = initial;
    const fn = function (next) {
        if (!arguments.length) return v;
        v = next;
        return fn;
    };
    return fn;
}

/**
 * The real company-search module. No jQuery double needed: nothing exercised
 * here touches the DOM.
 *
 * @returns {object}
 */
function loadCompanySearch() {
    return loadAmdModule(SEARCH);
}

describe('formatCompanyNumber — the one shared display filter (TWO-25326)', () => {
    const companySearch = loadCompanySearch();

    test('hides a TWO:-prefixed value', () => {
        expect(companySearch.formatCompanyNumber('TWO:abc-123')).toBe('');
    });

    test('hides it whatever the case, and through surrounding whitespace', () => {
        expect(companySearch.formatCompanyNumber('two:abc-123')).toBe('');
        expect(companySearch.formatCompanyNumber('Two:abc-123')).toBe('');
        expect(companySearch.formatCompanyNumber('  TWO:abc-123  ')).toBe('');
    });

    test('shows a genuine registry number unchanged', () => {
        expect(companySearch.formatCompanyNumber('923609016')).toBe('923609016');
        expect(companySearch.formatCompanyNumber('GB123456789')).toBe('GB123456789');
    });

    test('does not hide a number that merely CONTAINS the prefix later on', () => {
        // Anchored at position 0 — "begins with", not "contains". A value like
        // this is not an internal reference and hiding it would lose a real
        // number.
        expect(companySearch.formatCompanyNumber('123TWO:456')).toBe('123TWO:456');
    });

    test('treats absent / empty / non-string values as nothing to show', () => {
        expect(companySearch.formatCompanyNumber(null)).toBe('');
        expect(companySearch.formatCompanyNumber(undefined)).toBe('');
        expect(companySearch.formatCompanyNumber('')).toBe('');
        expect(companySearch.formatCompanyNumber('   ')).toBe('');
        // Numeric ids are a real shape off `national_identifier.id`.
        expect(companySearch.formatCompanyNumber(123456)).toBe('123456');
    });
});

describe('(b) the search-results rows never render a TWO: number', () => {
    /**
     * Run a response through production's own searchCompanies(), the way the
     * panel does.
     *
     * @param {object[]} items search-response items
     * @returns {Promise<object[]>} the rows the panel would render
     */
    function results(items) {
        const settlers = [];
        const $ = defaultMocks().jquery;
        $.ajax = function () {
            const jqxhr = {
                done: function (cb) { settlers.push(cb); return jqxhr; },
                fail: function () { return jqxhr; },
                always: function () { return jqxhr; },
                abort: function () {}
            };
            return jqxhr;
        };
        const search = loadAmdModule(SEARCH, { jquery: $ }).searchCompanies({
            config: {},
            token: {},
            scope: {},
            term: 'acme',
            getCountryCode: function () { return 'gb'; }
        });
        settlers.forEach(function (cb) { cb(proxyEnvelope({ items: items })); });
        return search.then(function (result) { return result.items; });
    }

    test('a TWO:-prefixed identifier renders the name alone, with no empty brackets', async () => {
        const mapped = await results([
            {
                name: 'Acme Widgets Ltd',
                highlight: '<b>Acme</b> Widgets Ltd',
                lookup_id: 'lookup-1',
                national_identifier: { id: 'TWO:internal-ref' }
            }
        ]);
        expect(mapped[0].html).toBe('<b>Acme</b> Widgets Ltd');
        expect(mapped[0].html).not.toContain('TWO:');
        expect(mapped[0].html).not.toContain('()');
        // …but the value is still carried, because selecting the row still has
        // to submit the identifier the registry gave us.
        expect(mapped[0].companyId).toBe('TWO:internal-ref');
        expect(mapped[0].lookupId).toBe('lookup-1');
    });

    test('a genuine identifier still renders in brackets', async () => {
        const mapped = await results([
            {
                name: 'Acme Widgets Ltd',
                highlight: '<b>Acme</b> Widgets Ltd',
                lookup_id: 'lookup-1',
                national_identifier: { id: '923609016' }
            }
        ]);
        expect(mapped[0].html).toBe('<b>Acme</b> Widgets Ltd (923609016)');
        expect(mapped[0].companyId).toBe('923609016');
    });
});

describe('(c) the order-intent notice drops the number AND its brackets', () => {
    const companySearch = loadCompanySearch();
    const component = loadAmdModule(RENDERER, {
        'Two_Gateway/js/model/company-search': companySearch
    });

    const COPY = {
        // The literal default from ConfigProvider::getOrderIntentApprovedNotice().
        withCompany: 'This order by {{companyName}} ({{companyNumber}}) is likely to be accepted by Two',
        withoutCompany: 'This order is likely to be accepted by Two',
        companyNameToken: '{{companyName}}',
        companyNumberToken: '{{companyNumber}}'
    };

    /**
     * @param {string} name companyName() value
     * @param {string} id companyId() value
     * @returns {string} resolved notice
     */
    function notice(name, id) {
        const ctx = Object.assign({}, component, {
            companyName: plainObservable(name),
            companyId: plainObservable(id)
        });
        return ctx.resolveCompanyNotice(COPY);
    }

    test('a TWO: number yields no brackets at all', () => {
        expect(notice('Acme Widgets Ltd', 'TWO:internal-ref')).toBe(
            'This order by Acme Widgets Ltd is likely to be accepted by Two'
        );
    });

    test('an EMPTY number yields no brackets either — the pre-existing "Company Name ()" case', () => {
        expect(notice('Acme Widgets Ltd', '')).toBe(
            'This order by Acme Widgets Ltd is likely to be accepted by Two'
        );
    });

    test('no notice ever contains an empty bracket pair or the hidden prefix', () => {
        ['TWO:internal-ref', '', '   ', 'two:x'].forEach(function (id) {
            const text = notice('Acme Widgets Ltd', id);
            expect(text).not.toContain('()');
            expect(text).not.toContain('( )');
            expect(text.toUpperCase()).not.toContain('TWO:');
        });
    });

    test('a genuine number still renders in brackets', () => {
        expect(notice('Acme Widgets Ltd', '923609016')).toBe(
            'This order by Acme Widgets Ltd (923609016) is likely to be accepted by Two'
        );
    });

    test('a brand copy override that places the token OUTSIDE brackets is handled too', () => {
        const ctx = Object.assign({}, component, {
            companyName: plainObservable('Acme Widgets Ltd'),
            companyId: plainObservable('TWO:internal-ref')
        });
        expect(
            ctx.resolveCompanyNotice(
                Object.assign({}, COPY, {
                    withCompany: 'Approved for {{companyName}} no {{companyNumber}} today'
                })
            )
        ).toBe('Approved for Acme Widgets Ltd no today');
    });

    test('a company NAME containing brackets is not mistaken for the number\'s brackets', () => {
        expect(notice('Acme (Holdings) Ltd', '923609016')).toBe(
            'This order by Acme (Holdings) Ltd (923609016) is likely to be accepted by Two'
        );
        expect(notice('Acme (Holdings) Ltd', 'TWO:internal-ref')).toBe(
            'This order by Acme (Holdings) Ltd is likely to be accepted by Two'
        );
    });
});

describe('the raw number still reaches the submitting path', () => {
    const companySearch = loadCompanySearch();
    const component = loadAmdModule(RENDERER, {
        'Two_Gateway/js/model/company-search': companySearch
    });

    test('getData() still submits the RAW number the label refuses to show', () => {
        const ctx = Object.assign({}, component, {
            companyName: plainObservable('Acme Widgets Ltd'),
            companyId: plainObservable('TWO:internal-ref'),
            getCode: function () {
                return 'two_payment';
            },
            project: plainObservable(''),
            department: plainObservable(''),
            orderNote: plainObservable(''),
            poNumber: plainObservable(''),
            invoiceEmails: plainObservable(''),
            selectedTerm: plainObservable(null)
        });
        expect(ctx.getData().additional_data.companyId).toBe('TWO:internal-ref');
    });

});

describe('(a) the capture panel\'s own label goes through the same filter', () => {
    const CompanyCaptureComponent = loadAmdModule(CONTROLLER);
    const createIdentity = loadAmdModule(IDENTITY);

    /**
     * A panel whose only real collaborators are its own identity and the real
     * search module the formatter lives on.
     *
     * @param {string} id captured organisation number
     * @returns {object} the component
     */
    function panelWith(id) {
        const host = {
            config: {},
            Panel: function () {},
            SoleTraderFlow: function () {},
            identity: createIdentity(),
            search: loadCompanySearch(),
            addressFieldSelector: '#shipping-new-address-form input[name="company"]',
            tileFieldSelector: '#two_gateway_form input#company_name'
        };
        HOST_MEMBERS.forEach(function (member) {
            host[member] = function () {};
        });
        const component = new CompanyCaptureComponent(host);
        component.identity().companyId(id);
        return component;
    }

    test('displayCompanyNumber() withholds a TWO: number and shows a real one', () => {
        expect(panelWith('TWO:internal-ref').displayCompanyNumber()).toBe('');
        expect(panelWith('two:internal-ref').displayCompanyNumber()).toBe('');
        expect(panelWith('923609016').displayCompanyNumber()).toBe('923609016');
    });

    test('the identity keeps the RAW number the label refuses to show', () => {
        expect(panelWith('TWO:internal-ref').identity().companyId()).toBe('TWO:internal-ref');
    });
});
