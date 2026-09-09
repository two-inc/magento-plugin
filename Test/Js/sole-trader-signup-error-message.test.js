/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The sole-trader signup failure the buyer reads comes from the checkout config, so a
 * locale catalogue or a brand overlay can change it.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';
const CHECKOUT_PAGE_URL = 'https://checkout.example.test';

/**
 * The flow with a signup popup up, and whatever the host was told to show.
 *
 * @param {string} configuredMessage the failure copy published in the checkout config
 * @returns {object} `{ flow, postToOpener, shown }`
 */
function load(configuredMessage) {
    const handlers = {};
    const fakeWindow = {
        addEventListener: function (type, handler) { handlers[type] = handler; },
        removeEventListener: function () {},
        open: function () { return null; }
    };
    const SoleTraderCtor = loadAmdModule(SOLE_TRADER, {}, {
        document: document,
        window: fakeWindow,
        setTimeout: setTimeout,
        clearTimeout: clearTimeout
    });

    const errors = [];
    const flow = new SoleTraderCtor({
        host: function () {
            return { showError: function (message) { errors.push(message); } };
        },
        identity: function () {
            return { isSoleTrader: function () { return true; } };
        },
        config: function () {
            return { checkoutPageUrl: CHECKOUT_PAGE_URL, soleTraderErrorMessage: configuredMessage };
        },
        // Stubbed so a regression to client-side translation shows up as this value.
        translate: function () { return 'a client-side translation'; }
    });
    flow._popupWindow = { closed: false, close: function () {}, focus: function () {} };
    flow.listenForSignupResult();

    return {
        flow: flow,
        /** @param {string} data the outcome the hosted signup posted back */
        postToOpener: function (data) {
            handlers.message({ origin: CHECKOUT_PAGE_URL, source: flow._popupWindow, data: data });
        },
        shown: function () { return errors; }
    };
}

describe('the sole-trader signup failure message', () => {
    it.each([
        ['direct', 'Your sole trader account could not be verified.', 'the flow reporting the failure itself'],
        ['posted', 'Your sole trader account could not be verified.', 'a rejected signup posted back by the popup'],
        ['posted', 'A catalogue-supplied failure message.', 'a catalogue value, not the English source']
    ])('shows the configured value for %s: %s (%s)', (trigger, configuredMessage) => {
        // Given a checkout config carrying the failure copy
        const harness = load(configuredMessage);

        // When the signup fails
        if (trigger === 'direct') {
            harness.flow.showSignupError();
        } else {
            harness.postToOpener('REJECTED');
        }

        // Then the buyer reads that configured value
        expect(harness.shown()).toEqual([configuredMessage]);
    });
});
