/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The "Search for company" return link is the only route out of manual entry.
 * Two panels can be live on one page (the address step and the payment step
 * render their own), so one panel's cleanup must not strand the other — while
 * still collecting a link an earlier bind left on the field it is cleaning.
 */

'use strict';

const $ = require('jquery');
const { loadCompanySearchPanel } = require('./amd-harness');

const GLOBALS = { document: document, window: window };

const BACK = '.two-company-search-back';

const CONFIG = { checkoutApiUrl: 'https://api.example.test' };

// One shared module load, as in a browser: a second load restarts the instance
// counter and hands both panels the same id.
const CompanySearchPanel = loadCompanySearchPanel($, null, GLOBALS);

function field(id) {
    return '<div class="field"><div class="control">' +
        '<input id="' + id + '" name="' + id + '" /></div></div>';
}

/**
 * A bound panel over the named field.
 *
 * @param {string} selector
 * @returns {object} the panel
 */
function panelOver(selector) {
    const panel = new CompanySearchPanel({ fieldSelector: selector, config: CONFIG });
    panel.bind();
    return panel;
}

function backLinkOwners() {
    return Array.prototype.map.call(
        document.querySelectorAll(BACK),
        function (node) { return node.closest('.control').querySelector('input').id; }
    ).sort();
}

beforeEach(() => {
    document.body.innerHTML = '<form id="two_gateway_form">' +
        field('company_name') + field('billing_company_name') + '</form>';
});

describe('two panels on one page own their own return link', () => {
    test.each([
        [
            'the second panel entering manual entry',
            'both links stand',
            function (first, second) { second.releaseField(); },
            ['billing_company_name', 'company_name']
        ],
        [
            'the second panel leaving manual entry',
            'only the second panel\'s link is collected',
            function (first, second) { second.releaseField(); second.reclaimField(); },
            ['company_name']
        ],
        [
            'the second panel re-rendering its own link',
            'the re-render replaces one link, not both',
            function (first, second) { second.releaseField(); second.releaseField(); },
            ['billing_company_name', 'company_name']
        ]
    ])('%s: %s', (name, description, act, expected) => {
        const first = panelOver('#company_name');
        const second = panelOver('#billing_company_name');
        first.releaseField();
        // Bootstrapped guard: without the first link every assertion is vacuous.
        expect(backLinkOwners()).toEqual(['company_name']);

        act(first, second);

        expect(backLinkOwners()).toEqual(expected);
    });

    test('a fresh panel over the same field collects the orphan the old one left', () => {
        const abandoned = panelOver('#company_name');
        abandoned.releaseField();
        expect(backLinkOwners()).toEqual(['company_name']);

        // A replacement panel over the same field: its own reference is null, so
        // only the sweep can reach the link still sitting in the wrapper.
        panelOver('#company_name').releaseField();

        expect(backLinkOwners()).toEqual(['company_name']);
    });
});
