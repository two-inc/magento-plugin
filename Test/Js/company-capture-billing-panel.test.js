/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25554: the billing panel's own mount — genuinely independent of the
 * shipping panel's, never a re-point of one shared instance (see
 * company-capture-component-lifecycle.test.js for the "exactly two
 * instances" invariant this relies on).
 */

'use strict';

const {
    loadAmdModule,
    loadCompanyCapture,
    defaultMocks,
    brandConfigMock
} = require('./amd-harness');

const ADDRESS_FORM = '#shipping-new-address-form';
const ADDRESS_FIELD = `${ADDRESS_FORM} input[name="company"]`;
const ADDRESS_COUNTRY = `${ADDRESS_FORM} select[name="country_id"]`;
const BILLING_FORM = '[data-form="billing-new-address"]';
const BILLING_FIELD = `${BILLING_FORM} input[name="company"]`;
const BILLING_COUNTRY = `${BILLING_FORM} select[name="country_id"]`;

/**
 * A minimal jQuery-shaped double over a fixed set of named nodes, each with a
 * settable `visible` flag — real jQuery's `:visible` is unusable under jsdom
 * (no layout engine, so every element reports zero size), so this models
 * visibility directly rather than through computed layout.
 *
 * @returns {{$: function, setVisible: function(string, boolean): void}}
 */
function makeDom() {
    const nodes = {};

    function node(selector) {
        if (nodes[selector]) return nodes[selector];
        let visible = true;
        let value = '';
        const n = {
            length: 1,
            val: function (next) {
                if (!arguments.length) return value;
                value = next;
                return n;
            },
            is: function (expr) {
                return expr === ':visible' ? visible : false;
            },
            filter: function () {
                return visible ? n : { length: 0 };
            },
            find: function (sel) {
                return node(selector + ' >find> ' + sel);
            },
            first: function () {
                return n;
            },
            on: function () {
                return n;
            },
            trigger: function () {
                return n;
            },
            _setVisible: function (v) {
                visible = v;
            }
        };
        nodes[selector] = n;
        return n;
    }

    function $(selector) {
        if (typeof selector !== 'string') return node(String(selector));
        return node(selector);
    }
    $.async = function (selector, cb) {
        cb(node(selector));
    };

    return {
        $: $,
        setVisible: function (selector, value) {
            node(selector)._setVisible(value);
        },
        setCountry: function (selector, value) {
            node(selector).val(value);
        }
    };
}

/**
 * @param {object} [overrides] merged over the standard mocks
 * @returns {object} `{ capture, dom }`
 */
function load(overrides) {
    const dom = makeDom();
    // Absent until made visible — matches core rendering no billing form at
    // all under "same as shipping" (checked, the default).
    dom.setVisible(BILLING_FIELD, false);
    dom.setCountry(ADDRESS_COUNTRY, 'no');
    dom.setCountry(BILLING_COUNTRY, 'gb');

    const capture = loadCompanyCapture(
        Object.assign(
            {
                jquery: dom.$,
                'Two_Gateway/js/model/brand-config': brandConfigMock({
                    isCompanySearchEnabled: true,
                    checkoutApiUrl: 'https://api.example.test',
                    supportedCompanyTypes: {}
                })
            },
            overrides || {}
        ),
        { document: document, window: window }
    );
    return { capture: capture, dom: dom };
}

describe('the billing panel only ever mounts at its own field', () => {
    test('absent (checked "same as shipping"): billing does not mount, and never falls back to the tile', () => {
        const { capture } = load();
        capture.billing.start();

        expect(capture.billing.mountSelector()).toBe('');
    });

    test('visible (unchecked): billing mounts at its own field', () => {
        const { capture, dom } = load();
        dom.setVisible(BILLING_FIELD, true);
        capture.billing.start();

        expect(capture.billing.mountSelector()).toBe(BILLING_FIELD);
    });

    test('present but hidden (re-checked after being unchecked): billing does not mount', () => {
        // TWO-25461's own finding, reused here: core can leave the billing
        // form in the DOM hidden rather than removing it.
        const { capture, dom } = load();
        dom.setVisible(BILLING_FIELD, true);
        capture.billing.start();
        expect(capture.billing.mountSelector()).toBe(BILLING_FIELD);

        dom.setVisible(BILLING_FIELD, false);
        capture.billing.refreshMount();

        expect(capture.billing.mountSelector()).toBe('');
    });
});

describe('each panel reads ONLY its own address form\'s country — never a shared one', () => {
    test('billing reads the billing form\'s country, not shipping\'s, even though they differ', () => {
        const { capture, dom } = load();
        dom.setVisible(BILLING_FIELD, true);
        capture.billing.start();

        expect(capture.billing.countryCode()).toBe('gb');
        expect(capture.shipping.countryCode()).toBe('no');
    });

    test('a shipping country change does not move billing\'s answer, and vice versa', () => {
        const { capture, dom } = load();
        dom.setVisible(BILLING_FIELD, true);
        capture.shipping.start();
        capture.billing.start();

        dom.setCountry(ADDRESS_COUNTRY, 'se');
        expect(capture.billing.countryCode()).toBe('gb');

        dom.setCountry(BILLING_COUNTRY, 'dk');
        expect(capture.shipping.countryCode()).toBe('se');
    });
});

describe('the two panels\' captures are independent — a pick on one never reaches the other', () => {
    test('a registered pick on shipping leaves billing\'s own identity untouched', () => {
        const { capture, dom } = load();
        dom.setVisible(BILLING_FIELD, true);
        capture.shipping.start();
        capture.billing.start();

        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });

        expect(capture.shipping.identity().companyId()).toBe('111');
        expect(capture.billing.identity().companyId()).toBe('');
    });

    test('a registered pick on billing leaves shipping\'s own identity untouched', () => {
        const { capture, dom } = load();
        dom.setVisible(BILLING_FIELD, true);
        capture.shipping.start();
        capture.billing.start();

        capture.billing.selectCompany({ text: 'Billing Co', companyId: '222', lookupId: 'l2' });

        expect(capture.billing.identity().companyId()).toBe('222');
        expect(capture.shipping.identity().companyId()).toBe('');
    });
});

describe('the resolved identity, end to end, follows the resolution rule live', () => {
    test('billing absent: the resolved identity mirrors shipping\'s pick', () => {
        const { capture } = load();
        capture.shipping.start();
        capture.billing.start();

        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });

        expect(capture.identity.companyId()).toBe('111');
    });

    test('billing distinct with a number: the resolved identity switches to billing\'s pick', () => {
        const { capture, dom } = load();
        capture.shipping.start();
        capture.billing.start();
        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });
        expect(capture.identity.companyId()).toBe('111');

        dom.setVisible(BILLING_FIELD, true);
        capture.billing.refreshMount();
        capture.billing.selectCompany({ text: 'Billing Co', companyId: '222', lookupId: 'l2' });

        expect(capture.identity.companyId()).toBe('222');
    });

    test('billing distinct but manual entry: the resolved identity falls back to shipping', () => {
        const { capture, dom } = load();
        dom.setVisible(BILLING_FIELD, true);
        capture.shipping.start();
        capture.billing.start();
        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });

        capture.billing.manualEntryMode();

        expect(capture.identity.companyId()).toBe('111');
        expect(capture.identity.companyName()).toBe('Shipping Co');
    });
});

/**
 * TWO-25554 corner case: the "same as shipping" checkbox toggles AFTER a
 * company was already resolved and — in production — sent to an order-intent
 * preview call. The stale preview's verdict must be superseded, and a fresh
 * order-intent call started for the newly-resolved company.
 */
describe('a checkbox toggle mid-checkout supersedes the order-intent already in flight', () => {
    const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

    function loadRenderer(capture, dom) {
        const requests = [];
        const renderer = loadAmdModule(RENDERER, Object.assign({}, defaultMocks(), {
            jquery: dom.$,
            'Two_Gateway/js/model/company-capture': capture
        }));
        renderer.getCode = function () { return 'two_payment'; };
        renderer.isOrderIntentEnabled = true;
        renderer.placeOrderIntent = function () {
            const request = { companyId: renderer.companyId(), settled: false };
            requests.push(request);
            return {
                always: function (fn) { request.always = fn; return this; },
                done: function (fn) { request.done = fn; return this; },
                fail: function () { return this; }
            };
        };
        renderer.initOrderIntentApprovedNotice({
            orderIntentApprovedNotice: {
                withCompany: 'Approved for {c} ({n}).',
                withoutCompany: 'Approved.',
                companyNameToken: '{c}',
                companyNumberToken: '{n}'
            }
        });
        renderer.messageContainer = { clear: function () {}, addErrorMessage: function () {}, errorMessages: { remove: function () {} } };
        return { renderer: renderer, requests: requests };
    }

    test('unchecking mid-flow starts a fresh order-intent for billing\'s company, and the old company\'s stale response is dropped', () => {
        const { capture, dom } = load();
        capture.shipping.start();
        capture.billing.start();

        const { renderer, requests } = loadRenderer(capture, dom);

        // Shipping's pick, resolved and checked while billing is not yet
        // distinct — production's own ordering (renderer boots once, on the
        // sidebar hook, before the buyer has necessarily touched anything).
        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });
        expect(requests).toHaveLength(1);
        expect(requests[0].companyId).toBe('111');

        // Billing becomes distinct, with its own company, mid-checkout.
        dom.setVisible(BILLING_FIELD, true);
        capture.billing.refreshMount();
        capture.billing.selectCompany({ text: 'Billing Co', companyId: '222', lookupId: 'l2' });

        // A fresh check started for the NEWLY resolved company.
        expect(requests).toHaveLength(2);
        expect(requests[1].companyId).toBe('222');

        // The stale response for the superseded company must not paint.
        requests[0].always();
        requests[0].done({ approved: true });
        expect(renderer.orderIntentApprovedNotice()).toBe('');

        // The current company's own response still works normally.
        requests[1].always();
        requests[1].done({ approved: true });
        expect(renderer.orderIntentApprovedNotice()).toContain('Billing Co');
    });
});
