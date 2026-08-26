/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326 §5 and §7, address step (Luma / Amasty OneStepCheckout / Fire
 * Checkout — one code path).
 *
 * The captured organisation number must appear as PLAIN TEXT under the
 * company-name field once a search result has been selected, and must appear
 * in no other state:
 *
 *  - never before a selection ("not visible before a result is selected");
 *  - never in manual-entry mode, which is name-only capture, so a number
 *    rendered there would assert a registry identity the buyer never picked;
 *  - never as an editable control at any point.
 *
 * The separate `custom_attributes[company_id]` INPUT is a different object
 * and stays in the DOM (it still submits) — hidden by CSS, pinned by
 * address-step-company-id-hidden.test.js. This file is about the text label
 * that replaced it visually.
 *
 * Backed by a real jsdom tree rather than a recording double: every
 * assertion here is about what is actually in the document and what it says,
 * which is the property that kept being claimed and kept not holding.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const MODULE = 'view/frontend/web/js/view/address-autocomplete.js';
const NAME_SELECTOR = '#shipping-new-address-form input[name="company"]';
const ID_SELECTOR =
    '#shipping-new-address-form input[name="custom_attributes[company_id]"]';
const TEXT_CLASS = 'two-company-id-text';

/** jQuery-lite over real jsdom nodes — only what this component path calls. */
function makeMiniQuery() {
    const dataStore = new WeakMap();

    const api = {
        get: function (i) {
            return this.nodes[i];
        },
        closest: function (sel) {
            const out = [];
            this.nodes.forEach(function (node) {
                const found = node.closest(sel);
                if (found && out.indexOf(found) === -1) out.push(found);
            });
            return wrap(out);
        },
        find: function (sel) {
            const out = [];
            this.nodes.forEach(function (node) {
                Array.prototype.push.apply(out, node.querySelectorAll(sel));
            });
            return wrap(out);
        },
        append: function (child) {
            const nodes = child && child.nodes ? child.nodes : [child];
            const target = this.nodes[0];
            if (target) {
                nodes.forEach(function (n) {
                    target.appendChild(n);
                });
            }
            return this;
        },
        remove: function () {
            this.nodes.forEach(function (node) {
                if (node.parentNode) node.parentNode.removeChild(node);
            });
            return this;
        },
        addClass: function (cls) {
            this.nodes.forEach(function (node) {
                node.classList.add(cls);
            });
            return this;
        },
        attr: function (name, value) {
            if (arguments.length < 2) {
                return this.nodes.length ? this.nodes[0].getAttribute(name) : undefined;
            }
            this.nodes.forEach(function (node) {
                node.setAttribute(name, value);
            });
            return this;
        },
        text: function (next) {
            if (!arguments.length) return this.nodes.length ? this.nodes[0].textContent : '';
            this.nodes.forEach(function (node) {
                node.textContent = next;
            });
            return this;
        },
        val: function (next) {
            if (!arguments.length) return this.nodes.length ? this.nodes[0].value : undefined;
            this.nodes.forEach(function (node) {
                node.value = next;
            });
            return this;
        },
        prop: function () {
            return this;
        },
        data: function (key, next) {
            const node = this.nodes[0];
            if (!node) return undefined;
            if (!dataStore.has(node)) dataStore.set(node, {});
            const bag = dataStore.get(node);
            if (arguments.length < 2) return bag[key];
            bag[key] = next;
            return this;
        },
        on: function () {
            return this;
        },
        off: function () {
            return this;
        },
        trigger: function () {
            return this;
        },
        show: function () {
            return this;
        },
        hide: function () {
            return this;
        }
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
    return $;
}

function load() {
    document.body.innerHTML =
        '<form id="shipping-new-address-form">' +
        '<div class="field">' +
        '<div class="control">' +
        '<input name="company" type="text">' +
        '</div>' +
        '</div>' +
        '<div class="field two-company-id-hidden">' +
        '<div class="control">' +
        '<input name="custom_attributes[company_id]" type="text">' +
        '</div>' +
        '</div>' +
        '</form>';

    const $ = makeMiniQuery();
    const identity = makeIdentityStub();
    const component = loadAmdModule(
        MODULE,
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Magento_Customer/js/customer-data': {
                set: function () {},
                get: function () {
                    return function () {
                        return {};
                    };
                }
            },
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandConfig: function () {
                    return { isCompanySearchEnabled: false };
                }
            }
        },
        { document: document, window: window }
    );
    return { component: component, $: $, identity: identity };
}

/** The page-level capture state the label decides on, as bare observables. */
function makeIdentityStub() {
    function observable(initial) {
        let value = initial;
        return function (next) {
            if (arguments.length) value = next;
            return value;
        };
    }
    return {
        captureMode: observable('registered'),
        companyId: observable(''),
        companyName: observable('')
    };
}

/** Put select2 "on" the company-name input, i.e. search mode is active. */
function activateSearch($) {
    $(NAME_SELECTOR).data('select2', {});
}

function labels() {
    return document.querySelectorAll('.' + TEXT_CLASS);
}

describe('TWO-25326 §5: the company number is a plain text label, and only after selection', () => {
    test('nothing is rendered before a result has been selected', () => {
        const { component, $ } = load();
        activateSearch($);

        component.renderCompanyIdText();

        expect(labels()).toHaveLength(0);
    });

    test('selecting a result renders the number as text under the name field', () => {
        const { component, $ } = load();
        activateSearch($);

        component.setCompanyData('919300894', 'Example Trading AS');

        expect(labels()).toHaveLength(1);
        const label = labels()[0];
        expect(label.textContent).toBe('919300894');
        // Under the NAME field specifically — inside that field's own
        // `.control`, after the input. §5 pins the position, not just the
        // existence, because a number rendered somewhere else on the form is
        // exactly the "visible in the address area" defect §7 forbids.
        const nameControl = document.querySelector('input[name="company"]').closest('.control');
        expect(label.parentElement).toBe(nameControl);
        expect(
            label.compareDocumentPosition(document.querySelector('input[name="company"]')) &
                window.Node.DOCUMENT_POSITION_PRECEDING
        ).toBeTruthy();
    });

    test('it is not an input, and carries nothing the buyer could type into', () => {
        const { component, $ } = load();
        activateSearch($);

        component.setCompanyData('919300894', 'Example Trading AS');

        const label = labels()[0];
        expect(label.tagName).toBe('DIV');
        expect(label.querySelector('input, textarea, select, [contenteditable]')).toBeNull();
        // `isContentEditable` is unimplemented in jsdom (always undefined),
        // so assert the attribute that would set it instead.
        expect(label.hasAttribute('contenteditable')).toBe(false);
    });

    test('it has an accessible name, since the visible text is a bare number', () => {
        // §7 forbids an extra VISIBLE caption in the address area, so the
        // caption has to be an accessible one — a bare number with no
        // accessible name is unreadable to a screen reader.
        const { component, $ } = load();
        activateSearch($);

        component.setCompanyData('919300894', 'Example Trading AS');

        expect(labels()[0].getAttribute('aria-label')).toBe('Company Number');
    });

    test('a company with no registry identifier renders no label at all', () => {
        const { component, $ } = load();
        activateSearch($);

        component.setCompanyData('', 'Identifier-less Example AS');

        expect(labels()).toHaveLength(0);
    });

    test('re-selecting replaces the number rather than stacking a second label', () => {
        const { component, $ } = load();
        activateSearch($);

        component.setCompanyData('919300894', 'Example Trading AS');
        component.setCompanyData('811912312', 'Other Example AS');

        expect(labels()).toHaveLength(1);
        expect(labels()[0].textContent).toBe('811912312');
    });

    /**
     * The manual-entry case, and the reason renderCompanyIdText() decides on
     * the capture mode rather than only on whether a number is present: the
     * hidden input can still be holding the previous pick's number at the
     * moment the buyer switches to typing a name by hand. Rendering it there
     * would attach a registry identity to a company the buyer typed themselves.
     */
    test('manual-entry mode shows no number even when one is still in the hidden input', () => {
        const { component, $, identity } = load();
        activateSearch($);
        component.setCompanyData('919300894', 'Example Trading AS');
        expect(labels()).toHaveLength(1);

        identity.captureMode('manual');
        $(ID_SELECTOR).val('919300894');

        component.renderCompanyIdText();

        expect(labels()).toHaveLength(0);
    });
});
