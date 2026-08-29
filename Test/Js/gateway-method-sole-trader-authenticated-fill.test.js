/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — the `postMessage` handshake the hosted signup finishes on, and
 * the one buyer lookup it is allowed to make.
 *
 * `/autofill/v1/buyer/current` answers with whatever buyer the Two cookie
 * identifies. Reading it BEFORE the buyer has authenticated is a cookie probe:
 * it would let a checkout adopt an identity nobody on this page proved they
 * hold. So the flow has exactly one caller — the ACCEPTED branch of the
 * handshake, after the hosted flow has verified the buyer server-side — and
 * that is pinned both behaviourally and in the source, because reinstating a
 * passive probe is invisible to every fixture that drives the handshake.
 *
 * Post-authentication the email that authenticated IS the identity: the
 * checkout's own contact field has no say in it. Re-gating on a match there
 * discarded an authenticated buyer and left the company field permanently
 * blank with no route forward (TWO-25461).
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';

const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';
const CHECKOUT_API_URL = 'https://api.example';
const BUYER_ENDPOINT = '/autofill/v1/buyer/current';

/** Stand-in for the signup popup's own window, the only source that counts. */
const POPUP = { popup: 'the tracked signup window', closed: false, close: function () {} };

const BUYER = {
    email: 'trader@example.com',
    organization_number: '999888777',
    company_name: 'Example Trader'
};

/**
 * The real flow against a stub host, with `fetch` recorded.
 *
 * @param {object} [options] `{ buyer, mode, firewallToken }` — what the buyer
 *        endpoint answers with (null for a 404), the capture mode to start in,
 *        and the firewall token the merchant config exposes to the browser
 * @returns {object} `{ flow, rec, identity, handler }`
 */
function loadFlow(options) {
    const opts = options || {};
    const rec = {
        requests: [],
        errors: [],
        adopted: [],
        abandons: [],
        listeners: [],
        /** Whether `identity.isBusy()` was still true as the write landed. */
        busyDuringWrite: null
    };

    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });
    identity.captureMode('mode' in opts ? opts.mode : 'soletrader');

    const fakeWindow = {
        open: function () { return null; },
        addEventListener: function (name, fn) { rec.listeners.push({ name: name, fn: fn }); },
        removeEventListener: function () {}
    };

    const SoleTraderCtor = loadAmdModule(
        SOLE_TRADER,
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search': Object.assign(
                {},
                defaultMocks()['Two_Gateway/js/model/company-search'],
                { apiClientParams: function () { return { client: 'magento' }; } }
            ),
            'Magento_Ui/js/model/messageList': {
                addErrorMessage: function (message) { rec.errors.push(message); },
                addSuccessMessage: function () {}
            }
        },
        {
            document: document,
            window: fakeWindow,
            btoa: global.btoa,
            setInterval: function () { return 1; },
            clearInterval: function () {},
            fetch: function (requestUrl, requestOptions) {
                rec.requests.push({ url: String(requestUrl), options: requestOptions });
                if (String(requestUrl).indexOf('get-tokens') !== -1) {
                    return Promise.resolve({
                        ok: true,
                        json: function () {
                            return Promise.resolve([{ delegation_token: 'dt', autofill_token: 'at' }]);
                        }
                    });
                }
                if (!opts.buyer) return Promise.resolve({ ok: false, status: 404 });
                return Promise.resolve({
                    ok: true,
                    json: function () { return Promise.resolve(opts.buyer); }
                });
            }
        }
    );

    const host = {
        config: function () {
            return {
                checkoutPageUrl: CHECKOUT_PAGE_URL,
                checkoutApiUrl: CHECKOUT_API_URL,
                firewallToken: opts.firewallToken || ''
            };
        },
        countryCode: function () { return 'gb'; },
        adoptSoleTrader: function (buyer) {
            rec.busyDuringWrite = identity.isBusy();
            rec.adopted.push(buyer);
            identity.write({ companyId: buyer.organization_number, companyName: buyer.company_name });
            identity.soleTraderAdopted(true);
        },
        abandonSoleTrader: function () { rec.abandons.push(true); }
    };

    const flow = new SoleTraderCtor(host);
    flow.autofillToken = 'at';
    // The tracked popup, set directly: opening one would also arm the close
    // watcher, whose own flight would mask the handshake's.
    flow._popupWindow = POPUP;
    flow.listenForSignupResult();
    const bound = rec.listeners.filter((entry) => entry.name === 'message');
    expect(bound).toHaveLength(1);

    return { flow: flow, rec: rec, identity: identity, handler: bound[0].fn };
}

/** Two macrotask turns, which is what the ACCEPTED branch needs to settle. */
function settle() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

function buyerRequests(rec) {
    return rec.requests.filter((entry) => entry.url.indexOf(BUYER_ENDPOINT) !== -1);
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('the buyer lookup happens only after authentication', () => {
    test('the flow has exactly one fetchBuyer call site, in the ACCEPTED branch', () => {
        // A reinstated passive probe is invisible to every behavioural fixture
        // here: it would auto-adopt a cookie identity with no handshake at all
        // and leave the handshake cases green. Pinning the call sites is what
        // catches that.
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', SOLE_TRADER), 'utf8');
        // Guard against a rename silently emptying the check below.
        expect(src).toContain('SoleTrader.prototype.fetchBuyer = function ()');

        const callSites = src.split('\n').filter((line) => /this\.fetchBuyer\(/.test(line));
        expect(callSites).toHaveLength(1);
        expect(src).toContain("if (event.data !== 'ACCEPTED')");
    });

    test('booting the flow and launching signup probes no buyer', async () => {
        const { flow, rec } = loadFlow({ buyer: BUYER });

        await flow.ensureTokens();
        flow.launchSignup();
        await settle();

        expect(buyerRequests(rec)).toEqual([]);
    });

    test('the lookup goes out under the autofill token, with cookies', async () => {
        const { rec, handler } = loadFlow({ buyer: BUYER });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();

        const request = buyerRequests(rec)[0];
        expect(request.options.headers['two-delegated-authority-token']).toBe('at');
        expect(request.options.credentials).toBe('include');
    });
});

describe('which messages the handshake acts on', () => {
    test.each([
        [
            { origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP },
            { adoptions: 1, errors: 0, lookups: 1 },
            'the tracked popup reports success'
        ],
        [
            { origin: CHECKOUT_PAGE_URL, data: 'REJECTED', source: POPUP },
            { adoptions: 0, errors: 1, lookups: 0 },
            'the tracked popup reports something other than ACCEPTED'
        ],
        [
            { origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: { other: 'window' } },
            { adoptions: 0, errors: 0, lookups: 0 },
            'an untracked window is ignored entirely, not surfaced as an error'
        ],
        [
            { origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: null },
            { adoptions: 0, errors: 0, lookups: 0 },
            'a non-window sender has a null source'
        ],
        [
            { origin: 'https://evil.example', data: 'ACCEPTED', source: POPUP },
            { adoptions: 0, errors: 0, lookups: 0 },
            'a foreign origin is ignored'
        ]
    ])('%p -> %p (%s)', async (event, expected) => {
        const { rec, handler } = loadFlow({ buyer: BUYER });

        handler(event);
        await settle();

        expect({
            adoptions: rec.adopted.length,
            errors: rec.errors.length,
            lookups: buyerRequests(rec).length
        }).toEqual(expected);
    });

    test('an ACCEPTED message outside sole-trader mode adopts nothing', async () => {
        const { rec, handler } = loadFlow({ buyer: BUYER, mode: 'registered' });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();

        expect(rec.adopted).toEqual([]);
        expect(buyerRequests(rec)).toEqual([]);
    });

    test('the listener is bound once however often it is armed', () => {
        const { flow, rec } = loadFlow({ buyer: BUYER });

        flow.listenForSignupResult();
        flow.listenForSignupResult();

        expect(rec.listeners.filter((entry) => entry.name === 'message')).toHaveLength(1);
    });
});

describe('what an authenticated buyer produces', () => {
    test.each([
        [BUYER, 'a buyer whose email matches nothing on the checkout is still adopted'],
        [
            Object.assign({}, BUYER, { email: null }),
            'a buyer with no email at all is adopted — the handshake is the proof'
        ]
    ])('%p (%s)', async (buyer) => {
        const { rec, identity, handler } = loadFlow({ buyer: buyer });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();

        expect(rec.adopted).toEqual([buyer]);
        expect(identity.companyId()).toBe(BUYER.organization_number);
        expect(identity.companyName()).toBe(BUYER.company_name);
        expect(identity.isSoleTrader()).toBe(true);
    });

    test('an ACCEPTED message the lookup cannot answer surfaces an error and fills nothing', async () => {
        const { rec, identity, handler } = loadFlow({ buyer: null });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();

        expect(rec.adopted).toEqual([]);
        expect(rec.errors).toHaveLength(1);
        expect(identity.companyId()).toBe('');
    });

    test('a replayed ACCEPTED lands on the same identity rather than clobbering it', async () => {
        const { rec, identity, handler } = loadFlow({ buyer: BUYER });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();

        expect(identity.companyId()).toBe(BUYER.organization_number);
        expect(identity.companyName()).toBe(BUYER.company_name);
    });
});

describe('the flight the handshake holds', () => {
    test('is still held as the identity write lands, and settled only after', async () => {
        // The popup can close the instant it posts, well before the identity is
        // in the form; settling on the response would let the close watcher
        // read a completed signup as an abandoned one.
        const { rec, identity, handler } = loadFlow({ buyer: BUYER });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        expect(identity.isBusy()).toBe(true);

        await settle();

        expect(rec.busyDuringWrite).toBe(true);
        expect(identity.isBusy()).toBe(false);
    });

    test('is settled even when the lookup answers with no buyer', async () => {
        const { identity, handler } = loadFlow({ buyer: null });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();

        expect(identity.isBusy()).toBe(false);
    });

    test('a closing popup does not abandon the signup while the lookup is confirming', async () => {
        const { flow, rec, handler } = loadFlow({ buyer: BUYER });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        expect(flow._signupConfirming).toBe(true);

        await settle();

        expect(flow._signupConfirming).toBe(false);
        expect(rec.abandons).toEqual([]);
    });
});


/**
 * The one call that stays browser-direct — it is authenticated by the buyer's
 * own session cookie on the API's domain, which no server-side call can
 * present. Its firewall header is therefore the only one the browser sends,
 * and only while the merchant has switched browser-originated traffic on.
 */
describe('the browser-direct buyer lookup and the firewall header', () => {
    test.each([
        ['waf-token', 'waf-token', 'a token exposed to the browser is sent on the one direct call'],
        ['', undefined, 'the default off state sends no header, so no token reaches the wire']
    ])('firewallToken %p sends %p (%s)', async (firewallToken, expected) => {
        const { rec, handler } = loadFlow({ buyer: BUYER, firewallToken: firewallToken });

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();

        const headers = buyerRequests(rec)[0].options.headers;
        expect(headers['X-WAF-TOKEN']).toBe(expected);
        expect(headers['two-delegated-authority-token']).toBe('at');
    });
});
