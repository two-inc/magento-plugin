/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25653 — a mint that succeeds after a failed boot mint re-runs the buyer lookup the failed one dropped.
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
const MINT_RETRY_DELAY_MS = 3000;
const MINT_RETRY_CEILING_MS = 5000;

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
 * @param {Array<number|string>} mintOutcomes one per mint, last repeating: a status, or 'partial' for a 200 with no delegation token
 * @returns {object} `{ rec, mocks, globals }`
 */
function makeEnv(mintOutcomes) {
    const rec = { opened: [], lookups: 0, mints: 0, retries: [] };

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
        currentAddressFormCountry: function () { return ''; },
        applyAddress: function () {},
        applyTelephone: function () { return true; },
        revertAutofilledAddress: function () { return 0; }
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
            addErrorMessage: function () {},
            addSuccessMessage: function () {}
        }
    };

    const globals = {
        document: document,
        window: fakeWindow,
        btoa: global.btoa,
        setInterval: function () { return 1; },
        clearInterval: function () {},
        // The mint retry is recorded, not run; the panel's shorter waits pass through.
        setTimeout: function (fn, delay) {
            if (delay >= MINT_RETRY_DELAY_MS) {
                rec.retries.push({ fn: fn, delay: delay });
                return rec.retries.length;
            }
            return setTimeout(fn, delay);
        },
        fetch: function (requestUrl) {
            const url = String(requestUrl);
            if (url.indexOf('get-tokens') !== -1) {
                const outcome = mintOutcomes[Math.min(rec.mints, mintOutcomes.length - 1)];
                rec.mints += 1;
                if (outcome !== 200 && outcome !== 'partial') {
                    return Promise.resolve({ ok: false, status: outcome });
                }
                const delegation = outcome === 'partial' ? '' : 'dt';
                return Promise.resolve({
                    ok: true,
                    json: function () {
                        return Promise.resolve([
                            { delegation_token: delegation, autofill_token: 'at' }
                        ]);
                    }
                });
            }
            if (url.indexOf(BUYER_ENDPOINT) !== -1) {
                rec.lookups += 1;
                return Promise.resolve({ ok: true, json: function () { return Promise.resolve(BUYER); } });
            }
            return Promise.resolve({ ok: false, status: 404 });
        }
    };

    return { rec: rec, mocks: mocks, globals: globals };
}

/**
 * The real component, panel and flow, booted against a tile company field so the chips exist to be clicked.
 *
 * @param {Array<number|string>} mintOutcomes forwarded to makeEnv()
 * @returns {Promise<object>} `{ identity, rec }`
 */
async function startStack(mintOutcomes) {
    document.body.innerHTML =
        '<form id="two_gateway_form">' +
        '<div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" />' +
        '</div></div></form>';
    const env = makeEnv(mintOutcomes);
    const mocks = Object.assign({}, env.mocks, {
        'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel(
            $,
            env.mocks['Two_Gateway/js/model/company-search'],
            env.globals
        )
    });
    const component = loadCompanyCapture(mocks, env.globals).shipping;
    component.start();
    await settle();
    return { identity: component.identity(), rec: env.rec };
}

function settle() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** Click the sole-trader chip the buyer's own way and let any write settle. */
async function clickSoleTrader() {
    dispatchNative($('#two_gateway_form input#company_name')[0], 'mousedown');
    const node = document.querySelector('.two-company-mode-chip[data-two-chip="soletrader"]');
    expect(node).not.toBeNull();
    node.click();
    await settle();
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('the buyer lookup a failed boot mint dropped', () => {
    test.each([
        {
            label: 'a 503 boot mint, re-run by the mint the chip itself makes',
            mintOutcomes: [503, 200],
            act: 'click',
            retries: 1,
            mints: 2,
            lookups: 1,
            companyName: 'Example Trader',
            popupsProvable: true
        },
        {
            label: 'a 503 boot mint, re-run by the bounded retry ahead of any click',
            mintOutcomes: [503, 200],
            act: 'retry',
            retries: 1,
            mints: 2,
            lookups: 1,
            companyName: 'Example Trader',
            popupsProvable: true
        },
        {
            // 429 is a 4xx about the moment, not about the request.
            label: 'a rate-limited boot mint, which is still retried',
            mintOutcomes: [429, 200],
            act: 'retry',
            retries: 1,
            mints: 2,
            lookups: 1,
            companyName: 'Example Trader',
            popupsProvable: true
        },
        {
            label: 'a 200 whose delegation token is empty, which does not block the lookup',
            mintOutcomes: ['partial', 200],
            act: 'none',
            retries: 1,
            mints: 1,
            lookups: 1,
            companyName: 'Example Trader',
            popupsProvable: true
        },
        {
            label: 'a clean boot, which asks exactly once and schedules nothing',
            mintOutcomes: [200],
            act: 'none',
            retries: 0,
            mints: 1,
            lookups: 1,
            companyName: 'Example Trader',
            popupsProvable: true
        },
        {
            label: 'a 503 boot mint whose retry also fails, which is not retried again',
            mintOutcomes: [503, 503],
            act: 'retry',
            retries: 1,
            mints: 2,
            lookups: 0,
            companyName: '',
            popupsProvable: false
        },
        {
            // Guard row: holds with the re-run reverted, and pins that a settled failure schedules nothing.
            label: 'a 400 boot mint, which is not retried at all',
            mintOutcomes: [400],
            act: 'none',
            retries: 0,
            mints: 1,
            lookups: 0,
            companyName: '',
            popupsProvable: false
        }
    ])('$label', async (row) => {
        const { identity, rec } = await startStack(row.mintOutcomes);

        if (row.act === 'click') await clickSoleTrader();
        if (row.act === 'retry') {
            expect(rec.retries).toHaveLength(1);
            rec.retries[0].fn();
            await settle();
        }

        expect(rec.retries).toHaveLength(row.retries);
        expect(rec.mints).toBe(row.mints);
        expect(rec.lookups).toBe(row.lookups);
        rec.retries.forEach((retry) => {
            expect(retry.delay).toBeGreaterThanOrEqual(MINT_RETRY_DELAY_MS);
            expect(retry.delay).toBeLessThan(MINT_RETRY_CEILING_MS);
        });

        // Whatever the route, the recovered record is what the chip adopts, asking nothing further.
        await clickSoleTrader();
        expect(identity.companyName()).toBe(row.companyName);
        expect(rec.lookups).toBe(row.lookups);
        // Only meaningful where tokens exist: with none, openPopup() bails for a reason this row is not about.
        if (row.popupsProvable) expect(rec.opened).toEqual([]);
    });
});
