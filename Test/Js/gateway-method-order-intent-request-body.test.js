/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25365 — the order-intent request's `buyer.company` object carries only
 * fields about the BUYER's company.
 *
 * Luma used to send `buyer.company.website = window.BASE_URL`. That global is
 * the MERCHANT's own storefront URL, so every order intent claimed the
 * merchant's site as the buying company's website — wrong data under a field
 * name that says something else. Nothing downstream reads it (the field is not
 * part of the request Luma is meant to send, and the Hyvä payment component
 * never sent it), so it is gone rather than re-sourced.
 *
 * The behavioural spec below composes a real request body and asserts on the
 * JSON that would go on the wire, with `window.BASE_URL` deliberately SET — a
 * spec run against a sandbox with no BASE_URL would pass on the missing global
 * rather than on the composition.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

const STOREFRONT_URL = 'https://merchant-storefront.example/';

/**
 * Load the renderer with a quote double carrying one physical line item and a
 * complete set of totals, plus a jQuery double that RECORDS the `$.ajax`
 * options instead of issuing a request.
 *
 * @returns {{component: object, requests: object[]}}
 */
function loadRenderer() {
    const requests = [];
    const $ = defaultMocks().jquery;

    $.ajax = function (options) {
        requests.push(options);
        return {
            done: function () { return this; },
            fail: function () { return this; },
            always: function () { return this; }
        };
    };

    const totals = {
        grand_total: '124.00',
        tax_amount: '24.00',
        shipping_incl_tax: '12.40',
        shipping_amount: '10.00',
        shipping_tax_amount: '2.40',
        quote_currency_code: 'NOK'
    };

    const quote = {
        getTotals: function () { return function () { return totals; }; },
        billingAddress: function () {
            return {
                countryId: 'NO',
                firstname: 'Ola',
                lastname: 'Nordmann'
            };
        },
        getItems: function () {
            return [
                {
                    name: 'Widget',
                    description: 'A widget',
                    discount_amount: '0.00',
                    row_total_incl_tax: '111.60',
                    row_total: '90.00',
                    qty: 1,
                    price: '90.00',
                    tax_amount: '21.60',
                    tax_percent: '24',
                    thumbnail: 'https://merchant-storefront.example/widget.png',
                    is_virtual: '0'
                }
            ];
        },
        isVirtual: function () { return false; },
        shippingMethod: function () { return { carrier_code: 'flatrate' }; },
        guestEmail: 'ola@example.com'
    };

    const component = loadAmdModule(
        RENDERER,
        { jquery: $, 'Magento_Checkout/js/model/quote': quote },
        {
            // BASE_URL present and non-empty on purpose — see the file header.
            window: {
                BASE_URL: STOREFRONT_URL,
                checkoutConfig: {
                    payment: {},
                    customerData: { email: 'ola@example.com' }
                }
            }
        }
    );

    return { component: component, requests: requests };
}

/**
 * A `this` context standing in for a live renderer instance, with just the
 * members placeOrderIntent() reads.
 *
 * @param {object} component the loaded renderer
 * @returns {object} the context
 */
function makeContext(component) {
    return Object.assign({}, component, {
        companyId: function () { return '923456789'; },
        companyName: function () { return 'Acme Widgets AS'; },
        getTelephone: function () { return '+4712345678'; },
        _brandConfig: {
            checkoutApiUrl: 'https://api.example.two.inc',
            orderIntentConfig: {
                weightUnit: 'kg',
                extensionPlatformName: 'magento2',
                extensionDBVersion: '1.0.0',
                merchant: { id: 'm-1', short_name: 'acme' }
            }
        }
    });
}

describe('order-intent request body omits buyer.company.website (TWO-25365)', () => {
    test('the composed request sends no `website` key at all', () => {
        const { component, requests } = loadRenderer();
        const ctx = makeContext(component);

        ctx.placeOrderIntent.call(ctx);

        expect(requests).toHaveLength(1);
        const body = JSON.parse(requests[0].data);

        expect(body.buyer.company).toEqual({
            organization_number: '923456789',
            country_prefix: 'NO',
            company_name: 'Acme Widgets AS'
        });
        expect('website' in body.buyer.company).toBe(false);
        // Belt and braces on the specific defect: the storefront URL must not
        // appear anywhere in the buyer object under any key.
        expect(JSON.stringify(body.buyer)).not.toContain(STOREFRONT_URL);
    });

    test('the source no longer reads window.BASE_URL', () => {
        // The behavioural spec above proves the key is absent from the body
        // it composes; this pins the GLOBAL itself as unused, so a later
        // change cannot reintroduce the merchant URL into some other field
        // without a spec failing.
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');

        expect(src).not.toMatch(/BASE_URL/);
        // Narrow on purpose: the KEY, not the word. Magento's own
        // website-scope vocabulary is legitimate prose in this file.
        expect(src).not.toMatch(/\bwebsite\s*:/);
    });
});
