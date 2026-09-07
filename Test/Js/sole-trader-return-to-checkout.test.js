/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25658: what focus landing on a control does to an open sole-trader signup popup, and to
 * the capture popover it was launched from. The popup's own controls are in another document.
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
 * @returns {object} `{ flow, windowHandlers, popupRaised, focusins, popoverClosed,
 *          returnToCheckout }`
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

    let popoverClosed = 0;
    const flow = new SoleTraderCtor({
        host: function () { return {}; },
        identity: function () { return {}; },
        config: function () { return {}; },
        panel: function () {
            return {
                getPanelElement: function () { return document.getElementById('popover'); },
                close: function () { popoverClosed += 1; }
            };
        }
    });
    let raised = 0;
    flow._popupWindow = {
        closed: false,
        close: function () { this.closed = true; },
        focus: function () { raised += 1; }
    };
    flow.watchForReturnToCheckout();
    // As company-search-panel.js binds every chip, and as soleTraderMode()
    // opens: the cancelled mousedown is why a mouse click never focuses it.
    const chip = document.getElementById('soletrader');
    chip.addEventListener('mousedown', (event) => { event.preventDefault(); });
    chip.addEventListener('click', () => { flow.focusSignupPopup(); });

    let focusins = 0;
    document.addEventListener('focusin', () => { focusins += 1; }, true);

    return {
        flow: flow,
        windowHandlers: handlers,
        popupRaised: function () { return raised; },
        focusins: function () { return focusins; },
        popoverClosed: function () { return popoverClosed; },
        /** @param {string} kind one of the gestures the table names */
        returnToCheckout: function (kind) {
            if (kind === 'a real mouse click on the Sole trader chip') {
                const mousedown = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
                chip.dispatchEvent(mousedown);
                expect(mousedown.defaultPrevented).toBe(true);
                chip.click();
            }
            if (kind === 'unrelated control') document.getElementById('other-field').focus();
            if (kind === 'the company query field') document.getElementById('query').focus();
            if (kind === 'a sibling chip') document.getElementById('registered').focus();
            if (kind === 'the Sole trader chip') document.getElementById('soletrader').focus();
            // A tab or app switch returns focus to the page, not to any control.
            if (kind === 'window focus') {
                if (handlers.focus) handlers.focus();
            }
        }
    };
}

beforeEach(renderCheckout);

describe('what a return to checkout does to an open signup popup', () => {
    test.each([
        ['the company query field', false, 0, 1,
            'inside the popover: the signup goes, the capture the buyer is still in stays'],
        ['a sibling chip', false, 0, 1,
            'inside the popover: switching capture mode ends the signup, not the capture'],
        ['unrelated control', false, 1, 1,
            'outside the popover: the buyer has left capture, so both go'],
        ['the Sole trader chip', true, 0, 1,
            'tabbing onto the chip must not take the signup down'],
        ['a real mouse click on the Sole trader chip', true, 0, 0,
            'the cancelled mousedown moves no focus, so nothing here runs at all'],
        ['window focus', true, 0, 0, 'a tab or app switch lands on no control at all']
    ])('focus landing on %s: popup open=%s, popover closed %d time(s)',
        (kind, open, popoverClosed, focusins, why) => {
            const ctx = load();

            ctx.returnToCheckout(kind);

            expect(tagged(why, [ctx.flow.isPopupOpen(), ctx.popoverClosed(), ctx.focusins()]))
                .toEqual(tagged(why, [open, popoverClosed, focusins]));
        });
});

test('the keyboard route raises the popup it kept, rather than reopening one', () => {
    const ctx = load();
    const held = ctx.flow._popupWindow;

    // Tab onto the chip, then Enter — which the browser delivers as a click.
    ctx.returnToCheckout('the Sole trader chip');
    document.getElementById('soletrader').click();

    expect(ctx.popupRaised()).toBe(2);
    expect(ctx.flow._popupWindow).toBe(held);
    expect(ctx.flow.isPopupOpen()).toBe(true);
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
