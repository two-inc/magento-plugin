/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * F001 — one delegation/autofill token pair and one buyer lookup per checkout,
 * however many capture panels the host builds.
 */

'use strict';

const $ = require('jquery');
const {
    loadCompanyCapture,
    defaultMocks,
    loadCompanySearchPanel,
    dispatchNative,
    brandConfigMock,
    quoteAddress,
    tagged
} = require('./amd-harness');

const BUYER = {
    email: 'trader@example.com',
    organization_number: '999888777',
    company_name: 'Example Trader',
    phone_number: '+4479000000',
    billing_address: { city: 'London', country: 'GB' }
};

function settle() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/**
 * Boot the real Luma stack the way `company-search-boot.js` does — the
 * adapter's own `start()`, which starts every panel it built.
 *
 * @returns {Promise<object>} `{ capture, rec }`
 */
async function startCheckout() {
    document.body.innerHTML =
        '<form id="two_gateway_form"><div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" /></div></div></form>';

    const rec = { mints: 0, lookups: 0, opened: [] };
    const fakeWindow = {
        open: function (url) {
            rec.opened.push(url);
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
        currentAddressFormCountry: function () { return ''; },
        applyAddress: function () {},
        applyTelephone: function () { return true; },
        revertAutofilledAddress: function () { return 0; }
    });
    const globals = {
        document: document,
        window: fakeWindow,
        btoa: global.btoa,
        setInterval: function () { return 1; },
        clearInterval: function () {},
        fetch: function (requestUrl) {
            const url = String(requestUrl);
            if (url.indexOf('get-tokens') !== -1) {
                rec.mints += 1;
                return Promise.resolve({
                    ok: true,
                    json: function () {
                        // A distinguishable pair per mint, as the endpoint answers.
                        return Promise.resolve([{
                            delegation_token: `dt-${rec.mints}`,
                            autofill_token: `at-${rec.mints}`
                        }]);
                    }
                });
            }
            if (url.indexOf('/autofill/v1/buyer/current') !== -1) {
                rec.lookups += 1;
                return Promise.resolve({ ok: true, json: function () { return Promise.resolve(BUYER); } });
            }
            return Promise.resolve({ ok: false, status: 404 });
        }
    };
    const mocks = {
        jquery: $,
        'Magento_Checkout/js/model/quote': quote,
        'Two_Gateway/js/model/company-search': companySearch,
        'Two_Gateway/js/model/brand-config': brandConfigMock({
            checkoutPageUrl: 'https://checkout.example.two.inc',
            checkoutApiUrl: 'https://api.example',
            isCompanySearchEnabled: true,
            supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
        }),
        'Magento_Ui/js/model/messageList': { addErrorMessage: function () {}, addSuccessMessage: function () {} }
    };
    mocks['Two_Gateway/js/model/company-search-panel'] = loadCompanySearchPanel($, companySearch, globals);

    const capture = loadCompanyCapture(mocks, globals);
    capture.start();
    await settle();
    return { capture: capture, rec: rec };
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('one checkout, one token pair', () => {
    test.each([
        tagged('token mints', function (rec) { return rec.mints; }),
        tagged('buyer lookups', function (rec) { return rec.lookups; })
    ])('booting every panel makes exactly one round of %s', async (_description, read) => {
        const { rec } = await startCheckout();

        expect(read(rec)).toBe(1);
    });

    test("a refresh tick mints nothing while another panel's flow is mid-signup", async () => {
        const { capture, rec } = await startCheckout();
        capture.billing.identity().beginFlight();

        capture.shipping.soleTrader().refreshTokens();
        await settle();

        expect(rec.mints).toBe(1);
    });

    test('the panel the buyer clicks adopts the held record with no popup', async () => {
        const { capture, rec } = await startCheckout();

        dispatchNative($('#two_gateway_form input#company_name')[0], 'mousedown');
        document.querySelector('.two-company-mode-chip[data-two-chip="soletrader"]').click();
        await settle();

        expect(rec.opened).toEqual([]);
        expect(capture.shipping.identity().companyName()).toBe('Example Trader');
    });
});
