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
 * The host alone, matched separately from the full URL above, because
 * normalising the value defeats an exact match — Magento's base URL always ends
 * in a slash, so stripping it is the obvious thing a reintroduction would do.
 * Adversarial review got four such forms past the full-URL check
 * (trailing-slash strip, scheme strip, percent-encode, upper-case); the host
 * token survives all of them.
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
                    // A DIFFERENT host from STOREFRONT_URL, and it must STAY
                    // different: in production this really is the storefront
                    // base plus `media/...`, so "making the fixture realistic"
                    // would trip the whole-body guard below with no defect
                    // present. The guard exists for the merchant URL arriving as
                    // a company field, which a product image is not.
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
        {
            jquery: $,
            'Magento_Checkout/js/model/quote': quote,
            // `mage/url` is the OTHER live route to the storefront URL — this
            // renderer already injects it and calls `url.build(...)` elsewhere.
            // The harness default returns its argument unchanged, so
            // `url.build('')` yields '' and a reintroduction through it would be
            // invisible to the wire assertions. Adversarial review got two such
            // mutations past this spec, one of them under the removed field's
            // exact name. Resolving to the real storefront root, as the browser
            // does, is what makes the body guard cover both routes.
            'mage/url': {
                build: function (path) { return STOREFRONT_URL + (path || ''); },
                setBaseUrl: function () {}
            }
        },
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

    test('the renderer reintroduces the field on no path, exercised or not', () => {
        // The behavioural spec above only sees the ONE path its fixture takes.
        // This covers the reverse risk: the field or the global reappearing on a
        // path no single fixture reaches — a country branch, a mutation of the
        // composed object after the literal.
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');
        // Comments stripped, or this check fails on DOCUMENTATION rather than on
        // code: prose explaining this very fix necessarily names the global, and
        // round 1 proved a raw-text match going red on exactly that.
        //
        // The three comment forms this file uses: JSDoc blocks, whole-line `//`,
        // and TRAILING `//` after code (there is one at the `termsAccepted`
        // observable). The trailing form was missed for two rounds, which left
        // the false-red channel open — appending `// no BASE_URL here` to a line
        // of code still reddened this check.
        //
        // Each strip is deliberately narrow, because a general one silently
        // WEAKENS the check rather than breaking it: `//` inside a string
        // literal eats the rest of that line, and one `'/*'` string constant
        // opens a fake comment swallowing hundreds of real lines. Hence the
        // quote-and-backtick exclusion on the trailing form — it declines to
        // strip rather than risk eating a URL in a string, which is the safe
        // direction. The backtick is in that class because this file composes
        // URLs in template literals; without it, a template literal containing
        // whitespace-`//` on a line that also read the global would have had its
        // real code deleted, silently weakening the token check below.
        const code = src
            .replace(/\/\*\*[\s\S]*?\*\//g, '')
            .replace(/^[ \t]*\/\/[^\n]*$/gm, '')
            .replace(/[ \t]\/\/[^\n'"`]*$/gm, '');

        // The bare token, not `window.BASE_URL`: narrowing to the member access
        // let `const { BASE_URL } = window` through. No false-positive risk —
        // this file contains no `BASE_URL` of any kind, and the leading `\b`
        // could not match the `TWO_*_BASE_URL` env-var names anyway (a word
        // boundary fails after an underscore, the same property exploited below).
        expect(code).not.toMatch(/\bBASE_URL\b/);
        // No `\b` before `web`: the boundary fails after an underscore, so
        // `company_website:` — a likely name if the field moves out of
        // `buyer.company` — slipped past. Assignment as well as the literal key,
        // for the after-the-fact mutation case. Still the KEY rather than the
        // bare word, so ordinary prose about Magento's website scope stays legal.
        expect(code).not.toMatch(/web_?site\s*[:=]/);

        // What this deliberately does NOT catch, so the guards above are not
        // read as more than they are: the URL reappearing on an unexercised
        // branch under a name no regex can anticipate (`homepage`, say), or
        // outside the body altogether (a request header). Both need a key-name
        // oracle this spec cannot have, and closing the first would mean banning
        // the URL builder this file legitimately uses elsewhere. A previous
        // `window[...]` ban was dropped for the same reason: it outlawed a
        // general JS construct across 2000 lines to cover a case strictly
        // narrower than the ones already accepted here.
    });
});
