/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461. Two buyer-identity lookups share one helper and must NOT share
 * one rule.
 *
 * `prefetchSoleTrader()` is PASSIVE and pre-auth: it reads whatever buyer the
 * Two cookie happens to identify before the buyer has proved anything, so a
 * buyer only counts when its email equals the one entered on the checkout
 * form. That check is correct there and is pinned below as a regression guard.
 *
 * `popupMessageListener()`'s `ACCEPTED` branch is POST-AUTHENTICATION: the
 * buyer has just completed verification server-side, and the email that
 * authenticated is the identity. It used to re-run the same email-match
 * comparison and, on a mismatch, take no branch at all — the buyer finished
 * signup and got a permanently blank readonly/required company field with no
 * route forward. `resolveBuyer(authenticated)` is what separates the two.
 *
 * `fetchBuyer()` is stubbed per test rather than driven through `fetch`: the
 * behaviour under test is which buyer the two call sites ACCEPT, not how the
 * buyer is transported. `getTokens()` is stubbed for the same reason.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const CHECKOUT_PAGE_URL = 'https://checkout.example';
const ENTERED_EMAIL = 'entered@example.com';

/**
 * Load the renderer and give it the state `initialize()` would have set up.
 * The harness returns the spec object itself, so it doubles as the `this` the
 * methods run against, complete with its own observables — but `initialize()`
 * never runs, so `_brandConfig` / `prefetched` have to be supplied here.
 *
 * @param {object|null} buyer what the stubbed `fetchBuyer()` resolves to
 * @returns {object} { renderer, listeners }
 */
function loadRenderer(buyer) {
    const listeners = [];
    const renderer = loadAmdModule(RENDERER, {}, {
        window: {
            checkoutConfig: { payment: {} },
            addEventListener: function (name, fn) {
                listeners.push({ name: name, fn: fn });
            }
        }
    });

    renderer._brandConfig = { checkoutPageUrl: CHECKOUT_PAGE_URL };
    renderer.prefetched = { ready: false, buyer: null, matches: false };
    renderer.prefetchedEmail = null;
    renderer.getEmail = () => ENTERED_EMAIL;
    renderer.getTokens = () =>
        Promise.resolve({ delegation_token: 'dt', autofill_token: 'at' });
    renderer.fetchBuyer = () => Promise.resolve(buyer);

    return { renderer: renderer, listeners: listeners };
}

/**
 * The `message` handler `popupMessageListener()` binds on window.
 *
 * @param {object} renderer loaded renderer
 * @param {Array} listeners captured window listeners
 * @returns {Function} the handler
 */
function messageHandler(renderer, listeners) {
    renderer.popupMessageListener();
    const bound = listeners.filter((l) => l.name === 'message');
    expect(bound).toHaveLength(1);
    return bound[0].fn;
}

const SOLE_TRADER = { organization_number: '999888777', company_name: 'Example Trader' };

describe('resolveBuyer applies the email-match rule only pre-authentication', () => {
    test.each([
        [false, { email: ENTERED_EMAIL }, true, 'passive: the entered email matches'],
        [false, { email: 'ENTERED@Example.COM' }, true, 'passive: match is case-insensitive'],
        [false, { email: '  entered@example.com  ' }, false, 'passive: the buyer email is not trimmed'],
        [false, { email: 'someone.else@example.com' }, false, 'passive: a mismatch is refused'],
        [false, { email: null }, false, 'passive: a buyer with no email cannot match'],
        [false, null, false, 'passive: no buyer at all'],
        [true, { email: 'someone.else@example.com' }, true, 'post-auth: a mismatch is accepted'],
        [true, { email: null }, true, 'post-auth: no email is required to match anything'],
        [true, { email: ENTERED_EMAIL }, true, 'post-auth: a matching email is accepted too'],
        [true, null, false, 'post-auth: no buyer is still no buyer']
    ])(
        'authenticated=%p buyer=%p -> matches=%p (%s)',
        (authenticated, buyer, expected) => {
            const { renderer } = loadRenderer(buyer);

            return renderer.resolveBuyer(authenticated).then((pf) => {
                expect(pf.matches).toBe(expected);
                expect(pf.ready).toBe(true);
                expect(pf.buyer).toBe(buyer);
                // Recorded on the component, not just returned: soleTraderMode()
                // reads `this.prefetched` on a later click.
                expect(renderer.prefetched).toBe(pf);
            });
        }
    );
});

describe('a completed signup autofills whatever identity it authenticated', () => {
    test.each([
        ['a MISMATCHED buyer email', 'someone.else@example.com'],
        ['a matching buyer email', ENTERED_EMAIL]
    ])('%s fills the company from the authenticated buyer', (_description, buyerEmail) => {
        const buyer = Object.assign({ email: buyerEmail }, SOLE_TRADER);
        const { renderer, listeners } = loadRenderer(buyer);
        const handler = messageHandler(renderer, listeners);

        renderer.showSoleTrader(true);
        renderer.showPopupMessage(true);

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED' });

        // The handler is async inside; let the resolveBuyer promise settle.
        return Promise.resolve().then(() => Promise.resolve().then(() => {
            expect(renderer.companyId()).toBe(SOLE_TRADER.organization_number);
            expect(renderer.companyName()).toBe(SOLE_TRADER.company_name);
            expect(renderer.showPopupMessage()).toBe(false);
            // Still sole trader — the fill must not bounce the buyer back to
            // the registered-organisation mode.
            expect(renderer.showSoleTrader()).toBe(true);
        }));
    });

    test('an ACCEPTED message that yields no buyer fills nothing', () => {
        const { renderer, listeners } = loadRenderer(null);
        const handler = messageHandler(renderer, listeners);

        renderer.showSoleTrader(true);

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED' });

        return Promise.resolve().then(() => Promise.resolve().then(() => {
            expect(renderer.companyId()).toBe('');
            expect(renderer.companyName()).toBe('');
        }));
    });

    test('a second ACCEPTED message is idempotent, not a clobber', () => {
        // The popup can post more than once (double submit, a re-opened
        // window). Replaying the fill has to land on the same identity.
        const buyer = Object.assign({ email: 'someone.else@example.com' }, SOLE_TRADER);
        const { renderer, listeners } = loadRenderer(buyer);
        const handler = messageHandler(renderer, listeners);

        renderer.showSoleTrader(true);

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED' });
        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED' });

        return Promise.resolve().then(() => Promise.resolve().then(() => {
            expect(renderer.companyId()).toBe(SOLE_TRADER.organization_number);
            expect(renderer.companyName()).toBe(SOLE_TRADER.company_name);
        }));
    });
});

describe('the passive prefetch still refuses a buyer it cannot tie to the form', () => {
    test('a mismatched cookie buyer does not prefill the company', () => {
        const { renderer } = loadRenderer({
            email: 'someone.else@example.com',
            organization_number: SOLE_TRADER.organization_number,
            company_name: SOLE_TRADER.company_name
        });
        renderer.showModeTab(true);

        return renderer.prefetchSoleTrader().then(() => {
            expect(renderer.prefetched.matches).toBe(false);
            expect(renderer.companyId()).toBe('');
            expect(renderer.companyName()).toBe('');
            expect(renderer.showSoleTrader()).toBe(false);
        });
    });

    test('a matching cookie buyer prefills and selects sole trader', () => {
        const { renderer } = loadRenderer(Object.assign({ email: ENTERED_EMAIL }, SOLE_TRADER));
        renderer.showModeTab(true);

        return renderer.prefetchSoleTrader().then(() => {
            expect(renderer.prefetched.matches).toBe(true);
            expect(renderer.companyId()).toBe(SOLE_TRADER.organization_number);
            expect(renderer.companyName()).toBe(SOLE_TRADER.company_name);
            expect(renderer.showSoleTrader()).toBe(true);
        });
    });
});
