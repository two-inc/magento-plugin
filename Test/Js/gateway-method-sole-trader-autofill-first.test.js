/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-40 — the buyer's own Two session is looked up as soon as sole trader is
 * on offer, and the chip then decides on the answer it already holds: adopt it
 * silently, or open the hosted signup inside the click itself.
 *
 * Mutation-resistance notes:
 *
 *  - the first silent-adoption case asserts the popup count is ZERO rather
 *    than only that a name landed, so adopting AND falling through to the
 *    popup fails;
 *  - the fall-through popup is asserted in the SAME TICK as the click, with
 *    nothing awaited between them, so anything reintroduced between the click
 *    and the open — a mint, a lookup, a promise hop — fails;
 *  - wherever an unasked question would look like a missing answer, the case
 *    pins the lookup COUNT too: a click that asks again, a boot that stops
 *    asking, a re-arm that never fires, and a "select a different sole trader"
 *    routed through the held record are each caught by the count alone;
 *  - the usable-record rule is driven with a real nameless buyer rather than by
 *    asserting a predicate exists;
 *  - supersession is driven with a second, distinguishable record, so a held
 *    answer re-adopted over the identity that won is visible in the field.
 */

'use strict';

const $ = require('jquery');
const {
    loadCompanyCapture,
    defaultMocks,
    loadCompanySearchPanel,
    dispatchNative,
    brandConfigMock,
    quoteAddress
} = require('./amd-harness');

const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';
const CHECKOUT_API_URL = 'https://api.example';
const BUYER_ENDPOINT = '/autofill/v1/buyer/current';

const BUYER = {
    email: 'trader@example.com',
    organization_number: '999888777',
    company_name: 'Example Trader',
    phone_number: '+4479000000',
    billing_address: {
        streetAddress: '1 Trader Way',
        city: 'London',
        postalCode: 'E1 6AN',
        country: 'GB'
    }
};

/** A second, distinguishable record, so a clobbered identity is visible. */
const OTHER_TRADER = {
    email: 'other@example.com',
    organization_number: '111222333',
    company_name: 'Other Trader',
    phone_number: '+4479111111',
    billing_address: { streetAddress: '2 Other Way', city: 'Leeds', country: 'GB' }
};

/** Stand-in for the signup popup's own window, the only source that counts. */
const POPUP = { popup: 'the tracked signup window', closed: false, close: function () {} };

/**
 * @param {object} [options] `{ buyer, laterBuyer, failLookup, hangLookup,
 *        holdLookup, companyTypes }` — the record the buyer endpoint answers
 *        with (omit for a 404), a different record for every lookup after the
 *        first, a transport failure, a lookup that never lands, one held until
 *        `rec.releaseLookup()`, or the registry's per-country company types
 * @returns {object} `{ rec, mocks, globals }`
 */
function makeEnv(options) {
    const opts = options || {};
    const rec = {
        opened: [],
        lookups: 0,
        tokenMints: 0,
        errors: [],
        listeners: [],
        applied: [],
        phones: [],
        reverts: 0
    };

    const fakeWindow = {
        open: function (url) {
            rec.opened.push({ url: url });
            return { closed: false, close: function () { this.closed = true; } };
        },
        addEventListener: function (name, fn) { rec.listeners.push({ name: name, fn: fn }); },
        removeEventListener: function () {}
    };

    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: quoteAddress({ countryId: 'GB' }),
        getQuoteId: function () { return 'cart-1'; },
        isVirtual: function () { return false; }
    });

    const companySearch = Object.assign({}, defaultMocks()['Two_Gateway/js/model/company-search'], {
        apiClientParams: function () { return { client: 'magento' }; },
        currentAddressFormCountry: function () { return ''; },
        applyAddress: function (source) { rec.applied.push(source); },
        applyTelephone: function (phoneNumber) { rec.phones.push(phoneNumber); return true; },
        revertAutofilledAddress: function () { rec.reverts += 1; return 0; }
    });

    const mocks = {
        jquery: $,
        'Magento_Checkout/js/model/quote': quote,
        'Two_Gateway/js/model/company-search': companySearch,
        'Two_Gateway/js/model/brand-config': brandConfigMock({
            checkoutPageUrl: CHECKOUT_PAGE_URL,
            checkoutApiUrl: CHECKOUT_API_URL,
            isCompanySearchEnabled: true,
            supportedCompanyTypes: opts.companyTypes
                || { gb: ['SOLE_TRADER'], no: ['SOLE_TRADER'] }
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
        setInterval: function () { return 1; },
        clearInterval: function () {},
        fetch: function (requestUrl) {
            const url = String(requestUrl);
            if (url.indexOf('get-tokens') !== -1) {
                rec.tokenMints += 1;
                return Promise.resolve({
                    ok: true,
                    json: function () {
                        return Promise.resolve([{ delegation_token: 'dt', autofill_token: 'at' }]);
                    }
                });
            }
            if (url.indexOf(BUYER_ENDPOINT) !== -1) {
                rec.lookups += 1;
                if (opts.failLookup) return Promise.reject(new Error('offline'));
                if (opts.hangLookup) return new Promise(function () {});
                const record = rec.lookups > 1 && opts.laterBuyer ? opts.laterBuyer : opts.buyer;
                const answer = record
                    ? { ok: true, json: function () { return Promise.resolve(record); } }
                    : { ok: false, status: 404 };
                // The FIRST lookup only: a later one has to be able to answer
                // while the held one is still out.
                if (!opts.holdLookup || rec.lookups > 1) return Promise.resolve(answer);
                return new Promise((resolve) => { rec.releaseLookup = () => resolve(answer); });
            }
            return Promise.resolve({ ok: false, status: 404 });
        }
    };

    return { rec: rec, mocks: mocks, globals: globals };
}

/**
 * The real component, real panel and real flow, booted against a payment-tile
 * company field so the chips exist to be clicked.
 *
 * @param {object} [options] forwarded to makeEnv()
 * @returns {Promise<object>} `{ component, flow, identity, rec }`
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
    // Lets the seeded availability answer, the mint and the lookup settle.
    await settle();
    return {
        component: component,
        flow: component.soleTrader(),
        identity: component.identity(),
        rec: env.rec
    };
}

function settle() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** Open the popover the buyer's own way and hand back one of its chips. */
function chip(mode) {
    dispatchNative($('#two_gateway_form input#company_name')[0], 'mousedown');
    const node = document.querySelector(`.two-company-mode-chip[data-two-chip="${mode}"]`);
    expect(node).not.toBeNull();
    return node;
}

/** Click the sole-trader chip and let any write it triggers settle. */
async function clickSoleTrader() {
    chip('soletrader').click();
    await settle();
}

/** The one `message` listener the flow arms, to drive the real handshake. */
function messageHandler(rec) {
    const bound = rec.listeners.filter((entry) => entry.name === 'message');
    expect(bound).toHaveLength(1);
    return bound[0].fn;
}

function differentTraderLink() {
    return document.querySelector('.two-select-different-sole-trader__link');
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('the lookup runs on availability, ahead of any click', () => {
    test('booting a sole-trader country looks the buyer up with no chip clicked', async () => {
        const { flow, rec } = await startStack({ buyer: BUYER });

        expect(rec.lookups).toBe(1);
        expect(rec.opened).toEqual([]);
        expect(flow.autofilledSoleTrader()).toEqual(BUYER);
    });

    test('a country whose registry offers no sole trader looks nobody up', async () => {
        const { rec } = await startStack({ buyer: BUYER, companyTypes: { gb: ['LIMITED_COMPANY'] } });

        expect(rec.tokenMints).toBe(0);
        expect(rec.lookups).toBe(0);
    });
});

describe('a session Two already knows skips the popup', () => {
    test('the chip adopts the held record and opens no popup', async () => {
        const { identity, rec } = await startStack({ buyer: BUYER });

        await clickSoleTrader();

        expect(rec.lookups).toBe(1);
        expect(rec.opened).toEqual([]);
        expect(identity.companyName()).toBe('Example Trader');
        expect(identity.companyId()).toBe('999888777');
        expect(identity.isSoleTrader()).toBe(true);
        expect(identity.soleTraderAdopted()).toBe(true);
    });

    test('the adopted name reaches the company field', async () => {
        await startStack({ buyer: BUYER });

        await clickSoleTrader();

        expect($('#two_gateway_form input#company_name').val()).toBe('Example Trader');
    });

    test('the adopted address and phone reach the checkout form', async () => {
        const { rec } = await startStack({ buyer: BUYER });

        await clickSoleTrader();

        expect(rec.applied).toEqual([BUYER.billing_address]);
        expect(rec.phones).toEqual([BUYER.phone_number]);
    });

    test('a silent adoption leaves no error in front of the buyer', async () => {
        const { rec } = await startStack({ buyer: BUYER });

        await clickSoleTrader();

        expect(rec.errors).toEqual([]);
    });

    test('a silent adoption does not leave the checkout busy', async () => {
        const { identity } = await startStack({ buyer: BUYER });

        await clickSoleTrader();

        expect(identity.isBusy()).toBe(false);
    });
});

describe('anything less than a usable record falls through to the popup', () => {
    test.each([
        [{}, 'the session identifies no buyer at all'],
        [{ failLookup: true }, 'the lookup fails in transport'],
        [{ hangLookup: true }, 'the lookup has not landed when the buyer clicks'],
        [
            { buyer: { email: 'nameless@example.com', organization_number: '111' } },
            'the record carries no company name, which would blank the field'
        ],
        [
            { buyer: Object.assign({}, BUYER, { company_name: '   ' }) },
            'the record carries a whitespace-only company name'
        ]
    ])('%p -> the popup opens (%s)', async (options) => {
        const { identity, rec } = await startStack(options);

        // Same tick as the click, nothing awaited: a popup a blocker allows is
        // one opened inside the gesture, and only this ordering pins that.
        chip('soletrader').click();
        expect(rec.opened).toHaveLength(1);
        expect(rec.opened[0].url).toContain(`${CHECKOUT_PAGE_URL}/soletrader/signup`);

        await settle();
        expect(rec.lookups).toBe(1);
        expect(identity.soleTraderAdopted()).toBe(false);
        expect(identity.companyName()).toBe('');
        expect(rec.applied).toEqual([]);
        expect(rec.phones).toEqual([]);
    });

    test('the fall-through popup carries no autoselect param, as a first launch', async () => {
        const { rec } = await startStack();

        await clickSoleTrader();

        expect(new URL(rec.opened[0].url).searchParams.get('autoselect')).toBeNull();
    });

    test('a fall-through click leaves nothing busy once its popup is gone', async () => {
        const { flow, identity } = await startStack();

        await clickSoleTrader();
        // The open popup holds a flight of its own, so releasing that is what
        // exposes whether anything else was left outstanding.
        flow.stopPopupCloseWatcher();

        expect(identity.isBusy()).toBe(false);
    });
});

describe('"select a different sole trader" never consults the held record', () => {
    test('the link opens the popup even though the held record would answer', async () => {
        const { flow, identity, rec } = await startStack({
            buyer: BUYER,
            laterBuyer: OTHER_TRADER
        });

        await clickSoleTrader();
        expect(differentTraderLink()).not.toBeNull();
        // The adoption spent the boot answer, so arm another to sit in front
        // of the link the buyer is about to click.
        flow.forgetAutofilledBuyer();
        await flow.prefetchBuyer();
        const lookupsWithAnswerHeld = rec.lookups;

        differentTraderLink().click();
        await settle();

        expect(rec.opened).toHaveLength(1);
        expect(new URL(rec.opened[0].url).searchParams.get('autoselect')).toBe('false');
        // Neither adopting the answer, which would hand back a trader the
        // buyer asked to replace, nor going looking for one.
        expect(identity.companyName()).toBe(BUYER.company_name);
        expect(rec.lookups).toBe(lookupsWithAnswerHeld);
    });

    test('the flow entry point itself makes no lookup', async () => {
        const { flow, rec } = await startStack({ buyer: BUYER });
        const lookupsAtBoot = rec.lookups;

        flow.selectDifferentSoleTrader();
        await settle();

        expect(rec.lookups).toBe(lookupsAtBoot);
        expect(rec.opened).toHaveLength(1);
    });

    test('re-clicking the chip once adopted goes straight to the popup too', async () => {
        const { identity, rec } = await startStack({ buyer: BUYER });

        await clickSoleTrader();
        const lookupsAfterAdoption = rec.lookups;

        await clickSoleTrader();

        expect(rec.lookups).toBe(lookupsAfterAdoption);
        expect(rec.opened).toHaveLength(1);
        expect(new URL(rec.opened[0].url).searchParams.get('autoselect')).toBe('false');
        expect(identity.companyName()).toBe(BUYER.company_name);
    });

    test('re-clicking after the fall-through popup asks nobody again', async () => {
        const { rec } = await startStack();

        await clickSoleTrader();
        await clickSoleTrader();

        expect(rec.lookups).toBe(1);
    });
});

describe('an adoption supersedes the held record', () => {
    test('the record is dropped as it is adopted, so nothing can re-adopt it', async () => {
        const { flow } = await startStack({ buyer: BUYER });
        expect(flow.autofilledSoleTrader()).toEqual(BUYER);

        flow.adoptBuyer(BUYER);

        expect(flow.autofilledSoleTrader()).toBeNull();
    });

    test('a handshake identity is what the checkout is left holding', async () => {
        const { flow, identity, rec } = await startStack({ laterBuyer: OTHER_TRADER });
        await clickSoleTrader();
        // The fall-through popup's own watcher flight is released first, so
        // what is left outstanding at the end is the handshake's alone.
        flow.stopPopupCloseWatcher();
        flow._popupWindow = POPUP;

        messageHandler(rec)({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();

        expect(identity.companyName()).toBe(OTHER_TRADER.company_name);
        expect(identity.companyId()).toBe(OTHER_TRADER.organization_number);
        expect(flow.autofilledSoleTrader()).toBeNull();
        expect(identity.isBusy()).toBe(false);
    });
});

describe('a country change re-arms the lookup', () => {
    test('a change before any country was recorded still retires the answer', async () => {
        const { component, identity, rec } = await startStack({
            buyer: BUYER,
            laterBuyer: OTHER_TRADER
        });
        // The sidebar boot: the mount observer resolves availability, and so
        // holds an answer, before any address form has given up a country.
        component._lastCountry = '';

        component.onCountryChanged('no');
        await settle();
        await clickSoleTrader();

        expect(rec.lookups).toBe(2);
        expect(identity.companyName()).toBe(OTHER_TRADER.company_name);
    });

    test('the new country is looked up afresh and the retired record is not adopted', async () => {
        const { component, identity, rec } = await startStack({
            buyer: BUYER,
            laterBuyer: OTHER_TRADER
        });

        component.onCountryChanged('no');
        await settle();
        expect(rec.lookups).toBe(2);
        expect(rec.reverts).toBeGreaterThan(0);

        await clickSoleTrader();

        expect(identity.companyName()).toBe(OTHER_TRADER.company_name);
        expect(rec.applied).toEqual([OTHER_TRADER.billing_address]);
        expect(rec.opened).toEqual([]);
    });
});

describe('leaving the mode keeps the answer the session still stands behind', () => {
    test.each([
        ['registeredMode', 'back to company search'],
        ['manualEntryMode', 'to manual entry'],
        ['abandonSoleTrader', 'by closing the popup with nothing captured']
    ])('an answer already held survives the buyer leaving via %s (%s)', async (leave) => {
        // The buyer clicks before the lookup lands, so they get the popup;
        // the answer arrives while they are still in the mode, and only then
        // do they leave it.
        const { component, identity, rec } = await startStack({
            buyer: BUYER,
            holdLookup: true
        });
        await clickSoleTrader();
        expect(rec.opened).toHaveLength(1);

        rec.releaseLookup();
        await settle();
        component[leave]();
        await clickSoleTrader();

        // Leaving the mode says nothing about who the session identifies, so
        // discarding the answer here turned the feature off for the whole page.
        expect(identity.companyName()).toBe(BUYER.company_name);
        expect(rec.opened).toHaveLength(1);
        expect(rec.lookups).toBe(1);
    });

    test.each([
        ['registeredMode', 'back to company search'],
        ['manualEntryMode', 'to manual entry']
    ])('the lookup in flight when the buyer left via %s is retired for a fresh one (%s)', async (leave) => {
        const { component, identity, rec } = await startStack({
            buyer: BUYER,
            laterBuyer: OTHER_TRADER,
            holdLookup: true
        });
        await clickSoleTrader();
        expect(rec.opened).toHaveLength(1);

        component[leave]();
        await settle();
        rec.releaseLookup();
        await settle();
        await clickSoleTrader();

        expect(rec.lookups).toBe(2);
        // The retired lookup never writes: what the buyer gets is the answer
        // armed after they left, not the one they clicked past.
        expect(identity.companyName()).toBe(OTHER_TRADER.company_name);
        expect(rec.opened).toHaveLength(1);
    });
});

describe('a lookup in flight cannot resurrect a replaced identity', () => {
    test('a boot answer landing after the signup adopted another is discarded', async () => {
        const { component, flow, identity, rec } = await startStack({
            buyer: BUYER,
            laterBuyer: OTHER_TRADER,
            holdLookup: true
        });
        await clickSoleTrader();
        // Set directly: opening one would arm the close watcher, whose own
        // flight would mask the handshake's.
        flow._popupWindow = POPUP;

        // The buyer enrols as somebody else while the boot lookup is still out.
        messageHandler(rec)({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED', source: POPUP });
        await settle();
        rec.releaseLookup();
        await settle();

        expect(identity.companyName()).toBe(OTHER_TRADER.company_name);
        expect(flow.autofilledSoleTrader()).toBeNull();

        component.registeredMode();
        await settle();
        await clickSoleTrader();

        // The trader the signup replaced must not come back on a later click.
        expect(identity.companyName()).not.toBe(BUYER.company_name);
    });
});

describe('a spent answer is replaced, not left absent', () => {
    test('a country that stopped offering sole traders arms no lookup on the way out', async () => {
        const { component, identity, rec } = await startStack({ buyer: BUYER });
        await clickSoleTrader();
        const lookupsAfterAdoption = rec.lookups;
        // The shape refreshSoleTraderAvailability leaves behind: availability
        // already false, the mode not yet retired.
        identity.soleTraderAvailable(false);

        component.registeredMode();
        await settle();

        // An answer held here belongs to a registry no chip can reach.
        expect(rec.lookups).toBe(lookupsAfterAdoption);
    });

    test.each([
        ['registeredMode', 'back to company search'],
        ['manualEntryMode', 'to manual entry']
    ])('adopting then leaving via %s still adopts on re-entry (%s)', async (leave) => {
        const { component, identity, rec } = await startStack({
            buyer: BUYER,
            laterBuyer: OTHER_TRADER
        });

        await clickSoleTrader();
        expect(identity.companyName()).toBe(BUYER.company_name);

        component[leave]();
        await settle();
        await clickSoleTrader();

        // The adoption spends the answer, and only this exit re-arms the
        // lookup — without it the chip falls to the popup with an empty field
        // and the buyer is never looked up again for the life of the page.
        expect(rec.lookups).toBe(2);
        expect(identity.companyName()).toBe(OTHER_TRADER.company_name);
        expect(rec.opened).toEqual([]);
    });
});

describe('the click never waits on a mint', () => {
    test('no token request goes out on the click', async () => {
        const { rec } = await startStack({ buyer: BUYER });
        const mintsBeforeClick = rec.tokenMints;

        await clickSoleTrader();

        expect(mintsBeforeClick).toBe(1);
        expect(rec.tokenMints).toBe(mintsBeforeClick);
    });

    test('without tokens there is no popup either, so the on-page link is the way back', async () => {
        const { component, flow, rec } = await startStack();
        flow.delegationToken = '';
        flow.autofillToken = '';

        component.soleTraderMode();

        expect(rec.lookups).toBe(1);
        expect(rec.opened).toEqual([]);
        expect(document.querySelector('.two-sole-trader-note')).not.toBeNull();
    });
});
