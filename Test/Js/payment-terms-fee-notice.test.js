/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * An empty fee span means "no fee for this term", so a fetch that could not
 * answer must say so rather than leave the fee area blank (ABN-512).
 */

'use strict';

const jq = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const MODULE = 'view/adminhtml/web/js/payment-terms-config.js';
const CONTAINER_ID = 'two_payment_payment_terms_payment_terms_checkboxes';
const NOTICE = '.two-term-checkboxes__fee-notice';

function render() {
    document.body.innerHTML =
        '<input name="form_key" value="k"/>'
        + '<input id="two_payment_payment_terms_payment_terms_duration_days" value=""/>'
        + '<select id="two_payment_payment_terms_default_payment_term"></select>'
        + '<select id="two_payment_payment_terms_surcharge_type"></select>'
        + '<div id="' + CONTAINER_ID + '" class="two-term-checkboxes" data-fees-url="/two/config/fees">'
        + '  <div class="two-term-checkboxes__item">'
        + '    <input type="checkbox" class="two-term-checkboxes__input" value="30"/>'
        + '    <span class="two-term-checkboxes__fee" data-term="30"></span>'
        + '  </div>'
        + '</div>';
}

/** Loads the module with jQuery's ajax replaced by a settleable double. */
function load() {
    render();
    const requests = [];
    jq.ajax = function (options) {
        const settlers = { done: [], fail: [] };
        const jqxhr = {
            options: options,
            done: function (fn) { settlers.done.push(fn); return jqxhr; },
            fail: function (fn) { settlers.fail.push(fn); return jqxhr; },
            settleDone: function (raw) { settlers.done.forEach(function (fn) { fn(raw); }); },
            settleFail: function () { settlers.fail.forEach(function (fn) { fn(); }); }
        };
        requests.push(jqxhr);
        return jqxhr;
    };

    const mocks = defaultMocks();
    mocks.jquery = jq;
    const module = loadAmdModule(MODULE, mocks);
    module.init();

    return { requests: requests };
}

const STALE = {
    success: true,
    currency: 'EUR',
    fees: { 30: { percentage: 1.5, fixed: 0 } },
    stale: true,
    fetched_at_display: 'Sep 1, 2026, 9:00:00 AM'
};
const FRESH = { success: true, currency: 'EUR', fees: { 30: { percentage: 1.5, fixed: 0 } }, stale: false };

describe('inline merchant fees, when the pricing service cannot answer', () => {
    it.each([
        ['an upstream failure with nothing cached says so', { success: false, error: 'upstream' }, 'could not be reached'],
        ['a response with no fee set at all says so', { success: true, currency: 'EUR' }, 'could not be reached'],
        ['a last-known-good set says it is not current', STALE, 'could not be refreshed'],
        ['a fresh set carries no notice', FRESH, '']
    ])('%s', (description, response, expectedFragment) => {
        const loaded = load();
        expect(loaded.requests.length).toBe(1);

        loaded.requests[0].settleDone(response);

        const notice = jq(NOTICE).text();
        if (expectedFragment === '') {
            expect(notice).toBe('', description);
        } else {
            expect(notice).toContain(expectedFragment);
        }
    });

    it('clears a stale notice once the figures come back current', () => {
        const loaded = load();
        loaded.requests[0].settleDone(STALE);
        expect(jq(NOTICE).text()).toContain('could not be refreshed');

        // A term change re-asks, and this time the service answers.
        jq('.two-term-checkboxes__input').trigger('change');
        loaded.requests[loaded.requests.length - 1].settleDone(FRESH);

        expect(jq(NOTICE).text()).toBe('');
    });

    it('names when the figures it is showing were retrieved', () => {
        const loaded = load();

        loaded.requests[0].settleDone(STALE);

        expect(jq(NOTICE).text()).toContain('Sep 1, 2026, 9:00:00 AM');
    });

    it('renders the figures it was given even when they are not current', () => {
        const loaded = load();

        loaded.requests[0].settleDone(STALE);

        expect(jq('.two-term-checkboxes__fee[data-term="30"]').text()).toContain('1.50%');
    });

    it('says so when the request itself fails', () => {
        const loaded = load();

        loaded.requests[0].settleFail();

        expect(jq(NOTICE).text()).toContain('could not be reached');
        expect(jq('.two-term-checkboxes__fee[data-term="30"]').text()).toBe('');
    });
});
