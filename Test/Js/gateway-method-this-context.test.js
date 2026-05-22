/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Guard against the regression where `this._brandConfig` is referenced
 * inside an iteration callback that doesn't preserve renderer `this`.
 *
 * The brand-overlay refactor (#128) moved per-instance config reads
 * onto `this._brandConfig`, but the renderer still uses `_.each(...,
 * function () { ... })` and `.forEach(function () { ... })` callbacks
 * in several methods. Inside those callbacks `this` is NOT the KO
 * component — `this._brandConfig` would be `undefined` and any deref
 * throws `TypeError: Cannot read properties of undefined`.
 *
 * The fix everywhere is to capture `var x = this._brandConfig` (or
 * use an arrow function / `_.each(..., fn, this)`). This test reads
 * the renderer source and asserts no `function (...) { ...
 * this._brandConfig... }` shape exists inside a `_.each` or
 * `.forEach` body.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const SRC = fs.readFileSync(
    path.resolve(__dirname, '../../view/frontend/web/js/view/payment/method-renderer/gateway_method.js'),
    'utf8'
);

describe('gateway_method.js `this._brandConfig` lexical-this safety', () => {
    /**
     * Walks the source looking for a `_.each(...)` or `.forEach(...)`
     * call where the callback is a `function (...)` (NOT an arrow),
     * and the body of that callback references `this._brandConfig`.
     * Returns the offending substrings (line + matched body) so test
     * failures point at exactly the regression site.
     */
    function findUnsafeBrandConfigDerefs(src) {
        const offenders = [];
        // Match _.each(<args>, function (<params>) { <body> })
        // and obj.forEach(function (<params>) { <body> }).
        // The body match stops at the first `})` that closes the IIFE
        // — non-greedy ensures we don't span across calls.
        const re = /(?:_\.each\s*\([^,]+,\s*|\.forEach\s*\(\s*)function\s*\([^)]*\)\s*\{([\s\S]*?)\}\)/g;
        let m;
        while ((m = re.exec(src)) !== null) {
            const body = m[1];
            if (/\bthis\._brandConfig\b/.test(body)) {
                const before = src.slice(0, m.index).split('\n').length;
                offenders.push({ line: before, body: body.trim().slice(0, 200) });
            }
        }
        return offenders;
    }

    it('contains no `this._brandConfig` reference inside a non-arrow iteration callback', () => {
        const offenders = findUnsafeBrandConfigDerefs(SRC);
        expect(offenders).toEqual([]);
    });
});
