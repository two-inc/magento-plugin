/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Ports the Woo reference UX: a grey inline hint of the selected
 * company's registry id, painted beside the payment tile's company-name
 * field, plus a CSS-only hide of the address step's separate "Company
 * Number" field (the field an earlier TWO-25288 change made real and
 * editable — see address-company-id.test.js — stays present and
 * functional; only its visual rendering changes).
 *
 * What is NOT re-asserted here: `companyId` reaching getData() and the
 * writer enumeration for it. Those are pinned by
 * gateway-method-company-selection.test.js and
 * tile-company-number-removed.test.js; duplicating them would only drift.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';
const STYLE = 'view/frontend/web/css/style.css';
const LAYOUT_PROCESSOR = 'Plugin/Model/Checkout/LayoutProcessorPlugin.php';

function readRepoFile(relPath) {
    return fs.readFileSync(path.join(__dirname, '..', '..', relPath), 'utf8');
}

function withoutComments(markup) {
    return markup.replace(/<!--[\s\S]*?-->/g, '');
}

describe('payment tile: grey inline company-id hint', () => {
    test('the template renders a hint span bound to companyId, next to the name field', () => {
        const markup = readRepoFile(TEMPLATE);
        const withoutHtmlComments = withoutComments(markup);

        // The span must exist, be bound to `companyId` (not a plain field:
        // getData() must keep reading the observable, never a DOM value), and
        // its visibility must track the same observable so it disappears
        // exactly when there is no id to show.
        expect(withoutHtmlComments).toMatch(/class="two-company-id-hint"/);
        const hintBlockMatch = withoutHtmlComments.match(
            /<span\s+class="two-company-id-hint"[\s\S]*?<\/span>/
        );
        expect(hintBlockMatch).not.toBeNull();
        const hintBlock = hintBlockMatch[0];
        expect(hintBlock).toMatch(/text:\s*companyId\b/);
        expect(hintBlock).toMatch(/visible:\s*companyId\b/);

        // It must NOT be an <input> — cosmetic only, carries no value of its
        // own. Reuses the same regression pin shape as
        // tile-company-number-removed.test.js so a reintroduction as a real
        // field would be caught the same way.
        const inputTags = withoutHtmlComments.match(/<input\b[^>]*>/g) || [];
        inputTags.forEach(function (tag) {
            expect(tag).not.toMatch(/two-company-id-hint/);
        });
    });

    test('the hint sits inside the same control block as the company-name input', () => {
        const markup = withoutComments(readRepoFile(TEMPLATE));

        const controlBlockMatch = markup.match(
            /<div class="control two-company-name-control">[\s\S]*?<\/div>\s*<\/div>/
        );
        expect(controlBlockMatch).not.toBeNull();
        const controlBlock = controlBlockMatch[0];

        expect(controlBlock).toContain('id="company_name"');
        expect(controlBlock).toContain('class="two-company-id-hint"');
    });

    test('the CSS positions the hint absolutely, in light grey, against a relative parent', () => {
        const css = readRepoFile(STYLE);

        const parentRuleMatch = css.match(/\.two-company-name-control\s*\{([^}]*)\}/);
        expect(parentRuleMatch).not.toBeNull();
        expect(parentRuleMatch[1]).toMatch(/position:\s*relative/);

        const hintRuleMatch = css.match(/\.two-company-id-hint\s*\{([^}]*)\}/);
        expect(hintRuleMatch).not.toBeNull();
        const hintRule = hintRuleMatch[1];
        expect(hintRule).toMatch(/position:\s*absolute/);
        expect(hintRule).toMatch(/color:\s*#dddddd/);
        expect(hintRule).toMatch(/white-space:\s*nowrap/);
    });

    test('applyCompanyData drives the observable the hint reads: present on a pick, empty on none', () => {
        const dom = makeInertJquery();
        const renderer = loadAmdModule(RENDERER, { jquery: dom.$ });
        renderer.getCode = function () {
            return 'two_payment';
        };

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        expect(renderer.companyId()).toBe('12345678');

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            { authoritative: true }
        );
        expect(renderer.companyId()).toBe('');
    });
});

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
});

/** Minimal jQuery double good enough to load the renderer in isolation. */
function makeInertJquery() {
    function node() {
        const n = {
            length: 0,
            val: function () {
                return arguments.length ? n : '';
            },
            prop: function () {
                return n;
            },
            attr: function () {
                return n;
            },
            text: function () {
                return n;
            },
            on: function () {
                return n;
            },
            off: function () {
                return n;
            },
            closest: function () {
                return n;
            },
            find: function () {
                return n;
            },
            data: function () {
                return null;
            },
            append: function () {
                return n;
            },
            hide: function () {
                return n;
            },
            show: function () {
                return n;
            },
            select2: function () {
                return n;
            }
        };
        return n;
    }

    function $() {
        return node();
    }
    $.async = function (selector, cb) {
        cb(selector);
    };
    $.each = function (xs, fn) {
        (xs || []).forEach(function (x, i) {
            fn(i, x);
        });
    };
    $.ajax = function () {
        return { done: () => this, fail: () => this, always: () => this };
    };
    $.Deferred = function () {
        const d = {
            resolve: () => d,
            reject: () => d,
            promise: () => d,
            done: () => d,
            fail: () => d,
            always: () => d
        };
        return d;
    };
    $.mage = { cookies: { get: () => null, set: () => {} }, redirect: () => {} };
    $.extend = Object.assign;
    $.fn = {};

    return { $: $ };
}
