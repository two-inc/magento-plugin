/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * `signupPrefill()` builds the hosted sole-trader signup's prefill payload
 * from the quote's billing address. Untested until TWO-25503 review round 1 —
 * a `return {}` stub, and mutating just the guest-email fallback alone, both
 * left the suite green.
 */

'use strict';

const $ = require('jquery');
const { loadCompanyCapture, brandConfigMock, defaultMocks } = require('./amd-harness');

/**
 * @returns {object} the shipping panel's own `_options` — signupPrefill()
 *          reads the RESOLVED identity (TWO-25554), seeded here to 'Acme Ltd'
 *          the way the resolver would once a capture actually resolved.
 */
function load(billingAddress, guestEmail) {
    const capture = loadCompanyCapture({
        jquery: $,
        'Two_Gateway/js/model/sole-trader': function () {},
        'Two_Gateway/js/model/brand-config': brandConfigMock(null),
        'Two_Gateway/js/model/company-search': defaultMocks()['Two_Gateway/js/model/company-search'],
        'Magento_Checkout/js/model/quote': Object.assign(
            {},
            defaultMocks()['Magento_Checkout/js/model/quote'],
            {
                billingAddress: function () { return billingAddress; },
                guestEmail: guestEmail
            }
        )
    });
    capture.identity.companyName('Acme Ltd');
    return capture.shipping;
}

describe('signupPrefill() builds the hosted-signup payload', () => {
    test('the full shape, from a complete billing address', () => {
        const component = load({
            email: 'buyer@example.com',
            firstname: 'Ola',
            lastname: 'Nordmann',
            telephone: '4712345678',
            street: ['Storgata 1', 'c/o Reception'],
            postcode: '0150',
            city: 'Oslo',
            region: 'Oslo',
            countryId: 'NO'
        });

        expect(component._options.signupPrefill()).toEqual({
            email: 'buyer@example.com',
            first_name: 'Ola',
            last_name: 'Nordmann',
            company_name: 'Acme Ltd',
            phone_number: '4712345678',
            billing_address: {
                building: 'Storgata',
                street: '1, c/o Reception',
                postal_code: '0150',
                city: 'Oslo',
                region: 'Oslo',
                country_code: 'NO'
            }
        });
    });

    test('a present billing email wins over the guest email', () => {
        const component = load({ email: 'buyer@example.com', street: [] }, 'guest@example.com');
        expect(component._options.signupPrefill().email).toBe('buyer@example.com');
    });

    test('an absent billing email falls back to the guest email', () => {
        const component = load({ street: [] }, 'guest@example.com');
        expect(component._options.signupPrefill().email).toBe('guest@example.com');
    });

    test('neither present resolves to an empty string, not undefined', () => {
        const component = load({ street: [] }, undefined);
        expect(component._options.signupPrefill().email).toBe('');
    });
});
