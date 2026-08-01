/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * #30.x.15 payment-tile follow-up. The shared model's DOM-level contract for
 * the manual-entry button (a real `<button>`, a sibling of the results list,
 * threshold-driven, staleness-checked) is pinned in
 * company-search-manual-entry.test.js — this file covers only what is
 * specific to the payment-tile surface (`gateway_method.js`): it must wire
 * the SAME shared helpers, with an activation callback that tears down this
 * picker's own widget (`self.clearCompany()`) and brings back the "Search
 * for company" link, rather than address-autocomplete.js's
 * `enterDetailsManually()`.
 *
 * Structural (source-grep) tests only: the surface's business logic here is
 * a couple of lines of glue around calls the shared-model suite already
 * proves correct in isolation, and jest-mocking gateway_method.js's full KO
 * component just to re-prove `attachManualEntryButton` was called would add
 * a second, weaker copy of that coverage rather than new coverage.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const SURFACE_PATH = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

describe('gateway_method.js payment-tile surface (structural fix, #30.x.15)', function () {
    let src;

    beforeAll(function () {
        src = readSource(SURFACE_PATH);
    });

    test('no longer builds the manual-entry affordance as a select2 pseudo-option', () => {
        expect(src).not.toMatch(/select2:selecting/);
        expect(src).not.toMatch(/isManualEntryOption/);
    });

    test('wires the shared model button on select2:open', () => {
        expect(src).toMatch(
            /companySearch\.attachManualEntryButton\(\s*\$companyNameField,\s*bindToken,/
        );
    });

    test("the activation callback tears down this picker's widget and restores the search link", () => {
        const openIndex = src.indexOf("on('select2:open'");
        expect(openIndex).toBeGreaterThan(-1);
        const closeIndex = src.indexOf("on('select2:close'", openIndex);
        expect(closeIndex).toBeGreaterThan(openIndex);
        const block = src.slice(openIndex, closeIndex);

        expect(block).toMatch(/attachManualEntryButton\(/);
        expect(block).toMatch(/self\.clearCompany\(\)/);
        expect(block).toMatch(/\.find\(\s*['"]\.search_for_company['"]\s*\)\s*\.show\(\)/);

        // The activation callback, NOT a bare select2:selecting handler —
        // clearCompany() must be reached through attachManualEntryButton's
        // own callback argument, not a second independent listener.
        const activateIndex = block.indexOf('attachManualEntryButton(');
        const clearIndex = block.indexOf('self.clearCompany()');
        expect(clearIndex).toBeGreaterThan(activateIndex);
    });

    test('detaches the button on close, and on the re-bind path before re-init', () => {
        const reinitIndex = src.indexOf('.select2({');
        expect(reinitIndex).toBeGreaterThan(-1);
        const beforeReinit = src.slice(0, reinitIndex);
        expect(beforeReinit).toMatch(/companySearch\.detachManualEntryButton\(\s*\$companyNameField\s*\)/);

        const detachCalls = src.match(/companySearch\.detachManualEntryButton\(/g) || [];
        // re-bind path, select2:close, and destroyCompanySearchWidget.
        expect(detachCalls.length).toBeGreaterThanOrEqual(3);
    });

    test('the shared model exports the button helpers this surface depends on', () => {
        const modelSrc = readSource(MODEL_PATH);
        ['attachManualEntryButton', 'detachManualEntryButton', 'buildManualEntryButton'].forEach(
            function (name) {
                expect(modelSrc).toMatch(new RegExp(name + ':\\s*function'));
            }
        );
    });
});
