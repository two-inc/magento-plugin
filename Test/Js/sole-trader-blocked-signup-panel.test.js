/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-26775: what a Sole trader click leaves behind, the panel a blocked popup
 * draws over the popover, and the click rules while the popup is open. Driven
 * through the real component, panel and flow (`sole-trader-stack.js`).
 */

'use strict';

const { dispatchNative } = require('./amd-harness');
const {
    CHECKOUT_PAGE_URL,
    FIELD,
    PANEL,
    SIGNUP_PANEL,
    flush,
    startStack,
    chip,
    popoverIsOpen,
    closePoll,
    seeClosed,
    teardown
} = require('./sole-trader-stack');

afterEach(teardown);

const CAPTION = 'Sign up or log in as a sole trader';
const PICK = { companyName: 'Acme Registered Ltd', companyId: '12345678' };

function signupPanel() {
    return document.querySelector(SIGNUP_PANEL);
}

function liveRegionText() {
    const region = document.querySelector('.two-company-dropdown__status');
    return region ? region.textContent : '';
}

describe('a Sole trader click clears nothing (TWO-26775)', () => {
    test.each([
        ['the click alone', function () {}, 'soletrader'],
        ['then Registered company', function () { chip('registered').click(); }, 'registered'],
        ['then a hand-close', function (ctx) {
            ctx.rec.handles[0].closed = true;
            seeClosed(closePoll(ctx.rec));
        }, 'registered']
    ])('a registered pick survives %s, in mode %s', async (why, act, mode) => {
        const ctx = await startStack();
        ctx.identity.write(PICK, { authoritative: true });

        chip('soletrader').click();
        act(ctx);

        expect([why, ctx.identity.companyName(), ctx.identity.companyId(), ctx.identity.captureMode()])
            .toEqual([why, PICK.companyName, PICK.companyId, mode]);
    });

    test('leaving an ADOPTED sole trader still discards it', async () => {
        const ctx = await startStack();
        ctx.identity.write(PICK, { authoritative: true });
        chip('soletrader').click();
        ctx.component.adoptSoleTrader({ company_name: 'Ola Nordmann', organization_number: 'ST-9' });
        expect(ctx.identity.companyId()).toBe('ST-9');

        chip('registered').click();

        expect([ctx.identity.companyName(), ctx.identity.companyId()]).toEqual(['', '']);
    });
});

describe('a blocked popup draws a panel over the popover (TWO-26775)', () => {
    async function blockedClick() {
        const ctx = await startStack();
        ctx.rec.blocked = true;
        chip('soletrader').click();
        return ctx;
    }

    test('the panel covers the open popover with one captioned button, focused', async () => {
        const ctx = await blockedClick();
        const cover = signupPanel();
        const popover = document.querySelector(PANEL);

        expect(ctx.rec.handles).toHaveLength(0);
        expect(popoverIsOpen()).toBe(true);
        expect(cover.parentNode).toBe(popover);
        expect(cover.querySelectorAll('button')).toHaveLength(1);
        const button = cover.querySelector('button');
        expect(button.textContent).toBe(CAPTION);
        // The caption is said once, as the focused button's name: the cover is a plain container.
        expect(['role', 'aria-label', 'aria-labelledby'].filter(function (name) { return cover.hasAttribute(name); }))
            .toEqual([]);
        // Centred inside the popover's gutter at its own width, not stretched edge to edge.
        expect([cover.style.padding, button.style.flex]).toEqual(['inherit', '0 1 auto']);
        expect(document.activeElement).toBe(cover.querySelector('button'));
        // Everything else in the popover is out of reach underneath it.
        expect(Array.prototype.filter.call(popover.children, function (child) {
            return child !== cover && !child.hasAttribute('inert');
        })).toEqual([]);
        // The status region is there, and stays empty.
        await new Promise(function (resolve) { setTimeout(resolve, 150); });
        expect([!!document.querySelector('.two-company-dropdown__status'), liveRegionText()]).toEqual([true, '']);
    });

    test('the button is the gesture: it opens the popup and the panel goes', async () => {
        const ctx = await blockedClick();
        ctx.rec.blocked = false;

        signupPanel().querySelector('button').click();

        expect(ctx.rec.handles).toHaveLength(1);
        expect(signupPanel()).toBeNull();
    });

    test('a button click still blocked keeps the panel up', async () => {
        const ctx = await blockedClick();

        signupPanel().querySelector('button').click();

        expect(ctx.rec.opens).toBe(2);
        expect(signupPanel()).not.toBeNull();
    });

    test.each([
        ['Registered company', function () { chip('registered').click(); }, false],
        ['Enter manually', function () { chip('manual').click(); }, false],
        ['a country change that resets the mode', function (ctx) {
            ctx.component._lastCountry = 'gb';
            ctx.component.onCountryChanged('fr');
        }, false],
        ['Escape, which closes the popover', function () {
            dispatchNative(signupPanel().querySelector('button'), 'keydown');
            document.querySelector(PANEL).dispatchEvent(
                new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })
            );
        }, false],
        ['a second Sole trader click, still blocked', function () { chip('soletrader').click(); }, true],
        ['an identity write that stays in sole trader', function (ctx) { ctx.identity.addressNotice('x'); }, true]
    ])('%s -> panel shown %p', async (why, act, shown) => {
        const ctx = await blockedClick();
        expect(signupPanel()).not.toBeNull();

        act(ctx);
        await flush();

        expect([why, !!signupPanel()]).toEqual([why, shown]);
        // Whatever the popover is doing, nothing in it is left inert.
        if (!shown) expect(document.querySelectorAll(PANEL + ' [inert]')).toHaveLength(0);
    });

    test('a closed popover with the panel down leaves no stale Sole trader mode', async () => {
        const ctx = await blockedClick();
        ctx.identity.write(PICK, { authoritative: true });

        document.querySelector(PANEL).dispatchEvent(
            new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })
        );

        expect([ctx.identity.captureMode(), ctx.identity.companyId()]).toEqual(['registered', PICK.companyId]);
    });
});

describe('the panel itself', () => {
    test('showSignupPanel opens a closed popover first', async () => {
        const ctx = await startStack();
        const panel = ctx.component.panel();
        expect(popoverIsOpen()).toBe(false);
        const calls = [];

        panel.showSignupPanel(CAPTION, function () { calls.push('activate'); });
        signupPanel().querySelector('button').click();

        expect([popoverIsOpen(), calls]).toEqual([true, ['activate']]);
    });

    test('closing the popover takes the panel and the inert marks with it', async () => {
        const ctx = await startStack();
        const panel = ctx.component.panel();
        panel.showSignupPanel(CAPTION, function () {});

        panel.close({ returnFocus: false });

        expect([signupPanel(), document.querySelectorAll(PANEL + ' [inert]').length]).toEqual([null, 0]);
    });
});

describe('a press on the checkout while the popup is open (TWO-26775)', () => {
    const EXTRA = '<div id="background">page text</div><input id="postcode">';

    test.each([
        ['non-focusable page content', '#background', true, true],
        ['a focusable control outside the popover', '#postcode', true, true],
        ['the popover\'s own dead space', PANEL, true, false],
        ['this capture\'s Sole trader chip', '[data-two-chip="soletrader"]', false, false]
    ])('a mousedown on %s closes popup=%p popover=%p', async (why, selector, popupCloses, popoverCloses) => {
        const ctx = await startStack({ extraHtml: EXTRA });
        chip('soletrader').click();
        expect(ctx.rec.handles).toHaveLength(1);
        await flush();
        const target = selector === PANEL
            ? document.querySelector('.two-company-mode-chips')
            : document.querySelector(selector);

        dispatchNative(target, 'mousedown');
        await flush();

        expect([why, ctx.rec.handles[0].closed, !popoverIsOpen()]).toEqual([why, popupCloses, popoverCloses]);
    });

    test('a rule close is not a hand-close: the poll that follows leaves the popover alone', async () => {
        const ctx = await startStack({ extraHtml: EXTRA });
        chip('soletrader').click();
        await flush();

        dispatchNative(document.querySelector('.two-company-mode-chips'), 'mousedown');
        seeClosed(closePoll(ctx.rec));

        expect(popoverIsOpen()).toBe(true);
    });

    test('a focusin on the parked field is the window\'s and closes nothing', async () => {
        const ctx = await startStack({ extraHtml: EXTRA });
        chip('soletrader').click();
        await flush();
        expect(document.activeElement).toBe(document.querySelector(FIELD));

        dispatchNative(document.querySelector(FIELD), 'focusin');

        expect(ctx.rec.handles[0].closed).toBe(false);
    });

    test('an ACCEPTED adoption still arrives once the popup is open', async () => {
        const ctx = await startStack();
        chip('soletrader').click();
        ctx.buyerRef.value = { company_name: 'Ola Nordmann', organization_number: 'ST-9' };
        ctx.rec.messageListeners
            .find(function (entry) { return entry.name === 'message'; })
            .fn({ origin: CHECKOUT_PAGE_URL, source: ctx.rec.handles[0], data: 'ACCEPTED' });
        await flush();
        await flush();

        expect(ctx.identity.companyId()).toBe('ST-9');
    });

    test('an ACCEPTED answer with no number writes nothing and asks again (TWO-26775, as PrestaShop)', async () => {
        const ctx = await startStack();
        ctx.identity.write(PICK, { authoritative: true });
        chip('soletrader').click();
        ctx.buyerRef.value = { company_name: 'Ola Nordmann', organization_number: '' };
        ctx.rec.messageListeners
            .find(function (entry) { return entry.name === 'message'; })
            .fn({ origin: CHECKOUT_PAGE_URL, source: ctx.rec.handles[0], data: 'ACCEPTED' });
        await flush();
        await flush();

        expect([ctx.identity.soleTraderAdopted(), ctx.identity.companyName(), ctx.identity.companyId(), !!signupPanel()])
            .toEqual([false, PICK.companyName, PICK.companyId, true]);
    });
});

describe('the popup\'s watchers across window returns and relaunches (TWO-26775)', () => {
    /** The window regaining focus: the browser re-fires focus on the field it holds. */
    function windowReturn() {
        const field = document.querySelector(FIELD);
        window.dispatchEvent(new FocusEvent('focus'));
        field.dispatchEvent(new FocusEvent('focus'));
        field.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    }

    function postAccepted(ctx, handle) {
        ctx.rec.messageListeners
            .find(function (entry) { return entry.name === 'message'; })
            .fn({ origin: CHECKOUT_PAGE_URL, source: handle, data: 'ACCEPTED' });
    }

    test.each([
        [1, 'the first return'],
        [2, 'a second return answers as the first did'],
        [3, 'and so does every one after it']
    ])('%p window returns close neither popup nor popover (%s)', async (returns, why) => {
        const ctx = await startStack();
        chip('soletrader').click();
        await flush();

        for (let i = 0; i < returns; i += 1) windowReturn();

        expect([why, ctx.rec.handles[0].closed, popoverIsOpen()]).toEqual([why, false, true]);
    });

    test('a return, a raise from the chip and another return still close nothing', async () => {
        const ctx = await startStack();
        chip('soletrader').click();
        await flush();
        windowReturn();
        const soleTrader = document.querySelector('[data-two-chip="soletrader"]');
        dispatchNative(soleTrader, 'mousedown');
        soleTrader.click();

        windowReturn();

        expect([ctx.rec.handles[0].closed, ctx.rec.opens]).toEqual([false, 1]);
    });

    test('a popup closed by rule and relaunched at once reads its own hand-close as one', async () => {
        const ctx = await startStack();
        chip('soletrader').click();
        await flush();
        const registered = document.querySelector('[data-two-chip="registered"]');
        dispatchNative(registered, 'mousedown');
        registered.click();
        chip('soletrader').click();
        await flush();
        expect(ctx.rec.handles).toHaveLength(2);

        ctx.rec.handles[1].closed = true;
        seeClosed(closePoll(ctx.rec));

        expect(popoverIsOpen()).toBe(false);
    });

    test.each([
        ['the popup still open when the lookup lands', false],
        ['the popup closed by itself, the lookup landing before the next poll tick', true]
    ])('signed up but Two knows nobody on the payment step: the panel stays (%s)', async (why, closedOnPost) => {
        const ctx = await startStack();
        chip('soletrader').click();
        await flush();
        const handle = ctx.rec.handles[0];
        postAccepted(ctx, handle);
        if (closedOnPost) handle.closed = true;
        await flush();
        await flush();
        expect([why, !!signupPanel()]).toEqual([why, true]);

        handle.closed = true;
        ctx.rec.intervals
            .filter(function (entry) { return entry.ms === 300 && !entry.cleared; })
            .forEach(seeClosed);

        expect([why, !!signupPanel(), ctx.identity.captureMode(), popoverIsOpen()])
            .toEqual([why, true, 'soletrader', true]);
    });
});
