/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25554: with a distinct billing address, EACH PANEL'S OWN COMPANY FIELD
 * displays that panel's capture and nothing else.
 *
 * Not about the address sub-fields `shippingWriteRoot()` already scopes: about
 * the value of the company field ITSELF, on the two page-level paths that can
 * reach it — the shipping step's own paint (`setCompanyData()`) and the quote's
 * billing address (`updateBillingAddress()`).
 *
 * Asserted on the two FIELDS in a real document, with the real
 * `company-search.js` behind them: the identity-level independence is
 * pinned by company-capture-billing-panel.test.js and held throughout, which
 * is exactly why it proved nothing about what the buyer saw.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';
const ADDRESS_STEP = 'view/frontend/web/js/view/address-autocomplete.js';
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

const SHIPPING_COMPANY = '#shipping-new-address-form input[name="company"]';
const BILLING_COMPANY = '[data-form="billing-new-address"] input[name="company"]';

/** @returns {string} the value one company field is displaying */
function displayed(selector) {
    return document.querySelector(selector).value;
}

/**
 * jQuery-lite over the real jsdom tree — the union of what the address step
 * and the mirror actually call, hand-rolled for the same reason every sibling
 * spec does it (jQuery is not a devDependency of this manifest).
 *
 * @returns {Function}
 */
function makeDollar() {
    const dataStore = new WeakMap();

    const api = {
        get: function (index) {
            return this.nodes[index];
        },
        each: function (fn) {
            this.nodes.forEach(function (node) {
                fn.call(node);
            });
            return this;
        },
        first: function () {
            return wrap(this.nodes.slice(0, 1));
        },
        eq: function (index) {
            return wrap(this.nodes.slice(index, index + 1));
        },
        closest: function (selector) {
            const out = [];
            this.nodes.forEach(function (node) {
                const found = node.closest(selector);
                if (found && out.indexOf(found) === -1) out.push(found);
            });
            return wrap(out);
        },
        find: function (selector) {
            const out = [];
            this.nodes.forEach(function (node) {
                Array.prototype.push.apply(out, node.querySelectorAll(selector));
            });
            return wrap(out);
        },
        is: function (selector) {
            // jsdom has no layout, so jQuery's own `:visible` would answer
            // false for every node; inline `display` expresses the one
            // distinction these paths ask about.
            if (selector === ':visible') {
                return this.nodes.some(function (node) {
                    return node.style.display !== 'none';
                });
            }
            return this.nodes.some(function (node) {
                return node.matches(selector);
            });
        },
        append: function (child) {
            const target = this.nodes[0];
            const nodes = child && child.nodes ? child.nodes : [child];
            if (target) nodes.forEach(function (node) { target.appendChild(node); });
            return this;
        },
        remove: function () {
            this.nodes.forEach(function (node) {
                if (node.parentNode) node.parentNode.removeChild(node);
            });
            return this;
        },
        addClass: function (name) {
            this.nodes.forEach(function (node) { node.classList.add(name); });
            return this;
        },
        attr: function (name, value) {
            if (arguments.length < 2) {
                if (!this.nodes.length || !this.nodes[0].hasAttribute(name)) return undefined;
                return this.nodes[0].getAttribute(name);
            }
            this.nodes.forEach(function (node) { node.setAttribute(name, value); });
            return this;
        },
        removeAttr: function (name) {
            this.nodes.forEach(function (node) { node.removeAttribute(name); });
            return this;
        },
        val: function (value) {
            if (!arguments.length) return this.nodes.length ? this.nodes[0].value : undefined;
            this.nodes.forEach(function (node) { node.value = value; });
            return this;
        },
        text: function (value) {
            if (!arguments.length) return this.nodes.length ? this.nodes[0].textContent : '';
            this.nodes.forEach(function (node) { node.textContent = value; });
            return this;
        },
        data: function (key, value) {
            const node = this.nodes[0];
            if (!node) return undefined;
            if (!dataStore.has(node)) dataStore.set(node, {});
            const bag = dataStore.get(node);
            if (arguments.length < 2) return bag[key];
            bag[key] = value;
            return this;
        },
        prop: function () { return this; },
        on: function () { return this; },
        off: function () { return this; },
        trigger: function () { return this; },
        show: function () { return this; },
        hide: function () { return this; }
    };

    function wrap(nodes) {
        const set = Object.create(api);
        set.nodes = nodes;
        set.length = nodes.length;
        return set;
    }

    function fromHtml(html) {
        const holder = document.createElement('div');
        holder.innerHTML = html;
        return Array.prototype.slice.call(holder.children);
    }

    function $(arg) {
        if (arg === undefined || arg === null) return wrap([]);
        if (typeof arg === 'string') {
            return arg.trim().charAt(0) === '<'
                ? wrap(fromHtml(arg))
                : wrap(Array.prototype.slice.call(document.querySelectorAll(arg)));
        }
        if (arg.nodes) return arg;
        if (arg.nodeType) return wrap([arg]);
        return wrap([]);
    }
    $.fn = {};
    $.extend = Object.assign;
    $.async = function () {};
    $.ajax = function () {
        return { done: function () { return this; }, fail: function () { return this; }, always: function () { return this; } };
    };
    return $;
}

function addressForm(id, dataForm) {
    return (
        `<form ${id ? `id="${id}"` : ''} ${dataForm ? `data-form="${dataForm}"` : ''}>` +
        '<div class="field"><div class="control"><input name="company" type="text"></div></div>' +
        '<div class="field"><div class="control">' +
        '<input name="custom_attributes[company_id]" type="text">' +
        '</div></div>' +
        '<div class="field"><div class="control">' +
        '<input name="street[0]" type="text"><input name="street[1]" type="text">' +
        '<input name="city" type="text"><input name="postcode" type="text">' +
        '<input name="region" type="text"><select name="region_id"></select>' +
        '<select name="country_id"><option value="NO" selected>Norway</option></select>' +
        '</div></div>' +
        '</form>'
    );
}

/**
 * Both panels on the page, wired to the real mirror and the real renderer.
 *
 * @returns {object}
 */
function load() {
    // Core's own billing shape: the form sits inside the block carrying the
    // "same as shipping" checkbox, which is what the mirror keys its
    // per-address record on.
    document.body.innerHTML =
        addressForm('shipping-new-address-form', '') +
        '<div class="checkout-billing-address">' +
        '<input type="checkbox" id="billing-address-same-as-shipping-two_payment"' +
        ' name="billing-address-same-as-shipping">' +
        addressForm('', 'billing-new-address') +
        '</div>';

    const $ = makeDollar();
    const globals = { document: document, window: window };
    const createIdentity = loadAmdModule(IDENTITY, {}, globals);
    const shippingIdentity = createIdentity();
    const billingIdentity = createIdentity();
    const search = loadAmdModule(SEARCH, { jquery: $ }, globals);
    const capture = {
        identity: shippingIdentity,
        shipping: {
            identity: function () { return shippingIdentity; },
            config: function () { return { isCompanySearchEnabled: true }; },
            countryCode: function () { return 'no'; },
            mountSelector: function () { return SHIPPING_COMPANY; },
            subscribeMount: function () {},
            soleTrader: function () { return null; }
        },
        billing: { identity: function () { return billingIdentity; } },
        // A distinct billing form is rendered in this fixture, so the billing
        // panel owns the billing role.
        billingRoleIdentity: function () { return billingIdentity; },
        refreshMount: function () {}
    };

    const addressStep = loadAmdModule(ADDRESS_STEP, {
        jquery: $,
        'Two_Gateway/js/model/company-capture': capture,
        'Two_Gateway/js/model/company-search': search,
        'Magento_Customer/js/customer-data': {
            set: function () {},
            get: function () { return function () { return {}; }; }
        },
        'Two_Gateway/js/model/brand-config': {
            getActiveTwoBrandConfig: function () { return { isCompanySearchEnabled: true }; }
        }
    }, globals);

    // Wired the way `initialize()` wires it in production: the field paint
    // hangs off the identity watcher.
    addressStep.watchCapturedIdentity();

    const renderer = loadAmdModule(RENDERER, Object.assign({}, defaultMocks(), {
        jquery: $,
        'Two_Gateway/js/model/company-capture': capture
    }), globals);
    renderer.getCode = function () { return 'two_payment'; };
    renderer.isOrderIntentEnabled = false;

    return {
        addressStep: addressStep,
        renderer: renderer,
        shippingIdentity: shippingIdentity,
        search: search
    };
}

/** The address step publishes name and number together, one turn later. */
function flushIdentityWatcher() {
    return new Promise(function (resolve) { setTimeout(resolve, 0); });
}

describe('a pick on one panel never paints the other panel\'s field', () => {
    /**
     * What the billing panel's own pick leaves behind: its field painted, and
     * the quote's billing address carrying that company to every payment
     * renderer.
     */
    function billingPick(renderer) {
        document.querySelector(BILLING_COMPANY).value = 'Billing Co';
        renderer.updateBillingAddress({
            company: 'Billing Co',
            telephone: '+47 123 45 678',
            customAttributes: [{ attribute_code: 'company_id', value: '222' }]
        });
    }

    test('a shipping pick paints the shipping field alone', () => {
        const { addressStep } = load();

        addressStep.setCompanyData('111', 'Shipping Co');

        expect(displayed(SHIPPING_COMPANY)).toBe('Shipping Co');
        expect(displayed(BILLING_COMPANY)).toBe('');
    });

    test('a billing pick leaves the shipping identity and field untouched', async () => {
        const { renderer, shippingIdentity } = load();

        billingPick(renderer);
        await flushIdentityWatcher();

        expect(shippingIdentity.companyName()).toBe('');
        expect(displayed(SHIPPING_COMPANY)).toBe('');
        expect(displayed(BILLING_COMPANY)).toBe('Billing Co');
    });

    test('the telephone still travels from that same billing address', () => {
        const { renderer } = load();

        billingPick(renderer);

        expect(renderer.telephone()).toBe('+47123 45 678');
    });
});
