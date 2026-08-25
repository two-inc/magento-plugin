/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * #30.x.15 payment-tile follow-up. The shared model's DOM-level contract for
 * the manual-entry button (a real `<button>`, a sibling of the results list,
 * threshold-driven, staleness-checked) is pinned in
 * company-search-manual-entry.test.js — this file covers only what is
 * specific to the payment-tile surface (`gateway_method.js`): it must
 * construct the ONE shared control (company-search-control.js), and it must
 * opt that control's in-dropdown button OUT, because on this surface manual
 * entry is a peer chip in the mode control instead. The address-area mount,
 * which has no mode control, keeps the button.
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

    /*
     * TWO-25503 reversed the direction of this surface's manual-entry glue.
     * The in-dropdown button was the ONLY route to manual entry here, and it
     * is now none of it: manual entry is a peer chip in the mode control, so
     * this surface opts the shared button out and owns the mode transition
     * itself. The behavioural half — what manualEntryMode() actually does —
     * is pinned in gateway-method-capture-mode-chips.test.js.
     */
    test('this surface opts the shared in-dropdown button out entirely', () => {
        expect(src).toContain('manualEntryEnabled: false');
        expect(src).not.toContain('onManualEntryActivated:');
        // The control has to honour the opt-out, not merely accept it.
        expect(controlSrc).toMatch(
            /if \(self\.manualEntryEnabled\) \{\s*companySearch\.attachManualEntryButton\(/
        );
    });

    test('the address-area mount still gets the button — the opt-out is per mount', () => {
        const addressSrc = readSource('view/frontend/web/js/view/address-autocomplete.js');
        expect(addressSrc).toContain('onManualEntryActivated:');
        expect(addressSrc).not.toContain('manualEntryEnabled:');
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
