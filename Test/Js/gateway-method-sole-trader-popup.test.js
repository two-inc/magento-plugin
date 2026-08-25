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
const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
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
 * A `this` context standing in for a live renderer, carrying just the members
 * the popup-launch path reads.
 *
 * @param {object} component the loaded renderer
 * @param {object} [overrides] members to replace on top
 * @returns {object} the context
 */
function makeContext(component, overrides) {
    const shown = [];
    const ctx = Object.assign({}, component, {
        _brandConfig: { checkoutPageUrl: CHECKOUT_PAGE_URL },
        delegationToken: '',
        autofillToken: '',
        companyName: function () { return 'Ola Nordmann'; },
        getEmail: function () { return 'ola@example.com'; },
        getTelephone: function () { return '+4712345678'; },
        getAutofillData: function () { return 'AUTOFILL'; },
        showModeTab: function () { return true; },
        showSoleTrader: function () { return true; },
        showPopupMessage: function (next) {
            if (!arguments.length) return shown.length ? shown[shown.length - 1] : false;
            shown.push(next);
            return next;
        },
        popupMessageStates: shown,
        soleTraderLookup: { ready: false, buyer: null, matches: false },
        soleTraderLookupEmail: null,
        enterSoleTraderUi: function () {},
        fillCompanyData: function () {},
        fetchBuyer: function () { return Promise.resolve(null); }
    });
    return Object.assign(ctx, overrides || {});
}

describe('sole-trader signup popup (TWO-25461)', () => {
    test('the popup is opened at the 700x805 the hosted flow needs', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component, {
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

    test('the renderer source carries no 610-wide window feature anywhere', () => {
        // The behavioural assertion above only sees the one call site the
        // fixture reaches. This covers the value being reintroduced on a path
        // no fixture takes (a second launch helper, a brand branch).
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');
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
        expect(bound.has('openIframe')).toBe(true);
        expect(bound.has('soleTraderMode')).toBe(true);

        const missing = [...bound].filter((name) => typeof component[name] !== 'function');
        expect(missing).toEqual([]);
    });

    test('the removed overlay leaves no hideIframe reference behind', () => {
        const template = fs.readFileSync(path.resolve(__dirname, '..', '..', TEMPLATE), 'utf8');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');
        expect(template).not.toContain('hideIframe');
        expect(src).not.toContain('hideIframe');
    });

    test('the signup URL carries both tokens once they are minted', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component, {
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

    test.each([
        ['both tokens missing', '', ''],
        ['business token missing', '', 'at-1'],
        ['autofill token missing', 'dt-1', '', ]
    ])('no popup is opened while %s', (_label, delegationToken, autofillToken) => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component, {
            delegationToken: delegationToken,
            autofillToken: autofillToken
        });

        const handle = ctx.openIframe.call(ctx);

        expect(handle).toBeNull();
        expect(opened).toEqual([]);
    });

    test('the chip click opens signup only once the tokens have landed', async () => {
        const { component, opened } = loadRenderer();
        let resolveTokens;
        const ctx = makeContext(component, {
            getTokens: function () {
                return new Promise((resolve) => {
                    resolveTokens = resolve;
                });
            }
        });

        ctx.soleTraderMode.call(ctx);
        // Still minting: nothing may open yet, because openIframe() could
        // only build an empty-token URL from here.
        expect(opened).toEqual([]);
        expect(ctx.hasSignupTokens.call(ctx)).toBe(false);

        resolveTokens({ delegation_token: 'dt-1', autofill_token: 'at-1' });
        // Yield to the macrotask queue rather than awaiting the returned
        // promise: the chain also settles fetchBuyer(), whose own microtasks
        // run after soleTraderMode()'s.
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(ctx.hasSignupTokens.call(ctx)).toBe(true);
        expect(opened).toHaveLength(1);
        // The popup was NOT blocked, so the fallback link stays withdrawn.
        expect(ctx.popupMessageStates).toEqual([false]);
    });

    test.each([
        { delegationToken: 'dt-1', autofillToken: 'at-1', expected: true,
            description: 'a blocked popup with tokens minted offers the link' },
        { delegationToken: '', autofillToken: '', expected: false,
            description: 'a failed token mint offers no link at all' }
    ])('blocked-popup branch: $description', async ({ delegationToken, autofillToken, expected }) => {
        // The resolved-but-no-match branch. `window.open` returning null is a
        // browser-blocked popup; tokens absent means the lookup resolved
        // through its catch, which leaves `ready` true and nothing minted.
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
        const ctx = makeContext(component, {
            delegationToken: delegationToken,
            autofillToken: autofillToken,
            soleTraderLookup: { ready: true, buyer: null, matches: false }
        });

        await ctx.soleTraderMode.call(ctx);

        expect(ctx.popupMessageStates).toEqual([expected]);
    });

    test('lookupSoleTrader resolves a promise on its skip paths', async () => {
        const { component } = loadRenderer();
        // The not-ready branch sequences on this promise, so a skip path
        // returning undefined would throw on `.then`.
        const noTab = makeContext(component, { showModeTab: function () { return false; } });
        const noEmail = makeContext(component, { getEmail: function () { return '   '; } });
        const deduped = makeContext(component, { soleTraderLookupEmail: 'ola@example.com' });

        await expect(noTab.lookupSoleTrader.call(noTab)).resolves.toBeUndefined();
        await expect(noEmail.lookupSoleTrader.call(noEmail)).resolves.toBeUndefined();
        await expect(deduped.lookupSoleTrader.call(deduped)).resolves.toBeUndefined();
    });

    test('a second chip click while the first lookup is still in flight opens signup once', async () => {
        // The dedupe key is written synchronously, so the second click used to
        // resume immediately on a lookup that had recorded nothing and minted
        // nothing: no adoption, no popup, and no fallback link either, since
        // that needs the tokens too. It must wait on the outstanding chain.
        const { component, opened } = loadRenderer();
        let resolveTokens;
        let mints = 0;
        const ctx = makeContext(component, {
            getTokens: function () {
                mints += 1;
                return new Promise((resolve) => {
                    resolveTokens = resolve;
                });
            }
        });

        const first = ctx.soleTraderMode.call(ctx);
        const second = ctx.soleTraderMode.call(ctx);
        await new Promise((resolve) => setTimeout(resolve, 0));

        // Still minting. Neither click may have reached a decision yet — a
        // recorded showPopupMessage() here is the dead end: the second click
        // resumed on an empty lookup, so it neither opened a popup nor offered
        // the link.
        expect(ctx.popupMessageStates).toEqual([]);
        expect(opened).toEqual([]);

        resolveTokens({ delegation_token: 'dt-1', autofill_token: 'at-1' });
        await Promise.all([first, second]);
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(mints).toBe(1);
        expect(opened.length).toBeGreaterThan(0);
        opened.forEach(({ url }) => {
            expect(new URL(url).searchParams.get('businessToken')).toBe('dt-1');
        });
    });
});
