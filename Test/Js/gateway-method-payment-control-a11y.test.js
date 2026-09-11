/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-554: the payment tile's controls must state what they are and which
 * option is chosen. These specs pin the term chips as a radio group whose
 * checked state follows the selection and whose keyboard behaviour matches the
 * role it advertises, and pin an accessible name onto the method radio and the
 * consent checkbox.
 *
 * jsdom has no accessibility layer and no sequential focus navigation, so
 * nothing here proves an utterance or a Tab landing. The tab stop is asserted
 * through its proxy — exactly one chip carrying tabindex 0 — and the arrow-key
 * traversal through the focus the handler moves itself, which jsdom does
 * perform. Utterance and Tab order are browser checks.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule, makeObservable, defaultMocks } = require('./amd-harness');

const ROOT = path.join(__dirname, '..', '..');
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';

const TERMS = [14, 30, 60];

/** An observable with real knockout's "an equal write notifies nobody". */
function koObservable(initial) {
    let value = initial;
    const obs = function (next) {
        if (arguments.length === 0) {
            return value;
        }
        value = next;
        return value;
    };
    obs.subscribe = function () {
        return { dispose: function () {} };
    };
    return obs;
}

/** A `this` standing in for a live renderer instance, wired for the chips only. */
function makeContext(selected) {
    const component = loadAmdModule(RENDERER);
    const ctx = Object.assign({}, component, {
        availableBuyerTerms: TERMS.slice(),
        selectedTerm: koObservable(selected)
    });
    ctx.getCode = function () {
        return 'two_payment';
    };
    ctx.selected = [];
    ctx.selectTerm = function (days) {
        ctx.selected.push(days);
        ctx.selectedTerm(days);
    };
    return ctx;
}

/**
 * The chip group as the template describes it, with the roving tabindex and
 * the checked state applied through the component's own accessors — the
 * fixture stands in for knockout's bindings, which cannot run under Jest.
 */
function renderChips(ctx) {
    const group = document.createElement('div');
    group.className = 'two-term-chips__container';
    group.setAttribute('role', 'radiogroup');
    TERMS.forEach(function (days) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'two-term-chip';
        chip.setAttribute('role', 'radio');
        chip.setAttribute('aria-checked', ctx.isTermChecked(days) ? 'true' : 'false');
        chip.setAttribute('tabindex', String(ctx.termTabIndex(days)));
        chip.textContent = days + ' days';
        group.appendChild(chip);
    });
    document.body.appendChild(group);
    return group;
}

/** Press a key on the group, as the template's keydown binding would. */
function press(ctx, group, key) {
    const event = new window.KeyboardEvent('keydown', { key: key, bubbles: true });
    Object.defineProperty(event, 'currentTarget', { value: group });
    const handled = ctx.onTermKeydown.call(ctx, ctx, event);
    Array.prototype.forEach.call(group.querySelectorAll('.two-term-chip'), function (chip, i) {
        chip.setAttribute('aria-checked', ctx.isTermChecked(TERMS[i]) ? 'true' : 'false');
        chip.setAttribute('tabindex', String(ctx.termTabIndex(TERMS[i])));
    });
    return handled;
}

beforeEach(() => {
    document.body.innerHTML = '';
});

function template() {
    return fs.readFileSync(path.join(ROOT, TEMPLATE), 'utf8');
}

function withoutComments(markup) {
    return markup.replace(/<!--(?!\s*\/?ko\b)[\s\S]*?-->/g, '');
}

/** The attribute text of the single element the pattern names. */
function attributesOf(pattern) {
    const match = withoutComments(template()).match(pattern);
    if (match === null) {
        throw new Error('the template has no element matching ' + pattern);
    }
    return match[1];
}

const GROUP = /<div\b([^>]*\bclass="two-term-chips__container"[^>]*)>/;
const CHIP = /<button\b([^>]*\bclass="two-term-chip"[^>]*)>/;
const GROUP_LABEL = /<span\b([^>]*\bclass="label"[^>]*)>/;
const METHOD_RADIO = /<input\b([^>]*\bname="payment\[method\]"[^>]*)>/;
const METHOD_TITLE = /<label\b([^>]*\bclass="two-payment-title"[^>]*)>/;
const CONSENT_BOX = /<input\b([^>]*\bname="terms-checkbox"[^>]*)>/;
const CONSENT_TEXT = /<span\b([^>]*\bclass="terms-text"[^>]*)>/;

describe('the term chips are a radio group, not a row of buttons (ABN-554)', () => {
    test.each([
        {
            element: GROUP,
            pattern: /\brole="radiogroup"/,
            case: 'the container declares the group role'
        },
        {
            element: GROUP,
            pattern: /'aria-labelledby':\s*termGroupLabelId\(\)/,
            case: 'the group is named by its own label'
        },
        {
            element: GROUP,
            pattern: /event:\s*\{\s*keydown:\s*onTermKeydown\s*\}/,
            case: 'the group handles its own keys'
        },
        { element: CHIP, pattern: /\brole="radio"/, case: 'each chip declares the radio role' },
        {
            element: CHIP,
            pattern: /'aria-checked':\s*\$parent\.isTermChecked\(days\)/,
            case: 'the checked state comes from the selection'
        },
        {
            element: CHIP,
            pattern: /tabindex:\s*\$parent\.termTabIndex\(days\)/,
            case: 'the tab stop is the roving one'
        },
        {
            element: CHIP,
            pattern: /'two-term-chip--selected':\s*\$parent\.isTermChecked\(days\)/,
            case: 'the visual tick comes from that same selection'
        },
        {
            element: GROUP_LABEL,
            pattern: /id:\s*termGroupLabelId\(\)/,
            case: "the group's label carries the referenced id"
        }
    ])('the markup contract: $case', ({ element, pattern }) => {
        expect(attributesOf(element)).toMatch(pattern);
    });

    test.each([
        {
            pattern: /<!--\s*ko if:\s*isLoading\(\)\s*-->/,
            case: "the loading dots call the chip's own computed, as the fee label must"
        },
        {
            pattern: /<!--\s*ko if:\s*!isLoading\(\)\s*&&\s*surchargeLabel\(\)\s*-->/,
            case: 'the fee label negates that computed, so leaving it uncalled is always false'
        }
    ])('the chips read their fees as computeds: $case', ({ pattern }) => {
        expect(template()).toMatch(pattern);
    });

    test('the renderer assigns the chip array itself, never a computed over it', () => {
        const source = fs.readFileSync(path.join(ROOT, RENDERER), 'utf8');

        expect(source).toMatch(/this\.termOptions = this\.buildTermOptions\(terms\);/);
    });

    test.each([
        {
            selected: 30,
            checked: ['false', 'true', 'false'],
            tabIndexes: [-1, 0, -1],
            case: 'the selected term is the checked chip'
        },
        {
            selected: 14,
            checked: ['true', 'false', 'false'],
            tabIndexes: [0, -1, -1],
            case: 'the first term selected'
        },
        {
            selected: 60,
            checked: ['false', 'false', 'true'],
            tabIndexes: [-1, -1, 0],
            case: 'the last term selected'
        },
        {
            selected: 45,
            checked: ['false', 'false', 'false'],
            tabIndexes: [0, -1, -1],
            case: 'a selection no chip offers checks nothing and leaves the tab stop on the first chip'
        },
        {
            selected: null,
            checked: ['false', 'false', 'false'],
            tabIndexes: [0, -1, -1],
            case: 'no selection yet leaves the tab stop on the first chip'
        }
    ])('the selection is exposed: $case', ({ selected, checked, tabIndexes }) => {
        const ctx = makeContext(selected);

        expect(
            TERMS.map(function (days) {
                return ctx.isTermChecked(days) ? 'true' : 'false';
            })
        ).toEqual(checked);
        expect(
            TERMS.map(function (days) {
                return ctx.termTabIndex(days);
            })
        ).toEqual(tabIndexes);
        expect(
            TERMS.filter(function (days) {
                return ctx.termTabIndex(days) === 0;
            })
        ).toHaveLength(1);
    });
});

describe('the arrow keys move the selection, and both ends wrap (ABN-554)', () => {
    test.each([
        { selected: 30, keys: ['ArrowRight'], expected: 60, case: 'forward' },
        {
            selected: 30,
            keys: ['ArrowDown'],
            expected: 60,
            case: 'forward on the vertical key too'
        },
        { selected: 30, keys: ['ArrowLeft'], expected: 14, case: 'backward' },
        { selected: 30, keys: ['ArrowUp'], expected: 14, case: 'backward on the vertical key too' },
        {
            selected: 60,
            keys: ['ArrowRight'],
            expected: 14,
            case: 'forward off the last chip wraps to the first'
        },
        {
            selected: 14,
            keys: ['ArrowLeft'],
            expected: 60,
            case: 'backward off the first chip wraps to the last'
        },
        { selected: 14, keys: ['End'], expected: 60, case: 'End jumps to the last chip' },
        { selected: 60, keys: ['Home'], expected: 14, case: 'Home jumps to the first chip' },
        {
            selected: 14,
            keys: ['ArrowRight', 'ArrowRight', 'ArrowRight'],
            expected: 14,
            case: 'three steps traverse the whole group and come back'
        }
    ])('$keys from $selected selects $expected — $case', ({ selected, keys, expected }) => {
        const ctx = makeContext(selected);
        const group = renderChips(ctx);
        group.querySelectorAll('.two-term-chip')[TERMS.indexOf(selected)].focus();

        keys.forEach(function (key) {
            expect(press(ctx, group, key)).toBeUndefined();
        });

        const focused = group.querySelectorAll('.two-term-chip')[TERMS.indexOf(expected)];
        expect(ctx.selectedTerm()).toBe(expected);
        expect(document.activeElement).toBe(focused);
        expect(ctx.selected).toHaveLength(keys.length);
    });

    test.each([
        { key: 'Tab', case: 'Tab leaves the group' },
        { key: ' ', case: 'Space activates the focused chip natively' },
        { key: 'Enter', case: 'Enter activates the focused chip natively' },
        { key: 'a', case: 'a printable key has no meaning here' }
    ])('the key keeps its default action: $case', ({ key }) => {
        const ctx = makeContext(30);
        const group = renderChips(ctx);

        expect(press(ctx, group, key)).toBe(true);
        expect(ctx.selected).toEqual([]);
    });

    test.each([
        { modifier: 'altKey', case: 'Alt+ArrowLeft is the browser going back' },
        { modifier: 'ctrlKey', case: 'Ctrl+ArrowRight belongs to the browser' },
        { modifier: 'metaKey', case: 'Cmd+ArrowRight belongs to the browser' }
    ])('a modified arrow key is left alone: $case', ({ modifier }) => {
        const ctx = makeContext(30);
        const group = renderChips(ctx);
        const event = new window.KeyboardEvent('keydown', { key: 'ArrowRight' });
        Object.defineProperty(event, 'currentTarget', { value: group });
        Object.defineProperty(event, modifier, { value: true });

        expect(ctx.onTermKeydown.call(ctx, ctx, event)).toBe(true);
        expect(ctx.selected).toEqual([]);
    });

    test('with focus on nothing, the step is taken from the selected term', () => {
        const ctx = makeContext(30);
        const group = renderChips(ctx);

        press(ctx, group, 'ArrowRight');

        expect(ctx.selectedTerm()).toBe(60);
        expect(document.activeElement).toBe(group.querySelectorAll('.two-term-chip')[2]);
    });

    test('a group offering one term handles no keys at all', () => {
        const ctx = makeContext(30);
        ctx.availableBuyerTerms = [30];
        const group = renderChips(ctx);

        expect(press(ctx, group, 'ArrowRight')).toBe(true);
        expect(ctx.selected).toEqual([]);
    });
});

describe('the method radio and the consent checkbox are named (ABN-554)', () => {
    test.each([
        {
            control: METHOD_RADIO,
            controlPattern: /attr:\s*\{'id':\s*getCode\(\)\}/,
            source: METHOD_TITLE,
            sourcePattern: /attr:\s*\{'for':\s*getCode\(\)\}/,
            case: 'the method radio is named by the tile title'
        },
        {
            control: CONSENT_BOX,
            controlPattern: /'aria-labelledby':\s*paymentTermsTextId\(\)/,
            source: CONSENT_TEXT,
            sourcePattern: /attr:\s*\{id:\s*paymentTermsTextId\(\)\}/,
            case: 'the consent checkbox is named by the consent sentence'
        }
    ])('$case', ({ control, controlPattern, source, sourcePattern }) => {
        expect(attributesOf(control)).toMatch(controlPattern);
        expect(attributesOf(source)).toMatch(sourcePattern);
    });

    test('the consent checkbox carries an id of its own, so a label can point at it', () => {
        expect(attributesOf(CONSENT_BOX)).toMatch(/id:\s*paymentTermsCheckboxId\(\)/);
    });

    test.each([
        { method: 'termGroupLabelId', prefix: 'two-term-group-label-' },
        { method: 'paymentTermsCheckboxId', prefix: 'two-terms-accepted-' },
        { method: 'paymentTermsTextId', prefix: 'two-terms-text-' }
    ])(
        '$method is per payment code, so sibling brand tiles cannot collide',
        ({ method, prefix }) => {
            const ctx = makeContext(30);

            expect(ctx[method]()).toBe(prefix + 'two_payment');

            ctx.getCode = function () {
                return 'two_payment_other_brand';
            };
            expect(ctx[method]()).toBe(prefix + 'two_payment_other_brand');
        }
    );
});

describe('the chips outlive a fee refresh (ABN-554)', () => {
    /** The renderer, loaded over a surcharge model whose fee map a spec can write. */
    function loadWithFees() {
        // The harness's own observable: the ko double tracks a dependency only
        // on one of its own, so a local stub would make the specs vacuous.
        const fees = makeObservable({});
        const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
            getPriceFormat: function () {
                return { pattern: '%s' };
            }
        });
        const component = loadAmdModule(RENDERER, {
            'Magento_Checkout/js/model/quote': quote,
            'Two_Gateway/js/model/surcharge': {
                selectedTerm: makeObservable(null),
                taxDisplay: makeObservable('excl'),
                currencySymbol: '\u20ac',
                selectTerm: function () {},
                isTermReconciled: function () {
                    return true;
                },
                displayedTermSurcharges: function () {
                    return fees();
                }
            }
        });
        return {
            options: component.buildTermOptions.call({}, TERMS.slice()),
            publish: fees
        };
    }

    test('a fee refresh updates the labels in place', () => {
        const { options, publish } = loadWithFees();

        // A plain array is the whole mechanism: knockout cannot recompute it,
        // so `foreach` has no reason to rebuild the chips.
        expect(Array.isArray(options)).toBe(true);
        expect(options.map((o) => o.isLoading())).toEqual([true, true, true]);

        publish({ 14: 0, 30: 1.5, 60: 3 });

        expect(options.map((o) => o.isLoading())).toEqual([false, false, false]);
        expect(options.map((o) => o.surchargeLabel())).toEqual(['+0', '+1.5', '+3']);

        publish({ 14: 0, 30: 0, 60: 0 });

        expect(options.map((o) => o.surchargeLabel())).toEqual(['', '', '']);
    });
});
