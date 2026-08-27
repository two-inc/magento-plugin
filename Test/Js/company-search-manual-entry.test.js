/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * #30.x.15. The manual-entry affordance has been a row inside the results
 * list, and then a button beside it; TWO-25503 makes it one of the mode chips
 * inside the popover itself. Each move was driven by the same two failures:
 *
 *  - anything living inside the results list is inside the element the picker
 *    clips and scrolls, so it was only visible once the buyer scrolled past
 *    however many real results came back, and reaching it by keyboard meant
 *    arrowing down through every one of them;
 *  - anything living outside the popover is a sibling of the company field,
 *    which the open popover draws over — so the route out of search mode was
 *    hidden exactly while the buyer was in search mode.
 *
 * The chip fixes both by construction: a real `<button>`, a descendant of the
 * panel, placed AFTER the results host so the browser's own tab order reaches
 * it without walking the results, and painted inside the panel so opening the
 * panel cannot cover it.
 *
 * Mutation-resistance notes:
 *  - the chip assertions run against the REAL panel over REAL jsdom nodes, so
 *    tag name, containment and document order are the document's answers;
 *  - translation is asserted on the msgid, since `$t` resolves to identity here;
 *  - the mode-transition tests read identity state and real DOM attributes
 *    after a real transition, never that a method exists.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const { loadAmdModule, defaultMocks, loadCompanySearchPanel, dispatchNative } = require('./amd-harness');

const COMPONENT_PATH = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY_PATH = 'view/frontend/web/js/model/company-identity.js';
const MSGID = 'Enter manually';

const GLOBALS = { document: document, window: window };
const PANEL = '.two-company-dropdown';
const RESULTS = '.two-company-dropdown__results';
const CHIP = '.two-company-mode-chip';
const MANUAL_CHIP = `${CHIP}[data-two-chip="manual"]`;
const HIDDEN_CLASS = 'two-hidden';

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

function companySearchMock() {
    return Object.assign(
        {},
        defaultMocks()['Two_Gateway/js/model/company-search'],
        { currentAddressFormCountry: function () { return 'gb'; } }
    );
}

/**
 * The page-level component over the fixture.
 *
 * Loaded fresh per test — both the component and the identity are page-level
 * singletons, so a shared load would carry one case's captured company into
 * the next.
 *
 * @param {object} [options]
 * @param {boolean} [options.companySearchEnabled] the admin setting manual
 *        entry is gated on
 * @param {Function} [options.Panel] panel class to mount; defaults to the real
 *        one
 * @returns {object} `{ component, identity }`
 */
function loadCapture(options) {
    const settings = options || {};
    const identity = loadAmdModule(IDENTITY_PATH, {}, GLOBALS);
    const SoleTraderStub = function () {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.focusSignupPopup = function () { return false; };
        this.launchSignup = function () { return {}; };
        this.forgetAdoptions = function () {};
    };
    const companySearch = companySearchMock();

    const component = loadAmdModule(
        COMPONENT_PATH,
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-panel':
                settings.Panel || loadCompanySearchPanel($, companySearch, GLOBALS),
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandConfig: function () {
                    return {
                        isCompanySearchEnabled: settings.companySearchEnabled !== false,
                        checkoutApiUrl: 'https://api.example.test',
                        companySearchLimit: 50,
                        supportedCompanyTypes: {}
                    };
                }
            }
        },
        GLOBALS
    );
    component.start();
    return { component: component, identity: identity };
}

function manualChip() {
    return document.querySelector(MANUAL_CHIP);
}

beforeEach(() => {
    document.body.innerHTML =
        '<form id="two_gateway_form"><div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" />' +
        '</div></div></form>';
    $(document).off('.twoCompanyCapture');
});

describe('the manual-entry affordance is a real, native button', () => {
    beforeEach(() => {
        loadCapture();
    });

    test('it is a <button type="button">, not a role=option pseudo-row', () => {
        const chip = manualChip();

        expect(chip).toBeTruthy();
        // Native semantics are the whole point: a real button is Tab-reachable
        // and Enter/Space-activatable with no keyboard handling of our own.
        expect(chip.tagName).toBe('BUTTON');
        expect(chip.getAttribute('type')).toBe('button');
        expect(chip.getAttribute('role')).toBeNull();
        expect(chip.textContent).toBe(MSGID);
    });

    test('the label is set as text, never as markup', () => {
        // The result rows disable escaping so server-side highlighting can
        // render, which makes the catalogue an injection point if a label is
        // ever interpolated into HTML.
        const source = readSource(COMPONENT_PATH);

        expect(source).toContain("$t('" + MSGID + "')");
        expect(source).not.toMatch(/<button[^>]*>\$\{/);
    });

    test.each(['nb_NO', 'nl_NL', 'sv_SE'])('the label is translated in %s', (locale) => {
        const csv = readSource('i18n/' + locale + '.csv');

        expect(csv).toContain('"' + MSGID + '","');
        // Magento drops rows whose translation equals the msgid.
        expect(csv).not.toContain('"' + MSGID + '","' + MSGID + '"');
    });
});

describe('where the chip sits', () => {
    beforeEach(() => {
        loadCapture();
    });

    test('it is inside the panel, not a sibling of the company field', () => {
        // A sibling is a node the open panel draws over — the defect
        // TWO-25503 exists to close.
        expect(manualChip().closest(PANEL)).not.toBeNull();
    });

    test('it comes after the results host, so tab order reaches it without walking results', () => {
        const results = document.querySelector(RESULTS);

        expect(
            results.compareDocumentPosition(manualChip()) &
                window.Node.DOCUMENT_POSITION_FOLLOWING
        ).toBeTruthy();
        expect(results.querySelectorAll(CHIP)).toHaveLength(0);
    });

    test('a repainted result list leaves the chips alone', () => {
        // Rows are replaced on every search; the chips are outside that host,
        // so no observer is needed to prove they survive.
        document.querySelector(RESULTS).innerHTML = '<div>Example Trading Ltd</div>';

        expect(document.querySelectorAll(MANUAL_CHIP)).toHaveLength(1);
    });

    test('repeated syncs never double the chips', () => {
        const capture = loadCapture();

        capture.component.syncChips();
        capture.component.syncChips();

        expect(document.querySelectorAll(MANUAL_CHIP)).toHaveLength(1);
    });
});

describe('the chip is offered only where manual entry can work', () => {
    test.each([
        [true, false, 'company search on — a typed name has a lookup behind it'],
        [false, true, 'company search off — a typed name would be a dead end']
    ])('search enabled %p -> chip hidden %p (%s)', (enabled, hidden) => {
        loadCapture({ companySearchEnabled: enabled });

        expect(manualChip().classList.contains(HIDDEN_CLASS)).toBe(hidden);
    });
});

describe('entering manual entry', () => {
    test('clicking the chip is the route in', () => {
        const capture = loadCapture();

        $(MANUAL_CHIP).trigger('click');

        expect(capture.identity.captureMode()).toBe('manual');
    });

    test('the field becomes a plain, typeable, focused text input', () => {
        const capture = loadCapture();

        capture.component.manualEntryMode();

        const field = document.querySelector('#company_name');
        expect(capture.identity.captureMode()).toBe('manual');
        expect(field.type).toBe('text');
        expect(field.readOnly).toBe(false);
        expect(field.disabled).toBe(false);
        expect(field.value).toBe('');
        expect(document.activeElement).toBe(field);
    });

    test('the panel is shut, so typing into the field does not reopen it', () => {
        const capture = loadCapture();

        capture.component.manualEntryMode();
        dispatchNative($('#company_name')[0], 'mousedown');

        expect(document.querySelector(PANEL).hasAttribute('hidden')).toBe(true);
    });

    test('an in-flight search is aborted BEFORE the field is released', () => {
        const calls = [];
        const PanelStub = function () {};
        PanelStub.prototype.bind = function () { calls.push('bind'); };
        PanelStub.prototype.abortActiveRequest = function () { calls.push('abort'); return true; };
        PanelStub.prototype.releaseField = function () { calls.push('release'); };
        PanelStub.prototype.reclaimField = function () {};
        PanelStub.prototype.close = function () {};
        PanelStub.prototype.syncChips = function () {};
        PanelStub.prototype.setDisplayText = function () {};
        PanelStub.prototype.isBound = function () { return calls.indexOf('bind') !== -1; };
        PanelStub.prototype.getField = function () { return $(); };

        const capture = loadCapture({ Panel: PanelStub });
        capture.component.manualEntryMode();

        // Order is load-bearing: a reply landing after the field is handed
        // back would paint results into a control the buyer has left.
        expect(calls.filter((call) => call === 'abort' || call === 'release'))
            .toEqual(['abort', 'release']);
    });

    test('the registry number is abandoned but the name survives the transition', () => {
        const capture = loadCapture();
        capture.identity.write({ companyName: 'Example Trading Ltd', companyId: '12345678' });

        capture.component.manualEntryMode();

        expect(capture.identity.companyId()).toBe('');
        // The sole-trader signup prefills from it and the intent notice reads it.
        expect(capture.identity.companyName()).toBe('Example Trading Ltd');
        expect(capture.identity.isCaptured()).toBe(false);
    });
});
