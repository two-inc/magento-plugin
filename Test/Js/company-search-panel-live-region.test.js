/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * What the company-search panel says to a screen reader.
 *
 * The panel painted rows and messages into a listbox nothing was watching, so a
 * buyer who cannot see the panel got silence for every outcome a search has.
 * The live region here carries the same roles, the same politeness and the same
 * announcement points as the reference panel on the sibling platform, which
 * gets them from the autocomplete widget it is built on.
 *
 * jsdom has no accessibility layer, so these assertions read the attributes and
 * the text written into the region; that the region is in the tree at all is
 * pinned by it living outside the `hidden` panel.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const GLOBALS = { document: document, window: window };
const FIELD_SELECTOR = '#company_name';
const LIVE_SELECTOR = '.two-company-dropdown__status';

/** Let the debounce fire and the search promise settle. */
function tick() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** The announcement is deferred, so nothing is in the region until it lands. */
function announced() {
    return new Promise((resolve) =>
        setTimeout(() => {
            const region = document.querySelector(LIVE_SELECTOR);
            resolve(region ? region.textContent : '');
        }, 200)
    );
}

describe("the company-search panel's live region", () => {
    let panel;
    let answer;
    let companySearch;

    beforeEach(() => {
        document.body.innerHTML =
            '<div class="control"><input id="company_name" type="text"></div>';

        answer = { items: [], unavailable: false, aborted: false };
        companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
        companySearch.SEARCH_DEBOUNCE_MS = 0;
        companySearch.MIN_INPUT_LENGTH = 1;
        companySearch.searchCompanies = function () {
            return Promise.resolve(answer);
        };

        const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
        panel = new CompanySearchPanel({ fieldSelector: FIELD_SELECTOR, config: {} });
        panel.bind();
    });

    afterEach(() => {
        panel.destroy();
    });

    /**
     * @param {number} count companies the search should answer with
     */
    async function searchReturning(count) {
        const items = [];

        for (let i = 0; i < count; i++) {
            items.push({ id: 'Example ' + i, text: 'Example ' + i, html: 'Example ' + i });
        }
        answer = { items: items, unavailable: false, aborted: false };
        await runSearch();
    }

    /** Open the panel and type a searchable term into it. */
    async function runSearch() {
        panel.open();
        const query = document.querySelector('.two-company-dropdown__query');

        query.value = 'kaffe';
        query.dispatchEvent(new window.Event('input', { bubbles: true }));
        await tick();
    }

    /**
     * @param {string} key ArrowDown or ArrowUp
     */
    function pressKey(key) {
        document
            .querySelector('.two-company-dropdown__query')
            .dispatchEvent(new window.KeyboardEvent('keydown', { key: key, bubbles: true }));
    }

    describe('the region itself', () => {
        test.each([
            ['role', 'status', 'announced as a status region'],
            ['aria-live', 'assertive', 'interrupts, because the rows have already changed'],
            ['aria-relevant', 'additions', 'the added line is what is read, not a removal']
        ])('carries %s=%s - %s', async (attribute, expected) => {
            await searchReturning(2);
            await announced();

            expect(document.querySelector(LIVE_SELECTOR).getAttribute(attribute)).toBe(expected);
        });

        test('lives outside the panel, which carries `hidden` between opens', async () => {
            await searchReturning(2);
            await announced();

            const region = document.querySelector(LIVE_SELECTOR);

            expect(region.parentElement).toBe(document.body);
            expect(region.closest('.two-company-dropdown')).toBeNull();
        });

        test('goes with the panel it belongs to', async () => {
            await searchReturning(2);
            await announced();
            expect(document.querySelector(LIVE_SELECTOR)).not.toBeNull();

            panel.destroy();

            expect(document.querySelector(LIVE_SELECTOR)).toBeNull();
        });
    });

    describe('what a search says', () => {
        test.each([
            [
                3,
                '3 results are available, use up and down arrow keys to navigate.',
                'a plural count'
            ],
            [1, '1 result is available, use up and down arrow keys to navigate.', 'a single match'],
            [0, 'No matches found', 'the no-matches copy, the same sentence the panel paints']
        ])('%i rows announces %s - %s', async (count, expected) => {
            await searchReturning(count);

            await expect(announced()).resolves.toBe(expected);
        });

        test('a repeat of the same answer is announced again', async () => {
            await searchReturning(2);
            await announced();

            await searchReturning(2);

            await expect(announced()).resolves.toBe(
                '2 results are available, use up and down arrow keys to navigate.'
            );
            expect(document.querySelector(LIVE_SELECTOR).children).toHaveLength(1);
        });

        test.each([
            ['k', 'one character'],
            ['ka', 'two, still under the threshold']
        ])('%s answers nothing, so nothing is announced - %s', async (term) => {
            companySearch.MIN_INPUT_LENGTH = 3;
            panel.open();
            const query = document.querySelector('.two-company-dropdown__query');

            query.value = term;
            query.dispatchEvent(new window.Event('input', { bubbles: true }));
            await tick();

            await expect(announced()).resolves.toBe('');
        });

        test('the search being down says so rather than reporting no matches', async () => {
            answer = { items: [], unavailable: true, aborted: false };
            await runSearch();

            await expect(announced()).resolves.toBe(
                'Company search is unavailable right now. Please try again shortly.'
            );
        });
    });

    describe('what arrow-key navigation says', () => {
        test.each([
            [['ArrowDown'], 'Example 0', 'the first row'],
            [['ArrowDown', 'ArrowDown'], 'Example 1', 'the row moved onto'],
            [['ArrowDown', 'ArrowUp'], 'Example 0', 'the row moved back to']
        ])('%j announces %s - %s', async (keys, expected) => {
            await searchReturning(3);
            await announced();

            keys.forEach(pressKey);

            await expect(announced()).resolves.toBe(expected);
        });
    });

    describe('the query field says whether its list is showing', () => {
        test.each([
            [false, 'false', 'the panel is hidden, so it claims nothing'],
            [true, 'true', 'the panel is open']
        ])('open=%s gives aria-expanded=%s - %s', (open, expected) => {
            if (open) panel.open();

            expect(
                document.querySelector('.two-company-dropdown__query').getAttribute('aria-expanded')
            ).toBe(expected);
        });
    });
});
