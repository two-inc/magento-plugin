/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25547 — reaching checkout mints the token pair and looks the buyer up.
 *
 * The session is driven directly here, with no component, no DOM, no country
 * and no payment method, because that is the whole claim: nothing about the
 * checkout's shape or the registry's answer for a country may stand between
 * the page loading and `get-tokens`.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const SESSION = 'view/frontend/web/js/model/sole-trader-session.js';

const CONFIG = { checkoutApiUrl: 'https://api.example', customHeaders: {} };

/**
 * @param {Array<object|Error>} responses one per call, in order
 * @returns {object} `{ Session, host, calls }`
 */
function makeSession(responses) {
    const calls = [];
    const Session = loadAmdModule(SESSION, {}, {
        setInterval: function () { return 1; },
        clearInterval: function () {},
        fetch: function (url, options) {
            calls.push({ url: String(url), method: (options && options.method) || 'GET' });
            const next = responses.shift();
            if (next instanceof Error) return Promise.reject(next);
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(next) });
        }
    });
    const host = {
        config: CONFIG,
        tokensUrl: function () { return 'https://shop.example/rest/V1/two/get-tokens'; },
        quoteId: function () { return 'quote-1'; },
        apiClientParams: function () { return {}; }
    };
    return { Session: Session, host: host, calls: calls };
}

const TOKENS = [{ delegation_token: 'dt', autofill_token: 'at' }];

describe('start() mints and looks the buyer up', () => {
    test.each([
        ['no company types at all', []],
        ['business-only company types', ['LIMITED_COMPANY']],
        ['sole traders offered', ['SOLE_TRADER']]
    ])('the registry answering %s does not stop the mint', async (_description, supportedCompanyTypes) => {
        const { Session, host, calls } = makeSession([TOKENS, null]);
        const session = new Session(Object.assign({}, host, {
            config: Object.assign({ supportedCompanyTypes: supportedCompanyTypes }, CONFIG)
        }));

        await session.start();

        expect(calls[0]).toEqual({ url: 'https://shop.example/rest/V1/two/get-tokens', method: 'POST' });
        expect(calls[1].url).toBe('https://api.example/autofill/v1/buyer/current');
    });

    test('a failed mint leaves the next attempt free to mint again', async () => {
        const { Session, host, calls } = makeSession([new Error('offline'), TOKENS, null]);
        const session = new Session(host);

        await session.start();
        expect(calls.length).toBe(1);

        await session.start();

        expect(calls[1].method).toBe('POST');
        expect(calls[2].url).toBe('https://api.example/autofill/v1/buyer/current');
    });
});
