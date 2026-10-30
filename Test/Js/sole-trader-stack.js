/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The real capture component, the real panel and the real sole-trader flow over
 * a payment-tile company field, for the TWO-26775 suites. `window.open` is a
 * double whose handles the cases close by hand, and which answers null while
 * `rec.blocked` is set, the way a popup blocker does. The 300ms close poll is
 * recorded rather than run, so a case ticks it at the moment it is about.
 */

'use strict';

const $ = require('jquery');
const {
    loadCompanyCapture,
    defaultMocks,
    loadCompanySearchPanel,
    dispatchNative,
    brandConfigMock,
    quoteAddress
} = require('./amd-harness');

const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';
const POPUP_CLOSE_POLL_MS = 300;
const PANEL = '.two-company-dropdown';
const FIELD = '#company_name';
const SIGNUP_PANEL = '.two-company-dropdown__signup';

/** Every stack started, so teardown() can retire them between cases. */
const started = [];

/**
 * Retire every stack a case started. The observers and `document` listeners a
 * panel and a flow arm outlive the body a case replaces, and a previous case's
 * panel re-attaching to the next case's field takes its chip clicks.
 */
function teardown() {
    started.splice(0).forEach(function (component) {
        if (component.panel()) component.panel().destroy();
        if (component.soleTrader()) component.soleTrader().dispose();
    });
}

function flush() {
    return new Promise(function (resolve) { setTimeout(resolve, 0); });
}

function makeEnv(buyerRef, email) {
    const rec = { handles: [], intervals: [], messageListeners: [], blocked: false, opens: 0 };
    let intervalSeq = 0;

    const fakeWindow = {
        open: function () {
            rec.opens += 1;
            if (rec.blocked) return null;
            const handle = {
                closed: false,
                close: function () { this.closed = true; },
                focus: function () {}
            };
            rec.handles.push(handle);
            return handle;
        },
        addEventListener: function (name, fn) { rec.messageListeners.push({ name: name, fn: fn }); },
        removeEventListener: function () {}
    };

    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        billingAddress: quoteAddress({ countryId: 'GB', email: email || '' }),
        getQuoteId: function () { return 'cart-1'; },
        isVirtual: function () { return false; }
    });

    const companySearch = Object.assign({}, defaultMocks()['Two_Gateway/js/model/company-search'], {
        apiClientParams: function () { return { client: 'magento' }; },
        currentAddressFormCountry: function () { return ''; }
    });

    const mocks = {
        jquery: $,
        'Magento_Checkout/js/model/quote': quote,
        'Two_Gateway/js/model/company-search': companySearch,
        'Two_Gateway/js/model/brand-config': brandConfigMock({
            checkoutPageUrl: CHECKOUT_PAGE_URL,
            checkoutApiUrl: 'https://api.example',
            isCompanySearchEnabled: true,
            supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
        }),
        'Magento_Ui/js/model/messageList': {
            addErrorMessage: function () {},
            addSuccessMessage: function () {}
        }
    };

    const globals = {
        document: document,
        window: fakeWindow,
        btoa: global.btoa,
        setInterval: function (fn, ms) {
            intervalSeq += 1;
            rec.intervals.push({ id: intervalSeq, fn: fn, ms: ms });
            return intervalSeq;
        },
        // Marked rather than dropped: a case can still reach a stopped poll, and asks.
        clearInterval: function (id) {
            rec.intervals.forEach(function (entry) {
                if (entry.id === id) entry.cleared = true;
            });
        },
        fetch: function (url) {
            if (String(url).indexOf('get-tokens') !== -1) {
                return Promise.resolve({
                    ok: true,
                    json: function () {
                        return Promise.resolve([{ delegation_token: 'dt', autofill_token: 'at' }]);
                    }
                });
            }
            if (String(url).indexOf('/autofill/v1/buyer/current') !== -1 && buyerRef.value) {
                const buyer = buyerRef.value;
                return Promise.resolve({ ok: true, json: function () { return Promise.resolve(buyer); } });
            }
            return Promise.resolve({ ok: false, status: 404 });
        }
    };

    return { rec: rec, mocks: mocks, globals: globals };
}

/**
 * @param {object} [options] `{ buyer, email, extraHtml }`: the held autofill
 *        record, the checkout email, markup added beside the form
 * @returns {Promise<object>} `{ component, identity, rec, buyerRef }`
 */
async function startStack(options) {
    const settings = options || {};
    document.body.innerHTML =
        '<form id="two_gateway_form">'
        + '<div class="field"><div class="control">'
        + '<input id="company_name" name="company_name" />'
        + '</div></div></form>'
        + (settings.extraHtml || '');
    const buyerRef = { value: settings.buyer || null };
    const env = makeEnv(buyerRef, settings.email);
    const mocks = Object.assign({}, env.mocks, {
        'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel(
            $,
            env.mocks['Two_Gateway/js/model/company-search'],
            env.globals
        )
    });
    const component = loadCompanyCapture(mocks, env.globals).shipping;
    component.start();
    started.push(component);
    await flush();
    await flush();
    return { component: component, identity: component.identity(), rec: env.rec, buyerRef: buyerRef };
}

/** Open the popover the buyer's way and hand back one of its chips. */
function chip(mode) {
    const field = document.querySelector(FIELD);
    const panel = document.querySelector(PANEL);
    if (!panel || panel.hasAttribute('hidden')) dispatchNative(field, 'mousedown');
    return document.querySelector('.two-company-mode-chip[data-two-chip="' + mode + '"]');
}

function popoverIsOpen() {
    const node = document.querySelector(PANEL);
    return !!node && !node.hasAttribute('hidden');
}

/** The close poll the latest launch armed. */
function closePoll(rec) {
    const polls = rec.intervals.filter(function (entry) { return entry.ms === POPUP_CLOSE_POLL_MS; });
    return polls[polls.length - 1];
}

/**
 * Run a close poll over a closed popup until it acts: it gives an ACCEPTED
 * queued behind the close one more tick before it abandons.
 *
 * @param {{fn: function, cleared: boolean}} poll
 */
function seeClosed(poll) {
    for (let tick = 0; tick < 2 && !poll.cleared; tick += 1) poll.fn();
}

module.exports = {
    CHECKOUT_PAGE_URL: CHECKOUT_PAGE_URL,
    PANEL: PANEL,
    FIELD: FIELD,
    SIGNUP_PANEL: SIGNUP_PANEL,
    flush: flush,
    teardown: teardown,
    startStack: startStack,
    chip: chip,
    popoverIsOpen: popoverIsOpen,
    closePoll: closePoll,
    seeClosed: seeClosed
};
