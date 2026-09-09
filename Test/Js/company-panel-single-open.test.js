/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-510 — only one company-search popover may be open at a time, and the
 * popover that closes gives its field's tab stop back.
 *
 * Two panels from ONE module instance, the way a checkout with a billing and a
 * shipping capture loads it. jsdom has no sequential focus navigation and
 * cannot tell a pointer-delivered event from a focus-delivered one, so what is
 * pinned here is the observable state — which popover is open, and what each
 * field's `tabindex` reads — never the event ordering that motivated the fix.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const GLOBALS = { document: document, window: window };
const CONFIG = { checkoutApiUrl: 'https://api.example.test' };
const PANEL = '.two-company-dropdown';

const FIELDS = { billing: '#billing_company', shipping: '#shipping_company' };
const OTHER = { billing: 'shipping', shipping: 'billing' };

function field(which) {
    return document.querySelector(FIELDS[which]);
}

function panelOf(which) {
    return field(which).parentElement.querySelector(PANEL);
}

function isOpen(which) {
    const node = panelOf(which);
    return !!node && !node.hasAttribute('hidden');
}

function tabIndexOf(which) {
    return field(which).getAttribute('tabindex');
}

/** @returns {object} a panel per mount, all from one module instance */
function setup() {
    document.body.innerHTML = `
        <form id="billing"><input id="billing_company" type="text"></form>
        <form id="shipping"><input id="shipping_company" type="text"></form>
    `;
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const panels = {};
    Object.keys(FIELDS).forEach(function (which) {
        panels[which] = new CompanySearchPanel({
            fieldSelector: FIELDS[which],
            config: CONFIG,
            getCountryCode: function () { return 'gb'; },
            getSelectedMode: function () { return ''; }
        });
        panels[which].bind();
    });
    return panels;
}

/** A real pointer press, which is what the defect turned on. */
function mouseDownOn(node) {
    node.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true }));
}

describe('single-open invariant', () => {
    test.each([
        ['billing', 'opening the shipping popover closes the billing one'],
        ['shipping', 'opening the billing popover closes the shipping one']
    ])('%s first', (first, description) => {
        const panels = setup();
        const second = OTHER[first];

        panels[first].open();
        panels[second].open();

        expect([isOpen(first), isOpen(second)]).toEqual([false, true], description);
    });

    test('re-opening the already-open popover leaves it open', () => {
        const panels = setup();
        panels.billing.open();
        panels.billing.open();
        expect(isOpen('billing')).toBe(true);
    });

    test('a closed popover frees the slot, so the other one can take it back', () => {
        const panels = setup();
        panels.billing.open();
        panels.shipping.open();
        panels.shipping.close();
        panels.billing.open();
        expect([isOpen('billing'), isOpen('shipping')]).toEqual([true, false]);
    });
});

describe('tab stop of the popover that closes', () => {
    test.each([
        ['at rest', function () {}, [null, null], 'neither field is a tab stop'],
        ['billing open', function (p) { p.billing.open(); }, ['-1', null], 'only the open one holds it'],
        ['shipping takes over', function (p) { p.billing.open(); p.shipping.open(); }, [null, '-1'], 'the closing field is given it back'],
        ['both closed again', function (p) { p.billing.open(); p.shipping.open(); p.shipping.close(); }, [null, null], 'no field is left at -1']
    ])('%s', (name, act, expected, description) => {
        const panels = setup();
        act(panels);
        expect([tabIndexOf('billing'), tabIndexOf('shipping')]).toEqual(expected, description);
    });
});

describe('a pointer press outside the open popover', () => {
    test('closes it', () => {
        const panels = setup();
        panels.billing.open();
        mouseDownOn(field('shipping'));
        expect(isOpen('billing')).toBe(false);
    });

    test('gives its field the tab stop back', () => {
        const panels = setup();
        panels.billing.open();
        mouseDownOn(field('shipping'));
        expect(tabIndexOf('billing')).toBeNull();
    });

    test('inside it, leaves it open', () => {
        const panels = setup();
        panels.billing.open();
        mouseDownOn(panelOf('billing'));
        expect(isOpen('billing')).toBe(true);
    });
});
