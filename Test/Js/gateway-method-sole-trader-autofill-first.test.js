/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-40 — the sole-trader chip consults the buyer's own Two session before it
 * reaches for the hosted signup, so a buyer Two already knows never sees the
 * popup.
 *
 * Mutation-resistance notes:
 *
 *  - the silent-adoption cases assert the popup count is ZERO, not merely that
 *    a name landed, so falling through to the popup as well as adopting fails;
 *  - every fall-through case asserts the lookup DID go out before the popup
 *    opened, so deleting the autofill call and passing on the popup assertions
 *    alone is not green;
 *  - "select a different sole trader" is pinned on the lookup COUNT, not just
 *    on the popup opening: routing that link through the autofill check would
 *    still open a popup on a 404, and only the count catches it;
 *  - the usable-record rule is driven with a real nameless buyer rather than by
 *    asserting a predicate exists.
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

/**
 * @param {object} [options] `{ buyer, failLookup }` — the record the buyer
 *        endpoint answers with (omit for a 404), or a transport failure
 * @returns {object} `{ rec, mocks, globals }`
 */
function makeEnv(options) {
    const opts = options || {};
    const rec = { opened: [], lookups: 0, tokenMints: 0, errors: [] };

    const fakeWindow = {
        open: function (url) {
            rec.opened.push({ url: url });
            return { closed: false, close: function () { this.closed = true; } };
        },
        addEventListener: function () {},
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
            checkoutApiUrl: CHECKOUT_API_URL,
            isCompanySearchEnabled: true,
            supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
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
                const answer = opts.buyer
                    ? { ok: true, json: function () { return Promise.resolve(opts.buyer); } }
                    : { ok: false, status: 404 };
                if (!opts.deferLookup) return Promise.resolve(answer);
                // Held open so a test can act on the checkout mid-flight.
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
    // Lets the seeded availability answer and the mint it triggers settle.
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

/** Click the sole-trader chip and let the autofill round trip settle. */
async function clickSoleTrader() {
    chip('soletrader').click();
    await settle();
}

function differentTraderLink() {
    return document.querySelector('.two-select-different-sole-trader__link');
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('a session Two already knows skips the popup', () => {
    test('the chip adopts the autofilled sole trader and opens no popup', async () => {
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

    test('a silent adoption leaves no error in front of the buyer', async () => {
        const { rec } = await startStack({ buyer: BUYER });

        await clickSoleTrader();

        expect(rec.errors).toEqual([]);
    });
});

describe('anything less than a usable record falls through to the popup', () => {
    test.each([
        [{}, 'the session identifies no buyer at all'],
        [{ failLookup: true }, 'the lookup fails in transport'],
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

        await clickSoleTrader();

        // The lookup went out FIRST: a deleted autofill call opens the popup
        // too, and only the count tells the two apart.
        expect(rec.lookups).toBe(1);
        expect(rec.opened).toHaveLength(1);
        expect(rec.opened[0].url).toContain(`${CHECKOUT_PAGE_URL}/soletrader/signup`);
        expect(identity.soleTraderAdopted()).toBe(false);
    });

    test('the fall-through popup carries no autoselect param, as a first launch', async () => {
        const { rec } = await startStack();

        await clickSoleTrader();

        expect(new URL(rec.opened[0].url).searchParams.get('autoselect')).toBeNull();
    });
});

describe('"select a different sole trader" never consults autofill', () => {
    test('the link opens the popup even though autofill would answer', async () => {
        const { rec } = await startStack({ buyer: BUYER });

        await clickSoleTrader();
        const lookupsAfterAdoption = rec.lookups;
        expect(differentTraderLink()).not.toBeNull();

        differentTraderLink().click();
        await settle();

        // The count, not just the popup: routing this link through the autofill
        // check would still open a popup whenever the lookup missed.
        expect(rec.lookups).toBe(lookupsAfterAdoption);
        expect(rec.opened).toHaveLength(1);
        expect(new URL(rec.opened[0].url).searchParams.get('autoselect')).toBe('false');
    });

    test('the flow entry point itself makes no lookup', async () => {
        const { flow, rec } = await startStack({ buyer: BUYER });

        flow.selectDifferentSoleTrader();
        await settle();

        expect(rec.lookups).toBe(0);
        expect(rec.opened).toHaveLength(1);
    });

    test('re-clicking the chip once adopted goes straight to the popup too', async () => {
        const { rec } = await startStack({ buyer: BUYER });

        await clickSoleTrader();
        const lookupsAfterAdoption = rec.lookups;

        await clickSoleTrader();

        expect(rec.lookups).toBe(lookupsAfterAdoption);
        expect(rec.opened).toHaveLength(1);
        expect(new URL(rec.opened[0].url).searchParams.get('autoselect')).toBe('false');
    });
});

describe('a lookup still in flight cannot overwrite what the buyer does next', () => {
    test.each([
        ['registeredMode', 'registered'],
        ['manualEntryMode', 'manual']
    ])('leaving for %s mid-lookup adopts nothing when it lands', async (leave, mode) => {
        const { component, identity, rec } = await startStack({ buyer: BUYER, deferLookup: true });
        await clickSoleTrader();
        expect(rec.lookups).toBe(1);

        component[leave]();
        rec.releaseLookup();
        await settle();

        expect(identity.captureMode()).toBe(mode);
        expect(identity.companyName()).toBe('');
        expect(identity.soleTraderAdopted()).toBe(false);
        // The mode the buyer left is not one to raise a signup for either.
        expect(rec.opened).toEqual([]);
    });

    test('the checkout is not left busy by a lookup that adopted nothing', async () => {
        const { component, identity, rec } = await startStack({ buyer: BUYER, deferLookup: true });
        await clickSoleTrader();

        component.registeredMode();
        rec.releaseLookup();
        await settle();

        expect(identity.isBusy()).toBe(false);
    });

    test('a double click makes one lookup and opens one popup', async () => {
        const { rec } = await startStack();

        chip('soletrader').click();
        chip('soletrader').click();
        await settle();

        expect(rec.lookups).toBe(1);
        expect(rec.opened).toHaveLength(1);
    });

    test('re-clicking after the fall-through popup does not ask autofill again', async () => {
        const { rec } = await startStack();

        await clickSoleTrader();
        expect(rec.lookups).toBe(1);

        await clickSoleTrader();

        expect(rec.lookups).toBe(1);
    });

    test('leaving and re-entering the mode does ask again', async () => {
        const { component, rec } = await startStack();

        await clickSoleTrader();
        component.registeredMode();
        await clickSoleTrader();

        expect(rec.lookups).toBe(2);
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

    test('without tokens the chip skips the lookup rather than minting inside the click', async () => {
        const { component, flow, rec } = await startStack({ buyer: BUYER });
        flow.delegationToken = '';
        flow.autofillToken = '';
        await component.soleTraderMode();

        expect(rec.lookups).toBe(0);
        // No tokens means no popup either, so the on-page link is the way back.
        expect(rec.opened).toEqual([]);
        expect(document.querySelector('.two-sole-trader-note')).not.toBeNull();
    });
});
