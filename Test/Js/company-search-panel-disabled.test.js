/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-525 — a country the registry search does not cover withdraws the SEARCH
 * and nothing else. `setDisabled()` hides the query row; the panel still
 * opens, because the chips inside it are the buyer's only route to manual
 * entry, and the company field is never given the native `disabled` flag.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const GLOBALS = { document: document, window: window };
const FIELD = '#company_name';
const PANEL = '.two-company-dropdown';
const QUERY = '.two-company-dropdown__query';
const SEARCH_ROW = '.two-company-dropdown__search';
const CHIPS = '.two-company-mode-chips';
const CHIP = '.two-company-mode-chip';
const HIDDEN = 'two-hidden';
const BASE_CONFIG = { checkoutApiUrl: 'https://api.example.test' };

const CHIPS_ALL = [
    { mode: 'registered', text: 'Registered company', onActivate: function () {} },
    { mode: 'soletrader', text: 'Sole trader', onActivate: function () {} },
    { mode: 'manual', text: 'Enter manually', onActivate: function () {} }
];

function panelIsOpen() {
    const node = document.querySelector(PANEL);
    return !!node && !node.hasAttribute('hidden');
}

function isHidden(selector) {
    const node = document.querySelector(selector);
    return !node || node.classList.contains(HIDDEN);
}

/** The chips the buyer can actually see, by mode. */
function visibleChipModes() {
    if (isHidden(CHIPS)) return [];
    return Array.prototype.slice
        .call(document.querySelectorAll(CHIP))
        .filter(function (chip) { return !chip.classList.contains(HIDDEN); })
        .map(function (chip) { return chip.getAttribute('data-two-chip'); });
}

/**
 * @param {object} [options] `{mode, offered}` — the selected capture mode, and
 *        the modes the host offers at all (defaults to all three)
 * @returns {object} `{panel, state}`; mutate `state.mode` to change mode
 */
function setup(options) {
    const settings = options || {};
    document.body.innerHTML = '<div class="control"><input id="company_name" type="text"></div>';
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const state = { mode: settings.mode || 'registered' };
    const offered = settings.offered || ['registered', 'soletrader', 'manual'];
    const panel = new CompanySearchPanel({
        fieldSelector: FIELD,
        config: BASE_CONFIG,
        getCountryCode: function () { return 'gb'; },
        getChips: function () { return CHIPS_ALL; },
        isChipVisible: function (mode) { return offered.indexOf(mode) !== -1; },
        getSelectedMode: function () { return state.mode; }
    });
    panel.bind();
    return { panel: panel, state: state };
}

describe('the country gate never blocks the buyer typing a company name', () => {
    test.each([
        ['registered', 'the search mode the gate is actually about'],
        ['soletrader', 'a mode that never searches'],
        ['manual', 'the mode that IS the buyer typing'],
        ['', 'no mode resolved yet']
    ])('mode %s: the field keeps no native disabled flag (%s)', (mode, description) => {
        const { panel } = setup({ mode: mode });

        panel.setDisabled(true);

        expect(document.querySelector(FIELD).disabled).toBe(false);
        expect(description).toBeTruthy();
    });

    test('a field the host itself disabled is left alone, enabled or gated', () => {
        // Given: a host that disabled the field for its own reasons.
        const { panel } = setup();
        document.querySelector(FIELD).disabled = true;

        // When / Then: the gate is not what owns that flag.
        panel.setDisabled(true);
        expect(document.querySelector(FIELD).disabled).toBe(true);
        panel.setDisabled(false);
        expect(document.querySelector(FIELD).disabled).toBe(true);
    });
});

describe('the query row is what the gate withdraws', () => {
    test.each([
        [true, true, 'gated: no query row to type a search into'],
        [false, false, 'ungated: the query row is back']
    ])('setDisabled(%s) leaves the search row hidden: %s (%s)', (disabled, hidden, description) => {
        const { panel } = setup();
        panel.open();

        panel.setDisabled(disabled);

        expect(isHidden(SEARCH_ROW)).toBe(hidden);
        expect(description).toBeTruthy();
    });

    test('a term already typed is dropped with the row', () => {
        const { panel } = setup();
        panel.open();
        document.querySelector(QUERY).value = 'Alp';

        panel.setDisabled(true);

        expect(document.querySelector(QUERY).value).toBe('');
    });
});

describe('the panel still opens while the search is withdrawn', () => {
    test.each([
        ['open() while gated', function (panel) { panel.setDisabled(true); panel.open(); }],
        ['gated while already open', function (panel) { panel.open(); panel.setDisabled(true); }]
    ])('%s leaves the panel showing (so the chips are reachable)', (description, drive) => {
        const { panel } = setup();

        drive(panel);

        expect(panelIsOpen()).toBe(true);
        expect(description).toBeTruthy();
    });

    test('the chips row survives being down to one chip the buyer is not in', () => {
        // Given: an uncovered country — search gated, sole trader unavailable.
        const { panel } = setup({ mode: 'registered', offered: ['manual'] });

        panel.setDisabled(true);
        panel.open();

        expect(visibleChipModes()).toEqual(['manual']);
    });

    test('a lone chip for the mode the buyer is already in is no choice, so the row goes', () => {
        const { panel } = setup({ mode: 'manual', offered: ['manual'] });

        panel.open();

        expect(visibleChipModes()).toEqual([]);
    });

    test('opening with no query row puts focus on the first offered chip', () => {
        const { panel } = setup({ mode: 'registered', offered: ['manual'] });
        panel.setDisabled(true);

        panel.open();

        expect(document.activeElement).toBe(document.querySelector(CHIP + ':not(.' + HIDDEN + ')'));
    });

    test('opening with a query row still puts focus in it', () => {
        const { panel } = setup();

        panel.open();

        expect(document.activeElement).toBe(document.querySelector(QUERY));
    });
});

describe('the gate survives a rebind', () => {
    test('a fresh field node re-attaches with the search still withdrawn', () => {
        const { panel } = setup();
        panel.setDisabled(true);

        // A checkout re-render replaces the field node the way core's own
        // Knockout re-binding does.
        const fresh = document.createElement('input');
        fresh.id = 'company_name';
        fresh.type = 'text';
        document.querySelector(FIELD).replaceWith(fresh);
        panel._attach(fresh);
        panel.open();

        expect(isHidden(SEARCH_ROW)).toBe(true);
        expect(document.querySelector(FIELD).disabled).toBe(false);
    });
});
