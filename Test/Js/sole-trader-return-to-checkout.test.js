/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25654: focus returning to any part of the checkout takes the signup popup
 * down, the Sole trader chip's own click alone excepted.
 */

'use strict';

const { loadAmdModule, tagged } = require('./amd-harness');

const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';

/** Long enough to clear RETURN_TO_CHECKOUT_GRACE_MS, which is module-private. */
const AFTER_GRACE_MS = 300;

/** Whatever the current test wants `document.hasFocus()` to answer. */
let pageHasFocus = true;

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
 * The component stub carries no `panel()`: the close rule reads no popover.
 *
 * @returns {object} `{ flow, returnCount, returnToCheckout }`
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
    flow._popupWindow = {
        closed: false,
        close: function () { this.closed = true; },
        // Inert, so only the chip's own cancel can keep the popup.
        focus: function () {}
    };
    flow.watchForReturnToCheckout();

    let returns = 0;

    return {
        flow: flow,
        returnCount: function () { return returns; },
        /**
         * @param {string} settlesOn id of the node focus ends up on
         * @param {boolean} chipRoute whether the Sole trader chip's click ran
         */
        returnToCheckout: function (settlesOn, chipRoute) {
            document.getElementById(settlesOn).focus();
            returns += 1;
            handlers.focus();
            // The chip's click route, as `soleTraderMode()` runs it.
            if (chipRoute) flow.focusSignupPopup();
            return new Promise(function (resolve) { setTimeout(resolve, AFTER_GRACE_MS); });
        }
    };
}

beforeEach(() => {
    pageHasFocus = true;
    document.hasFocus = function () { return pageHasFocus; };
    renderCheckout();
});

afterEach(() => {
    delete document.hasFocus;
});

describe('what a return to checkout does to an open signup popup', () => {
    // Rows 1 and 3 settle focus on the same node, so only the click tells them apart.
    test.each([
        ['the Sole trader chip', true, 'query', true,
            'the one exempt gesture — it re-raises the popup'],
        ['the Registered company chip', false, 'registered', false,
            'a sibling chip is not a route back to the signup'],
        ['the company query field', false, 'query', false,
            'the popover is not exempt, only the chip in it is'],
        ['an unrelated checkout field', false, 'other-field', false,
            'plainly looking away from the signup']
    ])('clicking %s leaves the popup open=%s', async (_what, open, settlesOn, chipRoute, why) => {
        const ctx = load();

        await ctx.returnToCheckout(settlesOn, chipRoute);

        expect(tagged(why, ctx.flow.isPopupOpen())).toEqual(tagged(why, open));
    });
});

test('a sibling-chip click closes the popup on that one return (TWO-25654)', async () => {
    // `window.focus` fires only on a transition, so this one return is the only chance.
    const ctx = load();

    await ctx.returnToCheckout('registered', false);
    document.getElementById('other-field').focus();

    expect(ctx.returnCount()).toBe(1);
    expect(ctx.flow.isPopupOpen()).toBe(false);
});

test('focus off the page entirely leaves the popup alone', async () => {
    const ctx = load();
    pageHasFocus = false;

    await ctx.returnToCheckout('other-field', false);

    expect(ctx.flow.isPopupOpen()).toBe(true);
});
