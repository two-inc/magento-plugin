/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — the popover's appearance inside a field-width panel.
 *
 * The panel can be as narrow as half a column on a three-column checkout.
 * Three rules carry the whole difference between a control that reads and one
 * that does not at that width, and none of them is visible from the DOM:
 *
 *  - result rows are ellipsised on one line, or every result takes three or
 *    four lines and the scroll container gets a horizontal scrollbar too;
 *  - the matched substring comes back inside `mark`, whose UA default is a
 *    yellow highlighter that neither checkout's theme resets;
 *  - the chips are sized to fit two per row rather than stacking into a column.
 *
 * The stylesheet is parsed by jsdom rather than string-matched, so a rule that
 * is present but syntactically dead — or overridden later in the file — fails.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '../..');
const STYLESHEET = path.join(REPO_ROOT, 'view/frontend/web/css/style.css');

/**
 * Build the panel's real DOM shape under the real stylesheet.
 *
 * The nesting is the panel's own: a `mark` styled by a descendant selector
 * computes nothing at all if it is measured outside the row it belongs to.
 *
 * @returns {Object} the computed styles of the row, its mark and a chip
 */
function computedPanelStyles() {
    const style = document.createElement('style');
    style.textContent = fs.readFileSync(STYLESHEET, 'utf8');
    document.head.appendChild(style);

    document.body.innerHTML = [
        '<span class="two-company-field-wrap">',
        '  <div class="two-company-dropdown">',
        '    <div class="two-company-dropdown__results">',
        '      <div class="two-company-dropdown__row" id="row">',
        '        <mark id="mark"><b>Alp</b></mark>ha Ltd',
        '      </div>',
        '    </div>',
        '    <div class="two-company-mode-chips">',
        '      <button type="button" class="two-company-mode-chip" id="chip">Registered company</button>',
        '    </div>',
        '  </div>',
        '</span>'
    ].join('\n');

    return {
        row: window.getComputedStyle(document.getElementById('row')),
        mark: window.getComputedStyle(document.getElementById('mark')),
        chip: window.getComputedStyle(document.getElementById('chip'))
    };
}

/**
 * @param {string} selector exactly as written in the stylesheet
 * @returns {CSSStyleDeclaration} that rule's own declarations
 */
function declaredStyle(selector) {
    const rules = Array.from(document.styleSheets[0].cssRules);
    const rule = rules.find(function (candidate) {
        return candidate.selectorText === selector;
    });
    if (!rule) throw new Error('no rule for ' + selector);
    return rule.style;
}

afterEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

describe('the panel can overhang a narrow field', () => {
    test('the panel declares a min-width wider than a three-column tile\'s field', () => {
        computedPanelStyles();
        expect(declaredStyle('.two-company-dropdown').getPropertyValue('min-width')).toBe('480px');
    });
});

describe('a result row stays on one line', () => {
    test.each([
        ['whiteSpace', 'nowrap'],
        ['overflow', 'hidden'],
        ['textOverflow', 'ellipsis']
    ])('the row declares %s: %s', (property, expected) => {
        expect(computedPanelStyles().row[property]).toBe(expected);
    });
});

describe('the matched substring is not a highlighter pen', () => {
    test('the mark inside a row paints no background of its own', () => {
        expect(computedPanelStyles().mark.backgroundColor).toBe('transparent');
    });

    test('it takes the row\'s own text colour rather than the UA\'s black', () => {
        computedPanelStyles();

        // jsdom resolves `inherit` for `color` back to its own default sheet's
        // value, so the DECLARATION is what can be asserted here.
        expect(declaredStyle('.two-company-dropdown__row mark').color).toBe('inherit');
    });
});

describe('the chips share a row rather than stacking', () => {
    test.each([
        ['flex', '1 1 auto', 'each chip takes a share of the row instead of its intrinsic width'],
        ['minWidth', '84px', 'a short label still reads as a chip'],
        ['fontSize', '13px', 'the theme\'s body size fits one chip per line, not two']
    ])('the chip declares %s: %s — %s', (property, expected) => {
        expect(computedPanelStyles().chip[property]).toBe(expected);
    });
});
