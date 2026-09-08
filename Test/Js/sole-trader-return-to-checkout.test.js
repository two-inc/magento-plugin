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
        // The company field is the popover's own trigger and sits outside it.
        + '<input id="company">'
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
/** Every flow load() armed, so the watchers can be released between tests. */
const loadedFlows = [];

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
                getField: function () { return [document.getElementById('company')]; },
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
    loadedFlows.push(flow);
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
            if (kind === 'the company name field') document.getElementById('company').focus();
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

// A `document` listener outlives `document.body.innerHTML = ...`, and so does the
// flow that armed it: left armed, every earlier test's flow judges this test's
// focus against its own still-open popup.
afterEach(() => {
    loadedFlows.splice(0).forEach((flow) => flow.stopReturnToCheckoutWatcher());
});

describe('what a return to checkout does to an open signup popup', () => {
    test.each([
        ['the company query field', false, 0, 0, 1,
            'inside the popover: the signup goes, the capture the buyer is still in stays'],
        ['a sibling chip', false, 0, 0, 1,
            'inside the popover: switching capture mode ends the signup, not the capture'],
        ['unrelated control', false, 1, 0, 1,
            'outside the popover: the buyer has left capture, so both go'],
        ['the company name field', false, 0, 0, 1,
            'the popover\'s own trigger: the signup goes, the results being typed against stay'],
        ['the Sole trader chip', true, 0, 0, 1,
            'arriving on the chip moves the popup neither way'],
        ['a real mouse click on the Sole trader chip', true, 0, 1, 0,
            'the cancelled mousedown moves no focus, so the click alone raises it'],
        ['window focus', true, 0, 0, 0, 'a tab or app switch lands on no control at all']
    ])('focus landing on %s: popup open=%s, popover closed %d time(s), raised %d time(s)',
        (kind, open, popoverClosed, raised, focusins, why) => {
            const ctx = load();

            ctx.returnToCheckout(kind);

            expect(tagged(why, [
                ctx.flow.isPopupOpen(), ctx.popoverClosed(), ctx.popupRaised(), ctx.focusins()
            ])).toEqual(tagged(why, [open, popoverClosed, raised, focusins]));
        });
});

test('the keyboard route raises the popup it kept, rather than reopening one', () => {
    const ctx = load();
    const held = ctx.flow._popupWindow;

    // Tab onto the chip, then Enter — which the browser delivers as a click.
    ctx.returnToCheckout('the Sole trader chip');
    document.getElementById('soletrader').click();

    // The Enter alone: the arrival before it raised nothing.
    expect(ctx.popupRaised()).toBe(1);
    expect(ctx.flow._popupWindow).toBe(held);
    expect(ctx.flow.isPopupOpen()).toBe(true);
});

describe('a second capture on the same page (TWO-25658)', () => {
    /**
     * The delivery capture's own popover and chip. Magento mounts two - shipping
     * and billing - each with its own panel, chips and sole-trader flow.
     *
     * @returns {object} `{ chip, launches }`, `launches` counting activations
     */
    function renderSibling() {
        const sibling = document.createElement('div');
        sibling.className = 'two-company-dropdown';
        sibling.id = 'popover-b';
        sibling.innerHTML = '<button data-two-chip="soletrader" id="soletrader-b">Eenmanszaak</button>';
        document.body.appendChild(sibling);
        const chip = document.getElementById('soletrader-b');
        const launches = { count: 0 };
        chip.addEventListener('click', function () { launches.count += 1; });
        return { chip: chip, launches: launches };
    }

    test('focus on the sibling capture\'s Sole trader chip closes this popup and launches that one', () => {
        const ctx = load();
        const sibling = renderSibling();

        sibling.chip.focus();

        expect(ctx.flow.isPopupOpen()).toBe(false);
        expect(ctx.popupRaised()).toBe(0);
        // Outside this capture's popover, so the buyer has left this capture.
        expect(ctx.popoverClosed()).toBe(1);
        expect(sibling.launches.count).toBe(1);
    });

    test('this capture\'s own chip is still exempt with a sibling on the page', () => {
        const ctx = load();
        const sibling = renderSibling();

        document.getElementById('soletrader').focus();

        expect(ctx.flow.isPopupOpen()).toBe(true);
        expect(ctx.popoverClosed()).toBe(0);
        expect(sibling.launches.count).toBe(0);
    });
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
