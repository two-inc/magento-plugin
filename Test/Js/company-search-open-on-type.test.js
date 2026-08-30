/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326 §1, the two wording/opening defects that are shared by all three
 * Magento checkout surfaces (Luma, Amasty OneStepCheckout, Fire Checkout —
 * one code path, three renderings):
 *
 *  - the dropdown opened on click and Enter but on NO other key, so a buyer
 *    who focused the company field and simply started typing their company
 *    name saw nothing happen;
 *  - a zero-result search said "No results found", where the cross-platform
 *    wording is "No matches found".
 *
 * Both live in the panel now, so both are driven through the real class over
 * real jsdom nodes — the seeded character is read back off the query field the
 * open actually built, so a fix that seeds before opening fails here rather
 * than passing.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel, dispatchNative } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const COMPONENT_PATH = 'view/frontend/web/js/model/company-capture-component.js';
const ADAPTER_PATH = 'view/frontend/web/js/model/company-capture.js';

const GLOBALS = { document: document, window: window };
const FIELD_SELECTOR = '#company_name';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test'
};

function readRepoFile(relPath) {
    const contents = fs.readFileSync(path.join(__dirname, '..', '..', relPath), 'utf8');
    if (contents.length < 200) {
        throw new Error(relPath + ' fixture looks truncated: ' + contents.length + ' bytes');
    }
    return contents;
}

/** Let the debounce fire and the search promise settle. */
function tick() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

function pressKey(target, key, extra) {
    const event = new window.KeyboardEvent(
        'keydown',
        Object.assign({ key: key, bubbles: true, cancelable: true }, extra || {})
    );
    target.dispatchEvent(event);
    return event;
}

/**
 * What a browser actually does for a printable key: keydown, then the
 * character lands in the field, then `input`. The panel seeds off `input`
 * rather than keydown so that a paste and an IME composition — neither of
 * which reports a printable `key` — take the same route.
 */
function typeInto(target, text, extra) {
    const event = pressKey(target, text, extra);
    if (event.defaultPrevented) return event;
    target.value += text;
    target.dispatchEvent(new window.Event('input', { bubbles: true }));
    return event;
}

function field() {
    return document.querySelector(FIELD_SELECTOR);
}

function queryField() {
    return document.querySelector('.two-company-dropdown__query');
}

function messageText() {
    const node = document.querySelector('.two-company-dropdown__message');
    return node ? node.textContent : null;
}

describe('TWO-25326 §1: any character opens the panel', () => {
    let panel;
    let searched;

    beforeEach(() => {
        document.body.innerHTML = '<div class="control"><input id="company_name" type="text"></div>';

        // Own array per test, not a reassigned outer one: a previous case's
        // panel keeps its debounce timer and would push into whatever the
        // shared binding then pointed at.
        const terms = [];
        searched = terms;
        const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
        companySearch.SEARCH_DEBOUNCE_MS = 0;
        // So one seeded keystroke is enough to reach the wire — the seeding is
        // what these cases are about, not the threshold.
        companySearch.MIN_INPUT_LENGTH = 1;
        companySearch.searchCompanies = function (options) {
            terms.push(options.term);
            return Promise.resolve({ items: [], unavailable: false, aborted: false });
        };

        const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
        panel = new CompanySearchPanel({ fieldSelector: FIELD_SELECTOR, config: BASE_CONFIG });
        panel.bind();
    });

    afterEach(() => {
        panel.destroy();
    });

    test.each([
        ['a', 'a letter'],
        ['9', 'a digit — an org number is a valid query'],
        ['&', 'punctuation']
    ])('%p opens the panel and is seeded into the query field (%s)', (key) => {
        typeInto(field(), key);

        expect(panel.isOpen()).toBe(true);
        // Seeded, not swallowed. Without this the buyer's first keystroke is
        // consumed by the open and they have to type the letter twice.
        expect(queryField().value).toBe(key);
        // And not left behind in the company field, which shows the captured
        // company rather than a half-typed query.
        expect(field().value).toBe('');
    });

    test.each([
        ['株式会社', 'an IME composition, which reports no printable key'],
        ['Acme Trading', 'a paste, which reports no key at all']
    ])('%p reaches the query field (%s)', async (text) => {
        field().value = text;
        field().dispatchEvent(new window.Event('input', { bubbles: true }));
        await tick();

        expect(queryField().value).toBe(text);
        expect(searched).toEqual([text]);
    });

    test('the seeded character starts a search without a second keystroke', async () => {
        typeInto(field(), 'e');
        await tick();

        expect(searched).toEqual(['e']);
    });

    test('Tab is never intercepted — §1 excludes it explicitly, and §4 needs it to navigate', () => {
        const tab = pressKey(field(), 'Tab');

        expect(tab.defaultPrevented).toBe(false);
        expect(panel.isOpen()).toBe(false);
    });

    test.each([
        ['Escape', 'closing'],
        ['ArrowDown', 'walking the list'],
        ['Backspace', 'editing'],
        ['Shift', 'a modifier alone'],
        ['F5', 'a browser key'],
        ['Home', 'caret movement'],
        ['Enter', 'submitting']
    ])('%p is not text entry, so nothing is seeded (%s)', (key) => {
        const event = pressKey(field(), key);

        expect(event.defaultPrevented).toBe(false);
        expect(queryField().value).toBe('');
    });

    test.each([
        [{ ctrlKey: true }, 'Ctrl'],
        [{ metaKey: true }, 'Cmd'],
        [{ altKey: true }, 'Alt']
    ])('%p+v is a browser shortcut, not text entry (%s)', (modifiers) => {
        const event = pressKey(field(), 'v', modifiers);

        expect(event.defaultPrevented).toBe(false);
        expect(panel.isOpen()).toBe(false);
    });

    test('a released field is a plain input again — manual entry types into it directly', () => {
        panel.releaseField();

        const event = pressKey(field(), 'a');

        expect(event.defaultPrevented).toBe(false);
        expect(panel.isOpen()).toBe(false);
    });

    test('a destroyed panel opens nothing', () => {
        panel.destroy();

        const event = pressKey(field(), 'a');

        expect(event.defaultPrevented).toBe(false);
        expect(document.querySelector('.two-company-dropdown')).toBeNull();
    });
});

describe('TWO-25326 §1: zero-result wording', () => {
    test('the message is "No matches found", not select2\'s "No results found"', () => {
        const model = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);

        expect(model.noResultsMessage()).toBe('No matches found');
    });

    test('a zero-result search renders that wording into the panel', async () => {
        document.body.innerHTML = '<div class="control"><input id="company_name" type="text"></div>';
        const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
        companySearch.SEARCH_DEBOUNCE_MS = 0;
        companySearch.searchCompanies = function () {
            return Promise.resolve({ items: [], unavailable: false, aborted: false });
        };
        const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
        const panel = new CompanySearchPanel({ fieldSelector: FIELD_SELECTOR, config: BASE_CONFIG });
        panel.bind();
        panel.open();

        dispatchNative($('.two-company-dropdown__query')[0], 'input', 'exa');
        await tick();

        expect(messageText()).toBe('No matches found');
    });
});

describe('the page-level component holds no parallel search wiring', () => {
    test('it constructs the shared panel rather than rendering results itself', () => {
        const src = readRepoFile(COMPONENT_PATH);

        // The panel class is injected, so the one construction site is the
        // only place a control can come from.
        expect(src.match(/new this\._options\.Panel\(/g)).toHaveLength(1);
        expect(readRepoFile(ADAPTER_PATH)).toContain('Panel: CompanySearchPanel');
        // A second, parallel implementation would be exactly the defect
        // TWO-25326 asked to close — one control, not two.
        expect(src).not.toContain('searchCompanies(');
        expect(src).not.toContain('two-company-dropdown');
    });
});
