/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461/TWO-25503. `/autofill/v1/buyer/current` is read from exactly one
 * place: `popupMessageListener()`'s `ACCEPTED` branch, after the buyer has
 * completed verification server-side. The email that authenticated IS the
 * identity, so the checkout's own contact field has no say in it — re-running
 * an email-match comparison there took no branch at all on a mismatch, and the
 * buyer finished signup to a permanently blank readonly/required company field
 * with no route forward.
 *
 * `fetchBuyer()` is stubbed per test rather than driven through `fetch`: the
 * behaviour under test is which buyer the two call sites ACCEPT, not how the
 * buyer is transported. `getTokens()` is stubbed for the same reason.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const SOLE_TRADER_MODEL = 'view/frontend/web/js/model/sole-trader.js';
const CHECKOUT_PAGE_URL = 'https://checkout.example';
const ENTERED_EMAIL = 'entered@example.com';

/**
 * Stand-in for the signup popup's own window. `popupMessageListener()` ignores
 * a message from anything other than the popup it is currently tracking, so
 * every fixture message below has to claim to come from this one.
 */
const POPUP = { popup: 'the tracked signup window' };

/**
 * Load the renderer and give it the state `initialize()` would have set up.
 * The harness returns the spec object itself, so it doubles as the `this` the
 * methods run against, complete with its own observables — but `initialize()`
 * never runs, so `_brandConfig` has to be supplied here. The transport stubs
 * go on the SoleTrader collaborator, which is what owns them.
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
    renderer.getEmail = () => ENTERED_EMAIL;
    // The real one needs Magento's message container, which the harness has no
    // stand-in for; tests that assert on it replace this with a recorder.
    renderer.showErrorMessage = () => {};

    const soleTrader = renderer.soleTrader();
    soleTrader.getTokens = () =>
        Promise.resolve({ delegation_token: 'dt', autofill_token: 'at' });
    soleTrader.fetchBuyer = () => Promise.resolve(buyer);
    soleTrader._soleTraderPopupWindow = POPUP;

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

describe('resolveBuyer trusts the identity the popup authenticated', () => {
    test.each([
        [{ email: 'someone.else@example.com' }, true, 'an email unlike the checkout contact is still the identity'],
        [{ email: null }, true, 'no email is required to match anything'],
        [{ email: ENTERED_EMAIL }, true, 'a matching email is accepted too'],
        [null, false, 'no buyer is still no buyer']
    ])('buyer=%p -> matches=%p (%s)', (buyer, expected) => {
        const { renderer } = loadRenderer(buyer);

        return renderer.resolveBuyer().then((resolved) => {
            expect(resolved.matches).toBe(expected);
            expect(resolved.buyer).toBe(buyer);
        });
    });
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

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });

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

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });

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

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });

        return Promise.resolve().then(() => Promise.resolve().then(() => {
            expect(renderer.companyId()).toBe(SOLE_TRADER.organization_number);
            expect(renderer.companyName()).toBe(SOLE_TRADER.company_name);
        }));
    });

    test('a non-ACCEPTED message from the tracked popup surfaces an error', () => {
        // WooCommerce and PrestaShop both surface here too; a silent failure
        // leaves the buyer with an open flow and no explanation.
        const { renderer, listeners } = loadRenderer(null);
        const errors = [];
        renderer.showErrorMessage = (message) => errors.push(message);
        const handler = messageHandler(renderer, listeners);

        renderer.showSoleTrader(true);

        handler({ origin: CHECKOUT_PAGE_URL, data: 'REJECTED', source: POPUP });

        expect(errors).toHaveLength(1);
    });
});

describe('the buyer record is read only after authentication (TWO-25503)', () => {
    /**
     * The passive probe is invisible to every behavioural fixture here:
     * reinstating it would auto-adopt a sole trader off a session cookie the
     * person checking out never authenticated against, and still leave the
     * chip-click tests green. Pinning the call sites in the source is what
     * catches that.
     */
    test('resolveBuyer is reached from the ACCEPTED branch and nowhere else', () => {
        const src = fs.readFileSync(
            path.resolve(__dirname, '..', '..', SOLE_TRADER_MODEL),
            'utf8'
        );
        // Guard against a rename silently emptying this check.
        expect(src).toContain('SoleTrader.prototype.resolveBuyer = function ()');

        const callSites = src.split('\n').filter((line) => /this\.resolveBuyer\(\)/.test(line));
        expect(callSites).toHaveLength(1);
        expect(src).not.toContain('lookupSoleTrader');
    });

    test('fetchBuyer has exactly one caller, and it is resolveBuyer', () => {
        const src = fs.readFileSync(
            path.resolve(__dirname, '..', '..', SOLE_TRADER_MODEL),
            'utf8'
        );
        const callSites = src.split('\n').filter((line) => /this\.fetchBuyer\(\)/.test(line));
        expect(callSites).toHaveLength(1);
    });
});
