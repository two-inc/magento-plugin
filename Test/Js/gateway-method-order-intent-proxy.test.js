/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The order-intent check runs server-side now, so the merchant API key and any
 * configured firewall token never reach the buyer's browser. The proxy answers
 * 200 whatever the upstream said, which would turn every upstream rejection
 * into a silent approval unless the renderer reads the envelope — these pin
 * that both outcomes still reach the handlers they always did.
 */

'use strict';

const jq = require('jquery');
const { loadAmdModule, defaultMocks, proxyEnvelope } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

function loadRenderer() {
    const requests = [];
    const $ = defaultMocks().jquery;
    $.Deferred = jq.Deferred;
    $.ajax = function (options) {
        const settlers = { done: [], fail: [] };
        const jqxhr = {
            options: options,
            done: function (fn) { settlers.done.push(fn); return jqxhr; },
            fail: function (fn) { settlers.fail.push(fn); return jqxhr; },
            always: function () { return jqxhr; },
            settleDone: function (raw) { settlers.done.forEach(function (fn) { fn(raw); }); },
            settleFail: function (xhr) { settlers.fail.forEach(function (fn) { fn(xhr); }); }
        };
        requests.push(jqxhr);
        return jqxhr;
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
            return { countryId: 'NO', firstname: 'Ola', lastname: 'Nordmann' };
        },
        getItems: function () { return []; }
    };

    const component = loadAmdModule(
        RENDERER,
        Object.assign({}, defaultMocks(), {
            jquery: $,
            'Magento_Checkout/js/model/quote': quote
        })
    );

    const ctx = Object.assign({}, component, {
        companyId: function () { return '923456789'; },
        companyName: function () { return 'Acme Widgets AS'; },
        getEmail: function () { return 'ola@example.test'; },
        getTelephone: function () { return '+4712345678'; },
        _brandConfig: {
            orderIntentConfig: {
                weightUnit: 'kg',
                extensionPlatformName: 'magento2',
                extensionDBVersion: '1.0.0',
                merchant: { id: 'm-1', short_name: 'acme' }
            }
        }
    });

    return { ctx: ctx, requests: requests };
}

describe('the order-intent check goes through the plugin, not straight to the API', () => {
    test('it posts the composed body to the plugin\'s own route', () => {
        const { ctx, requests } = loadRenderer();

        ctx.placeOrderIntent.call(ctx);

        expect(requests).toHaveLength(1);
        expect(requests[0].options.url).toBe('rest/V1/two/order-intent');
        expect(requests[0].options.url).not.toContain('order_intent');
        expect(JSON.parse(JSON.parse(requests[0].options.data).payload).gross_amount).toBe('124.00');
    });

    test.each([
        [
            { approved: true },
            { ok: true },
            'resolved',
            'an approval settles the promise the tile reads its verdict from'
        ],
        [
            { error_code: 'SCHEMA_ERROR' },
            { ok: false, status: 422 },
            'rejected',
            'an upstream rejection must not arrive as a 200-shaped success'
        ]
    ])('an upstream %p settles %s', (body, envelope, expectedOutcome) => {
        const { ctx, requests } = loadRenderer();
        const outcomes = [];

        ctx.placeOrderIntent.call(ctx)
            .done(function (response) { outcomes.push(['resolved', response]); })
            .fail(function (response) { outcomes.push(['rejected', response]); });
        requests[0].settleDone(proxyEnvelope(body, envelope));

        expect(outcomes).toHaveLength(1);
        expect(outcomes[0][0]).toBe(expectedOutcome);
        expect(
            expectedOutcome === 'rejected' ? outcomes[0][1].responseJSON : outcomes[0][1]
        ).toEqual(body);
    });

    test('a transport failure still reaches the failure handler as a jqXHR', () => {
        const { ctx, requests } = loadRenderer();
        const failures = [];

        ctx.placeOrderIntent.call(ctx).fail(function (xhr) { failures.push(xhr); });
        requests[0].settleFail({ status: 503 });

        expect(failures).toEqual([{ status: 503 }]);
    });
});
