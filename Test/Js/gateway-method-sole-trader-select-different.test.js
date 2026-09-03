/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §7 — re-signing up as a different sole trader.
 *
 * Two routes reach the same place: the "Select a different sole trader" link
 * the capture panel renders under its own company field, and re-clicking the
 * sole-trader chip once one is already adopted. Both must carry
 * `autoselect=false`, or the hosted flow silently re-picks the registration the
 * buyer is trying to replace and hands back the identity already on screen — a
 * dead end with no visible cause.
 *
 * Both routes belong to the panel that holds the adoption, so both are driven
 * through the real component here. That the link never reaches the OTHER
 * panel's flow is company-panel-chrome.test.js.
 */

'use strict';

const $ = require('jquery');
const {
    loadAmdModule,
    loadCompanyCapture,
    defaultMocks,
    loadCompanySearchPanel,
    dispatchNative,
    brandConfigMock,
    quoteAddress
} = require('./amd-harness');

const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';

const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';

/**
 * The mocks and sandbox globals the flow and the component share.
 *
 * @returns {object} `{ rec, identity, mocks, globals }`
 */
function makeEnv() {
    const rec = { opened: [], handles: [], tokenMints: 0 };

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
                billingAddress: quoteAddress({ countryId: 'GB' }),
                getQuoteId: function () { return 'cart-1'; },
                isVirtual: function () { return false; }
            }
        ),
        'Two_Gateway/js/model/company-search': Object.assign(
            {},
            defaultMocks()['Two_Gateway/js/model/company-search'],
            { apiClientParams: function () { return {}; }, currentAddressFormCountry: function () { return ''; } }
        ),
        'Two_Gateway/js/model/brand-config': brandConfigMock({
            checkoutPageUrl: CHECKOUT_PAGE_URL,
            checkoutApiUrl: 'https://api.example',
            isCompanySearchEnabled: true,
            supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
        })
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

    return { rec: rec, mocks: mocks, globals: globals };
}

/**
 * The real flow over Luma's wired capture component, with tokens already held.
 *
 * The component is deliberately NOT booted: a boot would mint tokens of its
 * own, and these cases are about the ones set below.
 *
 * @returns {object} `{ flow, rec }`
 */
function loadFlow() {
    const env = makeEnv();
    const SoleTraderCtor = loadAmdModule(SOLE_TRADER, env.mocks, env.globals);
    const flow = new SoleTraderCtor(loadCompanyCapture(env.mocks, env.globals).shipping);
    flow.delegationToken = 'dt-1';
    flow.autofillToken = 'at-1';
    return { flow: flow, rec: env.rec };
}

/**
 * The real component with the real flow underneath it, booted against a
 * payment-tile company field so the chips exist to be clicked.
 *
 * The REAL panel, not the harness default: the chips live inside the popover
 * now, so an inert panel renders none of them and every chip case below would
 * be reaching past the click handler it exists to drive.
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
    const mocks = Object.assign({}, env.mocks, {
        'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel(
            $,
            env.mocks['Two_Gateway/js/model/company-search'],
            env.globals
        )
    });
    const component = loadCompanyCapture(mocks, env.globals).shipping;
    component.start();
    await new Promise((resolve) => setTimeout(resolve, 0));
    return { component: component, flow: component.soleTrader(), rec: env.rec, identity: component.identity() };
}

/**
 * Open the popover the buyer's own way and hand back one of its chips.
 *
 * Clicking the field first is not setup, it is the flow: the panel is `hidden`
 * until then, so a chip taken without it is one no buyer could have reached.
 *
 * @param {string} mode
 * @returns {Element}
 */
function chip(mode) {
    dispatchNative($('#two_gateway_form input#company_name')[0], 'mousedown');
    const node = document.querySelector(`.two-company-mode-chip[data-two-chip="${mode}"]`);
    expect(node).not.toBeNull();
    expect(node.closest('.two-company-dropdown').hasAttribute('hidden')).toBe(false);
    expect(node.classList.contains('two-hidden')).toBe(false);
    return node;
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

        chip('soletrader').click();

        expect(rec.opened).toHaveLength(1);
        expect(autoselectOf(rec.opened[0])).toBe('false');
    });

    test('the first sole-trader chip click carries no autoselect param', async () => {
        const { rec } = await startStack();

        chip('soletrader').click();
        await new Promise((resolve) => setTimeout(resolve, 0));

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

describe('the panel that adopted renders the link, and the click reaches its own flow', () => {
    /** @returns {?Element} the rendered link, or null */
    function link() {
        return document.querySelector('.two-select-different-sole-trader__link');
    }

    test('nothing is offered until a sole trader has been adopted', async () => {
        // Gated on adoption, not on capture: a sole trader with no trading name
        // of their own has no company number, and keying on capture left them
        // no route out.
        await startStack();

        expect(link()).toBeNull();
    });

    test('the click re-signs up with autoselect off', async () => {
        const { rec, identity, flow } = await startStack();
        flow.delegationToken = 'dt-1';
        flow.autofillToken = 'at-1';
        identity.captureMode('soletrader');
        identity.soleTraderAdopted(true);

        link().click();

        expect(rec.opened).toHaveLength(1);
        expect(autoselectOf(rec.opened[0])).toBe('false');
    });

    test('the link sits under the panel\'s own company field', async () => {
        const { identity } = await startStack();
        identity.soleTraderAdopted(true);

        const field = document.querySelector('#two_gateway_form input#company_name');
        expect(link().closest('.control')).toBe(field.closest('.control'));
    });
});
