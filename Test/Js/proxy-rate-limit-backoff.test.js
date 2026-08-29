/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * A 429 from the plugin's own routes arrives as a raw Magento webapi fault,
 * not the `{ok,status,body}` envelope every other answer uses — the ceiling is
 * enforced before the route runs. Read as an ordinary failure it would decline
 * the buyer and let the next keystroke walk straight back into the limit.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const GLOBALS = { document: document, window: window };

const RATE_LIMIT_COPY = 'Too many requests. Please wait a moment and try again.';

/** `$.ajax` double whose failures carry a real HTTP status. */
function installAjaxDouble() {
    const requests = [];
    $.ajax = function (options) {
        const bound = { done: [], fail: [], always: [] };
        const jqxhr = {
            options: options,
            done: function (fn) { bound.done.push(fn); return jqxhr; },
            fail: function (fn) { bound.fail.push(fn); return jqxhr; },
            always: function (fn) { bound.always.push(fn); return jqxhr; },
            abort: function () {},
            settleFail: function (status, responseJSON) {
                bound.fail.forEach(function (fn) {
                    fn({ status: status, responseJSON: responseJSON }, 'error');
                });
                bound.always.forEach(function (fn) { fn(); });
            }
        };
        requests.push(jqxhr);
        return jqxhr;
    };
    return requests;
}

function search(companySearch, term) {
    return companySearch.searchCompanies({
        token: {},
        term: term,
        getCountryCode: function () { return 'gb'; }
    });
}

describe('company search backs off rather than retrying into the ceiling', () => {
    test.each([
        [429, 1, 'a refused search parks the next keystroke'],
        [500, 2, 'an ordinary failure is retried on the next keystroke']
    ])('after a %i, %i request(s) are issued in total', async (status, expected) => {
        const requests = installAjaxDouble();
        const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
        companySearch.clearResultCache();

        const first = search(companySearch, 'exa');
        requests[0].settleFail(status);
        await first;

        const second = search(companySearch, 'exam');
        if (requests[1]) requests[1].settleFail(status);

        await expect(second).resolves.toEqual({ items: [], unavailable: true, aborted: false });
        expect(requests).toHaveLength(expected);
    });
});

describe('the order-intent tile tells a rate-limited buyer to wait', () => {
    function renderer() {
        const notices = [];
        const component = loadAmdModule(RENDERER, defaultMocks());

        return Object.assign({}, component, {
            notices: notices,
            generalErrorMessage: 'Something went wrong with your order.',
            clearOrderIntentNotices: function () {},
            showOrderIntentErrorNotice: function (message) { notices.push(message); }
        });
    }

    test('the refusal message is shown, not a decline', () => {
        const ctx = renderer();

        ctx.processOrderIntentErrorResponse.call(ctx, {
            status: 429,
            responseJSON: { message: RATE_LIMIT_COPY }
        });

        expect(ctx.notices).toEqual([RATE_LIMIT_COPY]);
    });

    // A webapi fault carries no `error_code`, so without the status branch this
    // lands on the generic decline the switch below falls through to.
    test('a fault with no body still reads as a wait, not a decline', () => {
        const ctx = renderer();

        ctx.processOrderIntentErrorResponse.call(ctx, { status: 429 });

        expect(ctx.notices).toEqual([RATE_LIMIT_COPY]);
    });

    test('the plugin\'s own in-envelope refusal renders its message', () => {
        const ctx = renderer();

        ctx.processOrderIntentErrorResponse.call(ctx, {
            responseJSON: {
                error_code: 'PROXY_REFUSED',
                error_message: 'The payment integration is not available right now.'
            }
        });

        expect(ctx.notices).toEqual(['The payment integration is not available right now.']);
    });
});
