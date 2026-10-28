/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-26775: the link a blocked sole-trader popup leaves under the company
 * field belongs to sole-trader mode alone. It used to be withdrawn only by a
 * successful launch or an adoption, so it outlived every chip switch and even
 * a country change. Any move away from Sole trader now takes it down; a second
 * Sole trader click that is still blocked keeps it.
 *
 * Driven through the real component and the real sole-trader flow, with
 * `window.open` answering null the way a popup blocker does.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const CONTROLLER = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';

function flush() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** A started component on a GB checkout whose signup popup is always blocked. */
async function startBlocked() {
    const globals = {
        window: { open: function () { return null; }, addEventListener: function () {}, removeEventListener: function () {} },
        document: document,
        setInterval: function () { return 1; },
        clearInterval: function () {},
        btoa: btoa,
        fetch: function (url) {
            if (String(url).indexOf('tokens') === -1) return Promise.resolve({ ok: false, status: 404 });
            return Promise.resolve({
                ok: true,
                json: function () { return Promise.resolve([{ delegation_token: 'dt', autofill_token: 'at' }]); }
            });
        }
    };
    const Controller = loadAmdModule(CONTROLLER, {}, globals);
    const SoleTrader = loadAmdModule(SOLE_TRADER, {}, globals);
    const prompt = { calls: 0, visible: false };
    const host = {};
    Controller.HOST_CONTRACT.forEach(function (member) {
        host[member] = function () { return undefined; };
    });
    Object.assign(host, {
        config: {
            checkoutApiUrl: 'https://api.example',
            checkoutPageUrl: 'https://checkout.example',
            isCompanySearchEnabled: false,
            supportedCompanyTypes: { gb: ['SOLE_TRADER'], fr: ['LIMITED_COMPANY'] }
        },
        Panel: function () {},
        SoleTraderFlow: SoleTrader,
        identity: loadAmdModule(IDENTITY)(),
        search: {},
        addressFieldSelector: '',
        tileFieldSelector: '',
        fieldExists: function () { return false; },
        isVirtualCart: function () { return false; },
        getAdjacentCountry: function () { return null; },
        getQuoteCountry: function () { return 'gb'; },
        getFallbackCountry: function () { return ''; },
        watchCountryChanges: function () {},
        tokensUrl: function () { return 'https://shop.example/two/tokens'; },
        signupPrefill: function () { return {}; },
        signupCountry: function () { return 'GB'; },
        supportedCompanyTypesUrl: function (country) { return `https://registry.example/${country}`; },
        renderSignupPrompt: function (show) {
            prompt.calls += 1;
            prompt.visible = show;
        }
    });
    const component = new Controller(host);
    component.start();
    await flush();
    component.soleTraderMode();
    return { component: component, prompt: prompt };
}

describe('the blocked-popup link follows the mode (TWO-26775)', () => {
    test.each([
        ['Registered company takes it down', (c) => c.registeredMode({ openDropdown: true }), false],
        ['Enter manually takes it down', (c) => c.manualEntryMode(), false],
        ['a country change that resets the mode takes it down', (c) => c.onCountryChanged('fr'), false],
        ['a second Sole trader click, still blocked, keeps it', (c) => c.soleTraderMode(), true]
    ])('%s', async (description, act, visible) => {
        const { component, prompt } = await startBlocked();
        expect(prompt.visible).toBe(true);

        act(component);
        await flush();

        expect({ visible: prompt.visible, description }).toEqual({ visible, description });
    });
});
