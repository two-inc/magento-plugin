/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326 §5, address step (Luma / Amasty OneStepCheckout / Fire Checkout —
 * one code path): the captured organisation number must survive a PAGE RELOAD,
 * exactly as the company name does.
 *
 * The reported bug: pick a company, the number appears under the name field,
 * reload, and the name is still there while the number is gone.
 *
 * Why that happened, and therefore what this file has to model. Magento
 * persists the address form across loads through the `checkoutProvider`:
 * `Magento_Checkout/js/view/shipping.js` listens for changes on the provider's
 * `shippingAddress` data and writes them to the `checkout-data` localStorage
 * section, and on the next load pushes the whole saved object —
 * `custom_attributes` included — back into the provider before the fields
 * render. So the provider is the persistence boundary. The company NAME
 * crossed it for free (select2 fires a native `change`, which is what
 * Knockout's `value:` binding listens on); the company NUMBER was written with
 * a bare `$(…).val()`, which raises no event Knockout listens for, so the
 * provider never learned it, nothing was saved, and there was nothing to
 * restore.
 *
 * That boundary is the whole subject here, so it is modelled rather than
 * mocked away: a provider that notifies, a `shipping.js`-shaped saver behind
 * it, a storage bag that outlives the "page", a UI component whose `value`
 * observable is two-way linked to the provider (as `Magento_Ui/js/form/element/
 * abstract` is), and a Knockout-shaped mirror of that observable into the DOM
 * input. `reload()` then throws away the DOM, the component and the module
 * instance, keeping only the storage bag — so a test that passes here cannot
 * be passing on in-session state.
 *
 * LIMITATIONS — read before trusting a pass.
 *
 *  1. Company search is left OFF in these tests, so select2 is never really
 *     bound and search mode is asserted by putting the `select2` data key on
 *     the name input, the same convention as address-company-id.test.js. What
 *     that costs: on a real reload the picker's own bind ALSO calls
 *     `renderCompanyIdText()`, so these tests exercise the company-number
 *     field's own render paths in isolation. That is deliberate — those are the
 *     paths that have to hold when the number resolves after the picker — but
 *     it means a regression that only broke the picker-side render would not
 *     fail here.
 *  2. The saver models `shipping.js`'s change→localStorage step, not its
 *     street-not-empty guard. A real checkout with no street typed yet persists
 *     nothing at all, name included.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const MODULE = 'view/frontend/web/js/view/address-autocomplete.js';
const NAME_SELECTOR = '#shipping-new-address-form input[name="company"]';
const ID_SELECTOR = '#shipping-new-address-form input[name="custom_attributes[company_id]"]';
const TEXT_CLASS = 'two-company-id-text';
const COMPANY_ID_COMPONENT =
    'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.company_id';
/** Where the company-number component's value lives in the provider's data. */
const ID_PATH = 'shippingAddress.custom_attributes.company_id';

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
                    if (typeof n === 'string') {
                        target.insertAdjacentHTML('beforeend', n);
                    } else {
                        target.appendChild(n);
                    }
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
            this.nodes.forEach(function (node, i) {
                const next =
                    typeof value === 'function' ? value(i, node.getAttribute(name)) : value;
                node.setAttribute(name, next);
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
    // The nodes are already in the document, so resolve immediately with the
    // selector — the module re-wraps it with `$(...)`.
    $.async = function (selector, cb) {
        cb(selector);
    };
    return $;
}

/** Knockout-shaped observable: callable getter/setter with `subscribe`. */
function makeObservable(initial) {
    let value = initial;
    const subscribers = [];
    function obs(next) {
        if (!arguments.length) return value;
        if (next === value) return obs;
        value = next;
        subscribers.slice().forEach(function (fn) {
            fn(value);
        });
        return obs;
    }
    obs.subscribe = function (fn) {
        subscribers.push(fn);
        return {
            dispose: function () {
                const i = subscribers.indexOf(fn);
                if (i !== -1) subscribers.splice(i, 1);
            }
        };
    };
    obs.subscriberCount = function () {
        return subscribers.length;
    };
    return obs;
}

/**
 * The persistence boundary, modelled: a notifying provider plus the
 * `shipping.js`-shaped saver that turns a notification into a localStorage
 * write. `storage` is the bag that survives a reload.
 */
function makeCheckoutWorld(storage) {
    const data = {};
    const listeners = [];

    const provider = {
        get: function (path) {
            return data[path];
        },
        set: function (path, value) {
            data[path] = value;
            listeners.forEach(function (fn) {
                fn(path, value);
            });
        },
        on: function (fn) {
            listeners.push(fn);
        }
    };

    // Magento_Checkout/js/view/shipping.js: every change to the shippingAddress
    // data is written to the `checkout-data` localStorage section. Only paths
    // the provider has SEEN can be saved, which is the entire bug.
    provider.on(function (path, value) {
        if (path.indexOf('shippingAddress.') !== 0) return;
        storage[path] = value;
    });

    return provider;
}

/**
 * The company-number field's UI component, shaped like
 * `Magento_Ui/js/form/element/abstract`: a `value` observable two-way linked to
 * its provider path, plus Knockout's `value:` binding copying it into the DOM
 * input.
 */
function makeCompanyIdComponent(provider, mirrorToDom) {
    const component = {
        value: makeObservable(''),
        disabled: makeObservable(true)
    };
    component.subscriberCount = function () {
        // Only the module's own subscribers: the harness's Knockout/link stand-in
        // below is registered first and is not one of them.
        return component.value.subscriberCount() - 1;
    };
    component.value.subscribe(function (next) {
        // The element's `links: {value: '${$.provider}:${$.dataScope}'}`.
        provider.set(ID_PATH, next);
        // `ui/form/element/input`'s `value:` binding, DOM side. Suppressible
        // because its ordering against OUR subscriber on the same observable is
        // a Knockout internal, and the display read must not depend on it.
        if (mirrorToDom === false) return;
        const input = document.querySelector(ID_SELECTOR);
        if (input) input.value = next == null ? '' : String(next);
    });
    return component;
}

/**
 * One "page load". Builds a fresh DOM and a fresh component, restores whatever
 * the previous load persisted (the way shipping.js pushes the saved
 * `shippingAddress` object back into the provider), and loads the module.
 *
 * @param {object} storage the bag that outlives the page
 * @param {object} [options]
 * @param {boolean} [options.restoreBeforeInit] apply the restored number before
 *        `initialize()` runs. False models the provider push landing AFTER the
 *        component's `$.async` resolves, which is not ordered against it.
 * @param {boolean} [options.mirrorToDom] false suppresses Knockout's `value:`
 *        binding writing the component's value into the input, modelling the
 *        instant before it does so.
 * @param {object} [options.component] reuse a previous load's company-number
 *        component, as a real form re-render does.
 * @param {boolean} [options.searchMode] false leaves select2 off the name
 *        input, i.e. manual-entry mode.
 * @param {string} [options.country] the country the form is showing, default
 *        `GB`.
 */
function pageLoad(storage, options) {
    const opts = options || {};
    document.body.innerHTML =
        '<form id="shipping-new-address-form">' +
        '<div class="field">' +
        '<div class="control">' +
        '<select name="country_id"><option value="' +
        (opts.country || 'GB') +
        '" selected></option></select>' +
        '</div>' +
        '</div>' +
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
    const provider = makeCheckoutWorld(storage);
    // A caller may hand back the PREVIOUS load's component: the `company_id`
    // uiRegistry component genuinely outlives a form re-render, and the
    // subscription bookkeeping is only meaningful against a shared instance.
    const companyIdComponent =
        opts.component || makeCompanyIdComponent(provider, opts.mirrorToDom);
    const companyDataSection = makeObservable(storage.companyData);

    const restore = function () {
        // shipping.js: `checkoutProvider.set('shippingAddress', extend(current,
        // checkoutData.getShippingAddressFromData()))`. Only the number matters
        // here; the name is restored below through the input's own value.
        if (Object.prototype.hasOwnProperty.call(storage, ID_PATH)) {
            companyIdComponent.value(storage[ID_PATH]);
        }
    };
    // The company NAME is restored the same way, and always did work — it is
    // set on the input directly so these tests can tell "the number is missing"
    // apart from "the whole form is missing".
    if (storage.companyName) {
        document.querySelector(NAME_SELECTOR).value = storage.companyName;
    }
    if (opts.restoreBeforeInit !== false) restore();

    const component = loadAmdModule(
        MODULE,
        {
            jquery: $,
            uiRegistry: {
                get: function (name) {
                    return name === COMPANY_ID_COMPONENT ? companyIdComponent : undefined;
                },
                set: function () {},
                create: function () {},
                async: function () {
                    return function () {};
                }
            },
            'Magento_Customer/js/customer-data': {
                get: function (key) {
                    return key === 'companyData' ? companyDataSection : makeObservable({});
                },
                set: function (key, value) {
                    if (key === 'companyData') {
                        // A localStorage customer-data section: it outlives the
                        // page too.
                        storage.companyData = value;
                        companyDataSection(value);
                    }
                },
                reload: function () {}
            },
            'Two_Gateway/js/model/brand-config': {
                // Search off: see limitation 1 in the file header.
                getActiveTwoBrandConfig: function () {
                    return { isCompanySearchEnabled: false };
                }
            }
        },
        { document: document, window: window }
    );
    component._super = function () {};
    // Search mode, asserted the way the sibling tests assert it — and set
    // BEFORE `initialize()`, because on a reload the picker is bound while the
    // company-number field's own render paths run, and `renderCompanyIdText()`
    // deliberately paints nothing outside search mode.
    if (opts.searchMode !== false) $(NAME_SELECTOR).data('select2', {});
    component.initialize();
    return { component: component, $: $, restore: restore, companyIdComponent: companyIdComponent };
}

function labels() {
    return document.querySelectorAll('.' + TEXT_CLASS);
}

describe('TWO-25326 §5: the captured company number survives a page reload', () => {
    test('picking a company puts the number where a reload can find it', () => {
        // The crux. Not "the label appeared" — the label appearing was never the
        // broken part. What was broken is that the number never reached the
        // provider, so Magento had nothing to save and nothing to restore.
        const storage = {};
        const first = pageLoad(storage);

        first.component.setCompanyData('919300894', 'Example Trading AS');

        expect(labels()).toHaveLength(1);
        expect(storage[ID_PATH]).toBe('919300894');
        expect(first.companyIdComponent.value()).toBe('919300894');
    });

    test('after a reload the number is still shown, not just the name', () => {
        const storage = {};
        const first = pageLoad(storage);
        first.component.setCompanyData('919300894', 'Example Trading AS');
        // The name's own persistence is core's, and worked throughout; recorded
        // so the reload below starts from the state the bug was reported in.
        storage.companyName = 'Example Trading AS';
        expect(labels()).toHaveLength(1);

        // Reload: new DOM, new component, new module instance. Only `storage`
        // crosses over.
        pageLoad(storage);

        expect(document.querySelector(NAME_SELECTOR).value).toBe('Example Trading AS');
        expect(document.querySelector(ID_SELECTOR).value).toBe('919300894');
        expect(labels()).toHaveLength(1);
        expect(labels()[0].textContent).toBe('919300894');
    });

    test('the number is shown even if the restore lands after the form initialises', () => {
        // Magento's push of the saved shippingAddress object into the provider
        // is not ordered against this component's `$.async` resolves. If it
        // lands last, every render call has already run against an empty field.
        const storage = {};
        const first = pageLoad(storage);
        first.component.setCompanyData('919300894', 'Example Trading AS');
        storage.companyName = 'Example Trading AS';

        const second = pageLoad(storage, { restoreBeforeInit: false });
        expect(labels()).toHaveLength(0);

        second.restore();

        expect(labels()).toHaveLength(1);
        expect(labels()[0].textContent).toBe('919300894');
    });

    test('the label reads the component when the input has not caught up yet', () => {
        // The restore paints from a subscriber on the component's `value`
        // observable, and Knockout's own `value:` binding is another subscriber
        // on it. Which of the two runs first is a Knockout internal, so the
        // display read must not depend on the input being updated already.
        const storage = {};
        const first = pageLoad(storage);
        first.component.setCompanyData('919300894', 'Example Trading AS');
        storage.companyName = 'Example Trading AS';

        const second = pageLoad(storage, { restoreBeforeInit: false, mirrorToDom: false });
        second.restore();

        expect(document.querySelector(ID_SELECTOR).value).toBe('');
        expect(labels()).toHaveLength(1);
        expect(labels()[0].textContent).toBe('919300894');
    });

    test('a reload after manual entry restores no number, because none was captured', () => {
        // Manual entry is name-only capture. The number must not come back from
        // a previous pick — the whole point of the label is that it asserts a
        // registry identity, and a manually typed name has none.
        const storage = {};
        const first = pageLoad(storage);
        first.component.setCompanyData('919300894', 'Example Trading AS');
        // What enterDetailsManually() does to the captured company.
        first.component.setCompanyData();
        storage.companyName = 'Hand Typed Ltd';

        pageLoad(storage);

        expect(storage[ID_PATH]).toBe('');
        expect(document.querySelector(ID_SELECTOR).value).toBe('');
        expect(labels()).toHaveLength(0);
    });

    test('the label the component notification paints is the NEW number', () => {
        // Ordering inside setCompanyIdValue(). The component write notifies
        // synchronously and the label renders from that subscriber, and the
        // render reads the input before the component — so a component-first
        // write paints the PREVIOUS company's number, and paints a number at all
        // while clearing.
        //
        // Asserted with Knockout's DOM mirror suppressed. With the mirror in
        // place it is registered before the module's own subscriber and updates
        // the input first, which hides the ordering entirely — a test that let it
        // run would pass whichever way round the two writes went.
        const storage = {};
        const first = pageLoad(storage, { mirrorToDom: false });
        first.component.setCompanyData('919300894', 'Example Trading AS');

        // Registered after the module's subscriber, so it observes the label the
        // module has just painted rather than racing it.
        const painted = [];
        first.companyIdComponent.value.subscribe(function (next) {
            painted.push({ notified: next, label: labels().length ? labels()[0].textContent : null });
        });

        first.component.setCompanyData('811912312', 'Other Example AS');
        first.component.setCompanyData();

        expect(painted).toEqual([
            { notified: '811912312', label: '811912312' },
            { notified: '', label: null }
        ]);
    });

    test('a number captured in another country is not restored (TWO-24867)', () => {
        // `checkout-data` carries no record of the country the company was
        // captured in; the `companyData` section does. A GB organisation number
        // restored onto a checkout now showing ES would be credit-checked there
        // and refused upstream as a generic failure.
        const storage = {};
        storage[ID_PATH] = '919300894';
        storage.companyName = 'Example Trading AS';
        storage.companyData = {
            companyId: '919300894',
            companyName: 'Example Trading AS',
            companyCountry: 'gb'
        };

        pageLoad(storage, { country: 'ES' });

        expect(document.querySelector(ID_SELECTOR).value).toBe('');
        expect(labels()).toHaveLength(0);
        // The discard reaches `checkout-data` as well, so the number does not
        // come back on the load after this one. That persistence is also why a
        // misfire would be unrecoverable, which is why it is asserted.
        expect(storage[ID_PATH]).toBe('');
        // The NAME is left alone: a name with no identifier is an understood
        // state, while a name blanked on load reads as data loss.
        expect(document.querySelector(NAME_SELECTOR).value).toBe('Example Trading AS');
    });

    test('a foreign number is discarded even when it arrives after init', () => {
        // The one-shot guard on the `$.async` resolve runs while the field is
        // still empty, so a number restored late is one the guard never saw. This
        // is the ordering the value subscription exists for, and the country
        // check has to hold on it too or it is dead on the path that matters.
        const storage = {};
        storage[ID_PATH] = '919300894';
        storage.companyName = 'Example Trading AS';
        storage.companyData = {
            companyId: '919300894',
            companyName: 'Example Trading AS',
            companyCountry: 'gb'
        };

        const load = pageLoad(storage, { country: 'ES', restoreBeforeInit: false });
        load.restore();

        expect(document.querySelector(ID_SELECTOR).value).toBe('');
        expect(labels()).toHaveLength(0);
        expect(storage[ID_PATH]).toBe('');
    });

    test('a foreign number is discarded when only the component holds it', () => {
        // The discard runs immediately before the render, off the same
        // notification, and the render reads the component when the input has not
        // caught up. So the discard has to read the same pair — an input-only
        // read bails out on exactly that ordering, and the number it declined to
        // check is then painted and left in the provider to be credit-checked.
        const storage = {};
        storage[ID_PATH] = '919300894';
        storage.companyName = 'Example Trading AS';
        storage.companyData = {
            companyId: '919300894',
            companyName: 'Example Trading AS',
            companyCountry: 'gb'
        };

        const load = pageLoad(storage, {
            country: 'ES',
            restoreBeforeInit: false,
            mirrorToDom: false
        });
        load.restore();

        expect(load.companyIdComponent.value()).toBe('');
        expect(labels()).toHaveLength(0);
        expect(storage[ID_PATH]).toBe('');
    });

    test('an unstamped record fails open rather than dropping a valid number', () => {
        // Records written before the country stamp existed carry none, and
        // treating unstamped as wrong-country would drop a legitimate company on
        // the first load after an upgrade.
        const storage = {};
        storage[ID_PATH] = '919300894';
        storage.companyName = 'Example Trading AS';
        storage.companyData = { companyId: '919300894', companyName: 'Example Trading AS' };

        pageLoad(storage, { country: 'ES' });

        expect(document.querySelector(ID_SELECTOR).value).toBe('919300894');
        expect(labels()).toHaveLength(1);
    });

    test('re-initialising leaves exactly one subscription, held by the live view', () => {
        // `$.async` is a MutationObserver: it fires again on every re-render of
        // the address form, and the `company_id` uiRegistry component outlives
        // the view — so a subscription left in place would stack one per render
        // and the surviving subscriber would be closed over a superseded view.
        //
        // Asserted on the subscription itself, NOT on the number of labels
        // rendered: `renderCompanyIdText()` removes existing labels before
        // appending, so N stacked subscribers still produce exactly one label
        // and a label count cannot detect stacking at all.
        const storage = {};
        const first = pageLoad(storage);
        first.component.setCompanyData('919300894', 'Example Trading AS');
        storage.companyName = 'Example Trading AS';
        expect(first.companyIdComponent.subscriberCount()).toBe(1);

        // Three more form re-renders against the SAME component instance.
        const second = pageLoad(storage, { component: first.companyIdComponent });
        second.component.initialize();
        second.component.initialize();

        expect(first.companyIdComponent.subscriberCount()).toBe(1);
        // And the surviving subscriber is the live view's, not a stale one: only
        // the live view can see the current document.
        second.companyIdComponent.value('811912312');
        expect(labels()).toHaveLength(1);
        expect(labels()[0].textContent).toBe('811912312');
    });

    test('a restored number paints nothing in manual-entry mode', () => {
        // Manual entry is name-only capture. This is the branch the sibling
        // cases cannot reach, because they all assert search mode: select2 is
        // absent from the name input and a number is nonetheless sitting in the
        // restored field.
        const storage = {};
        storage[ID_PATH] = '919300894';
        storage.companyName = 'Hand Typed Ltd';

        pageLoad(storage, { searchMode: false });

        expect(document.querySelector(ID_SELECTOR).value).toBe('919300894');
        expect(labels()).toHaveLength(0);
    });
});
