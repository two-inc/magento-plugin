/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-554. The popover's capture-mode chips are a keyboard destination, and
 * the payment-term chips in the same stylesheet already ring the focused one.
 * The mode chips carried no focus rule at all, so with the theme resetting the
 * UA outline away the focused chip was indistinguishable.
 *
 * Computed values under the real stylesheet, not a reading of its text: a rule
 * a later selector out-scored would still be present in the source.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '../..');
const STYLESHEET = path.join(REPO_ROOT, 'view/frontend/web/css/style.css');

const RING = '2px solid var(--color-blue2)';
const OFFSET = '2px';

/**
 * The chips in their real nesting — a chip measured outside the popover misses
 * every descendant selector that styles it.
 *
 * @returns {Object} the selected and unselected mode chips, and a term chip
 */
function render() {
    const style = document.createElement('style');
    style.textContent = fs.readFileSync(STYLESHEET, 'utf8');
    document.head.appendChild(style);

    document.body.innerHTML = [
        '<span class="two-company-field-wrap">',
        '  <div class="two-company-dropdown">',
        '    <div class="two-company-mode-chips">',
        '      <button type="button" class="two-company-mode-chip two-company-mode-chip--selected"',
        '              id="selected">Registered company</button>',
        '      <button type="button" class="two-company-mode-chip" id="plain">Sole trader</button>',
        '    </div>',
        '  </div>',
        '</span>',
        '<div class="two-term-chips"><div class="two-term-chips__container">',
        '  <button type="button" class="two-term-chip" id="term">30</button>',
        '</div></div>'
    ].join('\n');

    return {
        selected: document.getElementById('selected'),
        plain: document.getElementById('plain'),
        term: document.getElementById('term')
    };
}

afterEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

describe('the popover mode chips show where the keyboard is (ABN-554)', () => {
    test.each([
        ['selected', 'the selected chip, whose own blue fill the ring has to survive'],
        ['plain', 'an unselected chip']
    ])('a keyboard-focused chip is ringed: %s (%s)', (which) => {
        const chip = render()[which];

        chip.focus();

        // Given the chip is where the keyboard landed
        expect(document.activeElement).toBe(chip);
        expect(chip.matches(':focus-visible')).toBe(true);
        // Then it is ringed, outside its own fill
        const computed = window.getComputedStyle(chip);
        expect({ outline: computed.outline, outlineOffset: computed.outlineOffset })
            .toEqual({ outline: RING, outlineOffset: OFFSET });
    });

    test('the ring is the payment-term chips\', not a second style', () => {
        const chips = render();

        chips.term.focus();
        const term = window.getComputedStyle(chips.term);
        chips.plain.focus();
        const mode = window.getComputedStyle(chips.plain);

        expect([mode.outline, mode.outlineOffset]).toEqual([term.outline, term.outlineOffset]);
    });
});
