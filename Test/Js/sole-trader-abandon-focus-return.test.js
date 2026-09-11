/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-561: closing the sole-trader signup window with nothing captured left
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

/** A capture component whose panel records the focus restores asked of it. */
function loadComponentWithPanelDouble() {
    const capture = loadCompanyCapture({
        jquery: $,
        'Two_Gateway/js/model/sole-trader': function () {},
        'Two_Gateway/js/model/brand-config': brandConfigMock(null),
        'Two_Gateway/js/model/company-search': defaultMocks()['Two_Gateway/js/model/company-search']
    }, GLOBALS);
    const component = capture.shipping;
    const restores = [];
    component._panel = {
        restoreFieldFocus: function () { restores.push(true); },
        reclaimField: function () {},
        bind: function () {},
        close: function () {},
        setDisplayText: function () {},
        syncChips: function () {},
        isBound: function () { return true; },
        unmount: function () {}
    };

    return { component: component, restores: restores };
}

describe('closing the sole-trader signup returns focus (ABN-561)', function () {
    test.each([
        ['focus dropped by the launch is handed back to the company field', false, false, false, 1],
        [
            'the buyer moved to another control, so the close is theirs and focus stays there',
            false,
            true,
            false,
            0
        ],
        ['an adopted sole trader is the adopt path\'s business, not this one', true, false, false, 0],
        [
            'a handover gave focus to another capture\'s signup, whose own launch blurred it',
            false,
            false,
            true,
            0
        ]
    ])('%s', function (because, adopted, focusElsewhere, handedOver, expectedRestores) {
        const ctx = loadComponentWithPanelDouble();
        ctx.component.identity().soleTraderAdopted(adopted);
        // After the load, which resets the fixture: the focused node has to
        // survive into abandonSoleTrader() for the guard to read it.
        document.body.innerHTML = '<input id="other-control">';
        if (focusElsewhere) {
            document.getElementById('other-control').focus();
        } else {
            // What openPopup() leaves behind: nothing focused at all.
            document.getElementById('other-control').blur();
        }

        ctx.component.abandonSoleTrader(handedOver ? { returnFocus: false } : undefined);

        expect(ctx.restores.length).toBe(expectedRestores);
    });
});

/** The real panel bound to a real field, so focus and open state are the DOM's. */
function bindRealPanel() {
    document.body.innerHTML =
        '<div class="control"><input id="company_name" type="text"></div>'
        + '<button id="elsewhere" type="button">elsewhere</button>';

    const companySearch = loadAmdModule('view/frontend/web/js/model/company-search.js', { jquery: $ }, GLOBALS);
    const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
    const panel = new CompanySearchPanel({
        fieldSelector: '#company_name',
        config: { checkoutApiUrl: 'https://api.example.test' },
        getCountryCode: function () { return 'gb'; },
        getSelectedMode: function () { return 'registered'; }
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
        ['a closed popover stays closed: the field opener must not fire', 'elsewhere', false],
        ['an open popover stays open, though the field sits outside its node', 'inside', true]
    ])('%s', async function (because, startFocus, expectedOpen) {
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
