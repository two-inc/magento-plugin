/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * A browser re-fires `focus` and `focusin` on the control the opener
 * window still holds the moment the signup popup closes. The company field is
 * that control, because the launch parked focus there, and its own
 * open-on-focus opener must not read the re-fire as the buyer asking for the
 * popover.
 *
 * jsdom fires no focus event on window blur or on window return, so the
 * re-fire is dispatched here by hand.
 *
 * Mutation-resistance notes:
 *  - the popover is driven to its state through the real field openers and the
 *    real outcome paths, never by calling `open()`/`close()` on the panel;
 *  - a real `mousedown` on the field is asserted to still open the popover
 *    after the flight, which is what separates a scoped suppression from an
 *    opener switched off;
 *  - the pair a Tab arrival sends is asserted to open the popover, which is what
 *    separates the re-fire being told apart from the opener being held shut.
 */

'use strict';

const $ = require('jquery');
const {
    loadCompanyCapture,
    defaultMocks,
    loadCompanySearchPanel,
    dispatchNative,
    brandConfigMock,
    quoteAddress,
    tagged
} = require('./amd-harness');

const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';
const POPUP_CLOSE_POLL_MS = 300;
const PANEL = '.two-company-dropdown';
const FIELD = '#company_name';
const TRADER = { company_name: 'Alpha Trading', organization_number: '123456' };

/**
 * @param {?object} autofillBuyer the record `/autofill/v1/buyer/current` answers with
 * @returns {object} `{ rec, mocks, globals }`
 */
function makeEnv(buyerRef) {
    const rec = { handles: [], intervals: [], messageListeners: [], blocked: false };
    let intervalSeq = 0;

    const fakeWindow = {
        open: function () {
            if (rec.blocked) return null;
            const handle = {
                closed: false,
                close: function () { this.closed = true; },
                focus: function () {}
            };
            rec.handles.push(handle);
            return handle;
        },
        addEventListener: function (name, fn) { rec.messageListeners.push({ name: name, fn: fn }); },
        removeEventListener: function () {}
    };

    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: quoteAddress({ countryId: 'GB' }),
        getQuoteId: function () { return 'cart-1'; },
        isVirtual: function () { return false; }
    });

    const companySearch = Object.assign({}, defaultMocks()['Two_Gateway/js/model/company-search'], {
        apiClientParams: function () { return { client: 'magento' }; },
        currentAddressFormCountry: function () { return ''; }
    });

    const mocks = {
        jquery: $,
        'Magento_Checkout/js/model/quote': quote,
        'Two_Gateway/js/model/company-search': companySearch,
        'Two_Gateway/js/model/brand-config': brandConfigMock({
            checkoutPageUrl: CHECKOUT_PAGE_URL,
            checkoutApiUrl: 'https://api.example',
            isCompanySearchEnabled: true,
            supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
        }),
        'Magento_Ui/js/model/messageList': {
            addErrorMessage: function () {},
            addSuccessMessage: function () {}
        }
    };

    const globals = {
        document: document,
        window: fakeWindow,
        btoa: global.btoa,
        // Recorded rather than run, so the 300ms popup-close poll is ticked by
        // hand at the point the case is about.
        setInterval: function (fn, ms) {
            intervalSeq += 1;
            rec.intervals.push({ id: intervalSeq, fn: fn, ms: ms });
            return intervalSeq;
        },
        clearInterval: function () {},
        fetch: function (url) {
            if (String(url).indexOf('get-tokens') !== -1) {
                return Promise.resolve({
                    ok: true,
                    json: function () {
                        return Promise.resolve([{ delegation_token: 'dt', autofill_token: 'at' }]);
                    }
                });
            }
            if (String(url).indexOf('/autofill/v1/buyer/current') !== -1 && buyerRef.value) {
                const buyer = buyerRef.value;
                return Promise.resolve({ ok: true, json: function () { return Promise.resolve(buyer); } });
            }
            return Promise.resolve({ ok: false, status: 404 });
        }
    };

    return { rec: rec, mocks: mocks, globals: globals };
}

function flush() {
    return new Promise(function (resolve) { setTimeout(resolve, 0); });
}

/** The real component, the real panel and the real flow over a payment-tile company field. */
async function startStack(buyerRef) {
    document.body.innerHTML =
        '<form id="two_gateway_form">'
        + '<div class="field"><div class="control">'
        + '<input id="company_name" name="company_name" />'
        + '</div></div></form>';
    const env = makeEnv(buyerRef);
    const mocks = Object.assign({}, env.mocks, {
        'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel(
            $,
            env.mocks['Two_Gateway/js/model/company-search'],
            env.globals
        )
    });
    const component = loadCompanyCapture(mocks, env.globals).shipping;
    component.start();
    await flush();
    return { component: component, rec: env.rec, buyerRef: buyerRef };
}

/**
 * Open the popover the buyer's way and hand back one of its chips.
 *
 * @param {string} mode
 * @returns {Element}
 */
function chip(mode) {
    dispatchNative(document.querySelector(FIELD), 'mousedown');
    const node = document.querySelector('.two-company-mode-chip[data-two-chip="' + mode + '"]');
    expect(node).not.toBeNull();
    return node;
}

/** The signup open over the real chips, with its close poll and popup handle in hand. */
async function openedStack() {
    const buyerRef = { value: null };
    const stack = await startStack(buyerRef);
    chip('soletrader').click();
    // An autofilled trader is adopted by the first click; the chooser, and so
    // the popup, comes up on the second.
    if (!stack.rec.handles.length) chip('soletrader').click();
    expect(stack.rec.handles).toHaveLength(1);
    // The launch parks focus on the company field a tick later, and the cases
    // below are all about what that park is worth when the popup goes away.
    await flush();
    expect(document.activeElement).toBe(document.querySelector(FIELD));
    return Object.assign({}, stack, {
        poll: stack.rec.intervals.find(function (entry) { return entry.ms === POPUP_CLOSE_POLL_MS; }),
        handle: stack.rec.handles[0]
    });
}

/** The buyer's own way of closing the popover, on the field the launch parked focus on. */
function escapeOnField() {
    const field = document.querySelector(FIELD);
    field.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
}

function popoverIsOpen() {
    const node = document.querySelector(PANEL);
    return !!node && !node.hasAttribute('hidden');
}

/**
 * What a browser sends the opener when the buyer's screen comes back to it: the
 * window's own focus, and beside it the pair re-fired on the control that still
 * holds focus. All three are synthesised here; jsdom sends none of them.
 */
function windowReturnRefire() {
    window.dispatchEvent(new window.Event('focus'));
    refireFocusOnField();
}

/**
 * Move focus to a control of its own, never with `blur()`: jsdom answers a
 * `blur()` with a window-targeted focus, which arms the return this suite is about.
 */
function focusAway() {
    const away = document.createElement('input');
    document.body.appendChild(away);
    away.focus();
}

/** The field pair on its own, which is what a Tab onto the field sends. */
function refireFocusOnField() {
    const field = document.querySelector(FIELD);
    dispatchNative(field, 'focus');
    dispatchNative(field, 'focusin');
}

describe('the popup closing must not reopen the company-search popover (ABN-554)', function () {
    test.each([
        ['the signup adopts a trader', false,
            'the adopt closed the popover, and the re-fire must not put it back'],
        ['the buyer closes the signup window', true,
            'the popover is held open across a signup, and nothing here closes it']
    ])('%s: popover open=%p afterwards (%s)', async function (path, expectedOpen, why) {
        const ctx = await openedStack();
        if (path === 'the signup adopts a trader') {
            ctx.buyerRef.value = TRADER;
            ctx.rec.messageListeners
                .find(function (entry) { return entry.name === 'message'; })
                .fn({ origin: CHECKOUT_PAGE_URL, source: ctx.handle, data: 'ACCEPTED' });
            await flush();
            await flush();
        }

        ctx.handle.closed = true;
        // The close is noticed first and the return arrives whenever the buyer comes
        // back, which is the ordering a live browser was measured in.
        ctx.poll.fn();
        await flush();
        windowReturnRefire();

        expect(tagged(why, [
            popoverIsOpen(),
            document.querySelector(FIELD).getAttribute('aria-expanded')
        ])).toEqual(tagged(why, [expectedOpen, String(expectedOpen)]));
    });

    /**
     * The popover shut with the hold still standing: the signup adopted a trader
     * and closed the popover itself, and the buyer's screen has not come back yet.
     *
     * Nothing here blurs a node, deliberately. jsdom answers an `element.blur()`
     * with a focus event whose target is the WINDOW, which no browser does, and
     * that is indistinguishable from the return this suite is about.
     */
    async function heldWithPopoverShut() {
        const ctx = await openedStack();
        ctx.buyerRef.value = TRADER;
        ctx.rec.messageListeners
            .find(function (entry) { return entry.name === 'message'; })
            .fn({ origin: CHECKOUT_PAGE_URL, source: ctx.handle, data: 'ACCEPTED' });
        await flush();
        await flush();
        ctx.handle.closed = true;
        expect(popoverIsOpen()).toBe(false);
        return ctx;
    }

    test.each([
        ['mousedown', 'the pointer opener is never held, so it opens throughout'],
        ['focus', 'the window return took the hold with it, and the focus opener is alive again']
    ])('a real %s on the field opens the popover after the window return (%s)', async function (opener, why) {
        const ctx = await heldWithPopoverShut();
        windowReturnRefire();
        expect(popoverIsOpen()).toBe(false);

        dispatchNative(document.querySelector(FIELD), opener);

        expect(tagged(why, popoverIsOpen())).toEqual(tagged(why, true));
        expect(ctx.rec.handles).toHaveLength(1);
    });

    test('the field pair with no window focus is the buyer arriving by Tab', async function () {
        await heldWithPopoverShut();

        refireFocusOnField();

        expect(popoverIsOpen()).toBe(true);
    });

    test('that arrival ends the hold, so focus alone opens the popover afterwards', async function () {
        await heldWithPopoverShut();
        refireFocusOnField();
        expect(popoverIsOpen()).toBe(true);
        // Focus leaving for another control closes the popover and touches no hold.
        focusAway();
        await flush();
        expect(popoverIsOpen()).toBe(false);

        dispatchNative(document.querySelector(FIELD), 'focus');

        expect(popoverIsOpen()).toBe(true);
    });

    test('the park putting focus back on the field is not that arrival', async function () {
        const ctx = await heldWithPopoverShut();
        focusAway();

        ctx.component.panel().restoreFieldFocus();

        expect(document.activeElement).toBe(document.querySelector(FIELD));
        expect(popoverIsOpen()).toBe(false);
        windowReturnRefire();
        expect(popoverIsOpen()).toBe(false);
    });

    test('the popup close alone leaves the hold standing', async function () {
        const ctx = await heldWithPopoverShut();
        ctx.poll.fn();
        await flush();

        dispatchNative(document.querySelector(FIELD), 'focus');

        expect(popoverIsOpen()).toBe(false);
    });

    test.each([
        ['pointerdown', 'a press on the field is the buyer reaching for the panel'],
        ['click', 'and so is a click that arrives without one']
    ])('a %s on the field ends the hold (%s)', async function (type, why) {
        await heldWithPopoverShut();

        dispatchNative(document.querySelector(FIELD), type);
        dispatchNative(document.querySelector(FIELD), 'focus');

        expect(tagged(why, popoverIsOpen())).toEqual(tagged(why, true));
    });

    test('Escape on the field ends the hold', async function () {
        await heldWithPopoverShut();

        escapeOnField();
        expect(popoverIsOpen()).toBe(false);
        dispatchNative(document.querySelector(FIELD), 'focus');

        expect(popoverIsOpen()).toBe(true);
    });

    test('a supersede the browser blocks leaves the focus opener alive', async function () {
        const ctx = await openedStack();
        // No replacement popup means no close poll, and so nothing left to end the flight.
        ctx.rec.blocked = true;
        ctx.component.soleTrader().selectDifferentSoleTrader();
        await flush();
        escapeOnField();
        expect(popoverIsOpen()).toBe(false);

        dispatchNative(document.querySelector(FIELD), 'focus');

        expect(popoverIsOpen()).toBe(true);
    });
});
