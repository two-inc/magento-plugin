/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-554 — every way the popover closes hands the buyer back to the company
 * field, and the field's own open-on-focus opener is held off for that one
 * programmatic focus alone.
 *
 * Mutation-resistance notes:
 *  - each case drives a REAL close path (a dispatched key event, a dispatched
 *    mousedown, a settled search the buyer picks from) rather than calling
 *    `close()`, so a path that stops reaching the shared close fails here;
 *  - the reopen cases assert the panel is open AFTER the close, which is what
 *    separates a correctly scoped suppression from a flag left set;
 *  - jsdom implements no sequential focus navigation, so the Tab-arrival case
 *    is a programmatic `focus()` on the field. What a real browser adds is in
 *    the PR's exploratory notes.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel, isProxyRoute, proxyEnvelope } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const GLOBALS = { document: document, window: window };

const FIELD = '#company_name';
const OUTSIDE = '#elsewhere';
const PANEL = '.two-company-dropdown';
const QUERY = '.two-company-dropdown__query';
const ROW = '.two-company-dropdown__row';
const CHIP = '.two-company-mode-chip';

const ONE_HIT = {
    items: [{ name: 'Alpha Ltd', highlight: 'Alpha Ltd', national_identifier: { id: '1' } }]
};

/** `$.ajax` replaced with jqXHRs the test settles by hand. */
function installAjaxDouble() {
    const requests = [];
    $.ajax = function (options) {
        const bound = { done: [], fail: [], always: [] };
        const jqxhr = {
            options: options,
            done: function (fn) { bound.done.push(fn); return jqxhr; },
            fail: function (fn) { bound.fail.push(fn); return jqxhr; },
            always: function (fn) { bound.always.push(fn); return jqxhr; },
            abort: function () {
                bound.fail.forEach(function (fn) { fn({ status: 0 }, 'abort'); });
                bound.always.forEach(function (fn) { fn(); });
            },
            settleDone: function (data) {
                const payload = isProxyRoute(options && options.url) ? proxyEnvelope(data) : data;
                bound.done.forEach(function (fn) { fn(payload); });
                bound.always.forEach(function (fn) { fn(); });
            }
        };
        requests.push(jqxhr);
        return jqxhr;
    };
    return requests;
}

function nextTick() {
    return new Promise(function (resolve) { setTimeout(resolve, 1); });
}

/**
 * A bound panel over the fixture.
 *
 * @param {object} [overrides] panel constructor options to replace
 * @returns {object} `{ panel, requests, selected }`
 */
function setup(overrides) {
    document.body.innerHTML =
        '<div class="control"><input id="company_name" type="text"></div>' +
        '<button id="elsewhere" type="button">elsewhere</button>';

    const requests = installAjaxDouble();
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    companySearch.clearResultCache();
    companySearch.SEARCH_DEBOUNCE_MS = 0;

    const selected = [];
    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const panel = new CompanySearchPanel(Object.assign({
        fieldSelector: FIELD,
        config: { checkoutApiUrl: 'https://api.example.test' },
        getCountryCode: function () { return 'gb'; },
        getSelectedMode: function () { return 'registered'; },
        onSelect: function (item) { selected.push(item); }
    }, overrides || {}));
    panel.bind();

    // Bootstrapped guard: with no built panel every assertion below is vacuous.
    expect(document.querySelector(QUERY)).not.toBeNull();
    return {
        panel: panel,
        requests: requests,
        selected: selected,
        // The single-open slot is a closure inside the loaded module, so a
        // second panel only shares it when it comes from THIS constructor.
        CompanySearchPanel: CompanySearchPanel
    };
}

function panelIsOpen() {
    const node = document.querySelector(PANEL);
    return !!node && !node.hasAttribute('hidden');
}

function fieldNode() {
    return document.querySelector(FIELD);
}

function dispatchMousedown(node) {
    node.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
}

function pressKey(node, key) {
    node.dispatchEvent(new window.KeyboardEvent('keydown', { key: key, bubbles: true, cancelable: true }));
}

/** Open, search and settle one hit, so a row the buyer can pick exists. */
async function openWithRows(ctx) {
    ctx.panel.open();
    const query = document.querySelector(QUERY);
    query.value = 'alp';
    query.dispatchEvent(new window.Event('input', { bubbles: true }));
    await nextTick();
    ctx.requests[ctx.requests.length - 1].settleDone(ONE_HIT);
    await nextTick();
    expect(document.querySelectorAll(ROW)).toHaveLength(1);
}

/** Open with two real chips, so a mode the buyer can pick exists. */
function openWithChips(ctx, onManual) {
    ctx.panel.getChips = function () {
        return [
            { mode: 'registered', text: 'Registered company', onActivate: function () {} },
            { mode: 'manual', text: 'Enter manually', onActivate: onManual }
        ];
    };
    ctx.panel.open();
    const chips = document.querySelectorAll(CHIP);
    expect(chips).toHaveLength(2);
    return chips;
}

const realAjax = $.ajax;

afterEach(() => {
    $.ajax = realAjax;
    $(document).off('mousedown');
});

describe('every close path hands focus back to the company field', () => {
    test.each([
        {
            drive: async (ctx) => {
                ctx.panel.open();
                pressKey(document.querySelector(QUERY), 'Escape');
            },
            description: 'Escape inside the panel'
        },
        {
            drive: async (ctx) => {
                ctx.panel.open();
                dispatchMousedown(document.querySelector(OUTSIDE));
            },
            description: 'a mousedown elsewhere on the page'
        },
        {
            drive: async (ctx) => {
                await openWithRows(ctx);
                dispatchMousedown(document.querySelector(ROW));
                expect(ctx.selected).toHaveLength(1);
            },
            description: 'a company adopted from the results'
        },
        {
            drive: async (ctx) => {
                openWithChips(ctx, function () {});
                ctx.panel.releaseField();
            },
            description: 'manual entry taking the field over'
        },
        {
            drive: async (ctx) => {
                ctx.panel.open();
                ctx.panel.close();
            },
            description: 'the capture controller closing it, as the sole-trader signup does on answering'
        }
    ])('closed with focus on the field after $description', async ({ drive }) => {
        const ctx = setup();

        await drive(ctx);

        expect(panelIsOpen()).toBe(false);
        expect(document.activeElement).toBe(fieldNode());
        // Manual entry hands the field back as a plain input and takes the
        // combobox attributes with it; every other path leaves `false`.
        expect(fieldNode().getAttribute('aria-expanded')).not.toBe('true');
    });
});

describe('focus leaving the panel closes it and leaves the buyer where they went', () => {
    /**
     * The deferred close only runs once focus has settled on another control,
     * so taking focus back would undo the buyer's own Tab (TWO-25326).
     */
    test('the control focus moved to keeps it', async () => {
        const ctx = setup();
        const chips = openWithChips(ctx, function () {});
        chips[chips.length - 1].focus();
        const next = document.querySelector(OUTSIDE);

        next.focus();
        await nextTick();

        expect(panelIsOpen()).toBe(false);
        expect(document.activeElement).toBe(next);
        expect(fieldNode().getAttribute('aria-expanded')).toBe('false');
    });
});

describe('the opener suppression covers the close\'s own focus and nothing after it', () => {
    test.each([
        {
            reopen: () => { pressKey(fieldNode(), 'a'); },
            description: 'any keydown on the field'
        },
        {
            reopen: () => { dispatchMousedown(fieldNode()); },
            description: 'a click on the field'
        },
        {
            reopen: () => {
                document.querySelector(OUTSIDE).focus();
                fieldNode().focus();
            },
            description: 'focus arriving at the field from elsewhere, as a Tab back does'
        },
        {
            reopen: () => {
                fieldNode().value = 'Alp';
                fieldNode().dispatchEvent(new window.Event('input', { bubbles: true }));
            },
            description: 'typing into the field, which an IME composition reaches instead of keydown'
        }
    ])('the popover comes back on $description', ({ reopen }) => {
        const ctx = setup();
        ctx.panel.open();
        pressKey(document.querySelector(QUERY), 'Escape');
        expect(panelIsOpen()).toBe(false);

        reopen();

        expect(panelIsOpen()).toBe(true);
        expect(fieldNode().getAttribute('aria-expanded')).toBe('true');
    });
});

describe('a second popover opening retires the first', () => {
    /**
     * The retired panel returns focus to its own field like any other close,
     * and the opening one places focus afterwards, so the buyer lands in the
     * popover they asked for.
     */
    test('the opening panel keeps the focus it places', () => {
        const first = setup();
        first.panel.open();

        document.body.insertAdjacentHTML(
            'beforeend',
            '<div class="control"><input id="company_name_two" type="text"></div>'
        );
        const second = new first.CompanySearchPanel({
            fieldSelector: '#company_name_two',
            config: { checkoutApiUrl: 'https://api.example.test' },
            getCountryCode: function () { return 'gb'; },
            getSelectedMode: function () { return 'registered'; }
        });
        second.bind();

        second.open();

        expect(first.panel.isOpen()).toBe(false);
        expect(document.activeElement).not.toBe(fieldNode());
        expect(second.isOpen()).toBe(true);
    });
});
