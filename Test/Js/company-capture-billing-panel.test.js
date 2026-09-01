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
 * Also models `$(document).on(event, selector, handler)` delegation, enough
 * to fire the ONE delegated listener a spec cares about by selector —
 * production's own event object is never read by either handler this file
 * fires, so the stub handed to a fired handler carries no `target`.
 *
 * @returns {{$: function, setVisible: function(string, boolean): void}}
 */
function makeDom() {
    const nodes = {};

    function node(selector) {
        if (nodes[selector]) return nodes[selector];
        let visible = true;
        let exists = true;
        let value = '';
        const delegated = [];
        const n = {
            get length() {
                return exists ? 1 : 0;
            },
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
            on: function (event, selectorOrHandler, maybeHandler) {
                // Delegated form only ($(document).on(event, selector, fn)) —
                // the module under test never binds directly to a node.
                if (typeof maybeHandler === 'function') {
                    delegated.push({ event: String(event).split('.')[0], selector: selectorOrHandler, handler: maybeHandler });
                }
                return n;
            },
            trigger: function () {
                return n;
            },
            _setVisible: function (v) {
                visible = v;
            },
            _setExists: function (v) {
                exists = v;
            },
            _fireDelegated: function (event, selector) {
                delegated
                    .filter(function (d) { return d.event === event && d.selector === selector; })
                    .forEach(function (d) { d.handler({}); });
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
        setExists: function (selector, value) {
            node(selector)._setExists(value);
        },
        setCountry: function (selector, value) {
            node(selector).val(value);
        },
        // `document` is jsdom's real global, passed as an extraGlobal — the
        // stub's own `$(document)` resolves it to the same node every time
        // via `String(document)`, exactly as company-capture.js's own calls do.
        fireChange: function (selector) {
            node(String(document))._fireDelegated('change', selector);
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

/**
 * TWO-25554 Amasty regression: shipping's own writes must never land in
 * billing's form, even though `billingRoleFormRoot()` is happy to answer
 * with it (see company-capture.js's `shippingWriteRoot()` doc). Amasty's
 * one-step layout pre-renders every payment method's billing fieldset
 * hidden from page load, so `billingRoleFormRoot()` — which wins on
 * presence alone, visible or not — would otherwise outrank shipping's own
 * visible form the whole time shipping is the panel in play.
 */
describe('the shipping panel writes into its OWN form — never billingRoleFormRoot()\'s answer', () => {
    /**
     * @param {object} dom
     * @returns {{mock: object, lookupRoots: Array}}
     */
    function companySearchSpy(dom) {
        const lookupRoots = [];
        const mock = Object.assign({}, defaultMocks()['Two_Gateway/js/model/company-search'], {
            // A billing candidate exists in the DOM (hidden) from page load,
            // same as Amasty — billingRoleFormRoot() answers with it
            // regardless of whether shipping is the panel in play.
            billingRoleFormRoot: function () { return dom.$(BILLING_FORM); },
            hasPrimaryAddressForm: function () { return true; },
            lookupCompanyAddress: function (config, item, root) {
                lookupRoots.push(root);
                return null;
            }
        });
        return { mock: mock, lookupRoots: lookupRoots };
    }

    function loadWithSpy(dom, spy) {
        return loadCompanyCapture(
            {
                jquery: dom.$,
                'Two_Gateway/js/model/brand-config': brandConfigMock({
                    isCompanySearchEnabled: true,
                    checkoutApiUrl: 'https://api.example.test',
                    supportedCompanyTypes: {}
                }),
                'Two_Gateway/js/model/company-search': spy.mock
            },
            { document: document, window: window }
        );
    }

    test('a shipping pick writes into the shipping form, not the hidden billing form Amasty always renders', () => {
        const dom = makeDom();
        dom.setVisible(BILLING_FIELD, false);
        const spy = companySearchSpy(dom);
        const capture = loadWithSpy(dom, spy);
        capture.shipping.start();

        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });

        expect(spy.lookupRoots).toHaveLength(1);
        expect(spy.lookupRoots[0]).toBe(dom.$(ADDRESS_FORM));
    });

    test('mounted on the tile fallback (no shipping form on this checkout at all), still defers to billingRoleFormRoot() as before the split', () => {
        const dom = makeDom();
        dom.setExists(ADDRESS_FIELD, false);
        const spy = companySearchSpy(dom);
        const capture = loadWithSpy(dom, spy);
        capture.shipping.start();
        expect(capture.shipping.mountSelector()).toBe('#two_gateway_form input#company_name');

        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });

        expect(spy.lookupRoots).toHaveLength(1);
        expect(spy.lookupRoots[0]).toBe(dom.$(BILLING_FORM));
    });
});

/**
 * TWO-25554 Amasty regression: `watchForMountHost()`
 * (company-capture-component.js) mounts the instant a node matching its
 * selector APPEARS — a one-shot check, never re-run on a later visibility
 * change alone. Luma only inserts the billing fieldset once "same as
 * shipping" is unchecked, so that appearance IS the toggle. Amasty renders
 * every payment method's billing fieldset hidden from page load, so the
 * one-shot check runs (and fails) before the buyer ever reveals it, and
 * nothing re-drives it afterwards without the checkbox listener this pins.
 */
describe('the "same as shipping" checkbox toggle re-checks both panels\' mounts', () => {
    const BILLING_TOGGLE = 'input[name="billing-address-same-as-shipping"]';

    // Asserts against panel() — whether mountPanel() actually RAN — not
    // mountSelector(), which recomputes fresh from current DOM state on
    // every call and would read as mounted even when refreshMount() was
    // never re-driven at all (the exact vacuous read this pins against).
    test('billing mounts once revealed, even though its field already existed hidden at boot', () => {
        const { capture, dom } = load();
        capture.start();
        expect(capture.billing.panel()).toBeNull();

        dom.setVisible(BILLING_FIELD, true);
        dom.fireChange(BILLING_TOGGLE);

        expect(capture.billing.panel()).not.toBeNull();
    });

    test('unmounts again once re-hidden, same as an explicit refreshMount() already does', () => {
        const { capture, dom } = load();
        capture.start();
        dom.setVisible(BILLING_FIELD, true);
        dom.fireChange(BILLING_TOGGLE);
        expect(capture.billing.panel()).not.toBeNull();

        dom.setVisible(BILLING_FIELD, false);
        dom.fireChange(BILLING_TOGGLE);

        expect(capture.billing.mountSelector()).toBe('');
    });
});

/**
 * TWO-25554, Fire Checkout regression: a company the BILLING panel captured
 * must never become the shipping identity's, however it reaches the payment
 * renderer.
 *
 * The first fix for this asked one question — does the billing panel hold a
 * live mount at its own field — and asked it of the DOM at the moment of the
 * write. That is sound for the mirror, which runs while nothing is
 * re-rendering, and it held on Amasty's static one-step layout in both
 * directions. It does not hold for a company arriving through the quote or
 * through `companyData`:
 *
 *  - Fire Checkout re-renders its payment area from the same `change` that
 *    pushes the pick into the quote, so the quote's notification can land
 *    while the billing fieldset is detached — mounted in every sense the
 *    buyer can see, and the query answers no;
 *  - `companyData` is a localStorage section that outlives the page load, so
 *    a company can arrive from it before any billing form exists.
 *
 * Both are modelled here by taking the billing field away AFTER the pick,
 * which is what either one looks like at the one moment that matters.
 */
describe('a company the billing panel captured is never adopted by the shipping identity', () => {
    const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

    /**
     * @param {object} capture the real company-capture module
     * @param {object} dom
     * @returns {object} the renderer, with order intent off
     */
    function loadRenderer(capture, dom) {
        const renderer = loadAmdModule(RENDERER, Object.assign({}, defaultMocks(), {
            jquery: dom.$,
            'Two_Gateway/js/model/company-capture': capture
        }), { document: document, window: window });
        renderer.getCode = function () { return 'two_payment'; };
        renderer.isOrderIntentEnabled = false;
        return renderer;
    }

    /** The billing panel, mounted and holding its own pick. */
    function billingPicks(capture, dom, company) {
        dom.setVisible(BILLING_FIELD, true);
        capture.shipping.start();
        capture.billing.start();
        capture.billing.selectCompany({ text: company, companyId: '222', lookupId: 'l2' });
    }

    /** What Fire's re-render (or a page that has not rendered one yet) leaves. */
    function billingFieldsetAway(dom, capture) {
        dom.setExists(BILLING_FIELD, false);
        expect(capture.billingOwnsCompanyField()).toBe(false);
    }

    function billingQuoteAddress(company) {
        return {
            company: company,
            telephone: '+47 123 45 678',
            customAttributes: [{ attribute_code: 'company_id', value: '222' }]
        };
    }

    test('through the quote\'s billing address, with the fieldset gone at the moment it notifies', () => {
        const { capture, dom } = load();
        billingPicks(capture, dom, 'Billing Co');
        const renderer = loadRenderer(capture, dom);
        billingFieldsetAway(dom, capture);

        renderer.updateBillingAddress(billingQuoteAddress('Billing Co'));

        expect(capture.shipping.identity().companyName()).toBe('');
        expect(capture.shipping.identity().companyId()).toBe('');
        expect(capture.billing.identity().companyName()).toBe('Billing Co');
    });

    test('through the companyData section, which outlives the page the panel was mounted on', () => {
        const { capture, dom } = load();
        billingPicks(capture, dom, 'Billing Co');
        const renderer = loadRenderer(capture, dom);
        billingFieldsetAway(dom, capture);

        renderer.applyCompanyData(
            { companyName: 'Billing Co', companyId: '222' },
            { authoritative: true }
        );

        expect(capture.shipping.identity().companyName()).toBe('');
    });

    test('the telephone on that same billing address still travels', () => {
        const { capture, dom } = load();
        billingPicks(capture, dom, 'Billing Co');
        const renderer = loadRenderer(capture, dom);
        billingFieldsetAway(dom, capture);

        renderer.updateBillingAddress(billingQuoteAddress('Billing Co'));

        expect(renderer.telephone()).toBe('+47123 45 678');
    });

    test('a company the billing panel never captured is still the shipping identity\'s to adopt', () => {
        const { capture, dom } = load();
        capture.shipping.start();
        capture.billing.start();
        const renderer = loadRenderer(capture, dom);

        renderer.updateBillingAddress(billingQuoteAddress('Some Other Co'));

        expect(capture.shipping.identity().companyName()).toBe('Some Other Co');
    });

    test('the shipping step\'s own company still restores from the section while a billing panel is mounted', () => {
        // The section is how a reload restores the shipping company. Gating it
        // on the billing panel's MOUNT — rather than on billing's own capture —
        // would take that away from every buyer with a distinct billing address.
        const { capture, dom } = load();
        billingPicks(capture, dom, 'Billing Co');
        const renderer = loadRenderer(capture, dom);

        renderer.applyCompanyData({ companyName: 'Shipping Co', companyId: '111' });

        expect(capture.shipping.identity().companyName()).toBe('Shipping Co');
    });
});
