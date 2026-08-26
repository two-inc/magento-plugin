/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §7 — re-signing up as a different sole trader.
 *
 * Two routes reach the same place: the payment tile's "Select a different sole
 * trader" link, and re-clicking the sole-trader chip once one is already
 * adopted. Both must carry `autoselect=false`, or the hosted flow silently
 * re-picks the registration the buyer is trying to replace and hands back the
 * identity already on screen — a dead end with no visible cause.
 *
 * The tile no longer owns the flow. It delegates to the page-level
 * company-capture component, which is what survives the payment-method list
 * being rebuilt on every totals change, so the link's own case below is a
 * delegation check and the behaviour is driven through the real component.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const COMPONENT = 'view/frontend/web/js/model/company-capture-component.js';
const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';

const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';

/**
 * The mocks and sandbox globals the flow and the component share.
 *
 * @returns {object} `{ rec, identity, mocks, globals }`
 */
function makeEnv() {
    const rec = { opened: [], handles: [], tokenMints: 0 };

    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });

    const fakeWindow = {
        open: function (url, target, features) {
            rec.opened.push({ url: url, target: target, features: features });
            const handle = { closed: false, close: function () { this.closed = true; } };
            rec.handles.push(handle);
            return handle;
        },
        addEventListener: function () {},
        removeEventListener: function () {}
    };

    const mocks = {
        jquery: $,
        'Magento_Checkout/js/model/quote': Object.assign(
            {},
            defaultMocks()['Magento_Checkout/js/model/quote'],
            {
                billingAddress: function () { return { countryId: 'GB' }; },
                getQuoteId: function () { return 'cart-1'; },
                isVirtual: function () { return false; }
            }
        ),
        'Two_Gateway/js/model/company-identity': identity,
        'Two_Gateway/js/model/company-search': Object.assign(
            {},
            defaultMocks()['Two_Gateway/js/model/company-search'],
            { apiClientParams: function () { return {}; }, currentAddressFormCountry: function () { return ''; } }
        ),
        'Two_Gateway/js/model/brand-config': {
            getActiveTwoBrandConfig: function () {
                return {
                    checkoutPageUrl: CHECKOUT_PAGE_URL,
                    checkoutApiUrl: 'https://api.example',
                    isCompanySearchEnabled: true,
                    supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
                };
            }
        }
    };

    const globals = {
        document: document,
        window: fakeWindow,
        btoa: global.btoa,
        setInterval: function () { return 1; },
        clearInterval: function () {},
        fetch: function (requestUrl) {
            if (String(requestUrl).indexOf('get-tokens') === -1) {
                return Promise.resolve({ ok: false, status: 404 });
            }
            rec.tokenMints += 1;
            return Promise.resolve({
                ok: true,
                json: function () {
                    return Promise.resolve([{ delegation_token: 'dt-1', autofill_token: 'at-1' }]);
                }
            });
        }
    };

    return { rec: rec, identity: identity, mocks: mocks, globals: globals };
}

/**
 * The real flow against a stub host, with tokens already held.
 *
 * @returns {object} `{ flow, rec }`
 */
function loadFlow() {
    const env = makeEnv();
    const SoleTraderCtor = loadAmdModule(SOLE_TRADER, env.mocks, env.globals);
    const flow = new SoleTraderCtor({
        config: function () { return { checkoutPageUrl: CHECKOUT_PAGE_URL }; },
        countryCode: function () { return 'gb'; },
        adoptSoleTrader: function () {},
        abandonSoleTrader: function () {}
    });
    flow.delegationToken = 'dt-1';
    flow.autofillToken = 'at-1';
    return { flow: flow, rec: env.rec };
}

/**
 * The real component with the real flow underneath it, booted against a
 * payment-tile company field so the chips exist to be clicked.
 *
 * @returns {Promise<object>} `{ component, flow, rec, identity }`
 */
async function startStack() {
    document.body.innerHTML =
        '<form id="two_gateway_form">' +
        '<div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" />' +
        '</div></div></form>';
    const env = makeEnv();
    const component = loadAmdModule(COMPONENT, env.mocks, env.globals);
    component.start();
    await new Promise((resolve) => setTimeout(resolve, 0));
    return { component: component, flow: component.soleTrader(), rec: env.rec, identity: env.identity };
}

function autoselectOf(entry) {
    return new URL(entry.url).searchParams.get('autoselect');
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('a re-signup offers a choice rather than the identity on screen', () => {
    test.each([
        ['selectDifferentSoleTrader', 'false', 'the link asks the hosted flow to offer a choice'],
        ['launchSignup', null, 'an ordinary first signup lets it pick freely']
    ])('%s() -> autoselect=%p (%s)', (method, expected) => {
        const { flow, rec } = loadFlow();

        flow[method]();

        expect(rec.opened).toHaveLength(1);
        expect(autoselectOf(rec.opened[0])).toBe(expected);
    });

    test('re-clicking the sole-trader chip once adopted re-signs up with autoselect off', async () => {
        const { rec, identity } = await startStack();
        identity.captureMode('soletrader');
        identity.soleTraderAdopted(true);

        document.querySelector('.two-company-mode-chip[data-two-chip="soletrader"]').click();

        expect(rec.opened).toHaveLength(1);
        expect(autoselectOf(rec.opened[0])).toBe('false');
    });

    test('the first sole-trader chip click carries no autoselect param', async () => {
        const { rec } = await startStack();

        document.querySelector('.two-company-mode-chip[data-two-chip="soletrader"]').click();

        expect(rec.opened).toHaveLength(1);
        expect(autoselectOf(rec.opened[0])).toBeNull();
    });

    test('the re-signup still carries the tokens, so the hosted flow accepts the URL', () => {
        const { flow, rec } = loadFlow();

        flow.selectDifferentSoleTrader();

        const url = new URL(rec.opened[0].url);
        expect(url.searchParams.get('businessToken')).toBe('dt-1');
        expect(url.searchParams.get('autofillToken')).toBe('at-1');
    });

    test('no popup opens for a re-signup with no tokens minted', () => {
        const { flow, rec } = loadFlow();
        flow.delegationToken = '';
        flow.autofillToken = '';

        expect(flow.selectDifferentSoleTrader()).toBeNull();
        expect(rec.opened).toEqual([]);
    });

    test('a re-signup while the first popup is still open closes it rather than opening a second live tab', () => {
        const { flow, rec } = loadFlow();

        flow.openPopup();
        flow.selectDifferentSoleTrader();

        expect(rec.handles).toHaveLength(2);
        expect(rec.handles[0].closed).toBe(true);
        expect(rec.handles[1].closed).toBe(false);
    });
});

describe('the payment tile only delegates', () => {
    /**
     * @param {?object} flowStub what the component hands back, or null before boot
     * @returns {object} the loaded renderer
     */
    function loadRenderer(flowStub) {
        return loadAmdModule(
            RENDERER,
            {
                jquery: $,
                'Two_Gateway/js/model/company-capture-component': {
                    config: function () { return {}; },
                    mountSelector: function () { return ''; },
                    refreshMount: function () {},
                    countryCode: function () { return 'gb'; },
                    soleTrader: function () { return flowStub; }
                }
            },
            { document: document, window: { checkoutConfig: { payment: {} }, addEventListener: function () {} } }
        );
    }

    test('the tile hands the click to the page-level flow', () => {
        const calls = [];
        const renderer = loadRenderer({
            selectDifferentSoleTrader: function () { calls.push(true); return 'popup'; }
        });

        expect(renderer.selectDifferentSoleTrader()).toBe('popup');
        expect(calls).toHaveLength(1);
    });

    test('a tile rendered before the component booted is silent, not broken', () => {
        // Amasty and Fire Checkout re-create payment renderers on every totals
        // change, so a tile can exist before the page-level component has one.
        const renderer = loadRenderer(null);

        expect(renderer.selectDifferentSoleTrader()).toBeNull();
    });
});

describe('the template offers the link where a re-signup makes sense', () => {
    test('the link is bound to selectDifferentSoleTrader and gated on adoption', () => {
        // Gated on adoption, not on capture: a sole trader with no trading name
        // of their own has no company number, and keying on capture left them
        // no route out.
        const template = fs.readFileSync(path.resolve(__dirname, '..', '..', TEMPLATE), 'utf8');
        const link = /class="two-select-different-sole-trader"[\s\S]{0,400}?selectDifferentSoleTrader\(\)/.exec(template);

        expect(link).not.toBeNull();
        expect(link[0]).toContain('visible: soleTraderAdopted');
    });

    test('the link is a sibling after the company field, never nested inside it', () => {
        const template = fs.readFileSync(path.resolve(__dirname, '..', '..', TEMPLATE), 'utf8');

        expect(template.indexOf('id="company_name"')).toBeGreaterThan(-1);
        expect(template.indexOf('two-select-different-sole-trader'))
            .toBeGreaterThan(template.indexOf('id="company_name"'));
    });
});
