/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — the three company-capture options as peers in ONE control.
 *
 * The tile used to present a two-item tab bar (registered organisation / sole
 * trader) and reach manual entry only through a separately worded button
 * inside the search dropdown, so a buyer who wanted to type a name by hand
 * had to open the picker and reject its results first. WooCommerce and
 * PrestaShop both offer all three as peer options in one control; this pins
 * Magento doing the same, each chip subject to its own gate.
 *
 * Mutation-resistance notes:
 *
 *  - the template assertions read the mode control's OWN markup slice, and
 *    each chip's selected state is asserted to be a binding against
 *    `captureMode()`. A statically selected chip — which is exactly what the
 *    old two-block markup had — fails, so re-hardcoding a selection cannot
 *    pass;
 *  - the behavioural assertions read real state after the real transition
 *    (`companyId()`, the field's value, the recorded control calls), never
 *    that a method exists. Emptying `manualEntryMode()` fails every one;
 *  - the chip gating is asserted by nesting, so moving a chip out of its
 *    `ko if` (making sole trader offerable in a country whose registry has no
 *    sole traders) fails rather than reading as green.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const CAPTURE = 'view/frontend/web/js/model/company-capture.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

/**
 * The mode control's own markup, from its opening tag to its closing one.
 *
 * @param {string} template the whole template
 * @returns {string} the `.mode_selector` element
 */
function modeControlMarkup(template) {
    const start = template.indexOf('<div class="mode_selector"');
    expect(start).toBeGreaterThan(-1);
    const end = template.indexOf('</div>', start);
    expect(end).toBeGreaterThan(start);
    return template.slice(start, end + '</div>'.length);
}

/**
 * The chip carrying a given label, from its `<span` to its `</span>`.
 *
 * @param {string} control the mode control's markup
 * @param {string} label the i18n source string the chip renders
 * @returns {string} the chip element
 */
function chipMarkup(control, label) {
    const labelIndex = control.indexOf("i18n: '" + label + "'");
    expect(labelIndex).toBeGreaterThan(-1);
    const start = control.lastIndexOf('<span', labelIndex);
    const end = control.indexOf('</span>', labelIndex);
    expect(start).toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(labelIndex);
    return control.slice(start, end + '</span>'.length);
}

/**
 * Whether a chip sits inside a `ko if` on the given expression, and closes
 * before that block does.
 *
 * @param {string} control the mode control's markup
 * @param {string} label the chip's i18n source string
 * @param {string} condition the `ko if` expression
 * @returns {boolean}
 */
function chipIsGatedOn(control, label, condition) {
    const open = control.indexOf('<!-- ko if: ' + condition + ' -->');
    if (open === -1) return false;
    const close = control.indexOf('<!-- /ko -->', open);
    const labelIndex = control.indexOf("i18n: '" + label + "'");
    return labelIndex > open && labelIndex < close;
}

/* ------------------------------------------------------------------ *
 * Renderer harness
 * ------------------------------------------------------------------ */

/**
 * A jQuery double backed by a single record per selector, so a `val('')` or a
 * `trigger('focus')` the production code performs is readable afterwards.
 *
 * @returns {object} `{ $, nodes }` — `nodes` is selector → recorded state
 */
function makeFieldDom() {
    const nodes = {};
    function nodeFor(selector) {
        if (!nodes[selector]) {
            nodes[selector] = { value: 'Previously Picked Ltd', triggered: [], attrs: {} };
        }
        return nodes[selector];
    }
    function $(selector) {
        const node = typeof selector === 'string' ? nodeFor(selector) : { attrs: {}, triggered: [] };
        const api = {
            length: 1,
            val: function (v) {
                if (arguments.length === 0) return node.value;
                node.value = v;
                return api;
            },
            trigger: function (name) { node.triggered.push(name); return api; },
            attr: function (name, v) { node.attrs[name] = v; return api; },
            text: function () { return api; },
            hide: function () { return api; },
            show: function () { return api; },
            find: function () { return api; },
            closest: function () { return api; },
            on: function () { return api; },
            off: function () { return api; }
        };
        return api;
    }
    $.async = function () {};
    $.extend = Object.assign;
    return { $: $, nodes: nodes };
}

/**
 * A `_companySearchControl` stand-in recording every call the mode
 * transitions make on it.
 *
 * @returns {object} the double, with a `calls` array of method names
 */
function makeControlDouble() {
    const calls = [];
    return {
        calls: calls,
        abortActiveRequest: function () { calls.push('abortActiveRequest'); return true; },
        destroy: function () { calls.push('destroy'); return true; },
        getField: function () { return { attr: function () {} }; },
        getSearchForCompanyLink: function () {
            calls.push('getSearchForCompanyLink');
            return { hide: function () { calls.push('hideLink'); } };
        },
        showSearchForCompanyLink: function () { calls.push('showSearchForCompanyLink'); },
        hideSearchForCompanyLink: function () { calls.push('hideSearchForCompanyLink'); },
        bind: function () { calls.push('bind'); }
    };
}

/**
 * The renderer, with the collaborators the mode transitions reach for
 * replaced by recorders. `enableCompanySearch` and `fillCustomerData` are
 * stubbed because both walk the quote's address objects, which this harness
 * does not model — and both are asserted on, so a transition dropping the
 * call fails rather than passing quietly.
 *
 * @returns {object} `{ renderer, capture, dom, control, enableCalls, revertCalls }`
 */
function loadRenderer() {
    const dom = makeFieldDom();
    const revertCalls = [];
    const renderer = loadAmdModule(RENDERER, {
        jquery: dom.$,
        'Two_Gateway/js/model/company-search': {
            revertAutofilledAddress: function () { revertCalls.push(1); },
            applyAddress: function () {},
            lookupCompanyAddress: function () { return Promise.resolve(null); }
        }
    });
    renderer.getCode = function () { return 'two_payment'; };
    // The search control and the mode transitions live on the renderer's
    // CompanyCapture, so the doubles have to be installed there — a stub on the
    // renderer's delegate is never consulted by CompanyCapture's own calls.
    const capture = renderer.companyCapture();
    capture._companySearchControl = makeControlDouble();
    const enableCalls = [];
    capture.enableCompanySearch = function (options) { enableCalls.push(options); };
    renderer.fillCustomerData = function () {};
    // Every test starts from the state a fresh tile is in. The observables
    // live on the shared component literal, so a previous test's mode would
    // otherwise leak into this one.
    renderer.captureMode('registered');
    renderer.showSoleTrader(false);
    renderer.companyId('');
    return {
        renderer: renderer,
        capture: capture,
        dom: dom,
        control: capture._companySearchControl,
        enableCalls: enableCalls,
        revertCalls: revertCalls
    };
}

describe('the company-capture mode control offers three peer options', function () {
    let control;

    beforeAll(function () {
        control = modeControlMarkup(readSource(TEMPLATE));
    });

    test('there is exactly one mode control, not one per mode', () => {
        const template = readSource(TEMPLATE);
        expect(template.match(/<div class="mode_selector"/g)).toHaveLength(1);
    });

    test('it holds three chips, in the order the siblings present them', () => {
        expect(control.match(/class="mode_item"/g)).toHaveLength(3);
        const order = ['Registered company', 'Sole trader', 'Enter manually'].map(
            function (label) {
                return control.indexOf("i18n: '" + label + "'");
            }
        );
        order.forEach(function (index) {
            expect(index).toBeGreaterThan(-1);
        });
        expect(order).toEqual([...order].sort((a, b) => a - b));
    });

    test.each([
        ['Registered company', 'registered'],
        ['Sole trader', 'soletrader'],
        ['Enter manually', 'manual']
    ])('the %s chip is selected by captureMode, never statically', (label, mode) => {
        const chip = chipMarkup(control, label);
        expect(chip).toContain("captureMode() === '" + mode + "'");
        expect(chip).toContain("'data-element':");
        // The two-block markup this replaced hardcoded the selected chip.
        expect(chip).not.toMatch(/data-element\s*=\s*["']selected-element["']/);
    });

    test.each([
        ['Registered company', 'registeredOrganisationMode({ openDropdown: true })'],
        ['Sole trader', 'soleTraderMode()'],
        ['Enter manually', 'manualEntryMode()']
    ])('the %s chip is clickable and calls its own transition', (label, call) => {
        expect(chipMarkup(control, label)).toContain('click: () => ' + call);
    });

    test('sole trader is offered only where the registry supports it', () => {
        expect(chipIsGatedOn(control, 'Sole trader', 'showModeTab')).toBe(true);
    });

    test('manual entry is offered only where showManualEntryChip() allows it', () => {
        expect(
            chipIsGatedOn(control, 'Enter manually', 'showManualEntryChip()')
        ).toBe(true);
    });

    test.each([
        [true, true, true, 'setting on, tile owns the field'],
        [true, false, false, 'setting on, address area owns the field'],
        [false, true, false, 'setting off, tile owns the field'],
        [false, false, false, 'setting off, address area owns the field']
    ])(
        'showManualEntryChip() with setting %s and tile-active %s is %s (%s)',
        (settingEnabled, tileActive, expected) => {
            const { renderer, capture } = loadRenderer();
            renderer.isAddressAreaCompanySearchEnabled = settingEnabled;
            capture.isTileCompanySearchActive = function () { return tileActive; };

            expect(renderer.showManualEntryChip()).toBe(expected);
        }
    );

    test('registered is offered unconditionally — it is the way out of the other two', () => {
        expect(chipIsGatedOn(control, 'Registered company', 'showModeTab')).toBe(false);
        expect(
            chipIsGatedOn(control, 'Registered company', 'isTileCompanySearchActive()')
        ).toBe(false);
    });

    test('the control itself renders whenever any of its chips would', () => {
        const template = readSource(TEMPLATE);
        const controlIndex = template.indexOf('<div class="mode_selector"');
        const gate = template.lastIndexOf(
            '<!-- ko if: showModeTab() || isTileCompanySearchActive() -->',
            controlIndex
        );
        expect(gate).toBeGreaterThan(-1);
    });
});

describe('switching between the three capture modes', function () {
    test('a fresh tile starts in registered-organisation mode', () => {
        const { renderer } = loadRenderer();

        expect(renderer.captureMode()).toBe('registered');
    });

    test('the manual-entry chip alone reaches manual entry', () => {
        const { renderer, dom, control, revertCalls } = loadRenderer();
        renderer.companyId('98765432');

        renderer.manualEntryMode();

        expect(renderer.captureMode()).toBe('manual');
        // Search mode is genuinely left behind: the picked company is gone,
        // the widget is torn down, and the field is blank and typeable.
        expect(renderer.companyId()).toBe('');
        expect(renderer.isCompanyCaptured()).toBe(false);
        expect(dom.nodes['input#company_name'].value).toBe('');
        // An in-flight search must not come back up onto a destroyed picker,
        // so the abort has to both happen and happen first.
        expect(control.calls).toContain('abortActiveRequest');
        expect(control.calls).toContain('destroy');
        expect(control.calls.indexOf('abortActiveRequest')).toBeLessThan(
            control.calls.indexOf('destroy')
        );
        expect(control.calls).toContain('showSearchForCompanyLink');
        // Destroying the widget removes whatever had focus with it.
        expect(dom.nodes['input#company_name'].triggered).toContain('focus');
        // Nothing to revert: this transition did not come from sole trader.
        expect(revertCalls).toHaveLength(0);
    });

    test('manual entry from sole-trader mode discards the sole-trader identity', () => {
        const { renderer, control, revertCalls } = loadRenderer();
        renderer.showSoleTrader(true);
        renderer.captureMode('soletrader');
        renderer.companyId('ST-SYNTH-007');
        renderer.showPopupMessage(true);

        renderer.manualEntryMode();

        expect(renderer.captureMode()).toBe('manual');
        expect(renderer.showSoleTrader()).toBe(false);
        expect(renderer.showPopupMessage()).toBe(false);
        expect(renderer.companyId()).toBe('');
        // The address half of the same discard.
        expect(revertCalls).toHaveLength(1);
        expect(control.calls).toContain('destroy');
    });

    test('the registered chip returns to search and lands the buyer in it', () => {
        const { renderer, control, enableCalls } = loadRenderer();
        renderer.manualEntryMode();

        renderer.registeredOrganisationMode({ openDropdown: true });

        expect(renderer.captureMode()).toBe('registered');
        expect(enableCalls).toEqual([{ openDropdown: true }]);
        // Two routes back into search would otherwise both stay live.
        expect(control.calls).toContain('hideSearchForCompanyLink');
    });

    test('the init path re-enters search mode without popping the dropdown open', () => {
        const { renderer, enableCalls } = loadRenderer();

        renderer.registeredOrganisationMode();

        expect(renderer.captureMode()).toBe('registered');
        expect(enableCalls).toEqual([undefined]);
    });

    test('entering the sole-trader UI selects its chip', () => {
        const { renderer } = loadRenderer();

        renderer.enterSoleTraderUi();

        expect(renderer.captureMode()).toBe('soletrader');
        expect(renderer.showSoleTrader()).toBe(true);
    });

    test('the "Search for company" link and the registered chip agree on the mode', () => {
        const src = readSource(CAPTURE);
        const hookIndex = src.indexOf('onReturnToSearch: function');
        expect(hookIndex).toBeGreaterThan(-1);
        expect(src.slice(hookIndex, hookIndex + 200)).toContain("captureMode('registered')");
    });
});
