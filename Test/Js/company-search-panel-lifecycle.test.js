/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — the popover's own open/close, teardown and keyboard contract.
 *
 * The neighbouring panel suites each drive the panel to reach something else
 * (the wording, the chips, the transport). What the panel does when it is
 * OPENED, CLOSED, DESTROYED or ARROWED THROUGH had no home, and every one of
 * those guarantees could be deleted from production with the suite still green.
 *
 * Mutation-resistance notes:
 *  - the REAL `company-search.js` over a `$.ajax` double, so "the request was
 *    aborted" is the jqXHR's own state rather than a call record;
 *  - the outside-click and Escape cases dispatch real bubbling DOM events and
 *    read `document.activeElement`, so a handler that is never bound fails;
 *  - the arrow cases assert the ACTIVE ROW INDEX after a sequence of presses,
 *    which is what separates clamping from wrapping — asserting a single press
 *    cannot tell the two apart.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';

const GLOBALS = { document: document, window: window };

const FIELD = '#company_name';
const OUTSIDE = '#elsewhere';
const PANEL = '.two-company-dropdown';
const QUERY = '.two-company-dropdown__query';
const RESULTS = '.two-company-dropdown__results';
const ROW = '.two-company-dropdown__row';
const ROW_ACTIVE = 'two-company-dropdown__row--active';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50
};

const THREE_HITS = {
    items: [
        { name: 'Alpha Ltd', highlight: '<em>Alp</em>ha Ltd', national_identifier: { id: '1' } },
        { name: 'Alpine Ltd', highlight: '<em>Alp</em>ine Ltd', national_identifier: { id: '2' } },
        { name: 'Alpaca Ltd', highlight: '<em>Alp</em>aca Ltd', national_identifier: { id: '3' } }
    ]
};

/**
 * `$.ajax` replaced with jqXHRs the test settles by hand, so an abort is the
 * request's own recorded state.
 *
 * @returns {Array} the requests handed out, newest last
 */
function installAjaxDouble() {
    const requests = [];
    $.ajax = function (options) {
        const bound = { done: [], fail: [], always: [] };
        const jqxhr = {
            options: options,
            aborted: false,
            done: function (fn) { bound.done.push(fn); return jqxhr; },
            fail: function (fn) { bound.fail.push(fn); return jqxhr; },
            always: function (fn) { bound.always.push(fn); return jqxhr; },
            abort: function () {
                jqxhr.aborted = true;
                bound.fail.forEach(function (fn) { fn({ status: 0 }, 'abort'); });
                bound.always.forEach(function (fn) { fn(); });
            },
            settleDone: function (data) {
                bound.done.forEach(function (fn) { fn(data); });
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
 * A bound panel over the fixture, plus the requests it issues.
 *
 * @param {number} [debounceMs] keystroke-to-request delay, 0 unless a case is
 *        about a search that has not fired yet
 * @returns {object} `{ panel, requests }`
 */
function setup(debounceMs) {
    document.body.innerHTML =
        '<div class="control"><input id="company_name" type="text"></div>' +
        '<button id="elsewhere" type="button">elsewhere</button>';

    const requests = installAjaxDouble();
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    companySearch.clearResultCache();
    companySearch.SEARCH_DEBOUNCE_MS = debounceMs || 0;

    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const panel = new CompanySearchPanel({
        fieldSelector: FIELD,
        config: BASE_CONFIG,
        getCountryCode: function () { return 'gb'; }
    });
    panel.bind();

    // Bootstrapped guard: without a built panel every assertion below is vacuous.
    expect(document.querySelector(QUERY)).not.toBeNull();
    return { panel: panel, requests: requests };
}

function panelIsOpen() {
    const node = document.querySelector(PANEL);
    return !!node && !node.hasAttribute('hidden');
}

function mousedownOn(selector) {
    document
        .querySelector(selector)
        .dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
}

function pressKey(target, key) {
    target.dispatchEvent(
        new window.KeyboardEvent('keydown', { key: key, bubbles: true, cancelable: true })
    );
}

/**
 * Type into the panel's query field the way a buyer does.
 *
 * A NATIVE `input` event, not jQuery's `.trigger('input')`: the panel binds
 * with `addEventListener`, and jQuery's trigger walks its own handler store
 * and then calls `elem[type]()` — there is no `elem.input()`, so a jQuery
 * trigger reaches nothing the panel bound.
 *
 * @param {string} value
 */
function typeQuery(value) {
    const field = document.querySelector(QUERY);
    field.value = value;
    field.dispatchEvent(new window.Event('input', { bubbles: true }));
}

/** Open, search and settle three hits, so the rows the arrows walk are real. */
async function openWithRows(ctx) {
    ctx.panel.open();
    typeQuery('alp');
    await nextTick();
    ctx.requests[ctx.requests.length - 1].settleDone(THREE_HITS);
    await nextTick();
    expect(document.querySelectorAll(ROW)).toHaveLength(3);
}

function activeRowIndex() {
    return Array.from(document.querySelectorAll(ROW))
        .findIndex(function (row) { return row.classList.contains(ROW_ACTIVE); });
}

/**
 * Live `mousedown` handlers on the document, whoever owns them.
 *
 * Counted by intercepting `addEventListener`/`removeEventListener` rather than
 * by reading a framework's handler store: what the teardown has to guarantee is
 * that the listener is GONE, and the count has to fall when it is removed
 * however it was bound.
 */
const liveDocumentMousedown = new Set();
const nativeAdd = document.addEventListener.bind(document);
const nativeRemove = document.removeEventListener.bind(document);
document.addEventListener = function (type, handler, options) {
    if (type === 'mousedown') liveDocumentMousedown.add(handler);
    return nativeAdd(type, handler, options);
};
document.removeEventListener = function (type, handler, options) {
    if (type === 'mousedown') liveDocumentMousedown.delete(handler);
    return nativeRemove(type, handler, options);
};

function documentPanelListeners() {
    return liveDocumentMousedown.size;
}

const realAjax = $.ajax;

afterEach(() => {
    $.ajax = realAjax;
    $(document).off('mousedown');
});

describe('closing drops the search the buyer walked away from', () => {
    test('a debounced search that has not fired yet never goes out', async () => {
        const ctx = setup(30);
        ctx.panel.open();
        typeQuery('alp');

        ctx.panel.close();
        await new Promise((resolve) => setTimeout(resolve, 60));

        expect(ctx.requests).toHaveLength(0);
    });

    test('a request already on the wire is aborted', async () => {
        const ctx = setup();
        ctx.panel.open();
        typeQuery('alp');
        await nextTick();
        expect(ctx.requests).toHaveLength(1);

        ctx.panel.close();

        // Left running it answers up to 30s later, into a panel nobody is
        // looking at, and the next open inherits its rows.
        expect(ctx.requests[0].aborted).toBe(true);
    });

    test('re-opening starts on an empty query, not the last session', () => {
        const ctx = setup();
        ctx.panel.open();
        typeQuery('alpha');
        ctx.panel.close();

        ctx.panel.open();

        expect(document.querySelector(QUERY).value).toBe('');
    });
});

describe('the panel closes when the buyer clicks away from it', () => {
    test.each([
        [OUTSIDE, false, 'a click elsewhere on the page closes it'],
        [QUERY, true, 'a click inside the panel does not'],
        [FIELD, true, 'a click on the field it is anchored to does not']
    ])('mousedown on %s leaves it open: %p (%s)', (target, staysOpen) => {
        const ctx = setup();
        ctx.panel.open();
        expect(panelIsOpen()).toBe(true);

        mousedownOn(target);

        expect(panelIsOpen()).toBe(staysOpen);
    });
});

describe('Escape hands the buyer back to the field', () => {
    test('focus lands on the company field and the panel stays shut', () => {
        const ctx = setup();
        ctx.panel.open();
        expect(document.activeElement).toBe(document.querySelector(QUERY));

        pressKey(document.querySelector(QUERY), 'Escape');

        expect(document.activeElement).toBe(document.querySelector(FIELD));
        // The field's own focus opener must not reopen what Escape just closed.
        expect(panelIsOpen()).toBe(false);
    });
});

/**
 * Open the panel with two real chips in it, so a case about tabbing BETWEEN
 * its controls has more than one to move across.
 *
 * @param {object} ctx
 * @returns {NodeList} the rendered chips
 */
function withChips(ctx) {
    ctx.panel.getChips = function () {
        return [
            { mode: 'registered', text: 'Registered company', onActivate: function () {} },
            { mode: 'manual', text: 'Enter manually', onActivate: function () {} }
        ];
    };
    ctx.panel.open();
    return document.querySelectorAll('.two-company-mode-chip');
}

describe('the panel closes when focus leaves it', () => {
    test('tabbing off the last chip closes it', async () => {
        const ctx = setup();
        const chips = withChips(ctx);
        // Bootstrapped: with no chip rendered the tab-off below has nothing to
        // leave FROM, and the case would pass on a panel that never opened.
        expect(chips.length).toBeGreaterThan(0);
        chips[chips.length - 1].focus();
        expect(panelIsOpen()).toBe(true);

        document.querySelector(OUTSIDE).focus();
        await nextTick();

        expect(panelIsOpen()).toBe(false);
    });

    test('moving between two controls inside it does not close it', async () => {
        const ctx = setup();
        const chips = withChips(ctx);
        expect(chips.length).toBeGreaterThan(0);

        // A focusout followed by a focusin, which is every Tab within the panel.
        chips[0].focus();
        await nextTick();

        expect(panelIsOpen()).toBe(true);
        expect(document.activeElement).toBe(chips[0]);
    });

    test('focus returning to the field it is anchored to does not close it', async () => {
        const ctx = setup();
        ctx.panel.open();

        document.querySelector(FIELD).focus();
        await nextTick();

        expect(panelIsOpen()).toBe(true);
    });

    test('focus DROPPED to <body> does not close it', async () => {
        const ctx = setup();
        ctx.panel.open();

        // Two things do this and neither is the buyer leaving: sole-trader mode
        // hides the row holding the caret, and a scrollbar drag in Chrome moves
        // focus off while the button is still down. Asserted AFTER the tick —
        // the deferred close is exactly what a synchronous assertion misses.
        document.querySelector(QUERY).blur();
        await nextTick();

        expect(document.activeElement).toBe(document.body);
        expect(panelIsOpen()).toBe(true);
    });

    test('a close already scheduled is dropped when the panel is destroyed', () => {
        jest.useFakeTimers();
        try {
            const ctx = setup();
            ctx.panel.open();
            document.querySelector(OUTSIDE).focus();
            const armed = jest.getTimerCount();
            expect(armed).toBeGreaterThan(0);

            ctx.panel.destroy();

            // A timer left armed outlives the panel it would have closed.
            expect(jest.getTimerCount()).toBe(armed - 1);
            jest.runOnlyPendingTimers();
            expect(document.querySelector(PANEL)).toBeNull();
        } finally {
            jest.useRealTimers();
        }
    });
});

describe('teardown leaves nothing bound to the document', () => {
    test('the outside-click listener is removed with the panel', () => {
        const before = documentPanelListeners();
        const ctx = setup();
        expect(documentPanelListeners()).toBe(before + 1);

        ctx.panel.destroy();

        // A checkout re-render builds a fresh panel each time; a listener the
        // teardown leaves behind is one more per render, forever.
        expect(documentPanelListeners()).toBe(before);
    });

    test.each([2, 5, 20])('%i chip syncs leave one listener set, not one per sync', (times) => {
        // The chips are rebuilt from scratch on every sync, and a checkout that
        // re-renders on every totals change syncs them constantly.
        const ctx = setup();
        ctx.panel.getChips = function () {
            return [{ mode: 'registered', text: 'Registered company', onActivate: function () {} }];
        };

        ctx.panel.syncChips();
        const afterFirst = ctx.panel._listeners.length;
        for (let i = 1; i < times; i += 1) ctx.panel.syncChips();

        expect(ctx.panel._listeners.length).toBe(afterFirst);
    });
});

describe('arrow keys walk the rows and stop at the ends', () => {
    test.each([
        [['ArrowUp'], 0, 'ArrowUp with nothing active lands on the first row, not the last'],
        [['ArrowDown'], 0, 'the first ArrowDown lands on the first row'],
        [['ArrowDown', 'ArrowDown', 'ArrowDown'], 2, 'ArrowDown reaches the last row'],
        [['ArrowDown', 'ArrowDown', 'ArrowDown', 'ArrowDown'], 2, 'past the last row it stays there rather than wrapping to the first'],
        [['ArrowDown', 'ArrowUp', 'ArrowUp'], 0, 'past the first row it stays there rather than wrapping to the last']
    ])('%p leaves row %i active (%s)', async (keys, expectedIndex) => {
        const ctx = setup();
        await openWithRows(ctx);

        keys.forEach(function (key) { pressKey(document.querySelector(QUERY), key); });

        expect(activeRowIndex()).toBe(expectedIndex);
    });

    test.each([
        [1, 0],
        [2, 1],
        [3, 2]
    ])('%i ArrowDown presses point aria-activedescendant at row %i', async (presses, index) => {
        const ctx = setup();
        await openWithRows(ctx);

        for (let i = 0; i < presses; i += 1) pressKey(document.querySelector(QUERY), 'ArrowDown');

        const activeId = document.querySelectorAll(ROW)[index].id;
        expect(activeId).toBeTruthy();
        expect(document.querySelector(QUERY).getAttribute('aria-activedescendant')).toBe(activeId);
    });
});

describe('the company field carries the combobox semantics', () => {
    test('it announces the results host it controls, and whether that host is showing', () => {
        const ctx = setup();
        const fieldNode = document.querySelector(FIELD);
        const resultsId = document.querySelector(RESULTS).id;

        expect(resultsId).toBeTruthy();
        expect(fieldNode.getAttribute('role')).toBe('combobox');
        expect(fieldNode.getAttribute('aria-controls')).toBe(resultsId);
        expect(fieldNode.getAttribute('aria-expanded')).toBe('false');

        ctx.panel.open();
        expect(fieldNode.getAttribute('aria-expanded')).toBe('true');

        ctx.panel.close();
        expect(fieldNode.getAttribute('aria-expanded')).toBe('false');
    });
});
