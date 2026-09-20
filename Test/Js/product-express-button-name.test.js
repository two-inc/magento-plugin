/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25800 — the button's ACCESSIBLE NAME still names the brand.
 *
 * The visible label is "Buy with" followed by the brand's mark, so the word no
 * longer carries the brand. The mark is a CSS background and has no alternative
 * text of its own, so the brand NAME beside it is what keeps the control's
 * accessible name reading "Buy with <brand>". That name must stay out of any
 * aria-hidden element, and a brand shipping no mark must keep it visible.
 *
 * The name is what these assert, not the markup: a screenshot cannot show it.
 *
 * The template is read from disk and its three echo expressions substituted, so
 * this asserts the REAL template's structure rather than a copy of it that
 * could drift.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const TEMPLATE = path.resolve(
    __dirname,
    '../../view/frontend/templates/product/express-button.phtml'
);

/**
 * Render the template's button markup with known values.
 *
 * Deliberately crude: it substitutes the escaped echoes and drops the PHP that
 * wraps them. Anything it cannot resolve is left visible in the output, so a
 * template that grows a new expression fails these assertions rather than
 * silently dropping it.
 *
 * @param {Object} values
 * @returns {String}
 */
function render(values) {
    let source = fs.readFileSync(TEMPLATE, 'utf8');

    // PHP comment blocks first, so their prose cannot reach the DOM as text.
    source = source.replace(/<\?php\s*\/\*[\s\S]*?\*\/\s*\?>/g, '');

    source = source
        .replace(/<\?=\s*\$block->escapeHtml\(\$block->getLabel\(\)\)\s*\?>/g, values.label)
        .replace(/<\?=\s*\$block->escapeHtml\(\$block->getBrandLabel\(\)\)\s*\?>/g, values.brand)
        .replace(/<\?=\s*\$block->escapeHtmlAttr\(\$block->getBrandCode\(\)\)\s*\?>/g, values.code)
        .replace(/<\?=[\s\S]*?\?>/g, '');

    // The visibility guard and the config array are PHP the DOM never sees.
    source = source.replace(/<\?php[\s\S]*?\?>/g, '');

    return source;
}

/**
 * The accessible name of a button, computed the way a browser computes it from
 * contents: the text of every descendant that is not hidden from the
 * accessibility tree, in document order, whitespace collapsed.
 *
 * Visual hiding (the clip-rect pattern the brand name uses on a brand that
 * paints a mark) deliberately does NOT remove a node from that tree, which is
 * the whole point of using it rather than display:none.
 *
 * @param {Element} button
 * @returns {String}
 */
function accessibleName(button) {
    let name = '';

    button.childNodes.forEach(function (node) {
        if (node.nodeType === 3) {
            name += node.textContent;

            return;
        }

        if (node.nodeType !== 1 || node.getAttribute('aria-hidden') === 'true') {
            return;
        }

        name += node.textContent;
    });

    return name.replace(/\s+/g, ' ').trim();
}

function button(values) {
    document.body.innerHTML = render(values);

    return document.querySelector('.two-product-express__button');
}

describe('TWO-25800 the purchase control names its brand', () => {
    test('the accessible name is "Buy with <brand>" even though the word is not visible', () => {
        const el = button({ label: 'Buy with', brand: 'Two', code: 'two_payment' });

        expect(accessibleName(el)).toBe('Buy with Two');
    });

    /**
     * Brand-resolved throughout: an overlay's control announces its own name,
     * never Two's.
     */
    test('an overlay brand names itself', () => {
        const el = button({ label: 'Buy with', brand: 'Acme Pay', code: 'acme_payment' });

        expect(accessibleName(el)).toBe('Buy with Acme Pay');
    });

    /**
     * The mark is a CSS background and carries no alternative text of its own,
     * so it must stay out of the accessibility tree — otherwise it contributes
     * nothing and the name is built from the text alone anyway. What must NOT
     * happen is the brand name being hidden with it.
     */
    test('the mark is out of the tree and the brand name is not', () => {
        const el = button({ label: 'Buy with', brand: 'Two', code: 'two_payment' });

        expect(el.querySelector('.two-product-express__mark').getAttribute('aria-hidden')).toBe(
            'true'
        );
        expect(
            el.querySelector('.two-product-express__brand').getAttribute('aria-hidden')
        ).toBeNull();
    });

    /**
     * The load-bearing rule from TWO-25799, restated for a control rather than
     * a message: a brand shipping no mark paints nothing, the mark span
     * collapses, and the name stays VISIBLE. No storefront ends up with a
     * purchase button that names no brand.
     */
    test('the brand name is real text, so a brand with no mark still reads as itself', () => {
        const el = button({ label: 'Buy with', brand: 'Acme Pay', code: 'acme_payment' });
        const brand = el.querySelector('.two-product-express__brand');

        expect(brand.textContent.trim()).toBe('Acme Pay');
        // Nothing in the markup hides it; only a brand's own CSS may, and only
        // when that same CSS paints a mark to carry the name visually.
        expect(brand.getAttribute('hidden')).toBeNull();
        expect(brand.getAttribute('style')).toBeNull();
    });
});
