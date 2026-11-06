/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — adopting a sole trader writes the buyer's own phone number, same
 * as woocommerce-plugin PR #496 and prestashop-plugin PR #186 now do.
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
const { loadAmdModule, loadCompanyCapture, brandConfigMock, defaultMocks } = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const COMPANY_SEARCH = 'view/frontend/web/js/model/company-search.js';

const BILLING = { street: 'Mill Lane', city: 'Ashford', postal_code: 'TN23 1AA', country_code: 'GB' };

const BUYER = {
    email: 'trader@example.com',
    organization_number: '999888777',
    company_name: 'Example Trader',
    billing_address: BILLING
};

/** The shipping panel's OWN address form — the only one its writes may reach. */
function mountShippingForm(priorTelephone) {
    document.body.innerHTML =
        '<form id="shipping-new-address-form">' +
        '<input name="company" />' +
        '<input name="street[0]" />' +
        '<input name="city" />' +
        '<input name="postcode" />' +
        `<input name="telephone" value="${priorTelephone || ''}" />` +
        '</form>';
}

function telephoneValue() {
    return document.querySelector('input[name="telephone"]').value;
}

/**
 * The real flow, reached the way the checkout reaches it: through Luma's wired
 * capture component, over the real company-search model, both closed over
 * jsdom's own document.
 *
 * Loaded fresh per test: the identity is a page-level singleton.
 *
 * @returns {object} `{ flow, companySearch, identity }`
 */
function loadFlow() {
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

    const component = loadCompanyCapture(
        {
            jquery: $,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/brand-config': brandConfigMock({
                checkoutPageUrl: 'https://checkout.example',
                checkoutApiUrl: 'https://api.example',
                isCompanySearchEnabled: true
            })
        },
        globals
    ).shipping;
    component.start();
    const identity = component.identity();
    identity.captureMode('soletrader');

    return { flow: component.soleTrader(), companySearch: companySearch, identity: identity };
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
        mountShippingForm(priorTelephone);
        const { flow } = loadFlow();

        flow.adoptBuyer(Object.assign({}, BUYER, { phone_number: buyerPhone }));

        expect(telephoneValue()).toBe(expected);
    });

    test('a buyer with a phone but no address still has their phone written', () => {
        // The two writes are separate calls with separate conditions; merging
        // them would leave this buyer's phone behind with their absent address.
        mountShippingForm('');
        const { flow } = loadFlow();

        flow.adoptBuyer({ organization_number: '1', company_name: 'Example', phone_number: '+442012345678' });

        expect(telephoneValue()).toBe('+442012345678');
        expect(document.querySelector('input[name="city"]').value).toBe('');
    });
});

describe('the address write is not the route the phone takes', () => {
    test('applyAddress() with the same record never touches telephone', () => {
        mountShippingForm('+441234567890');
        const { companySearch } = loadFlow();

        companySearch.applyAddress(
            Object.assign({}, BILLING, { phone_number: '+442012345678' }),
            $('#shipping-new-address-form'),
            {}
        );

        expect(document.querySelector('input[name="city"]').value).toBe('Ashford');
        expect(telephoneValue()).toBe('+441234567890');
    });
});

describe('a replayed adoption (TWO-26775: written every time, by PrestaShop\'s rules)', () => {
    test.each([
        ['+442012345678', '+440000000000', '+442012345678', 'a number on the record overwrites a corrected one'],
        ['', '+440000000000', '+440000000000', 'a blank leaves a number the buyer typed'],
        ['', null, '', 'a blank clears a number the plugin itself wrote']
    ])('second record %p over %p -> %p (%s)', (secondPhone, editedTo, expected, why) => {
        mountShippingForm('');
        const { flow } = loadFlow();

        flow.adoptBuyer(Object.assign({}, BUYER, { phone_number: '+442012345678' }));
        if (editedTo !== null) document.querySelector('input[name="telephone"]').value = editedTo;
        flow.adoptBuyer(Object.assign({}, BUYER, { phone_number: secondPhone }));

        expect([why, telephoneValue()]).toEqual([why, expected]);
    });
});

describe('applyTelephone() marks its own write (TWO-26775)', () => {
    const MARKER = 'data-two-autofilled-value';

    test.each([
        ['+442012345678', '', null, '+442012345678', '+442012345678', true, 'a value writes and is marked'],
        [' +44 1 ', '+440000000000', null, '+44 1', '+44 1', true, 'a value overwrites whatever was there, trimmed'],
        ['', '+441111111111', '+441111111111', '', null, true, 'a blank clears a marked value still as written'],
        ['', '+442222222222', '+441111111111', '+442222222222', '+441111111111', false, 'a blank keeps a marked value the buyer changed'],
        ['', '+441111111111', null, '+441111111111', null, false, 'a blank keeps a value nothing marked'],
        [undefined, '', null, '', null, false, 'nothing at all writes nothing']
    ])('%p over %p (marker %p) -> %p, marker %p, wrote %p (%s)', (phone, prior, marker, expected, expectedMarker, wrote, why) => {
        mountShippingForm(prior);
        if (marker !== null) document.querySelector('input[name="telephone"]').setAttribute(MARKER, marker);
        const { companySearch } = loadFlow();

        const result = companySearch.applyTelephone(phone, $('#shipping-new-address-form'));

        const field = document.querySelector('input[name="telephone"]');
        expect([why, field.value, field.getAttribute(MARKER), result]).toEqual([why, expected, expectedMarker, wrote]);
    });
});
