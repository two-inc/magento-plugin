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
const { loadCompanySearchControl, installAsyncSimulation } = require('./amd-harness');

const FIELD_SELECTOR = '#company_name';
const OTHER_SELECTOR = '#shipping-new-address-form input[name="company"]';

/**
 * The company-search model, stubbed down to what `initialise()` touches.
 *
 * @returns {object}
 */
function searchModelStub() {
    return {
        EVENT_NS: '.twoCompanySearch',
        MIN_INPUT_LENGTH: 3,
        DROPDOWN_CSS_CLASS: 'two-company-search-dropdown',
        buildLanguageOptions: function () { return {}; },
        buildSearchAjaxOptions: function () { return {}; },
        setSearching: function () {},
        setUnavailable: function () {},
        clearSearchChrome: function () {},
        attachManualEntryButton: function () {},
        detachManualEntryButton: function () {},
        markSearchBinding: function () {},
        attachOpenOnType: function () {}
    };
}

/**
 * A control bound against real jsdom nodes, with select2 replaced by a
 * recorder — the widget itself is not what these cases are about.
 *
 * @returns {object} `{ control, inits }` where `inits` counts select2 builds
 */
function loadControl() {
    installAsyncSimulation($);
    $.async.reset();

    const inits = [];
    // select2 is a jQuery plugin here, so the control's chained calls have to
    // keep working against the real jQuery object.
    $.fn.select2 = function (arg) {
        if (typeof arg === 'string') return this;
        inits.push(this[0]);
        return this;
    };

    const CompanySearchControl = loadCompanySearchControl(
        $,
        searchModelStub(),
        { document: document, window: window, require: function (deps, cb) { cb(); } }
    );

    const control = new CompanySearchControl({
        fieldSelector: FIELD_SELECTOR,
        config: {},
        manualEntryEnabled: false
    });
    return { control: control, inits: inits };
}

beforeEach(() => {
    document.body.innerHTML = '<form><input id="company_name" /></form>';
});

describe('one $.async observer per selector', () => {
    test('the first bind registers exactly one', () => {
        const { control } = loadControl();

        control.bind();

        expect($.async.registrations(FIELD_SELECTOR)).toBe(1);
    });

    test.each([2, 5, 20])('%i binds still register exactly one', (times) => {
        const { control } = loadControl();

        for (let i = 0; i < times; i++) control.bind();

        expect($.async.registrations(FIELD_SELECTOR)).toBe(1);
    });

    test('re-binding still re-initialises the widget', () => {
        // The point of a re-bind is that it re-points at the current node, so
        // registering once must not turn later binds into no-ops.
        const { control, inits } = loadControl();

        control.bind();
        const afterFirst = inits.length;
        control.bind();

        expect(inits.length).toBeGreaterThan(afterFirst);
    });

    test('re-binding picks up a node the checkout replaced', () => {
        const { control, inits } = loadControl();
        control.bind();

        document.body.innerHTML = '<form><input id="company_name" /></form>';
        const replacement = document.querySelector(FIELD_SELECTOR);
        control.bind();

        expect(inits[inits.length - 1]).toBe(replacement);
        expect($.async.registrations(FIELD_SELECTOR)).toBe(1);
    });

    test('moving to a different selector registers one observer for it', () => {
        document.body.innerHTML =
            '<form id="shipping-new-address-form"><input name="company" /></form>' +
            '<form><input id="company_name" /></form>';
        const { control } = loadControl();

        control.bind();
        control.fieldSelector = OTHER_SELECTOR;
        control.bind();

        expect($.async.registrations(FIELD_SELECTOR)).toBe(1);
        expect($.async.registrations(OTHER_SELECTOR)).toBe(1);
    });

    test('a DOM mutation re-fires the one observer once, not once per bind', () => {
        // The compounding case: with N observers a single mutation causes N
        // re-initialisations, each of which mutates again.
        const { control, inits } = loadControl();
        for (let i = 0; i < 10; i++) control.bind();
        const beforeMutation = inits.length;

        $.async.fireAll();

        expect(inits.length - beforeMutation).toBe(1);
    });
});
