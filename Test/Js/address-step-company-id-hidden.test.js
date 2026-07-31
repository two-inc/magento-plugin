/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. A CSS-only hide of the address step's separate "Company Number"
 * field. The field an earlier TWO-25288 change made real and editable — see
 * address-company-id.test.js — stays present and functional; only its visual
 * rendering changes.
 *
 * This file used to also pin a grey inline hint of the registry id beside the
 * payment tile's company-name field. That hint is GONE: the tile now shows the
 * number as a labelled read-only input of its own, which supersedes it, and
 * rendering both would have shown the buyer the same number twice. The
 * replacement is pinned by tile-company-readonly-fields.test.js.
 *
 * What is NOT asserted here: `companyId` reaching getData() and the writer
 * enumeration for it. Those are pinned by
 * gateway-method-company-selection.test.js and
 * tile-company-readonly-fields.test.js; duplicating them would only drift.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const STYLE = 'view/frontend/web/css/style.css';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';
const LAYOUT_PROCESSOR = 'Plugin/Model/Checkout/LayoutProcessorPlugin.php';

function readRepoFile(relPath) {
    const full = path.join(__dirname, '..', '..', relPath);
    const contents = fs.readFileSync(full, 'utf8');
    // Guard the fixture itself: a silently-empty or truncated read would make a
    // `not.toBeNull()` on a regex match fail for the wrong reason, and every
    // "does not contain" assertion below pass for the wrong reason.
    if (contents.length < 200) {
        throw new Error(relPath + ' fixture looks truncated: ' + contents.length + ' bytes');
    }
    return contents;
}

describe('address step: company-number field is CSS-hidden, not removed', () => {
    test('the layout processor still marks the field visible in the UI registry', () => {
        // `visible: false` would pull the component out of the render tree
        // entirely, breaking address-autocomplete.js's `uiRegistry.get()`
        // lookup — a CSS class is the only sanctioned way to hide it.
        // See Test/Unit/Plugin/Model/Checkout/LayoutProcessorPluginTest.php
        // for the PHPUnit-side pin of the same invariant.
        const php = readRepoFile(LAYOUT_PROCESSOR);

        expect(php).toMatch(/'visible'\s*=>\s*true/);
        expect(php).toMatch(/'additionalClasses'\s*=>\s*'two-company-id-hidden'/);
    });

    test('the CSS hides the field purely visually', () => {
        const css = readRepoFile(STYLE);

        const ruleMatch = css.match(/\.two-company-id-hidden\s*\{([^}]*)\}/);
        expect(ruleMatch).not.toBeNull();
        expect(ruleMatch[1]).toMatch(/display:\s*none/);
    });

    test('the superseded payment-tile hint is gone from markup and stylesheet', () => {
        // Both halves, because either one surviving alone is a defect: the span
        // without its CSS paints the number unstyled in the middle of the field,
        // and the CSS without the span is dead weight that would silently
        // reposition anything later given the class.
        const markup = readRepoFile(TEMPLATE);
        const css = readRepoFile(STYLE);

        expect(markup).not.toContain('two-company-id-hint');
        expect(markup).not.toContain('two-company-name-control');
        expect(css).not.toContain('two-company-id-hint');
        expect(css).not.toContain('two-company-name-control');
    });
});
