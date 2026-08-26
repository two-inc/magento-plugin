/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §7 — the two UX gaps left after the popup-launch bugfixes
 * (PR #343): the "select a different sole trader" link/autoselect param,
 * and the in-flight spinner for the chip lookup's round trip.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const SOLE_TRADER_MODEL = 'view/frontend/web/js/model/sole-trader.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';
const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';

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
                    return { closed: false };
                }
            },
            btoa: global.btoa
        }
    );
    return { component: component, opened: opened };
}

/**
 * A `this` context standing in for a live renderer — the HOST — plus the
 * SoleTrader collaborator it lazily mounts, which owns the tokens, the lookup
 * record and the transport.
 *
 * @param {object} component the loaded renderer
 * @param {object} [hostOverrides] members to replace on the renderer
 * @param {object} [soleTraderOverrides] members to replace on the collaborator
 * @returns {object} the context
 */
function makeContext(component, hostOverrides, soleTraderOverrides) {
    const ctx = Object.assign({}, component, {
        _brandConfig: { checkoutPageUrl: CHECKOUT_PAGE_URL },
        companyName: function () { return 'Ola Nordmann'; },
        getEmail: function () { return 'ola@example.com'; },
        getTelephone: function () { return '+4712345678'; },
        showModeTab: function () { return true; },
        showSoleTrader: function () { return true; }
    });
    Object.assign(ctx, hostOverrides || {});
    Object.assign(ctx.soleTrader(), {
        delegationToken: 'dt-1',
        autofillToken: 'at-1',
        getAutofillData: function () { return 'AUTOFILL'; }
    }, soleTraderOverrides || {});
    return ctx;
}

describe('select a different sole trader (TWO-25461 §7)', () => {
    test('window.open is called synchronously in the click handler, with no await/setTimeout before it', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component);

        // No `await`, no `setTimeout` between the call and the assertion:
        // proves the popup opens in the same tick as the click, so a real
        // browser's popup blocker sees it as user-gesture-triggered.
        ctx.selectDifferentSoleTrader.call(ctx);

        expect(opened).toHaveLength(1);
    });

    test('the link click builds a URL carrying autoselect=false and non-empty tokens', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component);

        ctx.selectDifferentSoleTrader.call(ctx);

        expect(opened).toHaveLength(1);
        const url = new URL(opened[0].url);
        expect(url.searchParams.get('autoselect')).toBe('false');
        expect(url.searchParams.get('businessToken')).toBe('dt-1');
        expect(url.searchParams.get('autofillToken')).toBe('at-1');
    });

    test('an ordinary openIframe() call (no options) carries no autoselect param', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component);

        ctx.openIframe.call(ctx);

        const url = new URL(opened[0].url);
        expect(url.searchParams.has('autoselect')).toBe(false);
    });

    test('no popup opens when tokens are not minted, even for a re-signup', () => {
        const { component, opened } = loadRenderer();
        const ctx = makeContext(component, {}, { delegationToken: '', autofillToken: '' });

        const handle = ctx.selectDifferentSoleTrader.call(ctx);

        expect(handle).toBeNull();
        expect(opened).toEqual([]);
    });

    test('the template binds the link to selectDifferentSoleTrader, gated on adopted sole-trader state', () => {
        const template = fs.readFileSync(path.resolve(__dirname, '..', '..', TEMPLATE), 'utf8');
        expect(template).toContain('selectDifferentSoleTrader');
        // DOM placement rule (porting guide §0/§7): the link markup is a
        // SIBLING appearing AFTER the company_name field, never nested
        // inside it — a plain ordering check on the raw markup.
        const fieldIndex = template.indexOf('id="company_name"');
        const linkIndex = template.indexOf('two-select-different-sole-trader');
        expect(fieldIndex).toBeGreaterThan(-1);
        expect(linkIndex).toBeGreaterThan(fieldIndex);
    });

    test('the popup-launch source carries no width=610 anywhere (regression guard shared with #343)', () => {
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', SOLE_TRADER_MODEL), 'utf8');
        expect(src).not.toContain('width=610');
    });
});

describe('at most one sole-trader popup is ever live (TWO-25461 review finding)', () => {
    /**
     * A window.open() double that hands back distinct, individually
     * closable handles, so a test can assert the FIRST one was closed
     * before/when the second opened.
     */
    function loadRendererWithClosableHandles() {
        const opened = [];
        const handles = [];
        const component = loadAmdModule(
            RENDERER,
            {},
            {
                window: {
                    checkoutConfig: { payment: {} },
                    open: function (url, target, features) {
                        opened.push({ url: url, target: target, features: features });
                        const handle = { closed: false, close: function () { this.closed = true; } };
                        handles.push(handle);
                        return handle;
                    }
                },
                btoa: global.btoa
            }
        );
        return { component: component, opened: opened, handles: handles };
    }

    test('clicking "select a different sole trader" while the first adoption popup is still open closes it, not a second concurrent tab', () => {
        const { component, opened, handles } = loadRendererWithClosableHandles();
        const ctx = makeContext(component);

        ctx.openIframe.call(ctx);
        ctx.selectDifferentSoleTrader.call(ctx);

        expect(opened).toHaveLength(2);
        expect(handles[0].closed).toBe(true);
        expect(handles[1].closed).toBe(false);
    });

    test('a double-click on "select a different sole trader" closes the first popup rather than leaving two live', () => {
        const { component, handles } = loadRendererWithClosableHandles();
        const ctx = makeContext(component);

        ctx.selectDifferentSoleTrader.call(ctx);
        ctx.selectDifferentSoleTrader.call(ctx);

        expect(handles).toHaveLength(2);
        expect(handles[0].closed).toBe(true);
        expect(handles[1].closed).toBe(false);
    });

    test('popupMessageListener ignores a message whose source is not the currently tracked popup', async () => {
        const opened = [];
        const listeners = [];
        const component = loadAmdModule(
            RENDERER,
            {},
            {
                window: {
                    checkoutConfig: { payment: {} },
                    open: function (url, target, features) {
                        opened.push({ url: url, target: target, features: features });
                        return { closed: false, close: function () { this.closed = true; } };
                    },
                    addEventListener: function (name, fn) {
                        listeners.push({ name: name, fn: fn });
                    }
                },
                btoa: global.btoa
            }
        );
        const resolveBuyer = jest.fn(() =>
            Promise.resolve({ matches: true, buyer: { organization_number: '1', company_name: 'X' } })
        );
        const fillCompanyData = jest.fn();
        const ctx = makeContext(
            component,
            { fillCompanyData: fillCompanyData },
            { resolveBuyer: resolveBuyer }
        );

        ctx.openIframe.call(ctx); // opens and tracks handle A as the live popup
        const staleWindow = { fake: 'a stand-in for handle A' };

        ctx.popupMessageListener.call(ctx);
        const handler = listeners.find((l) => l.name === 'message').fn;

        // A message claiming to come from something OTHER than the
        // currently-tracked popup — the exact shape of a stale/superseded
        // popup posting late — must not apply its result.
        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: staleWindow });
        await Promise.resolve();

        expect(resolveBuyer).not.toHaveBeenCalled();
        expect(fillCompanyData).not.toHaveBeenCalled();
    });
});

describe('sole-trader lookup in-flight spinner (TWO-25461 §7)', () => {
    test('the spinner flag is true while the round trip is outstanding, false once it settles (success)', async () => {
        const { component } = loadRenderer();
        let resolveTokens;
        const states = [];
        const ctx = makeContext(
            component,
            {
                fillCompanyData: function () {},
                soleTraderLookupInFlight: function (next) {
                    if (!arguments.length) return states.length ? states[states.length - 1] : false;
                    states.push(next);
                    return next;
                }
            },
            {
                enterSoleTraderUi: function () {},
                fetchBuyer: function () { return Promise.resolve(null); },
                getTokens: function () {
                    return new Promise((resolve) => { resolveTokens = resolve; });
                }
            }
        );

        const flight = ctx.lookupSoleTrader.call(ctx);
        expect(states).toEqual([true]);

        resolveTokens({ delegation_token: 'dt-1', autofill_token: 'at-1' });
        await flight;

        expect(states[states.length - 1]).toBe(false);
    });

    test('the spinner flag is cleared even when the round trip fails', async () => {
        const { component } = loadRenderer();
        const states = [];
        const ctx = makeContext(
            component,
            {
                soleTraderLookupInFlight: function (next) {
                    if (!arguments.length) return states.length ? states[states.length - 1] : false;
                    states.push(next);
                    return next;
                }
            },
            { getTokens: function () { return Promise.reject(new Error('mint failed')); } }
        );

        await ctx.lookupSoleTrader.call(ctx);

        expect(states).toEqual([true, false]);
    });

    test('the template renders the spinner inside the company-name field control, bound to the flag', () => {
        const template = fs.readFileSync(path.resolve(__dirname, '..', '..', TEMPLATE), 'utf8');
        expect(template).toContain('soleTraderLookupInFlight');
        expect(template).toContain('two-sole-trader-prefetch-spinner');
    });

    test('an earlier call settling after a newer one started does not clear the flag out from under it (review finding)', async () => {
        // Buyer edits the email again before the FIRST lookup resolves: a
        // second lookupSoleTrader() call fires for the new email. If the
        // first call's chain happens to settle after the second one starts,
        // its own .finally() must not flip the shared flag to false while
        // the second request is still genuinely outstanding.
        const { component } = loadRenderer();
        const states = [];
        let resolveFirst;
        let resolveSecond;
        const emails = ['first@example.com', 'second@example.com'];
        let callIndex = 0;
        const ctx = makeContext(
            component,
            {
                fillCompanyData: function () {},
                getEmail: function () { return emails[callIndex]; },
                soleTraderLookupInFlight: function (next) {
                    if (!arguments.length) return states.length ? states[states.length - 1] : false;
                    states.push(next);
                    return next;
                }
            },
            {
                enterSoleTraderUi: function () {},
                fetchBuyer: function () { return Promise.resolve(null); },
                getTokens: function () {
                    if (callIndex === 0) {
                        return new Promise((resolve) => { resolveFirst = resolve; });
                    }
                    return new Promise((resolve) => { resolveSecond = resolve; });
                }
            }
        );

        const firstFlight = ctx.lookupSoleTrader.call(ctx);
        callIndex = 1;
        const secondFlight = ctx.lookupSoleTrader.call(ctx);

        // Resolve the FIRST (older) call while the second is still pending.
        resolveFirst({ delegation_token: 'dt-1', autofill_token: 'at-1' });
        await firstFlight;

        // The second request is still genuinely in flight — the spinner
        // must still read true, not have been cleared by the stale first call.
        expect(ctx.soleTraderLookupInFlight()).toBe(true);

        resolveSecond({ delegation_token: 'dt-2', autofill_token: 'at-2' });
        await secondFlight;

        expect(ctx.soleTraderLookupInFlight()).toBe(false);
    });
});
