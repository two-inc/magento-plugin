/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25554: the sole-trader flow consults ITS OWN panel's popover when
 * deciding whether focus has come back to checkout — never a page-wide
 * `.two-company-dropdown` query, which answers with whichever popover comes
 * first in the document.
 *
 * The rule (TWO-25461): focus settling on checkout takes the signup popup down,
 * EXCEPT where it settles inside the popover the signup was launched from.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';

/** Long enough to clear RETURN_TO_CHECKOUT_GRACE_MS, which is module-private. */
const AFTER_GRACE_MS = 300;

/**
 * Two mounted popovers, in document order, each with something focusable in it.
 *
 * @returns {object} `{ first, second }` the two popover elements
 */
function renderTwoPopovers() {
    document.body.innerHTML =
        '<input id="outside">' +
        '<div class="two-company-dropdown" id="first"><input id="first-chip"></div>' +
        '<div class="two-company-dropdown" id="second"><input id="second-chip"></div>';
    return {
        first: document.getElementById('first'),
        second: document.getElementById('second')
    };
}

/**
 * The flow, with a signup popup already up and a focus watcher armed.
 *
 * @param {?Element} ownPopover the popover THIS flow's panel is mounted in
 * @returns {object} `{ flow, returnToCheckout }`
 */
function load(ownPopover) {
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
        config: function () { return {}; },
        panel: function () {
            return ownPopover ? { getPanelElement: function () { return ownPopover; } } : null;
        }
    });
    flow._popupWindow = {
        closed: false,
        close: function () { this.closed = true; }
    };
    flow.watchForReturnToCheckout();

    return {
        flow: flow,
        returnToCheckout: function () {
            handlers.focus();
            return new Promise(function (resolve) { setTimeout(resolve, AFTER_GRACE_MS); });
        }
    };
}

describe('the popup survives focus landing in this flow\'s own popover', () => {
    test.each([
        ['second', 'this flow\'s popover is the second on the page'],
        ['first', 'this flow\'s popover is the first on the page']
    ])('focus inside the %s popover keeps the popup (%s)', async (which, description) => {
        const popovers = renderTwoPopovers();
        const { flow, returnToCheckout } = load(popovers[which]);
        document.getElementById(`${which}-chip`).focus();

        await returnToCheckout();

        expect(flow.isPopupOpen()).toBe(true, description);
    });
});

describe('the popup goes when focus lands anywhere else', () => {
    test.each([
        ['first', 'second', 'the OTHER panel\'s popover is not a route to this signup'],
        ['second', 'first', 'and the same the other way round']
    ])('own=%s, focus in %s: the popup closes (%s)', async (own, focused, description) => {
        const popovers = renderTwoPopovers();
        const { flow, returnToCheckout } = load(popovers[own]);
        document.getElementById(`${focused}-chip`).focus();

        await returnToCheckout();

        expect(flow.isPopupOpen()).toBe(false, description);
    });

    test('a flow whose panel has not mounted yet closes rather than throwing', () => {
        renderTwoPopovers();
        document.getElementById('outside').focus();
        const { flow, returnToCheckout } = load(null);

        return returnToCheckout().then(function () {
            expect(flow.isPopupOpen()).toBe(false);
        });
    });
});
