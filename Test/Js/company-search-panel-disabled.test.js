/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25668 — the search field is greyed out, not hidden, on a country the
 * registry search does not cover. `setDisabled()` owns the native
 * `disabled` flag; `open()` is guarded in depth, and the flag survives a
 * rebind so a checkout re-render cannot silently re-enable the field.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const GLOBALS = { document: document, window: window };
const FIELD = '#company_name';
const PANEL = '.two-company-dropdown';
const BASE_CONFIG = { checkoutApiUrl: 'https://api.example.test' };

function panelIsOpen() {
    const node = document.querySelector(PANEL);
    return !!node && !node.hasAttribute('hidden');
}

function setup() {
    document.body.innerHTML = '<div class="control"><input id="company_name" type="text"></div>';
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const panel = new CompanySearchPanel({
        fieldSelector: FIELD,
        config: BASE_CONFIG,
        getCountryCode: function () { return 'gb'; }
    });
    panel.bind();
    return panel;
}

describe('setDisabled', () => {
    test('sets the native disabled flag on the field', () => {
        const panel = setup();
        panel.setDisabled(true);
        expect(document.querySelector(FIELD).disabled).toBe(true);
    });

    test('clears the native disabled flag on the field', () => {
        const panel = setup();
        panel.setDisabled(true);
        panel.setDisabled(false);
        expect(document.querySelector(FIELD).disabled).toBe(false);
    });

    test('closes an open panel when disabled', () => {
        const panel = setup();
        panel.open();
        expect(panelIsOpen()).toBe(true);

        panel.setDisabled(true);

        expect(panelIsOpen()).toBe(false);
    });

    test('leaves a closed panel closed when disabled', () => {
        const panel = setup();
        panel.setDisabled(true);
        expect(panelIsOpen()).toBe(false);
    });
});

describe('open() while disabled', () => {
    test('a call to open() is refused while disabled', () => {
        const panel = setup();
        panel.setDisabled(true);
        panel.open();
        expect(panelIsOpen()).toBe(false);
    });

    test('open() works again once re-enabled', () => {
        const panel = setup();
        panel.setDisabled(true);
        panel.setDisabled(false);
        panel.open();
        expect(panelIsOpen()).toBe(true);
    });
});

describe('the disabled flag survives a rebind', () => {
    test('a fresh field node inherits the flag on _attach()', () => {
        const panel = setup();
        panel.setDisabled(true);

        // A checkout re-render replaces the field node the way core's own
        // Knockout re-binding does.
        const wrap = document.querySelector(FIELD).closest('.two-company-field-wrap');
        const fresh = document.createElement('input');
        fresh.id = 'company_name';
        fresh.type = 'text';
        document.querySelector(FIELD).replaceWith(fresh);
        panel._attach(fresh);

        expect(document.querySelector(FIELD).disabled).toBe(true);
        expect(wrap).not.toBeNull();
    });
});
