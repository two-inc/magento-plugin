/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * "what is Two" is one control: an icon that is itself the link to
 * the brand's about page, with a hover/focus tooltip describing it. Knockout
 * cannot run under Jest, so the markup is pinned as template text; the visual
 * open/close is a browser check.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const ROOT = path.join(__dirname, '..', '..');
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const STYLESHEET = 'view/frontend/web/css/style.css';

function read(relative) {
    return fs.readFileSync(path.join(ROOT, relative), 'utf8');
}

function withoutComments(markup) {
    return markup.replace(/<!--(?!\s*\/?ko\b)[\s\S]*?-->/g, '');
}

/** The `ko if: showWhatIsTwo` block, which is the whole control. */
function aboutBlock() {
    const match = withoutComments(read(TEMPLATE)).match(
        /<!--\s*ko if:\s*showWhatIsTwo\s*-->([\s\S]*?)<!--\s*\/ko\s*-->/
    );
    if (match === null) {
        throw new Error('the template gates nothing on showWhatIsTwo');
    }
    return match[1];
}

/** The attribute text of the single element the pattern names, inside the block. */
function attributesOf(pattern) {
    const match = aboutBlock().match(pattern);
    if (match === null) {
        throw new Error('the about block has no element matching ' + pattern);
    }
    return match[1];
}

const ICON_ANCHOR = /<a\b([^>]*\bclass="two-about-icon"[^>]*)>/;
const ICON_IMAGE = /<img\b([^>]*)>/;
// Flow content, not phrasing: the tooltip body holds <p>s, so neither the
// wrapper nor the body may be a span.
const WRAPPER = /<div\b([^>]*\bclass="two-about"[^>]*)>/;
const TOOLTIP = /<div\b([^>]*\bclass="two-about-tooltip"[^>]*)>/;

describe('the about control is an anchor-wrapped icon (ABN-554)', () => {
    test.each([
        {
            element: ICON_ANCHOR,
            pattern: /href:\s*aboutLinkUrl/,
            case: 'the icon carries the brand about URL'
        },
        {
            element: ICON_ANCHOR,
            pattern: /\btarget="_blank"/,
            case: 'it leaves checkout in a new tab'
        },
        {
            element: ICON_ANCHOR,
            pattern: /\brel="noopener"/,
            case: 'the new tab gets no handle on the checkout window'
        },
        {
            element: ICON_ANCHOR,
            pattern: /'aria-label':\s*aboutLinkText/,
            case: 'the icon-only link is named for assistive tech'
        },
        {
            element: ICON_ANCHOR,
            pattern: /'aria-describedby':\s*aboutTooltipId\(\)/,
            case: 'the tooltip body is announced with the link'
        },
        {
            element: ICON_ANCHOR,
            pattern: /^(?:(?!\btabindex\b)[\s\S])*$/,
            case: 'the anchor is natively focusable, so it declares no tabindex'
        },
        {
            element: ICON_IMAGE,
            pattern: /\balt=""/,
            case: 'the image is decorative — the anchor carries the name'
        },
        {
            element: ICON_IMAGE,
            pattern: /src:\s*aboutIconUrl/,
            case: 'the icon asset comes from the server-resolved URL'
        },
        {
            element: WRAPPER,
            pattern: /\bclass="two-about"/,
            case: 'the control wrapper is flow content, so it may hold the body'
        },
        {
            element: TOOLTIP,
            pattern: /\brole="tooltip"/,
            case: 'the body declares what it is'
        },
        {
            element: TOOLTIP,
            // aria-describedby resolves a directly referenced node whether or
            // not it is hidden; without this the closed, opacity-0 body is also
            // read as stray text in document flow.
            pattern: /\baria-hidden="true"/,
            case: 'the body is out of document flow for assistive tech'
        },
        {
            element: TOOLTIP,
            pattern: /id:\s*aboutTooltipId\(\)/,
            case: 'the body carries the id the anchor points at'
        },
        {
            element: TOOLTIP,
            pattern: /html:\s*aboutTooltipHtml/,
            case: 'the body renders the server-supplied copy'
        }
    ])('the markup contract: $case', ({ element, pattern }) => {
        expect(attributesOf(element)).toMatch(pattern);
    });

    test.each([
        {
            pattern: /<a\b/,
            case: 'the tooltip holds no second link — the icon is the link'
        },
        {
            pattern: /aboutLinkText\s*}?\s*(?:,|\})?\s*-->\s*<\/a>|text:\s*aboutLinkText/,
            case: 'no text link survives anywhere in the control'
        }
    ])('the control is the icon alone: $case', ({ pattern }) => {
        expect(aboutBlock().replace(/<a\b[^>]*class="two-about-icon"[^>]*>/, '')).not.toMatch(
            pattern
        );
    });

    test('nothing outside the showWhatIsTwo gate renders an about control', () => {
        const outside = withoutComments(read(TEMPLATE)).replace(aboutBlock(), '');

        expect(outside).not.toMatch(/two-about/);
        expect(outside).not.toMatch(/aboutLinkUrl|aboutTooltipHtml|aboutIconUrl/);
    });
});

describe('the icon renders beside the tile title (ABN-554)', () => {
    /** The row that holds the title; the subtitle opens the next line of the block. */
    function titleRow() {
        const match = withoutComments(read(TEMPLATE)).match(
            /<div class="two-title-row">([\s\S]*?)<!--\s*ko if:\s*twoSubtitleHtml\s*-->/
        );
        if (match === null) {
            throw new Error('the template has no title row ahead of the subtitle');
        }
        return match[1];
    }

    test.each([
        { pattern: /class="two-payment-title"/, case: 'the tile title' },
        { pattern: /<!--\s*ko if:\s*showWhatIsTwo\s*-->/, case: 'the about control' }
    ])('the title row holds $case', ({ pattern }) => {
        expect(titleRow()).toMatch(pattern);
    });

    test('the row lays its children out horizontally', () => {
        const row = read(STYLESHEET).match(/\.two-title-row\s*\{([\s\S]*?)\}/)[1];

        expect(row).toMatch(/display:\s*flex;/);
        expect(row).not.toMatch(/flex-direction:\s*column/);
    });
});

describe('the renderer feeds the control from checkoutConfig (ABN-554)', () => {
    test.each([
        { field: 'aboutTooltipHtml', case: 'the tooltip copy' },
        { field: 'aboutIconUrl', case: 'the icon asset URL' },
        { field: 'aboutLinkText', case: 'the accessible name' }
    ])('$case is read from the brand subtree', ({ field }) => {
        expect(read(RENDERER)).toMatch(
            new RegExp('this\\.' + field + " = config\\." + field + " \\|\\| '';")
        );
    });

    test('the tooltip id is stable per payment code', () => {
        const component = loadAmdModule(RENDERER);
        const ctx = Object.assign({}, component, {
            getCode: function () {
                return 'two_payment';
            }
        });

        expect(ctx.aboutTooltipId()).toBe('two-about-tooltip-two_payment');
    });
});

describe('the tooltip opens on hover and on keyboard focus (ABN-554)', () => {
    test.each([
        { pattern: /\.two-about:hover\s+\.two-about-tooltip/, case: 'hover opens it' },
        { pattern: /\.two-about:focus-within\s+\.two-about-tooltip/, case: 'focus opens it' }
    ])('$case', ({ pattern }) => {
        expect(read(STYLESHEET)).toMatch(pattern);
    });

    test('the closed tooltip stays in the layout, so it can transition rather than pop', () => {
        const closed = read(STYLESHEET).match(/\.two-about-tooltip\s*\{([\s\S]*?)\}/)[1];

        expect(closed).toMatch(/opacity:\s*0;/);
        expect(closed).not.toMatch(/display:\s*none|visibility:\s*hidden/);
    });
});
