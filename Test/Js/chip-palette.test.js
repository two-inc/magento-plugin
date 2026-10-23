/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The unbranded chip palette: payment-term chips and company-mode
 * chips alike, in every state, on the one stylesheet both Luma and Amasty
 * load.
 *
 * Computed values under the real stylesheet, not a reading of its text: a rule
 * a later selector out-scored would still be present in the source. jsdom
 * resolves the cascade by source order alone and weighs no specificity, so the
 * chip rules are written so the intended winner is both the more specific rule
 * and the later one.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '../..');
const STYLESHEET = path.join(REPO_ROOT, 'view/frontend/web/css/style.css');

const ACCENT = 'rgb(9, 16, 48)';
const GREY = 'rgb(227, 227, 227)';
const WHITE = 'rgb(255, 255, 255)';

/** Stands in for `:hover`, which jsdom cannot enter. A source-order-equivalent
 *  stand-in: the substitution rewrites no rule's position, which is what jsdom
 *  resolves the cascade by. */
const HOVER = 'two-hover-probe';

/** Each chip in its real nesting — a chip measured outside its container misses
 *  every descendant selector that styles it. */
const CONTROLS = [
    {
        name: 'payment-term chip',
        base: 'two-term-chip',
        selected: 'two-term-chip--selected',
        exempt: 'two-term-chip--single',
        wrap: ['<div class="two-term-chips"><div class="two-term-chips__container">', '</div></div>']
    },
    {
        name: 'company-mode chip',
        base: 'two-company-mode-chip',
        selected: 'two-company-mode-chip--selected',
        wrap: [
            '<span class="two-company-field-wrap"><div class="two-company-dropdown">'
                + '<div class="two-company-mode-chips">',
            '</div></div></span>'
        ]
    }
];

/**
 * jsdom resolves no `var()`, so every declaration that uses one is dropped. The
 * sheet is re-injected with the custom properties substituted, read back off
 * the document rather than parsed out of the file.
 *
 * @returns {void}
 */
function injectStylesheet() {
    const source = fs.readFileSync(STYLESHEET, 'utf8');

    const probe = document.createElement('style');
    probe.textContent = source;
    document.head.appendChild(probe);
    const root = window.getComputedStyle(document.documentElement);

    let resolved = source;
    new Set(source.match(/var\(--[\w-]+\)/g)).forEach((reference) => {
        const value = root.getPropertyValue(reference.slice(4, -1)).trim();
        if (value) {
            resolved = resolved.split(reference).join(value);
        }
    });

    document.head.innerHTML = '';
    const sheet = document.createElement('style');
    sheet.textContent = resolved.split(':hover').join('.' + HOVER);
    document.head.appendChild(sheet);
}

/**
 * @param {Object} control one entry of CONTROLS
 * @param {string} classes extra classes putting the chip into one state
 * @param {Object} [options] `focused` to put the keyboard on it, `disabled` for the native flag
 * @returns {Element} the chip
 */
function chip(control, classes, options) {
    const settings = options || {};
    injectStylesheet();
    document.body.innerHTML = control.wrap[0]
        + '<button type="button" class="' + control.base + ' ' + classes + '" id="chip"'
        + (settings.disabled ? ' disabled' : '') + '>30 days</button>'
        + control.wrap[1];

    const element = document.getElementById('chip');
    if (settings.focused) {
        element.focus();
    }

    return element;
}

/**
 * @param {string} value a computed colour, hex or functional
 * @returns {string} the `rgb(r, g, b)` spelling
 */
function rgb(value) {
    const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value.trim());
    if (!hex) {
        return value.trim();
    }
    const digits = hex[1].length === 3 ? hex[1].replace(/./g, '$&$&') : hex[1];

    return 'rgb(' + [0, 2, 4].map((at) => parseInt(digits.substr(at, 2), 16)).join(', ') + ')';
}

/**
 * @param {Element} element a chip
 * @returns {Object} the three values the spec pins per state
 */
function paint(element) {
    const computed = window.getComputedStyle(element);

    return {
        borderWidth: computed.borderWidth,
        borderColor: rgb(computed.borderColor),
        background: rgb(computed.backgroundColor)
    };
}

/**
 * @param {Element} element a chip
 * @returns {Array} the outer box each side contributes, padding plus border
 */
function box(element) {
    const computed = window.getComputedStyle(element);

    return ['Top', 'Right', 'Bottom', 'Left'].map((side) =>
        parseFloat(computed['padding' + side]) + parseFloat(computed['border' + side + 'Width'])
    );
}

afterEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

describe.each(CONTROLS.map((control) => [control.name, control]))(
    'the unbranded %s palette',
    (name, control) => {
        const SELECTED = control.selected;

        test.each([
            ['', false, '2px', GREY, WHITE, 'at rest: a grey outline on white'],
            [SELECTED, false, '2px', ACCENT, ACCENT, 'selected: a solid accent fill'],
            [HOVER, false, '1px', ACCENT, GREY, 'hovered: a thinner accent outline on grey'],
            [SELECTED + ' ' + HOVER, false, '2px', ACCENT, ACCENT, 'hovered while selected: no change'],
            ['', true, '1px', ACCENT, GREY, 'focused: the hover treatment'],
            [SELECTED, true, '2px', ACCENT, ACCENT, 'focused while selected: the keyboard changes nothing'],
            [HOVER, true, '1px', ACCENT, GREY, 'hovered and focused at once'],
            [
                SELECTED + ' ' + HOVER,
                true,
                '2px',
                ACCENT,
                ACCENT,
                'hovered and focused while selected: neither changes it'
            ]
        ])(
            '[%s] focused=%s -> %s %s on %s (%s)',
            (classes, focused, borderWidth, borderColor, background) => {
                const element = chip(control, classes, { focused });

                if (focused) {
                    expect(element.matches(':focus')).toBe(true);
                }
                expect(paint(element)).toEqual({ borderWidth, borderColor, background });
            }
        );

        test.each([
            [HOVER, false, 'under the pointer'],
            ['', true, 'under the keyboard'],
            [SELECTED, true, 'selected and focused']
        ])('the chip does not resize: %s focused=%s (%s)', (classes, focused) => {
            const resting = box(chip(control, ''));

            expect(box(chip(control, classes, { focused }))).toEqual(resting);
        });

        test.each([
            ['', ACCENT, 'at rest the label is the accent'],
            [SELECTED, WHITE, 'on the selected fill it stays legible']
        ])('the label colour: [%s] is %s (%s)', (classes, expected) => {
            expect(rgb(window.getComputedStyle(chip(control, classes)).color)).toBe(expected);
        });

        test('the keyboard ring is the accent', () => {
            const focused = chip(control, '', { focused: true });

            expect(focused.matches(':focus-visible')).toBe(true);
            const ring = window.getComputedStyle(focused).outline.split(' ');

            expect([ring[0], ring[1], rgb(ring[2])]).toEqual(['2px', 'solid', ACCENT]);
        });
    }
);

describe('a disabled payment-term chip is exempt', () => {
    const control = CONTROLS[0];

    test('the sole offered term keeps its resting appearance under the pointer', () => {
        const hovered = chip(control, control.exempt + ' ' + HOVER, { disabled: true });

        expect(paint(hovered)).toEqual({ borderWidth: '2px', borderColor: ACCENT, background: ACCENT });
        expect(rgb(window.getComputedStyle(hovered).color)).toBe(WHITE);
        expect(box(hovered)).toEqual(box(chip(control, control.exempt, { disabled: true })));
    });
});

describe('the chip accent is its own property', () => {
    test('the sole-trader link keeps the shared blue', () => {
        injectStylesheet();
        document.body.innerHTML = '<span class="two-sole-trader-note__link" id="link">Sole trader</span>';

        expect(rgb(window.getComputedStyle(document.getElementById('link')).color))
            .toBe('rgb(48, 67, 209)');
    });
});
