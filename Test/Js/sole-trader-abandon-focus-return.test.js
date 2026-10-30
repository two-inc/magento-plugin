/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Closing the sole-trader signup window with nothing captured left
 * focus on the document body, because launching it blurs whatever held focus
 * (TWO-25658) and nothing gave it back.
 *
 * jsdom cannot verify sequential focus navigation, so what is asserted here is
 * the observable proxy: which element `document.activeElement` is, and whether
 * the panel node carries `hidden`. The keyboard route itself is verified in a
 * real browser.
 */

'use strict';

const $ = require('jquery');
const {
    loadAmdModule,
    loadCompanyCapture,
    loadCompanySearchPanel,
    brandConfigMock,
    defaultMocks
} = require('./amd-harness');

const GLOBALS = { document: document, window: window };
const PANEL = '.two-company-dropdown';

/**
 * A capture component whose panel records the focus restores and closes asked
 * of it.
 *
 * @param {?Element} [launcher] what the flow reports as the launcher still on screen
 */
function loadComponentWithPanelDouble(launcher) {
    const capture = loadCompanyCapture({
        jquery: $,
        'Two_Gateway/js/model/sole-trader': function () {},
        'Two_Gateway/js/model/brand-config': brandConfigMock(null),
        'Two_Gateway/js/model/company-search': defaultMocks()['Two_Gateway/js/model/company-search']
    }, GLOBALS);
    const component = capture.shipping;
    const restores = [];
    const closes = [];
    const released = [];
    // Leaving sole-trader mode reaches into the flow, which this fixture does
    // not boot.
    component._soleTrader = {
        closeSignupPopup: function () { return false; },
        focusSignupPopup: function () { return false; },
        launchSignup: function () { return {}; },
        matchingSoleTrader: function () { return null; },
        isPopupOpen: function () { return false; },
        showSignupPanel: function () {},
        launcherToRefocus: function () { return launcher || null; },
        autofilledSoleTrader: function () { return null; },
        forgetAutofilledBuyer: function () {},
        prefetchBuyer: function () {}
    };
    component._panel = {
        restoreFieldFocus: function () { restores.push(true); },
        reclaimField: function () {},
        releaseField: function () { released.push(true); },
        abortActiveRequest: function () {},
        bind: function () {},
        close: function () { closes.push(true); },
        setDisplayText: function () {},
        syncChips: function () {},
        isBound: function () { return true; },
        unmount: function () {}
    };

    return { component: component, restores: restores, closes: closes, released: released };
}

describe('closing the sole-trader signup returns focus', function () {
    test.each([
        [false, false, false, true, 'none', 'field', 1, 'a hand-close with no launcher on screen hands focus to the company field'],
        [false, false, false, true, 'launcher', 'launcher', 1, 'a hand-close hands focus back to the launcher (guide row 1.39)'],
        [true, false, false, true, 'launcher', 'launcher', 1, 'a hand-closed replacement signup goes back to its "select a different" link'],
        [false, true, false, true, 'none', 'other', 1, 'the buyer moved to another control, so focus stays theirs'],
        [false, false, true, true, 'none', 'none', 1, 'a handover gave focus to another capture\'s signup'],
        [false, false, false, false, 'none', 'none', 0, 'a rule close leaves the popover and focus where the buyer put them']
    ])(
        'adopted=%p elsewhere=%p handedOver=%p handClosed=%p launcher=%s -> focus %s, %p popover closes (%s)',
        function (adopted, focusElsewhere, handedOver, handClosed, launcherKind, expectedFocus, expectedCloses, why) {
            document.body.innerHTML = '<input id="other-control"><button id="launcher" type="button">Sole trader</button>';
            const launcher = launcherKind === 'launcher' ? document.getElementById('launcher') : null;
            const ctx = loadComponentWithPanelDouble(launcher);
            ctx.component.identity().captureMode('soletrader');
            ctx.component.identity().soleTraderAdopted(adopted);
            // loadCompanyCapture resets the body; put the fixture back.
            if (!document.getElementById('launcher')) {
                document.body.innerHTML = '<input id="other-control"><button id="launcher" type="button">Sole trader</button>';
            }
            if (focusElsewhere) {
                document.getElementById('other-control').focus();
            } else {
                // What openPopup() leaves behind: nothing focused at all.
                document.activeElement.blur();
            }
            const launcherNode = launcher || null;
            let launcherFocused = false;
            if (launcherNode) launcherNode.focus = function () { launcherFocused = true; };

            ctx.component.abandonSoleTrader({ returnFocus: !handedOver, handClosed: handClosed });

            const focus = launcherFocused ? 'launcher'
                : ctx.restores.length ? 'field'
                    : document.activeElement === document.getElementById('other-control') ? 'other' : 'none';
            expect([why, focus, ctx.closes.length]).toEqual([why, expectedFocus, expectedCloses]);
        }
    );
});

describe('an abandoned signup clears nothing and recomputes the mode (TWO-26775)', function () {
    test.each([
        ['registered', 'Acme Ltd', '12345678', 'registered', false, 'a registered pick is still the capture, so its mode comes back'],
        ['manual', 'Typed Name Ltd', '', 'manual', true, 'a manual name with no id lands the buyer back in manual entry'],
        ['registered', '', '', 'registered', false, 'nothing captured comes back as the default search']
    ])('from %s with %p/%p the close lands in %s (released=%p) (%s)', function (before, name, id, expectedMode, expectReleased, why) {
        const ctx = loadComponentWithPanelDouble();
        const identity = ctx.component.identity();
        identity.write({ companyName: name, companyId: id }, { authoritative: true });
        identity.captureMode(before);
        ctx.component.soleTraderMode();
        ctx.released.length = 0;

        ctx.component.abandonSoleTrader({ handClosed: true });

        expect([why, identity.captureMode(), identity.companyName(), identity.companyId(), ctx.released.length > 0])
            .toEqual([why, expectedMode, name, id, expectReleased]);
    });

    test('the restingMode() an adopted sole trader stands in is sole trader', function () {
        const ctx = loadComponentWithPanelDouble();
        ctx.component.identity().soleTraderAdopted(true);
        expect(ctx.component.restingMode()).toBe('soletrader');
    });
});

/**
 * The real panel bound to a real field, so focus and open state are the DOM's.
 *
 * @param {Array<object>} [chips] chip definitions to render, none by default
 */
function bindRealPanel(chips) {
    document.body.innerHTML =
        '<div class="control"><input id="company_name" type="text"></div>'
        + '<button id="elsewhere" type="button">elsewhere</button>';

    const companySearch = loadAmdModule('view/frontend/web/js/model/company-search.js', { jquery: $ }, GLOBALS);
    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const panel = new CompanySearchPanel({
        fieldSelector: '#company_name',
        config: { checkoutApiUrl: 'https://api.example.test' },
        getCountryCode: function () { return 'gb'; },
        getSelectedMode: function () { return 'registered'; },
        getChips: function () { return chips || []; }
    });
    panel.bind();

    // Bootstrapped guard: with no panel built, the open-state assertions below
    // would pass against nothing.
    expect(document.querySelector(PANEL)).not.toBeNull();
    return panel;
}

function panelIsOpen() {
    const node = document.querySelector(PANEL);
    return !!node && !node.hasAttribute('hidden');
}

/** The panel defers its focus-out close by a tick; drain that before asserting. */
function nextTick() {
    return new Promise(function (resolve) { setTimeout(resolve, 1); });
}

describe('restoreFieldFocus() hands the field back without moving the popover', function () {
    test.each([
        ['elsewhere', false, 'a closed popover stays closed: the field opener must not fire'],
        ['inside', true, 'an open popover stays open, though the field sits outside its node']
    ])('from %s the popover stays open=%p (%s)', async function (startFocus, expectedOpen, because) {
        const panel = bindRealPanel();
        if (startFocus === 'inside') {
            // The panel's own opener puts the caret inside the panel node, which
            // is the state whose focusout would otherwise close it.
            panel.open();
            expect(document.querySelector(PANEL).contains(document.activeElement)).toBe(true);
        } else {
            document.getElementById('elsewhere').focus();
            await nextTick();
        }

        panel.restoreFieldFocus();
        await nextTick();

        expect(document.activeElement).toBe(document.getElementById('company_name'));
        expect(panelIsOpen()).toBe(expectedOpen);
    });
});

describe('a chip-row rebuild hands the buyer\'s focus to the field', function () {
    const CHIPS = [
        { mode: 'registered', text: 'Registered company', onActivate: function () {} },
        { mode: 'soletrader', text: 'Sole trader', onActivate: function () {} }
    ];

    test.each([
        ['soletrader', 'company_name', 'the rebuild deletes the chip the buyer was on'],
        ['elsewhere', 'elsewhere', 'focus the rebuild did not touch stays where the buyer put it']
    ])('focus starting on %s ends on #%s (%s)', async function (startOn, expectedId) {
        const panel = bindRealPanel(CHIPS);
        panel.syncChips();
        const start = startOn === 'elsewhere'
            ? document.getElementById('elsewhere')
            : document.querySelector('.two-company-mode-chip[data-two-chip="' + startOn + '"]');
        start.focus();
        await nextTick();
        expect(document.activeElement).toBe(start);

        panel.syncChips();
        await nextTick();

        expect(document.activeElement).toBe(document.getElementById(expectedId));
    });
});
