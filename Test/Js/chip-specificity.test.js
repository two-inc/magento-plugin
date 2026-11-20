/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Jsdom's `getComputedStyle` resolves the cascade by source order and
 * weighs no specificity, so `chip-palette.test.js` cannot tell a rule that wins
 * on score from one that wins on position — a higher-scoring rule inserted
 * above the chip block would repaint every chip in a browser and leave that
 * suite green. The cascade is scored here instead: for each chip state, each
 * property is resolved the way a browser resolves it, and the resolution must
 * be unique as well as correct.
 *
 * The properties audited are derived from what the chip's own rules declare,
 * never listed: a named list is only ever as complete as someone's memory of
 * the spec, and each property it forgot was a state a rule could move unseen.
 * The comparison is whole-object, so a rule that gains a property fails here
 * until the table accounts for it.
 *
 * The resolver refuses what it cannot model rather than passing over it: a
 * selector it cannot score, an at-rule it does not recognise, a media condition
 * it cannot evaluate, a CSS-wide keyword and a multi-valued custom property all
 * fail the suite. One gap is left open deliberately: `border`, `outline`,
 * `background` and `padding` are split into their longhands, so a shorthand
 * that resets a component cannot hide one, while any other shorthand is compared
 * as written — a `flex`, `transition` or `border-radius` shorthand overriding a
 * longhand a weaker rule set is not modelled. Media blocks are descended
 * into and every state is resolved again at each width the sheet declares a
 * breakpoint for, so a rule that only applies at one viewport is still weighed.
 * Its one declared boundary is that it reads this module's own stylesheet — a
 * brand overlay ships its own, from its own repository, and the two are
 * composed there.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '../..');
const STYLESHEET = path.join(REPO_ROOT, 'view/frontend/web/css/style.css');

const ACCENT = 'rgb(9, 16, 48)';
const GREY = 'rgb(227, 227, 227)';
const WHITE = 'rgb(255, 255, 255)';

/** Stand-ins for the pseudo-classes jsdom cannot enter. A class scores as a
 *  pseudo-class does and the substitution moves no rule, so neither half of the
 *  cascade shifts. */
const PROBES = { ':hover': 'two-hover-probe', ':active': 'two-active-probe' };

const SIDES = ['top', 'right', 'bottom', 'left'];

const LENGTH = /^(0|-?[\d.]+(px|em|rem|%|vw|vh|ch)|thin|medium|thick)$/i;
const LINE_STYLE = /^(none|hidden|solid|dashed|dotted|double|groove|ridge|inset|outset)$/i;
const COLOUR = /^(#[0-9a-f]{3,8}|(var|rgba?|hsla?|color)\([^)]*\)|transparent|currentcolor|white|black|red|green|blue|gray|grey)$/i;
/** A CSS-wide keyword is not a colour, a length or a style — it takes its value
 *  from elsewhere in the cascade, which this resolver does not follow. */
const CSS_WIDE = /^(inherit|initial|unset|revert|revert-layer)$/i;

/** What a shorthand leaves a component at when the value omits it. */
const INITIAL = {
    'border-width': 'medium', 'border-style': 'none', 'border-color': 'currentcolor',
    'outline-width': 'medium', 'outline-style': 'none', 'outline-color': 'currentcolor'
};

/**
 * @param {string} value a declaration value
 * @returns {Array} its top-level tokens, functions kept whole
 */
function valueTokens(value) {
    return value.match(/[\w-]+\([^)]*\)|[^\s]+/g) || [];
}

/**
 * @param {string} name the property a token belongs to, for the message
 * @param {string} token one value token
 * @returns {string} which of length, style or colour it is
 * @throws {Error} on a spelling this resolver does not model
 */
function kindOf(name, token) {
    if (CSS_WIDE.test(token)) {
        throw new Error('unmodelled CSS-wide keyword "' + token + '" in "' + name + '"');
    }
    if (LENGTH.test(token)) {
        return 'length';
    }
    if (LINE_STYLE.test(token)) {
        return 'style';
    }
    if (COLOUR.test(token)) {
        return 'colour';
    }

    throw new Error('unmodelled value "' + token + '" in "' + name + '"');
}

/**
 * A shorthand RESETS every component it omits, so each one emits its whole
 * family: `border: none` leaves no width and no colour behind for a weaker rule
 * to supply, and a splitter that emitted only what it read would hand those
 * components to that weaker rule and report a clean winner.
 *
 * @param {string} family `border` or `outline`
 * @param {string} value the shorthand's value
 * @returns {Object} every longhand in that family
 */
function line(family, value) {
    const split = {
        [family + '-width']: INITIAL[family + '-width'],
        [family + '-style']: INITIAL[family + '-style'],
        [family + '-color']: INITIAL[family + '-color']
    };
    valueTokens(value).forEach((token) => {
        const kind = kindOf(family, token);
        split[family + '-' + (kind === 'length' ? 'width' : kind === 'style' ? 'style' : 'color')] = token;
    });

    return split;
}

/**
 * The shorthands split into their longhands. A spelling none of them models
 * throws rather than resolving to undefined, which would read as a missing
 * declaration instead of an unread one.
 */
const SHORTHANDS = {
    border: (value) => line('border', value),
    outline: (value) => line('outline', value),
    background: (value) => {
        // `none` is the image layer; the shorthand still resets the colour, so a
        // rule saying `background: none` paints transparent rather than nothing.
        const colours = valueTokens(value).filter((token) =>
            (/^none$/i.test(token) ? 'image' : kindOf('background', token)) === 'colour');

        return { 'background-color': colours.length ? colours[colours.length - 1] : 'transparent' };
    },
    padding: (value) => {
        const parts = valueTokens(value);
        parts.forEach((token) => kindOf('padding', token));
        const box = [
            parts[0],
            parts[1] === undefined ? parts[0] : parts[1],
            parts[2] === undefined ? parts[0] : parts[2],
            parts[3] === undefined ? (parts[1] === undefined ? parts[0] : parts[1]) : parts[3]
        ];

        return SIDES.reduce((split, side, at) => Object.assign(split, { ['padding-' + side]: box[at] }), {});
    }
};

/** Pseudo-classes this resolver scores. Anything else fails rather than
 *  scoring zero, which would silently hand the state to a weaker rule. */
const PSEUDO_CLASSES = [
    'hover', 'focus', 'focus-visible', 'focus-within', 'active', 'disabled',
    'enabled', 'checked', 'empty', 'root', 'first-child', 'last-child',
    'nth-child', 'nth-of-type', 'only-child', 'not'
];

const TERM = 'two-term-chip';
const MODE = 'two-company-mode-chip';

/** Resting and raised padding per family. The raised pair gives the border back
 *  the pixel it dropped, which is what keeps the box stable. */
const PADDING = {
    [TERM]: { rest: ['8px', '16px'], raised: ['9px', '17px'] },
    [MODE]: { rest: ['5px', '10px'], raised: ['6px', '11px'] }
};

/** Everything each family declares that no state moves. Listed because the
 *  comparison is whole-object: a chip rule that gained a property would have to
 *  be accounted for here rather than slipping past an allowlist. */
const CHROME = {
    [TERM]: {
        'align-items': 'center',
        'border-radius': '8px',
        cursor: 'pointer',
        display: 'flex',
        'flex-direction': 'column',
        'font-family': 'inherit',
        'min-width': '80px',
        position: 'relative',
        transition: 'border-color 0.15s ease, background-color 0.15s ease, color 0.15s ease'
    },
    [MODE]: {
        'border-radius': '5px',
        cursor: 'pointer',
        flex: '1 1 auto',
        'font-family': 'inherit',
        'font-size': '13px',
        'line-height': '1.3',
        'min-width': '84px',
        'text-align': 'center',
        'text-transform': 'none',
        'word-break': 'break-word'
    }
};

/**
 * @param {string} family the chip's base class
 * @param {Object} state `selected`, `raised`, `focused` and `sole`
 * @returns {Object} every property the chip's rules resolve to in that state
 */
function paint(family, state) {
    const box = PADDING[family][state.raised ? 'raised' : 'rest'];
    const expected = Object.assign({}, CHROME[family], {
        'border-style': 'solid',
        'border-width': state.selected || !state.raised ? '2px' : '1px',
        'border-color': state.selected || state.raised ? ACCENT : GREY,
        'background-color': state.selected ? ACCENT : state.raised ? GREY : WHITE,
        color: state.selected ? WHITE : ACCENT
    });
    SIDES.forEach((side, at) => {
        expected['padding-' + side] = box[at % 2];
    });
    if (state.focused) {
        Object.assign(expected, {
            'outline-width': '2px',
            'outline-style': 'solid',
            'outline-color': ACCENT,
            'outline-offset': '2px'
        });
    }
    if (state.sole) {
        // The sole term is disabled, so it states its own cursor and weight.
        Object.assign(expected, {
            cursor: 'default',
            'font-size': '14px',
            'font-weight': '500',
            opacity: '1'
        });
    }

    return expected;
}

/**
 * @param {string} family the chip's base class
 * @returns {Array} that family's states, as [label, classes, paint, options]
 */
function statesFor(family) {
    const selected = family + ' ' + family + '--selected';
    const hover = PROBES[':hover'];
    const active = PROBES[':active'];

    return [
        [family + ' rest', family, paint(family, {})],
        [family + ' selected', selected, paint(family, { selected: true })],
        [family + ' hover', family + ' ' + hover, paint(family, { raised: true })],
        [family + ' hover selected', selected + ' ' + hover, paint(family, { selected: true })],
        [
            family + ' focus', family,
            paint(family, { raised: true, focused: true }), { focused: true }
        ],
        [
            family + ' focus selected', selected,
            paint(family, { selected: true, focused: true }), { focused: true }
        ],
        [
            family + ' focus hover', family + ' ' + hover,
            paint(family, { raised: true, focused: true }), { focused: true }
        ],
        [
            family + ' focus hover selected', selected + ' ' + hover,
            paint(family, { selected: true, focused: true }), { focused: true }
        ],
        [family + ' active', family + ' ' + active, paint(family, {})],
        [family + ' active selected', selected + ' ' + active, paint(family, { selected: true })],
        [
            family + ' active hover', family + ' ' + hover + ' ' + active,
            paint(family, { raised: true })
        ]
    ];
}

const STATES = statesFor(TERM).concat(statesFor(MODE)).concat([
    [
        'sole term, disabled',
        TERM + ' ' + TERM + '--single',
        paint(TERM, { selected: true, sole: true }),
        { disabled: true }
    ],
    [
        'sole term, disabled and hovered',
        TERM + ' ' + TERM + '--single ' + PROBES[':hover'],
        paint(TERM, { selected: true, sole: true }),
        { disabled: true }
    ]
]);

/**
 * @param {string} compound one compound selector, no combinator
 * @returns {Array} its [id, class, element] contribution
 * @throws {Error} on any construct this resolver does not model
 */
function scoreCompound(compound) {
    const counts = [0, 0, 0];
    let rest = compound;

    while (rest.length) {
        let match;
        if ((match = /^\*/.exec(rest))) {
            // The universal selector contributes nothing.
        } else if ((match = /^#[\w-]+/.exec(rest))) {
            counts[0]++;
        } else if ((match = /^\.[\w-]+/.exec(rest))) {
            counts[1]++;
        } else if ((match = /^\[[^\]]+\]/.exec(rest))) {
            counts[1]++;
        } else if ((match = /^::[\w-]+/.exec(rest))) {
            counts[2]++;
        } else if ((match = /^:not\(([^()]*)\)/.exec(rest))) {
            const inner = match[1].split(',').map((one) => scoreCompound(one.trim()));
            [0, 1, 2].forEach((at) => {
                counts[at] += Math.max.apply(null, inner.map((one) => one[at]));
            });
        } else if ((match = /^:([\w-]+)(\([^()]*\))?/.exec(rest))) {
            if (PSEUDO_CLASSES.indexOf(match[1]) === -1) {
                throw new Error('unscored pseudo-class ":' + match[1] + '" in "' + compound + '"');
            }
            counts[1]++;
        } else if ((match = /^[a-zA-Z][\w-]*/.exec(rest))) {
            counts[2]++;
        } else {
            throw new Error('unscored selector fragment "' + rest + '" in "' + compound + '"');
        }
        rest = rest.slice(match[0].length);
    }

    return counts;
}

/**
 * @param {string} selector one selector, no comma
 * @returns {Array} the [id, class, element] triple CSS scores it by
 */
function score(selector) {
    return selector
        .split(/[\s>+~]+/)
        .filter((compound) => compound.length)
        .reduce((total, compound) => {
            const part = scoreCompound(compound);

            return [total[0] + part[0], total[1] + part[1], total[2] + part[2]];
        }, [0, 0, 0]);
}

/**
 * Importance outranks specificity outright, which is the whole reason it is
 * modelled: an `!important` chip colour would win in a browser.
 *
 * @param {Object} left a candidate
 * @param {Object} right a candidate
 * @returns {number} negative, zero or positive, as a comparator
 */
function weigh(left, right) {
    if (left.important !== right.important) {
        return left.important ? 1 : -1;
    }
    for (let at = 0; at < 3; at++) {
        if (left.score[at] !== right.score[at]) {
            return left.score[at] - right.score[at];
        }
    }

    return 0;
}

/**
 * @param {string} condition one media condition, no comma
 * @returns {Object} {types, min, max}
 * @throws {Error} on a feature this resolver cannot evaluate
 */
function parseCondition(condition) {
    let rest = condition.trim().toLowerCase().replace(/^only\s+/, '');
    const parsed = { type: 'all', min: 0, max: Infinity };

    const type = /^(all|screen|print)\b/.exec(rest);
    if (type) {
        parsed.type = type[1];
        rest = rest.slice(type[0].length).trim().replace(/^and\s+/, '');
    }
    while (rest.length) {
        const feature = /^\(\s*(min|max)-width\s*:\s*(\d+)px\s*\)/.exec(rest);
        if (!feature) {
            throw new Error('unevaluable media condition "' + condition + '"');
        }
        parsed[feature[1]] = parseInt(feature[2], 10);
        rest = rest.slice(feature[0].length).trim().replace(/^and\s+/, '');
    }

    return parsed;
}

/**
 * @param {Array} conditions the media conditions wrapping a rule
 * @param {number} width the viewport being resolved at
 * @returns {boolean} whether the rule applies there
 */
function applies(conditions, width) {
    return conditions.every((list) =>
        list.some((one) => one.type !== 'print' && width >= one.min && width <= one.max));
}

/**
 * @param {CSSRule} rule one rule
 * @returns {Object} its declarations, longhands split out of the shorthands
 */
function declarationsOf(rule) {
    const declarations = {};
    for (let at = 0; at < rule.style.length; at++) {
        const property = rule.style[at];
        const value = rule.style.getPropertyValue(property);
        const important = rule.style.getPropertyPriority(property) === 'important';
        const split = SHORTHANDS[property] ? SHORTHANDS[property](value) : { [property]: value };
        Object.keys(split).forEach((longhand) => {
            declarations[longhand] = { value: split[longhand], important };
        });
    }

    return declarations;
}

/**
 * Every style rule in the sheet, scored, in source order, media blocks
 * descended into. An at-rule that is neither a media block nor `@keyframes`
 * fails rather than being passed over — a rule hidden inside one would repaint
 * a chip in a browser with this suite green.
 *
 * @returns {Array} candidates carrying selector, score, importance, media, order
 */
function candidates() {
    let source = fs.readFileSync(STYLESHEET, 'utf8');
    Object.keys(PROBES).forEach((pseudo) => {
        source = source.split(pseudo).join('.' + PROBES[pseudo]);
    });

    const style = document.createElement('style');
    style.textContent = source;
    document.head.appendChild(style);

    const collected = [];
    const animated = {};
    let order = 0;
    const walk = (rules, media) => {
        Array.prototype.forEach.call(rules, (rule) => {
            order++;
            if (rule.selectorText !== undefined && rule.style) {
                const declarations = declarationsOf(rule);
                rule.selectorText.split(',').forEach((one) => {
                    const selector = one.trim();
                    collected.push({
                        selector, score: score(selector), order, declarations, media
                    });
                });

                return;
            }
            if (rule.media && rule.cssRules) {
                const conditions = String(rule.media.mediaText).split(',').map(parseCondition);
                walk(rule.cssRules, media.concat([conditions]));

                return;
            }
            if (rule.name !== undefined && rule.cssRules) {
                // An animation is a HIGHER cascade origin than a normal author
                // declaration, and `animation-fill-mode: both` keeps it winning
                // after the run. What it animates is recorded and checked against
                // the properties the chip's own rules turn out to declare, so the
                // skip is never a blanket one.
                animated[rule.name] = {};
                Array.prototype.forEach.call(rule.cssRules, (frame) => {
                    Object.keys(declarationsOf(frame)).forEach((property) => {
                        animated[rule.name][property] = true;
                    });
                });

                return;
            }
            throw new Error('unmodelled at-rule "' + rule.cssText.slice(0, 60) + '"');
        });
    };
    walk(style.sheet.cssRules, []);
    collected.animated = animated;

    return collected;
}

/**
 * Every width the sheet declares a breakpoint at, either side of it.
 *
 * @param {Array} all the candidates
 * @returns {Array} widths to resolve every state at
 */
function viewports(all) {
    const widths = { 360: true, 1280: true };
    all.forEach((rule) => rule.media.forEach((list) => list.forEach((one) => {
        if (one.min > 0) {
            widths[one.min] = true;
            widths[one.min - 1] = true;
        }
        if (one.max < Infinity) {
            widths[one.max] = true;
            widths[one.max + 1] = true;
        }
    })));

    return Object.keys(widths).map(Number).sort((a, b) => a - b);
}

/**
 * @param {string} value a colour, hex or functional
 * @returns {string} the `rgb(r, g, b)` spelling, so `#fff` and `#ffffff` agree
 */
function rgb(value) {
    const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value);
    if (!hex) {
        return value;
    }
    const digits = hex[1].length === 3 ? hex[1].replace(/./g, '$&$&') : hex[1];

    return 'rgb(' + [0, 2, 4].map((at) => parseInt(digits.substr(at, 2), 16)).join(', ') + ')';
}

/**
 * @param {string} property the property being resolved
 * @param {Object} declared a declaration, or undefined
 * @param {Object} tokens the `:root` custom properties
 * @returns {string} the value, `var()` resolved and colours normalised
 */
function resolve(property, declared, tokens) {
    const value = declared && declared.value !== undefined ? String(declared.value).trim() : '';
    const reference = /^var\((--[\w-]+)\)$/.exec(value);
    const literal = (reference ? tokens[reference[1]] : value).replace(/\s+/g, ' ');
    if (reference && valueTokens(literal).length > 1) {
        throw new Error('"' + reference[1] + '" holds "' + literal
            + '", which is more than the one value "' + property + '" was split as');
    }

    return /(^|-)color$/.test(property) ? rgb(literal) : literal;
}

/**
 * @param {string} classes the chip's classes
 * @param {Object} options `focused` and `disabled`
 * @returns {Element} the chip, in the nesting both families are rendered in
 */
function mount(classes, options) {
    document.body.innerHTML = '<span class="two-company-field-wrap"><div class="two-company-dropdown">'
        + '<div class="two-company-mode-chips"><div class="two-term-chips">'
        + '<div class="two-term-chips__container">'
        + '<button type="button" class="' + classes + '" id="chip"'
        + (options.disabled ? ' disabled' : '') + '>30 days</button>'
        + '</div></div></div></div></span>';

    const chip = document.getElementById('chip');
    if (options.focused) {
        chip.focus();
    }

    return chip;
}

afterEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

/**
 * The properties to audit are not listed: they are whatever the rules reaching
 * this chip actually declare. A named list is only ever as complete as someone's
 * memory of the spec, and each property it forgot was a state a rule could move
 * unseen.
 *
 * @param {Array} all the candidates
 * @param {Element} chip the mounted chip
 * @returns {Array} every property some matching rule declares
 */
function audited(all, chip) {
    const properties = {};
    all.forEach((rule) => {
        if (rule.selector.indexOf('::') !== -1 || !chip.matches(rule.selector)) {
            return;
        }
        Object.keys(rule.declarations).forEach((property) => {
            properties[property] = true;
        });
    });

    return Object.keys(properties).sort();
}

describe('the chip cascade is decided by weight, not by position', () => {
    test.each(STATES.map((state) => [state[0], state]))('%s', (label, state) => {
        const [, classes, expected, options] = state;
        const every = candidates();
        const root = window.getComputedStyle(document.documentElement);
        const tokens = {};
        Array.prototype.forEach.call(root, (property) => {
            if (property.startsWith('--')) {
                tokens[property] = root.getPropertyValue(property).trim();
            }
        });
        const chip = mount(classes, options || {});

        const readAt = (width) => {
            const all = every.filter((rule) => applies(rule.media, width));

            return audited(all, chip).reduce((resolved, property) => {
                const matching = all.filter((rule) => {
                    if (rule.declarations[property] === undefined) {
                        return false;
                    }
                    // A pseudo-element rule paints a generated box, never the chip's own.
                    if (rule.selector.indexOf('::') !== -1) {
                        return false;
                    }

                    return chip.matches(rule.selector);
                }).map((rule) => Object.assign({}, rule, {
                    // Importance is per declaration, so it is carried per property.
                    important: rule.declarations[property].important
                }));
                const winner = matching.slice().sort((a, b) =>
                    weigh(a, b) || a.order - b.order
                ).pop();
                const tied = matching.filter((rule) =>
                    winner && weigh(rule, winner) === 0
                        && resolve(property, rule.declarations[property], tokens)
                            !== resolve(property, winner.declarations[property], tokens)
                );

                return Object.assign(resolved, {
                    [property]: winner ? resolve(property, winner.declarations[property], tokens) : undefined,
                    [property + ' decided by position']: tied.map((rule) => rule.selector)
                });
            }, {});
        };

        // Only an animation this chip actually runs can outrank its rules. The
        // one in this sheet binds to a descendant, so it reaches no chip — but
        // that is checked rather than assumed, and per property.
        const running = every.filter((rule) => chip.matches(rule.selector)).reduce((names, rule) =>
            names.concat(valueTokens(
                (rule.declarations.animation || rule.declarations['animation-name'] || {}).value || ''
            )), []);
        audited(every, chip).forEach((property) => {
            running.forEach((name) => {
                if (every.animated[name] && every.animated[name][property]) {
                    throw new Error('keyframes "' + name + '" animates "' + property
                        + '", which this chip also declares');
                }
            });
        });

        const wanted = Object.keys(expected).sort().reduce((all, property) => Object.assign(all, {
            [property]: expected[property],
            [property + ' decided by position']: []
        }), {});
        const widths = viewports(every);

        expect(widths.length).toBeGreaterThan(0);
        // Whole-object equality both ways: a property the chip rules gained and
        // the table does not name fails here, which is what the named list could
        // never do.
        expect(widths.map(readAt)).toEqual(widths.map(() => wanted));
    });

    test.each([
        ['.a', [0, 1, 0]],
        ['button', [0, 0, 1]],
        ['body button.two-term-chip.two-term-chip--selected', [0, 2, 2]],
        ['button[type="button"].two-term-chip', [0, 2, 1]],
        ['#id .a:hover', [1, 2, 0]],
        ['.a:not(.b.c)', [0, 3, 0]],
        ['.a > .b::before', [0, 2, 1]]
    ])('scores %s as %s', (selector, expected) => {
        expect(score(selector)).toEqual(expected);
    });

    test('every selector in the sheet is one the resolver models', () => {
        const all = candidates();
        const chip = mount(TERM, {});

        expect(all.length).toBeGreaterThan(0);
        all.forEach((rule) => {
            expect(() => score(rule.selector)).not.toThrow();
            if (rule.selector.indexOf('::') === -1) {
                expect(() => chip.matches(rule.selector)).not.toThrow();
            }
        });
    });
});

/**
 * The audit above weighs only this sheet, where a `color` the hover rule states
 * and a `color` it leaves to the base rule paint alike. What tells them apart is
 * a host theme, which jsdom never loads: `button:hover` scores 0-1-1 and takes
 * the label from the base rule at 0-1-0, but not from the hover rule itself. The
 * theme is modelled here instead, last in source order, so only weight can hold
 * the label.
 */
describe('a theme cannot repaint the unselected chip label', () => {
    test.each([
        [
            'term chip, hovered', TERM + ' ' + PROBES[':hover'], {},
            'button.' + PROBES[':hover'],
            '.' + TERM + '.' + PROBES[':hover'] + ':not(:disabled):not(.' + TERM + '--selected)'
        ],
        [
            'term chip, focused', TERM, { focused: true }, 'button:focus',
            '.' + TERM + ':focus:not(:disabled):not(.' + TERM + '--selected)'
        ],
        [
            'mode chip, hovered', MODE + ' ' + PROBES[':hover'], {},
            'button.' + PROBES[':hover'],
            '.' + MODE + '.' + PROBES[':hover'] + ':not(.' + MODE + '--selected)'
        ],
        [
            'mode chip, focused', MODE, { focused: true }, 'button:focus',
            '.' + MODE + ':focus:not(.' + MODE + '--selected)'
        ]
    ])('%s', (label, classes, options, themeSelector, expected) => {
        const every = candidates();
        const root = window.getComputedStyle(document.documentElement);
        const tokens = {};
        Array.prototype.forEach.call(root, (property) => {
            if (property.startsWith('--')) {
                tokens[property] = root.getPropertyValue(property).trim();
            }
        });
        const chip = mount(classes, options);
        const theme = {
            selector: themeSelector,
            score: score(themeSelector),
            order: Infinity,
            declarations: { color: { value: '#fff', important: false } },
            media: []
        };

        const matching = every.concat([theme]).filter((rule) =>
            rule.declarations.color !== undefined
                && rule.selector.indexOf('::') === -1
                && chip.matches(rule.selector));
        const winner = matching.slice().sort((a, b) => weigh(a, b) || a.order - b.order).pop();

        expect({
            selector: winner.selector,
            color: resolve('color', winner.declarations.color, tokens)
        }).toEqual({ selector: expected, color: ACCENT });
    });
});
