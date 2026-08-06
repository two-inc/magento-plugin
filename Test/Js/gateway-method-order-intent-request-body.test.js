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
 * name that says something else. The field is not part of the request this
 * renderer is meant to send, and this module's own PHP order-create path
 * (`Service\Order`) never sent it either, so it is gone rather than re-sourced
 * from somewhere else. See TWO-25365 for the API-side reference.
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
 * The host alone, matched separately from the full URL above.
 *
 * Magento's `BASE_URL` always ends in a slash, so stripping the trailing slash
 * is the most natural thing a reintroduction would do — and adversarial review
 * proved four such normalisations (`replace(/[/]+$/, '')`, `split('://')[1]`,
 * `encodeURIComponent()`, `toUpperCase()`) sailing past an exact match on the
 * full URL. The host token survives all four.
 *
 * Deliberately NOT shortened to `example`: the fixture's product-image host
 * shares that suffix, so the assertion would fire on a legitimate field.
 */
const STOREFRONT_HOST = 'merchant-storefront.example';

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
                    // A DIFFERENT host from STOREFRONT_URL on purpose: a
                    // product image legitimately carries a merchant-hosted URL,
                    // and the whole-body guard below would otherwise trip on it
                    // instead of on the defect.
                    thumbnail: 'https://cdn.example/media/widget.png',
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
        // The exact-shape assertion above already pins the key set, so a
        // separate `'website' in …` check would be dead: JSON.parse produces no
        // undefined-valued keys, and any defined value fails the toEqual.
        //
        // What toEqual canNOT see is the URL reappearing somewhere else in the
        // body — adversarial review reached a passing `store_url:
        // window['BASE_' + 'URL']` sitting beside `merchant_id`, outside
        // `buyer` and past both source-text regexes below. The merchant's own
        // storefront URL has no business anywhere in this request, so the guard
        // is on the WHOLE body rather than on the buyer object.
        //
        // Two tokens, not one: the full URL, and the bare host for the
        // normalised forms an exact match cannot see (see STOREFRONT_HOST).
        // Lower-cased for the guard so an upper-cased value cannot slip by.
        const wire = JSON.stringify(body);
        expect(wire).not.toContain(STOREFRONT_URL);
        expect(wire.toLowerCase()).not.toContain(STOREFRONT_HOST);
    });

    test('the source no longer reads window.BASE_URL', () => {
        // The behavioural spec above proves the key is absent from the body it
        // composes. This covers the reverse risk: the global read on a path the
        // fixture never exercises — a country branch, a later mutation of the
        // composed object — which no single fixture can reach.
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');
        // COMMENTS STRIPPED, because the rationale prose that documents this
        // very fix names `window.BASE_URL` — a check matched against the raw
        // text would go red on someone explaining the change, i.e. fail on
        // documentation rather than on code. Proved in adversarial review.
        //
        // Only the two comment forms this file actually uses: JSDoc blocks and
        // WHOLE-LINE `//`. A general strip is what the previous round used and
        // it silently WEAKENS this check rather than breaking it — adversarial
        // review proved both halves: `//` inside a string literal
        // (`'https://x'`) deletes the rest of that line, and an unanchored
        // block strip lets a single `'/*'` string constant open a fake comment
        // that swallows 400+ real lines. Neither construct is in the file
        // today; the point is that nothing would surface when one arrives.
        const code = src
            .replace(/\/\*\*[\s\S]*?\*\//g, '')
            .replace(/^[ \t]*\/\/[^\n]*$/gm, '');

        // Narrowed to the actual global. A bare /BASE_URL/ also reddens on
        // `TWO_API_BASE_URL` / `TWO_CHECKOUT_BASE_URL`, which are live
        // vocabulary elsewhere in this module.
        expect(code).not.toMatch(/window\s*\.\s*BASE_URL/);
        // …and the computed form the same review used to evade that regex.
        // Zero legitimate `window[...]` accesses exist in this file.
        expect(code).not.toMatch(/window\s*\[/);
        // Assignment as well as the object-literal key, since a reintroduction
        // on an unexercised path would most likely mutate the composed object
        // after the literal (`…company.website = …`) rather than edit it.
        // Narrow on the KEY rather than the word: Magento's own website-scope
        // vocabulary is legitimate prose in this file.
        expect(code).not.toMatch(/\bweb_?site\s*[:=]/);
    });
});
