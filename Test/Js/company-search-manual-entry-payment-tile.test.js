/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * #30.x.15 payment-tile follow-up. The shared model's DOM-level contract for
 * the manual-entry button (a real `<button>`, a sibling of the results list,
 * threshold-driven, staleness-checked) is pinned in
 * company-search-manual-entry.test.js — this file covers only what is
 * specific to the payment-tile surface (`gateway_method.js`): it must
 * construct the ONE shared control (company-search-control.js) with an
 * `onManualEntryActivated` hook that tears down this picker's own widget
 * (`self.clearCompany()`) and brings back the "Search for company" link,
 * rather than address-autocomplete.js's `enterDetailsManually()`.
 *
 * TWO-25326 rebuild: the select2 wiring itself — including where
 * `attachManualEntryButton`/`detachManualEntryButton` are actually called —
 * moved out of this surface and into that one shared class. What is still
 * specific to THIS surface, and still worth pinning here, is the glue: which
 * hook it passes and what that hook does.
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
const CONTROL_PATH = 'view/frontend/web/js/model/company-search-control.js';

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

describe('gateway_method.js payment-tile surface (structural fix, #30.x.15)', function () {
    let src;
    let controlSrc;

    beforeAll(function () {
        src = readSource(SURFACE_PATH);
        controlSrc = readSource(CONTROL_PATH);
    });

    test('no longer builds the manual-entry affordance as a select2 pseudo-option', () => {
        expect(src).not.toMatch(/select2:selecting/);
        expect(src).not.toMatch(/isManualEntryOption/);
    });

    test('does not roll its own select2 wiring — constructs the shared control instead', () => {
        expect(src).toContain('new CompanySearchControl(');
        expect(src).not.toContain('.select2({');
        expect(src).not.toMatch(/companySearch\.attachManualEntryButton\(/);
        expect(src).not.toMatch(/companySearch\.detachManualEntryButton\(/);
    });

    test('the shared control wires the shared model button on select2:open, and detaches it on close/re-bind', () => {
        expect(controlSrc).toMatch(
            /companySearch\.attachManualEntryButton\(\s*\$field,\s*bindToken,/
        );
        // Search from the `bind()` method itself, not from index 0 — the
        // class's own doc comment mentions `.select2({…})` in prose, which
        // would otherwise be found first and make every real call below it
        // look like it came "before" the (real) select2 init.
        const bindMethodIndex = controlSrc.indexOf('CompanySearchControl.prototype.bind =');
        expect(bindMethodIndex).toBeGreaterThan(-1);
        const reinitIndex = controlSrc.indexOf('.select2({', bindMethodIndex);
        expect(reinitIndex).toBeGreaterThan(-1);
        const beforeReinit = controlSrc.slice(bindMethodIndex, reinitIndex);
        expect(beforeReinit).toMatch(/companySearch\.detachManualEntryButton\(\s*\$field\s*\)/);

        const detachCalls = controlSrc.match(/companySearch\.detachManualEntryButton\(/g) || [];
        // re-bind path (bind()) and select2:close.
        expect(detachCalls.length).toBeGreaterThanOrEqual(2);
    });

    test("the activation callback tears down this picker's widget and restores the search link", () => {
        const hookIndex = src.indexOf('onManualEntryActivated:');
        expect(hookIndex).toBeGreaterThan(-1);
        const nextKeyIndex = src.indexOf('onBound:', hookIndex);
        expect(nextKeyIndex).toBeGreaterThan(hookIndex);
        const block = src.slice(hookIndex, nextKeyIndex);

        expect(block).toMatch(/self\.clearCompany\(\)/);
        expect(block).toMatch(/showSearchForCompanyLink\(\s*true\s*\)/);

        // clearCompany() (the teardown) has to run BEFORE the link is shown,
        // or the widget's own destroy() would tear down a link the buyer was
        // just shown.
        const clearIndex = block.indexOf('self.clearCompany()');
        const showIndex = block.indexOf('showSearchForCompanyLink(');
        expect(showIndex).toBeGreaterThan(clearIndex);
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
