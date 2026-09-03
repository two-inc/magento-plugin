/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — the hosted sole-trader signup popup: how it is launched, what it
 * is launched with, and what happens to the checkout while it is open.
 *
 * The flow is PAGE-LEVEL (`model/sole-trader.js`), constructed by the
 * company-capture component, which is what it reads its host adapter, its
 * config and the identity through — so everything below drives the real flow
 * over Luma's real wired component. Nothing here reaches through a payment
 * renderer, which no longer participates.
 *
 * Mutation-resistance notes:
 *
 *  - the mint is asserted to have happened with the popup count still zero and
 *    no chip clicked, so moving it into the click handler fails rather than
 *    reading as green;
 *  - the click assertion runs in the SAME TICK as the click, with no await
 *    between: anything reintroduced between the click and `window.open()`
 *    leaves the popup unopened at the assertion, which is exactly what a popup
 *    blocker would do;
 *  - the click's own round trips are asserted exhaustively, so a mint or a
 *    lookup moved onto the launch path fails rather than reading as green;
 *  - the country param is read back off the URL under a component whose own
 *    `countryCode()` throws, so sourcing it from the DOM-fed value fails;
 *  - the busy flag and the abandon callback are read after driving the real
 *    watcher tick, never by asserting a method exists.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const {
    loadAmdModule,
    loadCompanyCapture,
    defaultMocks,
    loadCompanySearchPanel,
    dispatchNative,
    brandConfigMock,
    quoteAddress,
    makeObservable
} = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';

const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';
const CHECKOUT_API_URL = 'https://api.example';
const TOKEN_REFRESH_INTERVAL_MS = 30 * 60 * 1000;
const POPUP_CLOSE_POLL_MS = 300;

/**
 * The mocks and sandbox globals both loaders share, plus the recorders they
 * write into.
 *
 * `setInterval` is recorded rather than run: the production intervals are a
 * 30-minute token refresh and a 300ms popup-close poll, and driving their
 * callbacks by hand is what lets a test assert which one it ticked.
 *
 * @param {object} [options] `{ billingAddress, companyTypes }`
 * @returns {object} `{ rec, identity, mocks, globals }`
 */
function makeEnv(options) {
    const opts = options || {};
    const rec = {
        opened: [],
        handles: [],
        intervals: [],
        cleared: [],
        errors: [],
        messageListeners: [],
        fetched: [],
        adopted: [],
        abandons: [],
        tokenMints: 0,
        /** Flipped mid-test to model a browser blocking the popup. */
        blocked: false
    };
    let intervalSeq = 0;

    const fakeWindow = {
        open: function (url, target, features) {
            rec.opened.push({ url: url, target: target, features: features });
            if (rec.blocked) return null;
            const handle = { closed: false, close: function () { this.closed = true; } };
            rec.handles.push(handle);
            return handle;
        },
        addEventListener: function (name, fn) { rec.messageListeners.push({ name: name, fn: fn }); },
        removeEventListener: function () {}
    };

    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: (function () {
            const address = 'billingAddress' in opts ? opts.billingAddress : { countryId: 'GB' };
            return address ? quoteAddress(address) : makeObservable(address);
        })(),
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
            checkoutApiUrl: CHECKOUT_API_URL,
            isCompanySearchEnabled: true,
            supportedCompanyTypes: opts.companyTypes || { gb: ['SOLE_TRADER'] }
        }),
        'Magento_Ui/js/model/messageList': {
            addErrorMessage: function (message) { rec.errors.push(message); },
            addSuccessMessage: function () {}
        }
    };

    const globals = {
        document: document,
        window: fakeWindow,
        btoa: global.btoa,
        setInterval: function (fn, ms) {
            intervalSeq += 1;
            rec.intervals.push({ id: intervalSeq, fn: fn, ms: ms });
            return intervalSeq;
        },
        clearInterval: function (id) { rec.cleared.push(id); },
        fetch: function (requestUrl) {
            rec.fetched.push(String(requestUrl));
            if (String(requestUrl).indexOf('get-tokens') !== -1) {
                rec.tokenMints += 1;
                return Promise.resolve({
                    ok: true,
                    json: function () {
                        return Promise.resolve([
                            { delegation_token: `dt-${rec.tokenMints}`, autofill_token: `at-${rec.tokenMints}` }
                        ]);
                    }
                });
            }
            return Promise.resolve({ ok: false, status: 404 });
        }
    };

    return { rec: rec, mocks: mocks, globals: globals };
}

/**
 * The real flow over Luma's wired capture component, for everything that does
 * not need the component's chips.
 *
 * The component is deliberately NOT booted: a boot would mint tokens of its
 * own, and the cases below set the token state they are about.
 *
 * @param {object} [options] forwarded to makeEnv()
 * @returns {object} `{ flow, rec, identity, component }`
 */
function loadFlow(options) {
    const env = makeEnv(options);
    const SoleTraderCtor = loadAmdModule(SOLE_TRADER, env.mocks, env.globals);
    const component = loadCompanyCapture(env.mocks, env.globals).shipping;
    component.adoptSoleTrader = function (buyer) { env.rec.adopted.push(buyer); };
    component.abandonSoleTrader = function () { env.rec.abandons.push(true); };
    const flow = new SoleTraderCtor(component);
    return { flow: flow, rec: env.rec, identity: component.identity(), component: component };
}

/**
 * The real component with the real flow underneath it, booted against a
 * payment-tile company field so the chips exist to be clicked.
 *
 * The REAL panel, not the harness default: the chips live inside the popover
 * now, so an inert panel renders none of them and every chip case below would
 * be reaching past the click handler it exists to drive.
 *
 * @param {object} [options] forwarded to makeEnv()
 * @returns {Promise<object>} `{ component, flow, rec, identity }`
 */
async function startStack(options) {
    document.body.innerHTML =
        '<form id="two_gateway_form">' +
        '<div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" />' +
        '</div></div></form>';
    const env = makeEnv(options);
    const mocks = Object.assign({}, env.mocks, {
        'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel(
            $,
            env.mocks['Two_Gateway/js/model/company-search'],
            env.globals
        )
    });
    const component = loadCompanyCapture(mocks, env.globals).shipping;
    component.start();
    // The availability answer is seeded, so one macrotask turn is enough for it
    // and the mint it triggers to settle.
    await new Promise((resolve) => setTimeout(resolve, 0));
    return { component: component, flow: component.soleTrader(), rec: env.rec, identity: component.identity() };
}

/**
 * Open the popover the buyer's own way and hand back one of its chips.
 *
 * Clicking the field first is not setup, it is the flow: the panel is `hidden`
 * until then, so a chip taken without it is one no buyer could have reached.
 *
 * @param {string} mode
 * @returns {Element}
 */
function chip(mode) {
    dispatchNative($('#two_gateway_form input#company_name')[0], 'mousedown');
    const node = document.querySelector(`.two-company-mode-chip[data-two-chip="${mode}"]`);
    expect(node).not.toBeNull();
    expect(node.closest('.two-company-dropdown').hasAttribute('hidden')).toBe(false);
    expect(node.classList.contains('two-hidden')).toBe(false);
    return node;
}

function readSource() {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', SOLE_TRADER), 'utf8');
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('the tokens are minted on availability, never on the click', () => {
    test('booting a sole-trader country mints without a chip being clicked', async () => {
        const { flow, rec } = await startStack();

        expect(rec.tokenMints).toBe(1);
        expect(flow.hasSignupTokens()).toBe(true);
        expect(rec.opened).toEqual([]);
    });

    test('a country whose registry offers no sole trader mints nothing', async () => {
        const { flow, rec } = await startStack({ companyTypes: { gb: ['LIMITED_COMPANY'] } });

        expect(rec.tokenMints).toBe(0);
        expect(flow.hasSignupTokens()).toBe(false);
    });

    test('the buyer lookup goes out on availability, and the chip click spends no round trip at all', async () => {
        const { rec } = await startStack();
        const mintsBeforeClick = rec.tokenMints;
        const fetchesBeforeClick = rec.fetched.length;
        expect(rec.fetched).toContainEqual(expect.stringContaining('/autofill/v1/buyer/current'));

        chip('soletrader').click();

        // Read in the same tick as the click: anything reintroduced between
        // the two leaves this empty, which is what a popup blocker sees too.
        expect(rec.opened).toHaveLength(1);
        expect(rec.fetched.slice(fetchesBeforeClick)).toEqual([]);
        expect(rec.tokenMints).toBe(mintsBeforeClick);
    });
});

describe('what the signup URL carries', () => {
    /**
     * @param {object} [options] forwarded to loadFlow()
     * @returns {object} `{ flow, rec, identity, component }` with tokens already held
     */
    function mintedFlow(options) {
        const loaded = loadFlow(options);
        loaded.flow.delegationToken = 'dt-1';
        loaded.flow.autofillToken = 'at-1';
        return loaded;
    }

    test('both tokens, on the hosted signup path', () => {
        const { flow, rec } = mintedFlow();

        flow.openPopup();

        const url = new URL(rec.opened[0].url);
        expect(url.origin + url.pathname).toBe(`${CHECKOUT_PAGE_URL}/soletrader/signup`);
        expect(url.searchParams.get('businessToken')).toBe('dt-1');
        expect(url.searchParams.get('autofillToken')).toBe('at-1');
        expect(url.searchParams.get('autofillData')).toBeTruthy();
    });

    test('the 700x805 the hosted flow needs, and not the 610 that clipped it', () => {
        const { flow, rec } = mintedFlow();

        flow.openPopup();

        expect(rec.opened[0].features).toContain('width=700');
        expect(rec.opened[0].features).toContain('height=805');
        expect(rec.opened[0].features).not.toContain('610');
    });

    test('the launch source carries no 610-wide window feature on any path', () => {
        // The behavioural case above only sees the one call site the fixture
        // reaches; this covers the value returning on a path no fixture takes.
        expect(readSource()).not.toContain('width=610');
    });

    test.each([
        ['US', { countryId: 'US' }, 'US'],
        ['a lower-cased quote value is upper-cased', { countryId: 'gb' }, 'GB'],
        ['no billing country resolved', {}, null],
        ['no billing address at all', null, null]
    ])('the country param (%s)', (_description, billingAddress, expected) => {
        const { flow, rec } = mintedFlow({ billingAddress: billingAddress });

        flow.openPopup();

        expect(new URL(rec.opened[0].url).searchParams.get('country')).toBe(expected);
    });

    test('the country comes from the quote, never from the host the DOM feeds', () => {
        // PDEV-4669: the popup only renders its country-specific identity step
        // when the URL says so, and a buyer must not be able to pick their own
        // verification flow by editing the address form.
        const { flow, rec, component } = mintedFlow({ billingAddress: { countryId: 'US' } });
        component.countryCode = function () {
            throw new Error('the DOM-fed country must not reach the signup URL');
        };

        flow.openPopup();

        expect(new URL(rec.opened[0].url).searchParams.get('country')).toBe('US');
    });

    test.each([
        ['both tokens missing', '', ''],
        ['business token missing', '', 'at-1'],
        ['autofill token missing', 'dt-1', '']
    ])('no popup opens while %s', (_description, delegationToken, autofillToken) => {
        const { flow, rec } = loadFlow();
        flow.delegationToken = delegationToken;
        flow.autofillToken = autofillToken;

        expect(flow.openPopup()).toBeNull();
        expect(rec.opened).toEqual([]);
    });

    test('a superseded popup is closed rather than left able to post a stale result', () => {
        const { flow, rec } = mintedFlow();

        flow.openPopup();
        flow.openPopup();

        expect(rec.handles).toHaveLength(2);
        expect(rec.handles[0].closed).toBe(true);
        expect(rec.handles[1].closed).toBe(false);
    });
});

describe('a blocked popup falls back to the on-page link', () => {
    test.each([
        [{ autoselect: false }, 'false', 'a blocked replacement retries with autoselect still off'],
        [undefined, null, 'a blocked first signup retries with no autoselect param']
    ])('%p -> autoselect=%p (%s)', async (launchOptions, expectedAutoselect) => {
        const { flow, rec } = await startStack();
        rec.blocked = true;

        flow.launchSignup(launchOptions);

        const note = document.querySelector('.two-sole-trader-note');
        expect(note).not.toBeNull();
        expect(note.classList.contains('two-hidden')).toBe(false);
        expect(rec.opened).toHaveLength(1);

        rec.blocked = false;
        note.querySelector('.two-sole-trader-note__link').click();

        expect(rec.opened).toHaveLength(2);
        expect(new URL(rec.opened[1].url).searchParams.get('autoselect')).toBe(expectedAutoselect);
    });

    test('the note is reachable after the chip click that closes the popover', async () => {
        // Given: the chip closes the panel on its way to signup.
        // When: that signup is blocked.
        // Then: the note must not be anchored inside what just closed.
        const { rec } = await startStack();
        rec.blocked = true;

        chip('soletrader').click();

        const note = document.querySelector('.two-sole-trader-note');
        expect(note).not.toBeNull();
        expect(note.classList.contains('two-hidden')).toBe(false);
        expect(note.closest('.two-company-dropdown')).toBeNull();
    });

    test('a popup that opened leaves the fallback link withdrawn', async () => {
        const { flow } = await startStack();

        flow.launchSignup();

        const note = document.querySelector('.two-sole-trader-note');
        expect(note === null || note.classList.contains('two-hidden')).toBe(true);
    });
});

describe('the token refresh', () => {
    test('runs on a 30-minute interval once tokens are held', async () => {
        const { rec } = await startStack();

        const refresh = rec.intervals.filter((entry) => entry.ms === TOKEN_REFRESH_INTERVAL_MS);
        expect(refresh).toHaveLength(1);
    });

    test('a tick re-mints, but is skipped while a round trip is outstanding', async () => {
        const { rec, identity } = await startStack();
        const refresh = rec.intervals.find((entry) => entry.ms === TOKEN_REFRESH_INTERVAL_MS);
        const mintsBefore = rec.tokenMints;

        identity.beginFlight();
        refresh.fn();
        expect(rec.tokenMints).toBe(mintsBefore);

        identity.settleFlight();
        refresh.fn();
        expect(rec.tokenMints).toBe(mintsBefore + 1);
    });
});

describe('the popup-close watcher', () => {
    /**
     * @returns {object} `{ flow, rec, identity, poll, handle }` with a popup open
     */
    function openedFlow() {
        const loaded = loadFlow();
        loaded.flow.delegationToken = 'dt-1';
        loaded.flow.autofillToken = 'at-1';
        loaded.flow.openPopup();
        return Object.assign({}, loaded, {
            poll: loaded.rec.intervals.find((entry) => entry.ms === POPUP_CLOSE_POLL_MS),
            handle: loaded.rec.handles[0]
        });
    }

    test('an open popup holds the checkout busy', () => {
        const { identity, poll } = openedFlow();

        expect(poll).toBeDefined();
        expect(identity.isBusy()).toBe(true);
    });

    test.each([
        [false, 1, 'the buyer closed it having captured nothing'],
        [true, 0, 'the handshake lookup is still out and owns the outcome']
    ])('confirming=%p -> %p abandon calls (%s)', (confirming, expectedAbandons) => {
        const { flow, rec, poll, handle } = openedFlow();
        flow._signupConfirming = confirming;

        handle.closed = true;
        poll.fn();

        expect(rec.abandons).toHaveLength(expectedAbandons);
    });

    test('a poll while the popup is still open decides nothing', () => {
        const { rec, poll, identity } = openedFlow();

        poll.fn();

        expect(rec.abandons).toEqual([]);
        expect(identity.isBusy()).toBe(true);
    });

    test('stopping the watcher settles the flight it was holding', () => {
        const { flow, identity } = openedFlow();

        flow.stopPopupCloseWatcher();

        expect(identity.isBusy()).toBe(false);
    });

    test('a superseding launch settles the watcher it replaced, so busy cannot stack', () => {
        const { flow, identity } = openedFlow();

        flow.openPopup();
        flow.stopPopupCloseWatcher();

        expect(identity.isBusy()).toBe(false);
    });

    test('dispose releases the watcher and the refresh', () => {
        const { flow, rec, identity } = openedFlow();
        flow.startTokenRefresh();

        flow.dispose();

        expect(identity.isBusy()).toBe(false);
        expect(rec.cleared).toHaveLength(2);
    });
});

describe('the removed in-page iframe modal leaves nothing behind', () => {
    test('no hideIframe reference survives in the flow', () => {
        expect(readSource()).not.toContain('hideIframe');
    });
});
