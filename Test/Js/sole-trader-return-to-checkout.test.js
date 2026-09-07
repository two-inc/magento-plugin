/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25658: focus landing on a control of the checkout takes the signup popup
 * down, the Sole trader chip alone excepted. A return that lands on the page
 * rather than on a control — a tab or app switch — leaves it alone.
 */

'use strict';

const { loadAmdModule, tagged } = require('./amd-harness');

const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';

/** The popover open behind the signup; the chip labels are deliberately not English. */
function renderCheckout() {
    document.body.innerHTML =
        '<input id="other-field">'
        + '<div class="two-company-dropdown" id="popover">'
        + '<input id="query">'
        + '<button data-two-chip="registered" id="registered">Ingeschreven bedrijf</button>'
        + '<button data-two-chip="soletrader" id="soletrader">Eenmanszaak</button>'
        + '</div>';
}

/**
 * The flow, with a signup popup already up and the watcher armed.
 *
 * @returns {object} `{ flow, windowHandlers, popupRaised, returnToCheckout }`
 */
function load() {
    const handlers = {};
    const fakeWindow = {
        addEventListener: function (type, handler) { handlers[type] = handler; },
        removeEventListener: function () {},
        open: function () { return null; }
    };
    const SoleTraderCtor = loadAmdModule(SOLE_TRADER, {}, {
        document: document,
        window: fakeWindow,
        setTimeout: setTimeout,
        clearTimeout: clearTimeout
    });

    const flow = new SoleTraderCtor({
        host: function () { return {}; },
        identity: function () { return {}; },
        config: function () { return {}; }
    });
    let raised = 0;
    flow._popupWindow = {
        closed: false,
        close: function () { this.closed = true; },
        focus: function () { raised += 1; }
    };
    flow.watchForReturnToCheckout();

    return {
        flow: flow,
        windowHandlers: handlers,
        popupRaised: function () { return raised; },
        /** @param {string} kind one of the gestures the table names */
        returnToCheckout: function (kind) {
            if (kind === 'unrelated control') document.getElementById('other-field').focus();
            if (kind === 'the company query field') document.getElementById('query').focus();
            if (kind === 'a sibling chip') document.getElementById('registered').focus();
            if (kind === 'the Sole trader chip') document.getElementById('soletrader').focus();
            // A tab or app switch returns focus to the page, not to any control.
            if (kind === 'window focus') {
                if (handlers.focus) handlers.focus();
            }
            // The popup's own controls live in another document, which never
            // reaches the opener's listener.
            if (kind === 'a popup-internal control') {
                document.createElement('input')
                    .dispatchEvent(new Event('focusin', { bubbles: true }));
            }
        }
    };
}

beforeEach(renderCheckout);

describe('what a return to checkout does to an open signup popup', () => {
    test.each([
        ['unrelated control', false, 'plainly looking away from the signup'],
        ['the company query field', false, 'the popover is not exempt, only the chip in it is'],
        ['a sibling chip', false, 'a sibling chip is not a route back to the signup'],
        ['the Sole trader chip', true, 'the one exempt control — it raises the popup instead'],
        ['window focus', true, 'a tab or app switch lands on no control at all'],
        ['a popup-internal control', true, 'the buyer is still in the signup']
    ])('focus landing on %s leaves the popup open=%s', (kind, open, why) => {
        const ctx = load();

        ctx.returnToCheckout(kind);

        expect(tagged(why, ctx.flow.isPopupOpen())).toEqual(tagged(why, open));
    });
});

test('the Sole trader chip raises the popup it kept, rather than reopening one', () => {
    const ctx = load();
    const held = ctx.flow._popupWindow;

    ctx.returnToCheckout('the Sole trader chip');

    expect(ctx.popupRaised()).toBe(1);
    expect(ctx.flow._popupWindow).toBe(held);
});

test('no window-level focus listener is armed at all', () => {
    const ctx = load();

    expect(Object.keys(ctx.windowHandlers)).not.toContain('focus');
});

test('closing the popup releases the watcher, so a later focus closes nothing', () => {
    const ctx = load();

    ctx.flow.closeSignupPopup();
    document.getElementById('other-field').focus();

    expect(ctx.flow._returnHandler).toBe(null);
});
