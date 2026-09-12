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
    brandConfigMock,
    quoteAddress,
    quoteAddressValue
} = require('./amd-harness');

const ADDRESS_FORM = '#shipping-new-address-form';
const ADDRESS_FIELD = `${ADDRESS_FORM} input[name="company"]`;
const ADDRESS_COUNTRY = `${ADDRESS_FORM} select[name="country_id"]`;
const BILLING_FORM = '[data-form="billing-new-address"]';
const BILLING_FIELD = `${BILLING_FORM} input[name="company"]`;
const BILLING_COUNTRY = `${BILLING_FORM} select[name="country_id"]`;
const BILLING_TOGGLE = 'input[name="billing-address-same-as-shipping"]';

/** The cache key that makes the quote's billing address its own, not shipping's. */
const DISTINCT_BILLING_KEY = 'billing-of-its-own';

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
        const props = {};
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
            prop: function (name, next) {
                if (arguments.length < 2) return props[name];
                props[name] = next;
                return n;
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
            _setProp: function (name, v) {
                props[name] = v;
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
        setChecked: function (selector, value) {
            node(selector)._setProp('checked', value);
        },
        setCountry: function (selector, value) {
            node(selector).val(value);
        },
        // The unmounted fallback read goes through `$root.find(...)` rather
        // than the flat country selector, which the stub answers with a node
        // of its own.
        setFormCountry: function (rootSelector, value) {
            node(rootSelector).find('select[name="country_id"]').val(value);
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
 * @returns {object} `{ capture, dom, quote }` — the quote's two addresses share
 *          a cache key, so billing starts as shipping, matching the checked
 *          checkbox and the absent billing form below
 */
function load(overrides) {
    const dom = makeDom();
    // Absent until made visible — matches core rendering no billing form at
    // all under "same as shipping" (checked, the default).
    dom.setVisible(BILLING_FIELD, false);
    dom.setChecked(BILLING_TOGGLE, true);
    dom.setCountry(ADDRESS_COUNTRY, 'no');
    dom.setCountry(BILLING_COUNTRY, 'gb');

    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        shippingAddress: quoteAddress(),
        billingAddress: quoteAddress()
    });

    const capture = loadCompanyCapture(
        Object.assign(
            {
                jquery: dom.$,
                'Magento_Checkout/js/model/quote': quote,
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
    return { capture: capture, dom: dom, quote: quote };
}

/**
 * The buyer unchecks "my billing address is the same as shipping", core renders
 * the billing fieldset, and the quote takes on a second address.
 *
 * @param {object} dom
 * @param {object} quote
 */
function billingBecomesDistinct(dom, quote) {
    dom.setChecked(BILLING_TOGGLE, false);
    dom.setVisible(BILLING_FIELD, true);
    quote.billingAddress(quoteAddressValue({}, DISTINCT_BILLING_KEY));
}

describe('the billing panel only ever mounts at its own field', () => {
    test('absent (checked "same as shipping"): billing does not mount, and never falls back to the tile', () => {
        const { capture } = load();
        capture.billing.start();

        expect(capture.billing.mountSelector()).toBe('');
    });

    test('visible (unchecked): billing mounts at its own field', () => {
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
        capture.billing.start();

        expect(capture.billing.mountSelector()).toBe(BILLING_FIELD);
    });

    test('present but hidden (re-checked after being unchecked): billing does not mount', () => {
        // TWO-25461's own finding, reused here: core can leave the billing
        // form in the DOM hidden rather than removing it.
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
        capture.billing.start();
        expect(capture.billing.mountSelector()).toBe(BILLING_FIELD);

        dom.setVisible(BILLING_FIELD, false);
        capture.billing.refreshMount();

        expect(capture.billing.mountSelector()).toBe('');
    });
});

describe('each panel reads ONLY its own address form\'s country — never a shared one', () => {
    test('billing reads the billing form\'s country, not shipping\'s, even though they differ', () => {
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
        capture.billing.start();

        expect(capture.billing.countryCode()).toBe('gb');
        expect(capture.shipping.countryCode()).toBe('no');
    });

    test('a shipping country change does not move billing\'s answer, and vice versa', () => {
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
        capture.shipping.start();
        capture.billing.start();

        dom.setCountry(ADDRESS_COUNTRY, 'se');
        expect(capture.billing.countryCode()).toBe('gb');

        dom.setCountry(BILLING_COUNTRY, 'dk');
        expect(capture.shipping.countryCode()).toBe('se');
    });

    test('unmounted, billing falls back to its OWN form and not the shipping form', () => {
        // Unmounted there is no adjacent select to read, and with no country on
        // the quote either the live form read is the only answer left.
        const { capture, dom } = load();
        dom.setFormCountry(BILLING_FORM, 'dk');
        dom.setFormCountry(ADDRESS_FORM, 'se');
        capture.billing.start();

        expect(capture.billing.mountSelector()).toBe('');
        expect(capture.billing.countryCode()).toBe('dk');
    });
});

describe('billingRoleIdentity() follows billingIsDistinct(), not the presence of a panel', () => {
    test('a quote holding no billing address at all leaves shipping in the billing role', () => {
        const { capture, dom, quote } = load();
        dom.setVisible(BILLING_FIELD, true);
        dom.setChecked(BILLING_TOGGLE, false);
        capture.shipping.start();
        capture.billing.start();
        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });
        capture.billing.selectCompany({ text: 'Billing Co', companyId: '222', lookupId: 'l2' });

        quote.billingAddress(null);

        expect(capture.billingRoleIdentity().companyId()).toBe('111');
    });
});

describe('the two panels\' captures are independent — a pick on one never reaches the other', () => {
    test('a registered pick on shipping leaves billing\'s own identity untouched', () => {
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
        capture.shipping.start();
        capture.billing.start();

        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });

        expect(capture.shipping.identity().companyId()).toBe('111');
        expect(capture.billing.identity().companyId()).toBe('');
    });

    test('a registered pick on billing leaves shipping\'s own identity untouched', () => {
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
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
        const { capture, dom, quote } = load();
        capture.shipping.start();
        capture.billing.start();
        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });
        expect(capture.identity.companyId()).toBe('111');

        billingBecomesDistinct(dom, quote);
        capture.billing.refreshMount();
        capture.billing.selectCompany({ text: 'Billing Co', companyId: '222', lookupId: 'l2' });

        expect(capture.identity.companyId()).toBe('222');
    });

    test('billing distinct but manual entry: the resolved identity falls back to shipping', () => {
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
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
        const { capture, dom, quote } = load();
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
        billingBecomesDistinct(dom, quote);
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
 * TWO-25554: shipping's own writes land in shipping's form or nowhere. Amasty's
 * one-step layout pre-renders every payment method's billing fieldset hidden
 * from page load, so "the best available address form" is not an answer that can
 * be trusted to be shipping's own.
 */
describe('the shipping panel writes into its OWN form, or nowhere', () => {
    /**
     * @param {object} dom
     * @returns {{mock: object, lookupRoots: Array}}
     */
    function companySearchSpy(dom) {
        const lookupRoots = [];
        const mock = Object.assign({}, defaultMocks()['Two_Gateway/js/model/company-search'], {
            hasPrimaryAddressForm: function () { return true; },
            lookupCompanyAddress: function (config, item, root) {
                lookupRoots.push(root || null);
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

    test('mounted on the tile fallback, with no shipping form at all, it writes NOWHERE', () => {
        // A panel with no form of its own writes nowhere: the only address form
        // on such a checkout is the BILLING panel's (TWO-25554).
        const dom = makeDom();
        dom.setExists(ADDRESS_FIELD, false);
        dom.setVisible(BILLING_FIELD, false);
        const spy = companySearchSpy(dom);
        const capture = loadWithSpy(dom, spy);
        capture.shipping.start();
        expect(capture.shipping.mountSelector()).toBe('#two_gateway_form input#company_name');

        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });

        expect(spy.lookupRoots).toEqual([null]);
    });

    test('the tile is not the shipping panel\'s while the billing panel is mounted', () => {
        // One control per field: a second visible, required company field the
        // resolver ignores cannot be completed by the buyer at all (TWO-25554).
        const dom = makeDom();
        dom.setExists(ADDRESS_FIELD, false);
        dom.setVisible(BILLING_FIELD, true);
        const spy = companySearchSpy(dom);
        const capture = loadWithSpy(dom, spy);
        capture.shipping.start();
        capture.billing.start();

        expect(capture.billing.mountSelector()).toBe(BILLING_FIELD);
        expect(capture.shipping.mountSelector()).toBe('');
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
        const { capture, dom, quote } = load();
        capture.start();
        expect(capture.billing.panel()).toBeNull();

        billingBecomesDistinct(dom, quote);
        dom.fireChange(BILLING_TOGGLE);

        expect(capture.billing.panel()).not.toBeNull();
    });

    test('unmounts again once re-hidden, same as an explicit refreshMount() already does', () => {
        const { capture, dom, quote } = load();
        capture.start();
        billingBecomesDistinct(dom, quote);
        dom.fireChange(BILLING_TOGGLE);
        expect(capture.billing.panel()).not.toBeNull();

        dom.setVisible(BILLING_FIELD, false);
        dom.fireChange(BILLING_TOGGLE);

        expect(capture.billing.mountSelector()).toBe('');
    });
});

/**
 * TWO-25554: the quote's BILLING address seeds exactly one panel — the one that
 * owns the billing ROLE.
 *
 * The billing panel while billing is a distinct address, the shipping panel
 * otherwise — the only capture the resolver reads then, so seeding the billing
 * panel discards a saved company the buyer may not be able to re-search.
 *
 * Distinctness is the buyer's checkbox and the quote's own two addresses, so a
 * checkout whose billing fieldset is away at the moment the quote notifies still
 * seeds billing. The checkbox is what retires billing's own capture, and it is
 * exercised here.
 */
describe('the quote\'s billing address seeds the panel owning the billing role', () => {
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

    /**
     * The billing panel, mounted and holding its own pick. Booted through
     * `capture.start()` — the checkbox listener that retires a stale billing
     * capture is wired there, so a per-component boot pins nothing about it.
     */
    function billingPicks(booted, company) {
        billingBecomesDistinct(booted.dom, booted.quote);
        booted.capture.start();
        booted.capture.billing.selectCompany({ text: company, companyId: '222', lookupId: 'l2' });
    }

    /** What Fire's re-render (or a page that has not rendered one yet) leaves. */
    function billingFieldsetAway(dom, capture) {
        dom.setExists(BILLING_FIELD, false);
        expect(capture.billing.mountSelector()).toBe('');
    }

    /**
     * The address the quote notifies with. Also put ON the quote, which is what
     * the predicate reads — an address handed to the renderer that the quote
     * does not hold is a state no checkout reaches.
     *
     * @param {object} booted
     * @param {string} company
     * @returns {object} quote address
     */
    function quoteNotifiesBilling(booted, company) {
        const address = quoteAddressValue({
            company: company,
            telephone: '+47 123 45 678',
            customAttributes: [{ attribute_code: 'company_id', value: '222' }]
        }, booted.quote.billingAddress().getCacheKey());
        booted.quote.billingAddress(address);
        return address;
    }

    /** Core's own checkbox, re-checked: billing is shipping again. */
    function sameAsShippingAgain(booted) {
        booted.dom.setVisible(BILLING_FIELD, false);
        booted.dom.setChecked(BILLING_TOGGLE, true);
        booted.dom.fireChange(BILLING_TOGGLE);
    }

    /**
     * Two macrotasks. `watchCapturedIdentity` publishes on `setTimeout(0)`, so a
     * denial made before it has run denies a propagation that had not happened.
     *
     * @returns {Promise}
     */
    function flushCapture() {
        return new Promise(function (resolve) { setTimeout(resolve, 0); })
            .then(function () {
                return new Promise(function (resolve) { setTimeout(resolve, 0); });
            });
    }

    test('with the fieldset transiently away, a distinct billing address still seeds BILLING', async () => {
        // A third-party re-render takes the fieldset away for a moment while
        // neither the checkbox nor the quote has changed; routing on what is on
        // screen puts billing's company in the shipping panel's own field
        // (TWO-25554).
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture, dom } = booted;
        const renderer = loadRenderer(capture, dom);
        billingFieldsetAway(dom, capture);

        renderer.updateBillingAddress(quoteNotifiesBilling(booted, 'Saved Billing Co'));
        await flushCapture();

        expect(capture.billing.identity().companyName()).toBe('Saved Billing Co');
        expect(capture.billing.identity().companyId()).toBe('222');
        expect(capture.shipping.identity().companyName()).toBe('');
        expect(capture.shipping.identity().companyId()).toBe('');
    });

    test('with the fieldset transiently away the resolver still reads BILLING', async () => {
        // The seed and the resolver answer off ONE predicate, so the identity
        // the seed lands on is the identity downstream reads. Split, this is the
        // shape that stranded the company on a panel nobody reads.
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture, dom } = booted;
        const renderer = loadRenderer(capture, dom);
        billingFieldsetAway(dom, capture);

        renderer.updateBillingAddress(quoteNotifiesBilling(booted, 'Saved Billing Co'));
        await flushCapture();

        expect(capture.identity.companyName()).toBe('Saved Billing Co');
        expect(capture.identity.companyId()).toBe('222');
    });

    test('a returning buyer with no billing company field is still offered the saved company', async () => {
        // A saved distinct billing address on a checkout that renders no billing
        // company field at all: the seed lands on billing and the resolver reads
        // billing, so the tile and order-intent see the company (TWO-25554).
        const booted = load();
        const { capture, dom, quote } = booted;
        dom.setChecked(BILLING_TOGGLE, false);
        dom.setExists(BILLING_FIELD, false);
        quote.billingAddress(quoteAddressValue({}, DISTINCT_BILLING_KEY));
        capture.start();
        expect(capture.billing.mountSelector()).toBe('');
        const renderer = loadRenderer(capture, dom);

        renderer.updateBillingAddress(quoteNotifiesBilling(booted, 'Saved Billing Co'));
        await flushCapture();

        expect(capture.identity.companyName()).toBe('Saved Billing Co');
        expect(capture.identity.companyId()).toBe('222');
        expect(capture.shipping.identity().companyName()).toBe('');
    });

    test('a billing address the quote says IS the shipping address seeds SHIPPING', () => {
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture, dom, quote } = booted;
        const renderer = loadRenderer(capture, dom);
        billingFieldsetAway(dom, capture);
        quote.billingAddress(quoteAddressValue());

        renderer.updateBillingAddress(quoteNotifiesBilling(booted, 'Saved Co'));

        expect(capture.shipping.identity().companyName()).toBe('Saved Co');
        expect(capture.shipping.identity().companyId()).toBe('222');
    });

    test('re-checking "same as shipping" retires the billing panel\'s own capture', async () => {
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture } = booted;
        capture.billing.identity().soleTraderAdopted(true);
        capture.billing.identity().captureMode('soletrader');

        sameAsShippingAgain(booted);

        // Synchronously, in the checkbox handler itself. A later availability
        // resolution retires an adoption too, for its own reason, and asserting
        // only after that would pin nothing about the retirement.
        expect(capture.billing.identity().companyName()).toBe('');
        expect(capture.billing.identity().companyId()).toBe('');
        expect(capture.billing.identity().soleTraderAdopted()).toBe(false);
        expect(capture.billing.identity().captureMode()).toBe('registered');

        await flushCapture();
        expect(capture.billing.identity().companyName()).toBe('');
        expect(capture.billing.identity().soleTraderAdopted()).toBe(false);
    });

    test('the checkbox retires the capture before the quote has dropped its second address', () => {
        // The checkbox is the buyer saying so, and core updates the quote after
        // it. Reading the quote alone leaves the retired panel still winning the
        // resolution for as long as that lag lasts.
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture, quote } = booted;
        expect(capture.identity.companyName()).toBe('Billing Co');

        sameAsShippingAgain(booted);

        expect(quote.billingAddress().getCacheKey()).toBe(DISTINCT_BILLING_KEY);
        expect(capture.identity.companyName()).toBe('');
    });

    test('after that re-check the returning buyer\'s saved company seeds SHIPPING, not billing', () => {
        // A saved shipping address carries the company as a custom attribute
        // and reaches the panels only through the quote's billing address. A
        // billing capture still standing after the re-check routes that seed to
        // a panel the resolver does not read, and the tile and order-intent
        // then show nothing at all.
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture, dom, quote } = booted;
        const renderer = loadRenderer(capture, dom);
        sameAsShippingAgain(booted);
        quote.billingAddress(quoteAddressValue());

        renderer.updateBillingAddress(quoteNotifiesBilling(booted, 'Saved Shipping Co'));

        expect(capture.shipping.identity().companyName()).toBe('Saved Shipping Co');
        expect(capture.shipping.identity().companyId()).toBe('222');
        expect(capture.billing.identity().companyName()).toBe('');
    });

    test('the companyData section still restores the shipping step\'s own company', () => {
        // The section has exactly one writer — publishCompanyData() in
        // view/address-autocomplete.js, off the SHIPPING identity — so a row in
        // it is the shipping step's by construction, and is how a reload
        // restores it.
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture, dom } = booted;
        const renderer = loadRenderer(capture, dom);
        billingFieldsetAway(dom, capture);

        renderer.applyCompanyData(
            { companyName: 'Shipping Co', companyId: '111' },
            { authoritative: true }
        );

        expect(capture.shipping.identity().companyName()).toBe('Shipping Co');
        expect(capture.billing.identity().companyName()).toBe('Billing Co');
    });

    test('the telephone on that same billing address still travels', () => {
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture, dom } = booted;
        const renderer = loadRenderer(capture, dom);
        billingFieldsetAway(dom, capture);

        renderer.updateBillingAddress(quoteNotifiesBilling(booted, 'Billing Co'));

        expect(renderer.telephone()).toBe('+47123 45 678');
    });

    test('with no billing panel and no billing capture, the seed is the SHIPPING identity\'s', () => {
        // Billing is not a distinct address here, so the shipping identity is
        // the only capture the resolver reads: seeding the billing panel would
        // discard a saved company the buyer cannot re-search (TWO-25554).
        const booted = load();
        const { capture, dom } = booted;
        capture.shipping.start();
        capture.billing.start();
        const renderer = loadRenderer(capture, dom);

        renderer.updateBillingAddress(quoteNotifiesBilling(booted, 'Some Other Co'));

        expect(capture.shipping.identity().companyName()).toBe('Some Other Co');
        expect(capture.shipping.identity().companyId()).toBe('222');
        expect(capture.billing.identity().companyName()).toBe('');
    });

    test('the shipping step\'s own company still restores from the section while a billing panel is mounted', () => {
        // The section is how a reload restores the shipping company, and a
        // buyer with a distinct billing address must not lose that.
        const booted = load();
        billingPicks(booted, 'Billing Co');
        const { capture, dom } = booted;
        const renderer = loadRenderer(capture, dom);

        renderer.applyCompanyData({ companyName: 'Shipping Co', companyId: '111' });

        expect(capture.shipping.identity().companyName()).toBe('Shipping Co');
    });
});

/**
 * TWO-25554: the payment renderer's reaction to the RESOLVED company changing.
 *
 * The resolved identity is a live mirror of whichever panel wins, so a
 * billing-only pick changes it, and the renderer starts a fresh order-intent
 * check for it. That check must not travel through anything whose other half
 * writes the SHIPPING panel's identity — the address step then displays
 * billing's company as though the buyer had picked it there.
 *
 * This route touches neither the billing form, the mount nor the DOM, so
 * nothing that scopes a write by any of those speaks for it.
 */
describe('a resolved-company change starts a check WITHOUT writing the shipping identity', () => {
    const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

    /**
     * @returns {{renderer: object, requests: Array}} the renderer with order
     *          intent ON and `placeOrderIntent()` recorded rather than sent
     */
    function loadRendererWithIntent(capture, dom) {
        const requests = [];
        const renderer = loadAmdModule(RENDERER, Object.assign({}, defaultMocks(), {
            jquery: dom.$,
            'Two_Gateway/js/model/company-capture': capture
        }), { document: document, window: window });
        renderer.getCode = function () { return 'two_payment'; };
        renderer.isOrderIntentEnabled = true;
        renderer.placeOrderIntent = function () {
            requests.push({ companyId: renderer.companyId(), companyName: renderer.companyName() });
            return {
                always: function () { return this; },
                done: function () { return this; },
                fail: function () { return this; }
            };
        };
        // Assigns the module-level resolved-company reaction, same as the
        // renderer's own initialize() does in production.
        renderer.initOrderIntentApprovedNotice({
            orderIntentApprovedNotice: {
                withCompany: 'Approved for {c} ({n}).',
                withoutCompany: 'Approved.',
                companyNameToken: '{c}',
                companyNumberToken: '{n}'
            }
        });
        renderer.messageContainer = {
            clear: function () {},
            addErrorMessage: function () {},
            errorMessages: { remove: function () {} }
        };
        return { renderer: renderer, requests: requests };
    }

    test('a billing-only pick leaves the shipping identity empty', () => {
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
        capture.shipping.start();
        capture.billing.start();
        loadRendererWithIntent(capture, dom);

        capture.billing.selectCompany({ text: 'Billing Co', companyId: '222', lookupId: 'l2' });

        expect(capture.billing.identity().companyName()).toBe('Billing Co');
        expect(capture.shipping.identity().companyName()).toBe('');
        expect(capture.shipping.identity().companyId()).toBe('');
    });

    test('and still starts the check for the company that actually resolved', () => {
        const { capture, dom, quote } = load();
        billingBecomesDistinct(dom, quote);
        capture.shipping.start();
        capture.billing.start();
        const { requests } = loadRendererWithIntent(capture, dom);

        capture.billing.selectCompany({ text: 'Billing Co', companyId: '222', lookupId: 'l2' });

        expect(requests).toHaveLength(1);
        expect(requests[0].companyId).toBe('222');
    });

    test('a shipping pick still reaches the shipping identity, as its own panel\'s write', () => {
        const { capture, dom } = load();
        capture.shipping.start();
        capture.billing.start();
        loadRendererWithIntent(capture, dom);

        capture.shipping.selectCompany({ text: 'Shipping Co', companyId: '111', lookupId: 'l1' });

        expect(capture.shipping.identity().companyName()).toBe('Shipping Co');
    });
});
