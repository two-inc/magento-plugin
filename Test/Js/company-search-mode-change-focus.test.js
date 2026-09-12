/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-554 — changing capture mode inside the popover, and pressing on the
 * popover's own dead space, both leave the buyer somewhere.
 *
 * jsdom CAVEATS this suite works around explicitly:
 *  - a browser does not blur the caret out of a hidden row until it restyles,
 *    which is AFTER the handler that hid it, and jsdom never blurs it at all.
 *    So neither engine reports the caret as lost inside `syncChips()`, and the
 *    production code cannot ask where focus is — these cases pin that it asks
 *    the node instead;
 *  - a press performs no default action, so the dead-space cases assert the
 *    press was cancelled — the one thing that stops the browser blurring the
 *    caret — rather than an unmoved caret alone;
 *  - there is no sequential focus navigation, so the keyboard route out of an
 *    occluded field is verified in a real browser, not here.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const GLOBALS = { document: document, window: window };

const FIELD = '#company_name';
const PANEL = '.two-company-dropdown';
const QUERY = '.two-company-dropdown__query';
const MESSAGE = '.two-company-dropdown__message';
const CHIP = '.two-company-mode-chip';

/**
 * A bound panel whose chips drive the three modes the way the capture component
 * does: registered re-opens on the query field, sole trader repaints the chips
 * and leaves the popover up for the hosted signup, manual entry takes the field.
 *
 * @returns {object} `{ panel, mode }`
 */
function setup() {
    document.body.innerHTML =
        '<div class="control"><input id="company_name" type="text"></div>' +
        '<button id="elsewhere" type="button">elsewhere</button>';

    $.ajax = function () {
        return { done: function () { return this; }, fail: function () { return this; }, always: function () { return this; }, abort: function () {} };
    };
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    companySearch.clearResultCache();

    const state = { mode: 'registered' };
    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const panel = new CompanySearchPanel({
        fieldSelector: FIELD,
        config: { checkoutApiUrl: 'https://api.example.test' },
        getCountryCode: function () { return 'gb'; },
        getSelectedMode: function () { return state.mode; },
        getChips: function () {
            return [
                {
                    mode: 'registered',
                    text: 'Registered company',
                    onActivate: function () {
                        state.mode = 'registered';
                        panel.reclaimField();
                        panel.bind({ open: true });
                    }
                },
                {
                    mode: 'soletrader',
                    text: 'Sole trader',
                    onActivate: function () {
                        state.mode = 'soletrader';
                        panel.syncChips();
                    }
                },
                {
                    mode: 'manual',
                    text: 'Enter manually',
                    onActivate: function () {
                        state.mode = 'manual';
                        panel.releaseField();
                    }
                }
            ];
        }
    });
    panel.bind();

    // Bootstrapped guard: with no built panel every assertion below is vacuous.
    expect(document.querySelector(QUERY)).not.toBeNull();
    return { panel: panel, state: state };
}

function fieldNode() {
    return document.querySelector(FIELD);
}

function panelIsOpen() {
    const node = document.querySelector(PANEL);
    return !!node && !node.hasAttribute('hidden');
}

function chipFor(mode) {
    return document.querySelector(CHIP + '[data-two-chip="' + mode + '"]');
}

/** A real click, which is a press the chip cancels and then a click. */
function clickChip(mode) {
    const chip = chipFor(mode);
    chip.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
    chip.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
}

function pressMouse(node) {
    const event = new window.MouseEvent('mousedown', { bubbles: true, cancelable: true });
    node.dispatchEvent(event);
    return event;
}

function pressKey(node, key) {
    const event = new window.KeyboardEvent('keydown', { key: key, bubbles: true, cancelable: true });
    node.dispatchEvent(event);
    return event;
}

const realAjax = $.ajax;

afterEach(() => {
    $.ajax = realAjax;
    $(document).off('mousedown');
});

describe('a mode change leaves the buyer somewhere', () => {
    test.each([
        {
            mode: 'registered',
            from: 'soletrader',
            expectOpen: true,
            focused: () => document.querySelector(QUERY),
            description: 'registered company puts the caret in the query field'
        },
        {
            mode: 'soletrader',
            from: 'registered',
            expectOpen: true,
            focused: () => chipFor('registered'),
            description: 'sole trader withdraws the query row and hands focus to a chip'
        },
        {
            mode: 'manual',
            from: 'registered',
            expectOpen: false,
            focused: fieldNode,
            description: 'manual entry closes the popover and takes the field'
        }
    ])('$description', ({ mode, from, expectOpen, focused }) => {
        const ctx = setup();
        if (from !== 'registered') clickChip(from);
        ctx.panel.open();

        clickChip(mode);

        expect(panelIsOpen()).toBe(expectOpen);
        expect(document.activeElement).toBe(focused());
    });

    test('the company field where the mode leaves nothing in the popover to focus', () => {
        const ctx = setup();
        // Only the mode the buyer is in is offered, so the chip row is withheld
        // and the withdrawn query row leaves the open panel with nothing in it.
        ctx.panel.isChipVisible = function (mode) { return mode === 'soletrader'; };
        ctx.panel.open();
        ctx.state.mode = 'soletrader';

        ctx.panel.syncChips();

        expect(document.activeElement).toBe(fieldNode());
    });
});

describe('Escape closes from anywhere inside the popover', () => {
    test.each([
        {
            reach: () => document.querySelector(QUERY),
            description: 'the query field'
        },
        {
            reach: () => chipFor('manual'),
            description: 'a mode chip, which is what holds focus once the query row is withdrawn'
        }
    ])('Escape on $description closes it and hands the field back', ({ reach }) => {
        const ctx = setup();
        ctx.panel.open();
        const from = reach();
        from.focus();

        pressKey(from, 'Escape');

        expect(panelIsOpen()).toBe(false);
        expect(document.activeElement).toBe(fieldNode());
    });

    test('the opener suppression covers that focus and nothing after it', () => {
        const ctx = setup();
        ctx.panel.open();
        clickChip('soletrader');

        pressKey(document.activeElement, 'Escape');
        expect(panelIsOpen()).toBe(false);

        pressKey(fieldNode(), 'a');

        expect(panelIsOpen()).toBe(true);
    });

    test('Escape on the company field the signup launch parks focus on', () => {
        const ctx = setup();
        ctx.panel.open();
        clickChip('soletrader');
        // The popover is held up for the signup's duration with focus on the
        // field, which sits outside the panel node Escape is bound to.
        ctx.panel.restoreFieldFocus();

        const event = pressKey(fieldNode(), 'Escape');

        expect(panelIsOpen()).toBe(false);
        expect(document.activeElement).toBe(fieldNode());
        expect(event.defaultPrevented).toBe(true);
    });
});

describe('a press on the popover\'s dead space changes nothing', () => {
    test.each([
        {
            target: () => document.querySelector(PANEL),
            cancelled: true,
            description: 'the panel\'s own padding'
        },
        {
            target: () => document.querySelector(MESSAGE),
            cancelled: true,
            description: 'the message line'
        },
        {
            target: () => document.querySelector(QUERY),
            cancelled: false,
            description: 'the query field, which the press must still be able to place the caret in'
        },
        {
            target: () => document.querySelector('.two-company-mode-chips'),
            cancelled: true,
            description: 'the chip row between two chips'
        }
    ])('$description', ({ target, cancelled }) => {
        const ctx = setup();
        ctx.panel.open();
        const before = document.activeElement;

        const event = pressMouse(target());

        expect(event.defaultPrevented).toBe(cancelled);
        expect(panelIsOpen()).toBe(true);
        expect(document.activeElement).toBe(before);
    });
});
