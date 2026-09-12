/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-554. A sole offered term is rendered as a natively disabled button so
 * Tab skips it, which puts it in reach of Luma's own `button:disabled` fade.
 * The chip is the term the buyer is being given, not an unavailable control,
 * so the plugin's rules have to out-score the theme's.
 *
 * Computed values under the real stylesheet, not a reading of its text. jsdom
 * resolves the cascade by source order alone, so what is pinned here is that
 * the declarations exist and reach the chip; the specificity win over the
 * theme, and the `var()` fill jsdom leaves unresolved, are proven in Chrome.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const STYLESHEET = path.resolve(__dirname, '../../view/frontend/web/css/style.css');

// Luma's shape for the rule, at its specificity (0-1-1).
const THEME = 'button:disabled { opacity: 0.5; cursor: not-allowed; }';

/**
 * The sole-term chip in its real nesting, under the theme rule and then the
 * plugin stylesheet, in the order a Magento page loads them.
 *
 * @param {boolean} withPlugin whether the plugin stylesheet is loaded at all
 * @returns {HTMLElement} the chip
 */
function render(withPlugin) {
    const theme = document.createElement('style');
    theme.textContent = THEME;
    document.head.appendChild(theme);

    if (withPlugin) {
        const plugin = document.createElement('style');
        plugin.textContent = fs.readFileSync(STYLESHEET, 'utf8');
        document.head.appendChild(plugin);
    }

    document.body.innerHTML = [
        '<div class="two-term-chips"><div class="two-term-chips__container" role="group">',
        '  <button type="button" class="two-term-chip two-term-chip--single" disabled id="chip">',
        '    <span class="two-term-chip__days">30 days</span>',
        '  </button>',
        '</div></div>'
    ].join('\n');

    return document.getElementById('chip');
}

afterEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

describe('the sole offered term reads as a selected chip (ABN-554)', () => {
    test.each([
        ['opacity', '0.5', '1', 'solid, not faded out'],
        ['cursor', 'not-allowed', 'default', 'no refusal pointer over an offer']
    ])('%s: theme alone gives %s, the plugin gives %s — %s', (property, themed, fixed) => {
        // Given the theme rule alone reaches the chip
        expect(window.getComputedStyle(render(false))[property]).toBe(themed);
        document.head.innerHTML = '';

        // Then the plugin's own declaration is what lands
        expect(window.getComputedStyle(render(true))[property]).toBe(fixed);
    });
});
