/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — `bind()` registers ONE `$.async` observer per selector.
 *
 * `$.async` is a MutationObserver that nothing ever disconnects, and the
 * initialisation it triggers mutates the DOM heavily. So a second registration
 * for the same selector makes the first one's own mutations re-enter the
 * callback, and every later render re-fires every observer ever registered.
 * On a checkout that re-binds per totals change that compounds until the
 * renderer stops responding — a frozen checkout, reached by selecting a
 * payment method.
 *
 * The cases below fail against a `bind()` that registers per call. They need
 * the harness's `$.async` SIMULATION rather than its old one-shot stub: a stub
 * that never re-fires cannot express stacking, which is why this class of
 * defect survived three review rounds and a green suite.
 */

'use strict';

const $ = require('jquery');
const { loadCompanySearchPanel, installAsyncSimulation } = require('./amd-harness');

const FIELD_SELECTOR = '#company_name';
const OTHER_SELECTOR = '#shipping-new-address-form input[name="company"]';

/**
 * The company-search model, stubbed down to what building a panel touches.
 *
 * @returns {object}
 */
function searchModelStub() {
    return {
        MIN_INPUT_LENGTH: 3,
        SEARCH_DEBOUNCE_MS: 300,
        minInputLengthMessage: function () { return 'Enter 3 or more characters'; },
        noResultsMessage: function () { return 'No matches found'; },
        abortActiveRequest: function () { return false; },
        searchCompanies: function () {
            return Promise.resolve({ items: [], unavailable: false, aborted: false });
        }
    };
}

/**
 * A panel over real jsdom nodes, with every re-point recorded.
 *
 * `_attach` is the panel's own re-initialisation entry point — the thing an
 * observer firing a second time would run twice.
 *
 * @returns {object} `{ panel, attaches }` where `attaches` holds the field node
 *          each attach re-pointed at
 */
function loadPanel() {
    installAsyncSimulation($);
    $.async.reset();

    const CompanySearchPanel = loadCompanySearchPanel($, searchModelStub(), {
        document: document,
        window: window
    });

    const attaches = [];
    const attach = CompanySearchPanel.prototype._attach;
    CompanySearchPanel.prototype._attach = function ($field) {
        if ($field.length) attaches.push($field[0]);
        return attach.apply(this, arguments);
    };

    const panel = new CompanySearchPanel({
        fieldSelector: FIELD_SELECTOR,
        config: {}
    });
    return { panel: panel, attaches: attaches };
}

beforeEach(() => {
    document.body.innerHTML = '<form><input id="company_name" /></form>';
});

describe('one $.async observer per selector', () => {
    test('the first bind registers exactly one', () => {
        const { panel } = loadPanel();

        panel.bind();

        expect($.async.registrations(FIELD_SELECTOR)).toBe(1);
    });

    test.each([2, 5, 20])('%i binds still register exactly one', (times) => {
        const { panel } = loadPanel();

        for (let i = 0; i < times; i++) panel.bind();

        expect($.async.registrations(FIELD_SELECTOR)).toBe(1);
    });

    test('re-binding still re-attaches the panel', () => {
        // The point of a re-bind is that it re-points at the current node, so
        // registering once must not turn later binds into no-ops.
        const { panel, attaches } = loadPanel();

        panel.bind();
        const afterFirst = attaches.length;
        panel.bind();

        expect(attaches.length).toBeGreaterThan(afterFirst);
    });

    test('re-binding picks up a node the checkout replaced', () => {
        const { panel, attaches } = loadPanel();
        panel.bind();

        document.body.innerHTML = '<form><input id="company_name" /></form>';
        const replacement = document.querySelector(FIELD_SELECTOR);
        panel.bind();

        expect(attaches[attaches.length - 1]).toBe(replacement);
        expect(panel.getField()[0]).toBe(replacement);
        expect($.async.registrations(FIELD_SELECTOR)).toBe(1);
    });

    test('moving to a different selector registers one observer for it', () => {
        document.body.innerHTML =
            '<form id="shipping-new-address-form"><input name="company" /></form>' +
            '<form><input id="company_name" /></form>';
        const { panel } = loadPanel();

        panel.bind();
        panel.fieldSelector = OTHER_SELECTOR;
        panel.bind();

        expect($.async.registrations(FIELD_SELECTOR)).toBe(1);
        expect($.async.registrations(OTHER_SELECTOR)).toBe(1);
    });

    test('a DOM mutation re-fires the one observer once, not once per bind', () => {
        // The compounding case: with N observers a single mutation causes N
        // re-attaches, each of which mutates again.
        const { panel, attaches } = loadPanel();
        for (let i = 0; i < 10; i++) panel.bind();
        const beforeMutation = attaches.length;

        // A re-render replaces the field, which is the mutation the observers
        // answer — the same node still sitting there is no event at all.
        document.querySelector(FIELD_SELECTOR).remove();
        document.querySelector('form').innerHTML = '<input id="company_name" />';
        $.async.fireAll();

        expect(attaches.length - beforeMutation).toBe(1);
    });

    test('repeated binds leave exactly one panel in the wrapper', () => {
        // Two query fields in one wrapper would both write to one identity.
        const { panel } = loadPanel();

        for (let i = 0; i < 5; i++) panel.bind();

        expect(document.querySelectorAll('.two-company-dropdown').length).toBe(1);
        expect(document.querySelectorAll('.two-company-field-wrap').length).toBe(1);
    });
});
