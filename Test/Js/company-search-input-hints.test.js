/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Pins the below-threshold company-search input hint: OUR
 * translatable string naming a FIXED number, rather than a remaining count.
 *
 * And the centralisation that hint depends on: the panel may not repeat a
 * literal minimum length. It must read the shared constant, or the number the
 * hint claims and the number the panel enforces can drift apart.
 *
 * Mutation-resistance notes, because this repo's AMD harness makes it easy
 * to write assertions that cannot fail:
 *  - The shared model is injected with a DELIBERATELY WRONG threshold (7).
 *    An assertion against 3 would pass whether the source read the constant
 *    or repeated a literal; asserting 7 can only pass if it reads it.
 *  - Translation is asserted on the msgid, not on a rendered label, because
 *    the harness resolves `$t` to identity.
 *  - The hint is read off the panel the REAL class built over a real jsdom
 *    node, so a bind that returned before building fails rather than
 *    presenting as green.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';

const GLOBALS = { document: document, window: window };

const FIELD_SELECTOR = '#company_name';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50
};

/** Nothing here is 3, so a surviving literal 3 cannot pass. */
const WRONG_THRESHOLD = 7;

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

/**
 * The real shared model, but loaded so its threshold is WRONG_THRESHOLD.
 * Patching the source rather than the returned object matters: the hint
 * helper closes over the module-local constant, so an object-level override
 * would leave the message quoting 3 and the test would prove nothing about
 * the two staying in step.
 */
function loadCompanySearchWithWrongThreshold() {
    const src = readSource(MODEL_PATH);
    const needle = 'const MIN_INPUT_LENGTH = 3;';
    expect(src).toContain(needle);
    const patched = src.replace(needle, 'const MIN_INPUT_LENGTH = ' + WRONG_THRESHOLD + ';');

    const tmp = path.join(__dirname, '__tmp_company_search_threshold.js');
    fs.writeFileSync(tmp, patched, 'utf8');
    try {
        return loadAmdModule(
            path.relative(path.resolve(__dirname, '..', '..'), tmp),
            { jquery: $ },
            GLOBALS
        );
    } finally {
        fs.unlinkSync(tmp);
    }
}

/**
 * A bound, open panel over the fixture, plus the terms it actually searched
 * for. Debounced to zero so a keystroke reaches the search within one tick.
 *
 * @param {object} companySearch the shared model to build against
 * @returns {object} `{ panel, searched }`
 */
function openPanel(companySearch) {
    const searched = [];
    companySearch.SEARCH_DEBOUNCE_MS = 0;
    companySearch.searchCompanies = function (options) {
        searched.push(options.term);
        return Promise.resolve({ items: [], unavailable: false, aborted: false });
    };

    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const panel = new CompanySearchPanel({ fieldSelector: FIELD_SELECTOR, config: BASE_CONFIG });
    panel.bind();

    // Bootstrapped guard: without a built panel every assertion below is vacuous.
    expect(document.querySelector('.two-company-dropdown__query')).toBeTruthy();
    panel.open();
    return { panel: panel, searched: searched };
}

/**
 * Type into the panel's query field the way the buyer does.
 *
 * @param {string} term
 * @returns {Promise} resolves once the debounce has elapsed
 */
function typeQuery(term) {
    $('.two-company-dropdown__query').val(term).trigger('input');
    return new Promise((resolve) => setTimeout(resolve, 0));
}

beforeEach(() => {
    document.body.innerHTML =
        '<form id="two_gateway_form"><div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" />' +
        '</div></div></form>';
});

describe('below-threshold hint (element 4)', () => {
    test('the panel quotes the shared threshold, not a remaining count', () => {
        openPanel(loadCompanySearchWithWrongThreshold());
        const expected = 'Please enter ' + WRONG_THRESHOLD + ' or more characters';

        expect($('.two-company-dropdown__message').text()).toBe(expected);
        expect($('.two-company-dropdown__query').attr('placeholder')).toBe(expected);
        expect($('.two-company-dropdown__message').text()).not.toContain('%1');
    });

    test('the accessible name is the field, not the transient hint', () => {
        // Naming the field after the hint leaves a screen-reader user tabbing
        // back in after a full query still hearing "Enter 7 or more characters".
        openPanel(loadCompanySearchWithWrongThreshold());

        expect($('.two-company-dropdown__query').attr('aria-label')).toBe('Search for company');
    });

    test.each([
        [WRONG_THRESHOLD - 1, false, 'below the shared threshold'],
        [WRONG_THRESHOLD, true, 'at the shared threshold']
    ])('a %i-character term searches: %s (%s)', async (length, searches) => {
        const { searched } = openPanel(loadCompanySearchWithWrongThreshold());
        const term = 'a'.repeat(length);

        await typeQuery(term);

        expect(searched).toEqual(searches ? [term] : []);
    });

    test('a term the buyer backspaces below the threshold restores the hint', async () => {
        const { searched } = openPanel(loadCompanySearchWithWrongThreshold());
        await typeQuery('a'.repeat(WRONG_THRESHOLD));

        await typeQuery('a');

        expect($('.two-company-dropdown__message').text()).toBe(
            'Please enter ' + WRONG_THRESHOLD + ' or more characters'
        );
        expect(searched.length).toBe(1);
    });
});

describe('the hint is one translatable string', () => {
    test('the msgid is placeholder-form and translated in every catalogue', () => {
        const msgid = 'Please enter %1 or more characters';
        const model = readSource(MODEL_PATH);
        expect(model).toContain("$t('" + msgid + "')");
        // The literal-number form must be gone, or translators keep a row
        // that no longer matches any msgid the code emits.
        expect(model).not.toContain("$t('Please enter 3 or more characters')");

        ['nb_NO', 'nl_NL', 'sv_SE'].forEach((locale) => {
            const csv = readSource('i18n/' + locale + '.csv');
            expect(csv).toContain('"' + msgid + '","');
            expect(csv).not.toContain('"Please enter 3 or more characters"');
            // Magento drops rows whose translation equals the msgid.
            expect(csv).not.toContain('"' + msgid + '","' + msgid + '"');
        });
    });

    test('the hint and the enforced threshold come from one source', () => {
        const companySearch = loadCompanySearchWithWrongThreshold();
        expect(companySearch.minInputLengthMessage()).toContain(
            String(companySearch.MIN_INPUT_LENGTH)
        );
    });
});
