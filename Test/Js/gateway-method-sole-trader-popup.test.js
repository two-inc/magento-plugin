/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 — the three defects in the sole-trader signup popup launch path.
 *
 * 1. The popup opened 610px wide, narrower than the hosted signup flow's own
 *    minimum layout width (700), which clipped it.
 * 2. The note's overlay element bound a click to `hideIframe()`, a method that
 *    exists on nothing. It was the last remnant of a removed in-page iframe
 *    modal (no `.iframe-parent` markup survives, and nothing adds the `.show`
 *    class its stylesheet needs), so the element was permanently invisible and
 *    the broken binding only latent — an overlay stylesheet making it visible
 *    was all it took to turn a click into a TypeError. The element is gone.
 * 3. The "popup not ready" branch offered the fallback link before the signup
 *    tokens were minted, so clicking it built
 *    `…?businessToken=&autofillToken=…` — a URL the hosted flow rejects.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const SOLE_TRADER_MODEL = 'view/frontend/web/js/model/sole-trader.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';
const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';

/**
 * Load the renderer with `window.open` recorded rather than performed.
 *
 * @returns {object} `{ component, opened }` — `opened` collects one
 *          `{ url, target, features }` entry per `window.open()` call.
 */
function loadRenderer() {
    const opened = [];
    const component = loadAmdModule(
        RENDERER,
        {},
        {
            window: {
                checkoutConfig: { payment: {} },
                open: function (url, target, features) {
                    opened.push({ url: url, target: target, features: features });
                    // A truthy handle, as a browser that did NOT block the
                    // popup returns — the caller reads it to decide whether to
                    // fall back to the link. `close()` because a re-launch
                    // closes the handle it is replacing.
                    return { closed: false, close: function () {} };
                }
            },
            // getAutofillData() base64-encodes the buyer payload. The vm
            // sandbox inherits no browser globals, so without this the popup
            // path dies in the harness instead of being exercised.
            btoa: global.btoa
        }
    );
    return { component: component, opened: opened };
}

/**
 * A `this` context standing in for a live renderer — the HOST — plus the
 * SoleTrader collaborator it lazily mounts, between them carrying just the
 * members the popup-launch path reads.
 *
 * @param {object} component the loaded renderer
 * @param {object} [hostOverrides] members to replace on the renderer
 * @param {object} [soleTraderOverrides] members to replace on the collaborator
 * @returns {object} the context
 */
function makeContext(component, hostOverrides, soleTraderOverrides) {
    const shown = [];
    const ctx = Object.assign({}, component, {
        _brandConfig: { checkoutPageUrl: CHECKOUT_PAGE_URL },
        companyName: function () { return 'Ola Nordmann'; },
        getEmail: function () { return 'ola@example.com'; },
        getTelephone: function () { return '+4712345678'; },
        showModeTab: function () { return true; },
        showSoleTrader: function () { return true; },
        showPopupMessage: function (next) {
            if (!arguments.length) return shown.length ? shown[shown.length - 1] : false;
            shown.push(next);
            return next;
        },
        popupMessageStates: shown,
        companyId: function () { return ''; },
        soleTraderBusy: function () {},
        registeredOrganisationMode: function () {},
        fillCompanyData: function () {}
    });
    Object.assign(ctx, hostOverrides || {});
    Object.assign(ctx.soleTrader(), {
        delegationToken: '',
        autofillToken: '',
        getAutofillData: function () { return 'AUTOFILL'; },
        enterSoleTraderUi: function () {},
        fetchBuyer: function () { return Promise.resolve(null); }
    }, soleTraderOverrides || {});
    return ctx;
}

describe('sole-trader signup popup (TWO-25461)', () => {
    test('the popup is opened at the 700x805 the hosted flow needs', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component, {}, {
            delegationToken: 'dt-1',
            autofillToken: 'at-1'
        });

        const handle = ctx.openIframe.call(ctx);

        expect(handle).toBeTruthy();
        expect(opened).toHaveLength(1);
        expect(opened[0].features).toContain('width=700');
        expect(opened[0].features).toContain('height=805');
        // The defect value specifically — a partial revert would otherwise
        // satisfy a "contains width=700" check if both were somehow present.
        expect(opened[0].features).not.toContain('610');
    });

    test('the popup-launch source carries no 610-wide window feature anywhere', () => {
        // The behavioural assertion above only sees the one call site the
        // fixture reaches. This covers the value being reintroduced on a path
        // no fixture takes (a second launch helper, a brand branch).
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', SOLE_TRADER_MODEL), 'utf8');
        expect(src).not.toContain('width=610');
    });

    test('every method the template arrow-binds to a click exists on the renderer', () => {
        // This is the `hideIframe()` class of defect: a template calling a
        // method the view model never had, which throws only when the element
        // is actually clicked and so survives every non-clicking test.
        //
        // Scope: arrow-bound calls (`click: () => foo()`), which are the
        // renderer's OWN methods. Bare-reference bindings (`click: placeOrder`)
        // resolve against the Magento base component, which the harness
        // replaces with a stub, so they cannot be checked here.
        const template = fs.readFileSync(path.resolve(__dirname, '..', '..', TEMPLATE), 'utf8');
        const bound = new Set();
        const pattern = /=>\s*\{?\s*([A-Za-z_$][\w$]*)\s*\(/g;
        let match;
        while ((match = pattern.exec(template)) !== null) {
            bound.add(match[1]);
        }

        const { component } = loadRenderer();
        // A guard on the scan itself: a regex that silently matched nothing
        // would make the loop below vacuous.
        expect(bound.has('launchSignup')).toBe(true);
        expect(bound.has('soleTraderMode')).toBe(true);

        const missing = [...bound].filter((name) => typeof component[name] !== 'function');
        expect(missing).toEqual([]);
    });

    test('the removed overlay leaves no hideIframe reference behind', () => {
        const template = fs.readFileSync(path.resolve(__dirname, '..', '..', TEMPLATE), 'utf8');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', SOLE_TRADER_MODEL), 'utf8');
        expect(template).not.toContain('hideIframe');
        expect(src).not.toContain('hideIframe');
    });

    test('the signup URL carries both tokens once they are minted', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component, {}, {
            delegationToken: 'dt-1',
            autofillToken: 'at-1'
        });

        ctx.openIframe.call(ctx);

        expect(opened).toHaveLength(1);
        const url = new URL(opened[0].url);
        expect(url.origin + url.pathname).toBe(CHECKOUT_PAGE_URL + '/soletrader/signup');
        expect(url.searchParams.get('businessToken')).toBe('dt-1');
        expect(url.searchParams.get('autofillToken')).toBe('at-1');
    });

    test('getAutofillData() round-trips a non-ASCII name without throwing', () => {
        const soleTraderModule = loadAmdModule(
            SOLE_TRADER_MODEL,
            {
                'Magento_Checkout/js/model/quote': Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
                    billingAddress: function () {
                        return {
                            firstname: 'Håkon',
                            lastname: 'Sørensen',
                            street: ['Storgata 1'],
                            postcode: '0155',
                            city: 'Oslo',
                            region: 'Oslo',
                            countryId: 'NO'
                        };
                    }
                })
            },
            { btoa: global.btoa }
        );
        const soleTrader = new soleTraderModule({
            getEmail: function () { return 'hakon@example.com'; },
            companyName: function () { return 'Sørensen Consulting'; },
            getTelephone: function () { return '+4712345678'; }
        });

        let encoded;
        expect(() => { encoded = soleTrader.getAutofillData(); }).not.toThrow();
        const decoded = JSON.parse(decodeURIComponent(escape(atob(encoded))));
        expect(decoded.first_name).toBe('Håkon');
        expect(decoded.last_name).toBe('Sørensen');
        expect(decoded.company_name).toBe('Sørensen Consulting');
    });

    test.each([
        ['US', 'US', 'US'],
        ['GB', 'gb', 'GB'],
        ['no billing country resolved', '', null]
    ])('the signup URL country param (%s)', (_label, billingCountryId, expectedParam) => {
        const opened = [];
        const component = loadAmdModule(
            RENDERER,
            {
                'Magento_Checkout/js/model/quote': Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
                    billingAddress: function () { return { countryId: billingCountryId }; }
                })
            },
            {
                window: {
                    checkoutConfig: { payment: {} },
                    open: function (url, target, features) {
                        opened.push({ url: url, target: target, features: features });
                        return { closed: false };
                    }
                },
                btoa: global.btoa
            }
        );
        const ctx = makeContext(component, {}, { delegationToken: 'dt-1', autofillToken: 'at-1' });

        ctx.openIframe.call(ctx);

        const url = new URL(opened[0].url);
        expect(url.searchParams.get('country')).toBe(expectedParam);
    });

    test('the country param is sourced from the quote billing address, not the DOM-fed countryCode observable', () => {
        // PDEV-4669: a self-selected DOM value must not be able to dodge the
        // country-specific identity step. countryCode() is fed in part by a
        // live DOM watcher (watchAddressFormCountry()) and must be ignored
        // here even when it disagrees with the quote's own billing address.
        const opened = [];
        const component = loadAmdModule(
            RENDERER,
            {
                'Magento_Checkout/js/model/quote': Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
                    billingAddress: function () { return { countryId: 'US' }; }
                })
            },
            {
                window: {
                    checkoutConfig: { payment: {} },
                    open: function (url, target, features) {
                        opened.push({ url: url, target: target, features: features });
                        return { closed: false };
                    }
                },
                btoa: global.btoa
            }
        );
        const ctx = makeContext(
            component,
            { countryCode: function () { return 'gb'; } },
            { delegationToken: 'dt-1', autofillToken: 'at-1' }
        );

        ctx.openIframe.call(ctx);

        expect(new URL(opened[0].url).searchParams.get('country')).toBe('US');
    });

    test.each([
        ['both tokens missing', '', ''],
        ['business token missing', '', 'at-1'],
        ['autofill token missing', 'dt-1', '', ]
    ])('no popup is opened while %s', (_label, delegationToken, autofillToken) => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component, {}, {
            delegationToken: delegationToken,
            autofillToken: autofillToken
        });

        const handle = ctx.openIframe.call(ctx);

        expect(handle).toBeNull();
        expect(opened).toEqual([]);
    });

    test('the chip click opens signup synchronously, with no await before window.open', () => {
        // The popup-blocker contract (TWO-25503): tokens are minted up front,
        // so the click handler reaches `window.open()` inside the gesture that
        // triggered it. A single synchronous call, not a promise, is the
        // observable form of that.
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component, {}, {
            delegationToken: 'dt-1',
            autofillToken: 'at-1',
            getTokens: function () { throw new Error('no mint may happen on the click path'); }
        });

        const handle = ctx.soleTraderMode.call(ctx);

        expect(opened).toHaveLength(1);
        expect(handle).toBeTruthy();
        expect(ctx.popupMessageStates).toEqual([false]);
    });

    test('a chip click before the up-front mint has landed opens nothing and offers the link', async () => {
        const { component, opened } = loadRenderer();
        let resolveTokens;
        const ctx = makeContext(component, {}, {
            getTokens: function () {
                return new Promise((resolve) => { resolveTokens = resolve; });
            }
        });

        ctx.soleTraderMode.call(ctx);

        expect(opened).toEqual([]);
        // The link is the only route back, so it is offered whether or not
        // tokens exist yet — its own click retries once they land.
        expect(ctx.popupMessageStates).toEqual([true]);

        resolveTokens({ delegation_token: 'dt-1', autofill_token: 'at-1' });
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(ctx.hasSignupTokens.call(ctx)).toBe(true);
        // Retrying through the link now succeeds, still synchronously.
        ctx.launchSignup.call(ctx);
        expect(opened).toHaveLength(1);
        expect(ctx.popupMessageStates).toEqual([true, false]);
    });

    test('re-clicking the chip once an identity is adopted re-signs up with autoselect=false', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(
            component,
            { companyId: function () { return '1234567'; } },
            { delegationToken: 'dt-1', autofillToken: 'at-1' }
        );

        ctx.soleTraderMode.call(ctx);

        expect(opened).toHaveLength(1);
        expect(new URL(opened[0].url).searchParams.get('autoselect')).toBe('false');
    });

    test.each([
        { delegationToken: 'dt-1', autofillToken: 'at-1',
            description: 'a blocked popup with tokens minted offers the link' },
        { delegationToken: '', autofillToken: '',
            description: 'a click before the mint has landed offers the link too' }
    ])('blocked-popup branch: $description', ({ delegationToken, autofillToken }) => {
        // `window.open` returning null is a browser-blocked popup. Either way
        // the on-page link is the only remaining route to signup, and its own
        // click re-mints if that is what was missing.
        const opened = [];
        const component = loadAmdModule(
            RENDERER,
            {},
            {
                window: {
                    checkoutConfig: { payment: {} },
                    open: function (url, target, features) {
                        opened.push({ url: url, target: target, features: features });
                        return null;
                    }
                },
                btoa: global.btoa
            }
        );
        const ctx = makeContext(component, {}, {
            delegationToken: delegationToken,
            autofillToken: autofillToken,
            getTokens: function () { return Promise.resolve({ delegation_token: 'dt-2', autofill_token: 'at-2' }); }
        });

        ctx.soleTraderMode.call(ctx);

        expect(ctx.popupMessageStates).toEqual([true]);
    });

    test('no buyer lookup happens before the buyer has authenticated in the popup', () => {
        // The passive `/autofill/v1/buyer/current` probe is gone (TWO-25503):
        // the record it returns is whatever Two session cookie the browser
        // carries, which the person checking out may never have authenticated
        // against. Only the ACCEPTED handshake may read it.
        const { component } = loadRenderer();
        const ctx = makeContext(component, {}, {
            delegationToken: 'dt-1',
            autofillToken: 'at-1',
            fetchBuyer: function () { throw new Error('no passive buyer probe may run'); }
        });

        expect(() => ctx.soleTraderMode.call(ctx)).not.toThrow();
    });

    test('concurrent ensureTokens() calls mint once and share the outstanding chain', async () => {
        const { component } = loadRenderer();
        let mints = 0;
        let resolveTokens;
        const ctx = makeContext(component, {}, {
            getTokens: function () {
                mints += 1;
                return new Promise((resolve) => { resolveTokens = resolve; });
            }
        });

        const first = ctx.ensureSoleTraderTokens.call(ctx);
        const second = ctx.ensureSoleTraderTokens.call(ctx);
        resolveTokens({ delegation_token: 'dt-1', autofill_token: 'at-1' });

        await expect(Promise.all([first, second])).resolves.toEqual([true, true]);
        expect(mints).toBe(1);
        // Already held: a later call is a no-op rather than a re-mint.
        await ctx.ensureSoleTraderTokens.call(ctx);
        expect(mints).toBe(1);
    });

    test('a failed mint leaves no tokens and does not arm the refresh timer', async () => {
        const { component } = loadRenderer();
        const ctx = makeContext(component, {}, {
            getTokens: function () { return Promise.reject(new Error('boom')); }
        });

        await expect(ctx.ensureSoleTraderTokens.call(ctx)).resolves.toBe(false);
        expect(ctx.hasSignupTokens.call(ctx)).toBe(false);
        expect(ctx.soleTrader()._tokenRefreshId).toBeNull();
    });
});

describe('sole-trader token refresh (TWO-25503, WooCommerce/PrestaShop parity)', () => {
    beforeEach(() => { jest.useFakeTimers(); });
    afterEach(() => { jest.useRealTimers(); });

    test('a successful mint arms a 30-minute re-mint that keeps the tokens usable', async () => {
        const { component } = loadRenderer();
        let mints = 0;
        const ctx = makeContext(component, {}, {
            getTokens: function () {
                mints += 1;
                return Promise.resolve({
                    delegation_token: `dt-${mints}`,
                    autofill_token: `at-${mints}`
                });
            }
        });

        await ctx.ensureSoleTraderTokens.call(ctx);
        expect(mints).toBe(1);

        jest.advanceTimersByTime(30 * 60 * 1000);
        await Promise.resolve();
        expect(mints).toBe(2);
        expect(ctx.soleTrader().delegationToken).toBe('dt-2');
    });

    test('the refresh tick is skipped while the signup popup is open', async () => {
        const { component } = loadRenderer();
        let mints = 0;
        const ctx = makeContext(component, {}, {
            getTokens: function () {
                mints += 1;
                return Promise.resolve({ delegation_token: 'dt-1', autofill_token: 'at-1' });
            }
        });

        await ctx.ensureSoleTraderTokens.call(ctx);
        ctx.soleTrader()._soleTraderPopupWindow = { closed: false, close: function () {} };

        jest.advanceTimersByTime(30 * 60 * 1000);
        await Promise.resolve();

        expect(mints).toBe(1);
    });

    test('dispose() clears the refresh timer', async () => {
        const { component } = loadRenderer();
        let mints = 0;
        const ctx = makeContext(component, {}, {
            getTokens: function () {
                mints += 1;
                return Promise.resolve({ delegation_token: 'dt-1', autofill_token: 'at-1' });
            }
        });

        await ctx.ensureSoleTraderTokens.call(ctx);
        ctx.soleTrader().dispose();

        jest.advanceTimersByTime(60 * 60 * 1000);
        await Promise.resolve();

        expect(mints).toBe(1);
    });
});

describe('abandoned signup popup (WooCommerce watchPopupClose parity)', () => {
    beforeEach(() => { jest.useFakeTimers(); });
    afterEach(() => { jest.useRealTimers(); });

    /**
     * @param {object} popup the handle `window.open` should hand back
     * @param {object} [hostOverrides] members to replace on the renderer
     * @returns {object} `{ ctx, reverted, busyStates }`
     */
    function launchWatched(popup, hostOverrides) {
        const component = loadAmdModule(
            RENDERER,
            {},
            {
                window: {
                    checkoutConfig: { payment: {} },
                    open: function () { return popup; }
                },
                btoa: global.btoa
            }
        );
        const reverted = [];
        const busyStates = [];
        const ctx = makeContext(
            component,
            Object.assign({
                registeredOrganisationMode: function () { reverted.push(true); },
                soleTraderBusy: function (next) { busyStates.push(next); }
            }, hostOverrides || {}),
            { delegationToken: 'dt-1', autofillToken: 'at-1' }
        );
        ctx.soleTraderMode.call(ctx);
        return { ctx: ctx, reverted: reverted, busyStates: busyStates };
    }

    test('closing the popup with nothing captured hands the checkout back to company search', () => {
        const popup = { closed: false, close: function () {} };
        const { reverted, busyStates } = launchWatched(popup);

        expect(busyStates).toEqual([true]);
        popup.closed = true;
        jest.advanceTimersByTime(300);

        expect(reverted).toEqual([true]);
        expect(busyStates).toEqual([true, false]);
    });

    test('closing the popup after an identity was captured leaves sole-trader mode alone', () => {
        const popup = { closed: false, close: function () {} };
        const { reverted } = launchWatched(popup, { companyId: function () { return '1234567'; } });

        popup.closed = true;
        jest.advanceTimersByTime(300);

        expect(reverted).toEqual([]);
    });

    test('closing the popup while the ACCEPTED lookup is still out leaves it to that handler', () => {
        const popup = { closed: false, close: function () {} };
        const { ctx, reverted } = launchWatched(popup);
        ctx.soleTrader()._signupConfirming = true;

        popup.closed = true;
        jest.advanceTimersByTime(300);

        expect(reverted).toEqual([]);
    });
});
