/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — surviving a host that re-renders by MORPHING server markup over
 * the live DOM.
 *
 * The panel builds a `span.two-company-field-wrap` around the company field and
 * appends itself there. That wrapper is in no host's server markup, so a morph
 * deletes it — panel, chips and every attribute the panel wrote — while KEEPING
 * the field node. The field is then bare and clicking it does nothing until the
 * page is reloaded.
 *
 * Two guarantees make a host able to recover from that, and neither can be
 * asserted by driving the panel's happy path:
 *  - `isBound()` has to ANSWER FALSE, so the host has something to poll. A
 *    field-only check answers true, because the field is exactly what the morph
 *    keeps;
 *  - rebuilding must not accumulate a `document` listener per rebuild, or a
 *    checkout that re-renders on every keystroke leaks one per render.
 *
 * Mutation-resistance notes:
 *  - the morph is applied to the REAL DOM the panel built, in the order a morph
 *    applies it, rather than simulated by nulling the panel's own fields — a
 *    check that reads state instead of the document would still pass;
 *  - the listener case counts live `document` listeners by intercepting
 *    add/removeEventListener, so an unbind that records but does not remove
 *    fails;
 *  - each morph case starts from a DIFFERENT live state (closed, open, manual
 *    entry just exited), because the three checkout actions that trigger this
 *    reach the morph from those three states.
 */

'use strict';

const $ = require('jquery');
const { loadCompanySearchPanel } = require('./amd-harness');

const GLOBALS = { document: document, window: window };

const ROOT = '#control';
const FIELD = '#company_name';
const WRAP = '.two-company-field-wrap';
const PANEL = '.two-company-dropdown';
const CHIPS = '.two-company-mode-chips';
const BACK = '.two-company-search-back';

/** Every attribute the panel writes onto the host's own field. */
const PANEL_FIELD_ATTRIBUTES = [
    'role',
    'aria-haspopup',
    'aria-controls',
    'aria-expanded',
    'placeholder'
];

/**
 * Live `mousedown` handlers on the document, whoever owns them.
 *
 * Counted by intercepting the DOM's own add/remove rather than reading a
 * handler store: what matters is that the listener is GONE.
 */
const liveDocumentMousedown = new Set();
const nativeAdd = document.addEventListener.bind(document);
const nativeRemove = document.removeEventListener.bind(document);

document.addEventListener = function (type, handler, options) {
    if (type === 'mousedown') liveDocumentMousedown.add(handler);
    return nativeAdd(type, handler, options);
};
document.removeEventListener = function (type, handler, options) {
    if (type === 'mousedown') liveDocumentMousedown.delete(handler);
    return nativeRemove(type, handler, options);
};

/**
 * @param {Array<object>} [chips] what `getChips` reports
 * @returns {object} a bound panel over the fixture
 */
function setup(chips) {
    document.body.innerHTML =
        '<div id="control"><input id="company_name" type="text"></div>';

    const CompanySearchPanel = loadCompanySearchPanel($, null, GLOBALS);
    const panel = new CompanySearchPanel({
        fieldSelector: FIELD,
        config: { checkoutApiUrl: 'https://api.example.test', companySearchLimit: 50 },
        getChips: function () { return chips || []; },
        getCountryCode: function () { return 'gb'; }
    });
    panel.bind();

    // Bootstrapped guard: without a built panel every assertion below is vacuous.
    expect(document.querySelector(PANEL)).not.toBeNull();
    expect(panel.isBound()).toBe(true);
    return panel;
}

/**
 * What a morph does to this control: the wrapper is not in the server markup,
 * so it goes, the field is put back where the server rendered it, and the
 * attributes the panel wrote are not reinstated.
 */
function morphServerMarkupOverControl() {
    const root = document.querySelector(ROOT);
    const field = document.querySelector(FIELD);
    const wrap = field.parentElement;

    root.insertBefore(field, wrap);
    wrap.remove();
    PANEL_FIELD_ATTRIBUTES.forEach(function (attribute) {
        field.removeAttribute(attribute);
    });
}

describe('a morph that discards the panel is reported as unbound', () => {
    test.each([
        [
            'closed',
            'the buyer picked a payment method without touching the control',
            function (panel) { panel.close(); }
        ],
        [
            'open',
            'the buyer had the popover up when the country changed',
            function (panel) { panel.open(); }
        ],
        [
            'just back from manual entry',
            'the buyer used the return link, which is the cleanest reproduction',
            function (panel) { panel.releaseField(); panel.reclaimField(); }
        ]
    ])('from %s — %s', (_state, _because, reach) => {
        const panel = setup();
        reach(panel);

        morphServerMarkupOverControl();

        expect(document.querySelectorAll(WRAP)).toHaveLength(0);
        expect(document.querySelectorAll(PANEL)).toHaveLength(0);
        expect(document.querySelectorAll(CHIPS)).toHaveLength(0);
        expect(panel.isBound()).toBe(false);
    });
});

describe('recovering from the morph', () => {
    test('a re-bind puts the whole control back', () => {
        const panel = setup([{ mode: 'registered', text: 'Registered company', onActivate() {} }]);
        morphServerMarkupOverControl();

        panel.bind();

        expect(document.querySelectorAll(WRAP)).toHaveLength(1);
        expect(document.querySelectorAll(PANEL)).toHaveLength(1);
        expect(document.querySelectorAll(CHIPS)).toHaveLength(1);
        expect(document.querySelectorAll('.two-company-mode-chip')).toHaveLength(1);
        expect(panel.isBound()).toBe(true);
    });

    test('the rebuilt control still opens when the field is clicked', () => {
        const panel = setup();
        morphServerMarkupOverControl();
        panel.bind();

        document
            .querySelector(FIELD)
            .dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));

        expect(panel.isOpen()).toBe(true);
    });

    test('rebuilding does not leave a document listener behind per rebuild', () => {
        const panel = setup();
        const afterFirstBuild = liveDocumentMousedown.size;

        morphServerMarkupOverControl();
        panel.bind();
        morphServerMarkupOverControl();
        panel.bind();

        expect(liveDocumentMousedown.size).toBe(afterFirstBuild);
    });

    test('destroy() still takes the listener back after a rebuild', () => {
        const panel = setup();
        const before = liveDocumentMousedown.size;
        morphServerMarkupOverControl();
        panel.bind();

        panel.destroy();

        expect(liveDocumentMousedown.size).toBe(before - 1);
    });
});

describe('a focus-out close already scheduled when the morph lands', () => {
    test('does not fire against the control the re-bind has just rebuilt', async () => {
        const panel = setup();
        panel.open();
        // Arms the deferred close: focus leaves the panel, and the morph lands
        // inside the tick before it resolves.
        document.querySelector('.two-company-dropdown__query').dispatchEvent(
            new window.FocusEvent('focusout', { bubbles: true })
        );

        morphServerMarkupOverControl();
        panel.bind();
        panel.open();
        await new Promise(function (resolve) { setTimeout(resolve, 1); });

        expect(panel.isOpen()).toBe(true);
        expect(document.querySelectorAll(PANEL)).toHaveLength(1);
    });
});

describe('the return link the morph swept away', () => {
    test('re-entering manual entry after a morph renders exactly one link', () => {
        const panel = setup();
        panel.releaseField();
        expect(document.querySelectorAll(BACK)).toHaveLength(1);

        morphServerMarkupOverControl();
        panel.bind();
        panel.releaseField();

        expect(document.querySelectorAll(BACK)).toHaveLength(1);
    });
});
