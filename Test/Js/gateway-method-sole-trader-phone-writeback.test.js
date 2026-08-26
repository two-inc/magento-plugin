/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — adopting a sole trader writes the buyer's own phone number, same
 * as WooCommerce (PR #496) and PrestaShop (PR #186) now do.
 *
 * The route matters as much as the outcome. `applyAddress()` deliberately never
 * touches telephone — a registry business number is not the buyer's own — so
 * the phone goes through `applyTelephone()`, a separate call with its own
 * scope. Asserted against the REAL company-search model and a real form: a
 * double recording "applyTelephone was called" would stay green if the two
 * writes were merged back into one.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';
const COMPANY_SEARCH = 'view/frontend/web/js/model/company-search.js';

const BILLING = { street: 'Mill Lane', city: 'Ashford', postal_code: 'TN23 1AA', country_code: 'GB' };

const BUYER = {
    email: 'trader@example.com',
    organization_number: '999888777',
    company_name: 'Example Trader',
    billing_address: BILLING
};

/** The billing-role address form the write is scoped to. */
function mountBillingForm(priorTelephone) {
    document.body.innerHTML =
        '<div data-form="billing-new-address">' +
        '<input name="street[0]" />' +
        '<input name="city" />' +
        '<input name="postcode" />' +
        `<input name="telephone" value="${priorTelephone || ''}" />` +
        '</div>';
}

function telephoneValue() {
    return document.querySelector('input[name="telephone"]').value;
}

/**
 * The real flow over the real company-search model, both closed over jsdom's
 * own document.
 *
 * Loaded fresh per test: the once-per-identity address guard is module-scope
 * state in the flow, and the identity model is a page-level singleton.
 *
 * @returns {object} `{ flow, companySearch, identity }`
 */
function loadFlow() {
    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });
    identity.captureMode('soletrader');

    const globals = {
        document: document,
        window: { open: function () { return null; }, addEventListener: function () {}, removeEventListener: function () {} },
        btoa: global.btoa,
        setInterval: function () { return 1; },
        clearInterval: function () {},
        fetch: function () { return Promise.resolve({ ok: false, status: 404 }); }
    };

    const companySearch = loadAmdModule(
        COMPANY_SEARCH,
        { jquery: $, 'Magento_Checkout/js/model/quote': defaultMocks()['Magento_Checkout/js/model/quote'] },
        globals
    );

    const SoleTraderCtor = loadAmdModule(
        SOLE_TRADER,
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search': companySearch
        },
        globals
    );

    const host = {
        config: function () { return { checkoutPageUrl: 'https://checkout.example', checkoutApiUrl: 'https://api.example' }; },
        countryCode: function () { return 'gb'; },
        adoptSoleTrader: function (buyer) {
            identity.write({ companyId: buyer.organization_number, companyName: buyer.company_name });
        },
        abandonSoleTrader: function () {}
    };

    return { flow: new SoleTraderCtor(host), companySearch: companySearch, identity: identity };
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('the buyer\'s own phone number reaches the checkout', () => {
    test.each([
        ['+442012345678', '', '+442012345678', 'a phone number on the buyer is written'],
        ['  +442012345678  ', '', '+442012345678', 'surrounding whitespace is trimmed off'],
        [undefined, '+441234567890', '+441234567890', 'no phone number leaves the field untouched'],
        ['', '+441234567890', '+441234567890', 'an empty phone number leaves the field untouched'],
        ['   ', '+441234567890', '+441234567890', 'a blank phone number leaves the field untouched']
    ])('%p (prior %p) -> %p (%s)', (buyerPhone, priorTelephone, expected) => {
        mountBillingForm(priorTelephone);
        const { flow } = loadFlow();

        flow.adoptBuyer(Object.assign({}, BUYER, { phone_number: buyerPhone }));

        expect(telephoneValue()).toBe(expected);
    });

    test('a buyer with a phone but no address still has their phone written', () => {
        // The two writes are separate calls with separate conditions; merging
        // them would leave this buyer's phone behind with their absent address.
        mountBillingForm('');
        const { flow } = loadFlow();

        flow.adoptBuyer({ organization_number: '1', company_name: 'Example', phone_number: '+442012345678' });

        expect(telephoneValue()).toBe('+442012345678');
        expect(document.querySelector('input[name="city"]').value).toBe('');
    });
});

describe('the address write is not the route the phone takes', () => {
    test('applyAddress() with the same record never touches telephone', () => {
        mountBillingForm('+441234567890');
        const { companySearch } = loadFlow();

        companySearch.applyAddress(
            Object.assign({}, BILLING, { phone_number: '+442012345678' }),
            companySearch.billingRoleFormRoot()
        );

        expect(document.querySelector('input[name="city"]').value).toBe('Ashford');
        expect(telephoneValue()).toBe('+441234567890');
    });
});

describe('a replayed adoption', () => {
    test('does not re-run the phone write over a number the buyer has since corrected', () => {
        mountBillingForm('');
        const { flow } = loadFlow();
        const buyer = Object.assign({}, BUYER, { phone_number: '+442012345678' });

        flow.adoptBuyer(buyer);
        document.querySelector('input[name="telephone"]').value = '+440000000000';
        flow.adoptBuyer(buyer);

        expect(telephoneValue()).toBe('+440000000000');
    });

    test('forgetAdoptions() re-arms it, the same as the address half', () => {
        mountBillingForm('');
        const { flow } = loadFlow();
        const buyer = Object.assign({}, BUYER, { phone_number: '+442012345678' });

        flow.adoptBuyer(buyer);
        document.querySelector('input[name="telephone"]').value = '';
        flow.forgetAdoptions();
        flow.adoptBuyer(buyer);

        expect(telephoneValue()).toBe('+442012345678');
    });
});
