/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * #30.x.15. The manual-entry affordance was previously a real `<li
 * role="option">` living INSIDE the select2 results list, on the premise
 * that inheriting select2's own keyboard model was the only way to keep it
 * reachable. Live testing after that shipped found the tradeoff was worse
 * than the defect it fixed:
 *
 *  - `.select2-results__options` is exactly the element select2 clips and
 *    scrolls, so the row was only visible once the buyer scrolled past
 *    however many real results came back, and reaching it by keyboard meant
 *    arrowing down through every one of them;
 *  - Enter worked (it closes over select2's own `select2:selecting`
 *    dispatch), but Space did not: select2's core only special-cases
 *    Enter/Up/Down/Escape on the search field's keydown, so an unhandled
 *    Space fell through to its native default of typing a literal space
 *    character into the search box.
 *
 * This file pins the replacement: a real `<button>`, a SIBLING of the
 * results list rather than a row inside it, which fixes both by
 * construction — native Tab-adjacent focus, native Enter/Space activation,
 * and a position outside select2's own scroll/clip area so it is always
 * visible once the search threshold is met.
 *
 * Mutation-resistance notes:
 *  - the model tests run against REAL jsdom nodes through a small
 *    jQuery-lite double, so `length`, class lists, attributes and sibling
 *    position are the document's answers, not a stub's;
 *  - the threshold is injected DELIBERATELY WRONG (5), so asserting against
 *    a surviving literal 3 cannot pass;
 *  - translation is asserted on the msgid, since `$t` resolves to identity
 *    here;
 *  - the surface tests grep the real address-autocomplete.js source, so
 *    reverting to the old `select2:selecting` interception fails them
 *    directly.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const SURFACE_PATH = 'view/frontend/web/js/view/address-autocomplete.js';
const MSGID = 'My company is not on the list';

/** Nothing here is 3, so a surviving literal 3 cannot pass. */
const WRONG_THRESHOLD = 5;

const MANUAL_ENTRY_CLASS = 'two-company-search__manual-entry';
const MANUAL_ENTRY_NS = '.twoManualEntry';

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

/**
 * The real shared model, loaded with its threshold constant rewritten.
 * Patching the SOURCE, not the returned object: the helpers close over the
 * module-local constant, so an object-level override would prove nothing.
 */
function loadModelWithWrongThreshold($) {
    const src = readSource(MODEL_PATH);
    const needle = 'const MIN_INPUT_LENGTH = 3;';
    expect(src).toContain(needle);
    const patched = src.replace(needle, 'const MIN_INPUT_LENGTH = ' + WRONG_THRESHOLD + ';');

    const tmp = path.join(__dirname, '__tmp_manual_entry_threshold.js');
    fs.writeFileSync(tmp, patched, 'utf8');
    try {
        return loadAmdModule(path.relative(path.resolve(__dirname, '..', '..'), tmp), {
            jquery: $
        });
    } finally {
        fs.unlinkSync(tmp);
    }
}

/* ------------------------------------------------------------------ *
 * jQuery-lite over real jsdom nodes. Only the calls the model actually
 * makes, backed by the real DOM so assertions are the document's answers.
 * ------------------------------------------------------------------ */
function makeMiniQuery() {
    const dataStore = new WeakMap();
    const handlerStore = new WeakMap();

    function splitNs(spec) {
        const dot = spec.indexOf('.');
        return dot === -1
            ? { type: spec, ns: '' }
            : { type: spec.slice(0, dot), ns: spec.slice(dot) };
    }

    function fromHtml(html) {
        const holder = document.createElement('div');
        holder.innerHTML = html;
        return Array.prototype.slice.call(holder.children);
    }

    function wrap(nodes) {
        const set = Object.create(api);
        set.nodes = nodes;
        set.length = nodes.length;
        return set;
    }

    const api = {
        get: function (i) {
            return this.nodes[i];
        },
        attr: function (name, value) {
            if (name && typeof name === 'object') {
                this.nodes.forEach(function (node) {
                    Object.keys(name).forEach(function (key) {
                        node.setAttribute(key, name[key]);
                    });
                });
                return this;
            }
            if (arguments.length > 1) {
                this.nodes.forEach(function (node) {
                    node.setAttribute(name, value);
                });
                return this;
            }
            const first = this.nodes[0];
            return first ? first.getAttribute(name) : undefined;
        },
        addClass: function (classes) {
            this.nodes.forEach(function (node) {
                classes.split(/\s+/).forEach(function (cls) {
                    if (cls) node.classList.add(cls);
                });
            });
            return this;
        },
        text: function (value) {
            if (arguments.length) {
                this.nodes.forEach(function (node) {
                    node.textContent = value;
                });
                return this;
            }
            return this.nodes.length ? this.nodes[0].textContent : '';
        },
        data: function (key, value) {
            const node = this.nodes[0];
            if (!node) return arguments.length > 1 ? this : undefined;
            let store = dataStore.get(node);
            if (!store) {
                store = {};
                dataStore.set(node, store);
            }
            if (arguments.length > 1) {
                store[key] = value;
                return this;
            }
            return store[key];
        },
        removeData: function (key) {
            const node = this.nodes[0];
            if (!node) return this;
            const store = dataStore.get(node);
            if (store) delete store[key];
            return this;
        },
        children: function (selector) {
            const matched = [];
            this.nodes.forEach(function (node) {
                Array.prototype.forEach.call(node.children, function (child) {
                    if (!selector || child.matches(selector)) matched.push(child);
                });
            });
            return wrap(matched);
        },
        find: function (selector) {
            const matched = [];
            this.nodes.forEach(function (node) {
                Array.prototype.forEach.call(node.querySelectorAll(selector), function (found) {
                    matched.push(found);
                });
            });
            return wrap(matched);
        },
        parent: function () {
            const matched = [];
            this.nodes.forEach(function (node) {
                if (node.parentNode) matched.push(node.parentNode);
            });
            return wrap(matched);
        },
        prev: function () {
            const matched = [];
            this.nodes.forEach(function (node) {
                if (node.previousElementSibling) matched.push(node.previousElementSibling);
            });
            return wrap(matched);
        },
        is: function (selector) {
            if (selector && selector.nodes) {
                const targets = selector.nodes;
                return this.nodes.some(function (node) {
                    return targets.indexOf(node) !== -1;
                });
            }
            return this.nodes.some(function (node) {
                return node.matches(selector);
            });
        },
        after: function (other) {
            const node = this.nodes[0];
            if (!node || !node.parentNode) return this;
            const incoming = other && other.nodes ? other.nodes.slice() : [other];
            let ref = node;
            incoming.forEach(function (n) {
                node.parentNode.insertBefore(n, ref.nextSibling);
                ref = n;
            });
            return this;
        },
        append: function (other) {
            const parent = this.nodes[0];
            if (!parent) return this;
            const incoming = other && other.nodes ? other.nodes.slice() : [other];
            incoming.forEach(function (node) {
                parent.appendChild(typeof node === 'string' ? fromHtml(node)[0] : node);
            });
            return this;
        },
        remove: function () {
            this.nodes.forEach(function (node) {
                if (node.parentNode) node.parentNode.removeChild(node);
            });
            return this;
        },
        val: function (value) {
            if (arguments.length) {
                this.nodes.forEach(function (node) {
                    node.value = value;
                });
                return this;
            }
            return this.nodes.length ? this.nodes[0].value : undefined;
        },
        on: function (spec, handler) {
            const parsed = splitNs(spec);
            this.nodes.forEach(function (node) {
                let bound = handlerStore.get(node);
                if (!bound) {
                    bound = [];
                    handlerStore.set(node, bound);
                }
                node.addEventListener(parsed.type, handler);
                bound.push({ type: parsed.type, ns: parsed.ns, handler: handler });
            });
            return this;
        },
        off: function (spec) {
            const parsed = splitNs(spec || '');
            this.nodes.forEach(function (node) {
                const bound = handlerStore.get(node) || [];
                bound
                    .filter(function (entry) {
                        return (
                            (!parsed.type || entry.type === parsed.type) &&
                            (!parsed.ns || entry.ns === parsed.ns)
                        );
                    })
                    .forEach(function (entry) {
                        node.removeEventListener(entry.type, entry.handler);
                        bound.splice(bound.indexOf(entry), 1);
                    });
            });
            return this;
        },
        removeAttr: function (name) {
            this.nodes.forEach(function (node) {
                node.removeAttribute(name);
            });
            return this;
        },
        /**
         * Real jQuery's `.trigger('focus')` calls the native `.focus()`
         * method rather than dispatching a synthetic event (the two differ:
         * only the former actually moves `document.activeElement`), which is
         * exactly the distinction the focus-restoration fix (#30.x.15 round
         * 2) depends on. Everything else dispatches a real DOM event so a
         * `keydown`/`resize` listener under test still sees it.
         */
        trigger: function (spec) {
            const parsed = splitNs(spec);
            this.nodes.forEach(function (node) {
                if (parsed.type === 'focus' && typeof node.focus === 'function') {
                    node.focus();
                    return;
                }
                node.dispatchEvent(new window.Event(parsed.type, { bubbles: true }));
            });
            return this;
        }
    };

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
    $.ajax = function () {
        throw new Error('no request may be issued while showing the manual-entry button');
    };
    /** Handlers currently bound to a node, for duplicate-bind assertions. */
    $.boundHandlers = function (node, type, ns) {
        return (handlerStore.get(node) || []).filter(function (entry) {
            return (!type || entry.type === type) && (!ns || entry.ns === ns);
        });
    };
    return $;
}

/** A picker's real DOM, shaped the way select2 4.1 renders it. */
function makePickerDom($) {
    document.body.innerHTML =
        '<input id="company_name" type="text">' +
        '<span class="select2-dropdown">' +
        '<span class="select2-search select2-search--dropdown">' +
        '<input class="select2-search__field" type="text">' +
        '</span>' +
        '<span class="select2-results">' +
        '<ul class="select2-results__options" role="listbox" id="select2-company-results"></ul>' +
        '</span>' +
        '</span>';

    const $field = $('#company_name');
    const token = {};
    $field.data('twoSearchBind', token);
    $field.data('select2', { $dropdown: $('.select2-dropdown') });

    return {
        $field: $field,
        token: token,
        $results: $('.select2-results__options'),
        $wrapper: $('.select2-results'),
        results: $('.select2-results__options').get(0),
        wrapper: $('.select2-results').get(0),
        search: $('.select2-search__field').get(0)
    };
}

function addRealResult(dom, name) {
    const li = document.createElement('li');
    li.className = 'select2-results__option select2-results__option--selectable';
    li.setAttribute('role', 'option');
    li.textContent = name;
    dom.results.appendChild(li);
    return li;
}

function typeTerm(dom, term) {
    dom.search.value = term;
    dom.search.dispatchEvent(new window.Event('input', { bubbles: true }));
}

function manualButtons(dom) {
    return dom.wrapper.querySelectorAll('.' + MANUAL_ENTRY_CLASS);
}

function tick() {
    return new Promise(function (resolve) {
        setTimeout(resolve, 0);
    });
}

describe('the manual-entry affordance is a real, native button', () => {
    let $;
    let model;

    beforeEach(() => {
        $ = makeMiniQuery();
        model = loadModelWithWrongThreshold($);
    });

    test('it is a real <button type="button">, not a role=option pseudo-row', () => {
        const $button = model.buildManualEntryButton({ data: function () {} }, {}, function () {});
        const node = $button.get(0);

        expect(node).toBeTruthy();
        expect(node.tagName).toBe('BUTTON');
        expect(node.getAttribute('type')).toBe('button');
        // No listbox-option chrome left over from the old row: a native
        // button needs none of it to be focusable or activatable.
        expect(node.classList.contains('select2-results__option')).toBe(false);
        expect(node.getAttribute('role')).toBeNull();
        expect(node.classList.contains(MANUAL_ENTRY_CLASS)).toBe(true);
        expect(node.textContent).toBe(MSGID);
    });

    test('the label is set as text, never as markup', () => {
        // This picker disables select2's markup escaping so server-side
        // highlighting can render, which makes the catalogue an injection
        // point if the label is ever interpolated into HTML.
        const source = readSource(MODEL_PATH);
        expect(source).toContain("$t('" + MSGID + "')");
        expect(source).not.toMatch(/<button[^>]*>\$\{/);
    });

    test('clicking it calls onActivate, deferred past the current dispatch', async () => {
        const dom = makePickerDom($);
        const onActivate = jest.fn();
        const $button = model.buildManualEntryButton(dom.$field, dom.token, onActivate);
        dom.wrapper.appendChild($button.get(0));

        $button.get(0).dispatchEvent(new window.Event('click', { bubbles: true }));
        // Not yet: the call is deferred a tick so tearing down the widget
        // does not happen from inside this click's own dispatch.
        expect(onActivate).not.toHaveBeenCalled();

        await tick();
        expect(onActivate).toHaveBeenCalledTimes(1);
    });

    test('a click cannot activate a bind that has already been superseded', async () => {
        const dom = makePickerDom($);
        const onActivate = jest.fn();
        const $button = model.buildManualEntryButton(dom.$field, dom.token, onActivate);
        dom.wrapper.appendChild($button.get(0));

        $button.get(0).dispatchEvent(new window.Event('click', { bubbles: true }));
        // A checkout re-render rebinds a fresh identity before the deferred
        // callback gets to run.
        dom.$field.data('twoSearchBind', {});

        await tick();
        expect(onActivate).not.toHaveBeenCalled();
    });
});

describe('when the button is shown', () => {
    let $;
    let model;
    let dom;

    beforeEach(() => {
        $ = makeMiniQuery();
        model = loadModelWithWrongThreshold($);
        dom = makePickerDom($);
    });

    function sync() {
        return model.syncManualEntryButton(dom.$field, dom.token, function () {});
    }

    test('it appears at the SHARED threshold, not a literal, and goes away below it', () => {
        expect(model.MIN_INPUT_LENGTH).toBe(WRONG_THRESHOLD);

        dom.search.value = 'exam'; // 4 — below the injected threshold
        sync();
        expect(manualButtons(dom)).toHaveLength(0);

        dom.search.value = 'examp'; // 5 — at it
        sync();
        expect(manualButtons(dom)).toHaveLength(1);

        dom.search.value = 'exam';
        sync();
        expect(manualButtons(dom)).toHaveLength(0);
    });

    test('it is a SIBLING of the results list, immediately after it — not a child of it', () => {
        addRealResult(dom, 'Example Trading Ltd');
        addRealResult(dom, 'Example Holdings AS');
        dom.search.value = 'example';
        sync();

        // Not inside the list select2 clips and scrolls.
        expect(dom.results.children).toHaveLength(2);
        expect(dom.results.querySelectorAll('.' + MANUAL_ENTRY_CLASS)).toHaveLength(0);

        // A sibling of it, inside the same panel.
        expect(manualButtons(dom)).toHaveLength(1);
        expect(dom.results.nextElementSibling.classList.contains(MANUAL_ENTRY_CLASS)).toBe(true);
    });

    test('a later page of results does not require it to be re-synced', () => {
        dom.search.value = 'example';
        sync();
        expect(manualButtons(dom)).toHaveLength(1);

        // select2 empties and repaints the LIST on a new result page — since
        // the button is a sibling of the list, not a child, this can never
        // touch it. No MutationObserver is needed to prove this.
        dom.results.innerHTML = '';
        addRealResult(dom, 'Example Logistics Ltd');

        expect(manualButtons(dom)).toHaveLength(1);
        expect(dom.results.nextElementSibling.classList.contains(MANUAL_ENTRY_CLASS)).toBe(true);
    });

    test('syncing repeatedly never doubles it, and does not rebuild an unchanged button', () => {
        dom.search.value = 'example';
        const $first = sync();
        const $second = sync();
        const $third = sync();

        expect(manualButtons(dom)).toHaveLength(1);
        // Same node every time: an unconditional rebuild would replace it
        // with a new element on every call.
        expect($first.get(0)).toBe($second.get(0));
        expect($second.get(0)).toBe($third.get(0));
    });

    test("a stale bind cannot paint a button onto the live picker", () => {
        const staleToken = dom.token;
        dom.search.value = 'example';

        // Establish that this very call DOES paint while the bind is live,
        // so the assertion below cannot pass merely because nothing was
        // ever going to show — asserted on the return value, not on a
        // previously-added button disappearing: the button lives OUTSIDE
        // the results list this helper gates on, so a stale call correctly
        // leaves an already-shown button alone rather than reaching in to
        // remove it.
        const $live = model.syncManualEntryButton(dom.$field, staleToken, function () {});
        expect($live.length).toBe(1);

        dom.$field.data('twoSearchBind', {});

        const $stale = model.syncManualEntryButton(dom.$field, staleToken, function () {});
        expect($stale.length).toBe(0);
    });
});

describe('what drives the button', () => {
    let $;
    let model;
    let dom;

    beforeEach(() => {
        $ = makeMiniQuery();
        model = loadModelWithWrongThreshold($);
        dom = makePickerDom($);
    });

    test('typing to the threshold shows it with no request issued', () => {
        // `$.ajax` throws in this double, so reaching the transport at all
        // fails the test: the button must not wait on a debounce or search.
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        expect(manualButtons(dom)).toHaveLength(0);

        typeTerm(dom, 'examp');
        expect(manualButtons(dom)).toHaveLength(1);

        typeTerm(dom, 'exam');
        expect(manualButtons(dom)).toHaveLength(0);
    });

    test('reopening the picker does not stack input handlers', () => {
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        model.attachManualEntryButton(dom.$field, dom.token, function () {});

        expect($.boundHandlers(dom.search, 'input', MANUAL_ENTRY_NS)).toHaveLength(1);
    });

    test('its namespace does not collide with the below-threshold cancel handler', () => {
        expect(MANUAL_ENTRY_NS).not.toBe(model.EVENT_NS);
        model.markSearchBinding(dom.$field, dom.token);
        model.attachManualEntryButton(dom.$field, dom.token, function () {});

        expect($.boundHandlers(dom.search, 'input', model.EVENT_NS)).toHaveLength(1);
        expect($.boundHandlers(dom.search, 'input', MANUAL_ENTRY_NS)).toHaveLength(1);
    });

    test('attaching while the term is already at threshold shows it immediately, without waiting for input', () => {
        dom.search.value = 'example';
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        expect(manualButtons(dom)).toHaveLength(1);
    });

    test('neither closure survives its own bind being superseded', () => {
        const staleToken = dom.token;
        model.attachManualEntryButton(dom.$field, staleToken, function () {});

        typeTerm(dom, 'example');
        expect(manualButtons(dom)).toHaveLength(1);

        // Simulate the picker being torn down and a fresh one bound to the
        // same node: a real re-render replaces the dropdown's DOM entirely,
        // so this bind's results list starts clean.
        dom.wrapper.innerHTML =
            '<ul class="select2-results__options" role="listbox" id="select2-company-results"></ul>';
        dom.results = dom.wrapper.querySelector('ul');
        dom.$field.data('twoSearchBind', {});

        // The stale closure's own `input` handler must not paint onto this
        // fresh, empty results list.
        typeTerm(dom, 'example ltd');
        expect(manualButtons(dom)).toHaveLength(0);
    });

    test('detaching removes the button and stops the input handler, using the live select2 instance directly', () => {
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        typeTerm(dom, 'example');
        expect(manualButtons(dom)).toHaveLength(1);

        // No token argument — this must clean up whatever is CURRENTLY
        // attached to the field's live select2 instance.
        model.detachManualEntryButton(dom.$field);

        expect(manualButtons(dom)).toHaveLength(0);
        typeTerm(dom, 'example ltd');
        expect(manualButtons(dom)).toHaveLength(0);
    });
});

describe('keyboard reachability (round 2 — Tab is not free)', () => {
    let $;
    let model;
    let dom;

    beforeEach(() => {
        $ = makeMiniQuery();
        model = loadModelWithWrongThreshold($);
        dom = makePickerDom($);
    });

    /**
     * select2 4.1's OWN search-field keypress handler treats Tab exactly
     * like Enter (`t===ENTER||t===TAB` both trigger `results:select` and
     * `preventDefault()`), bound at construction time — before this module
     * gets a chance to touch the field. A jQuery bubble-phase listener
     * added later (e.g. on `select2:open`) cannot win that race; only a
     * capture-phase listener, installed by attachManualEntryButton(), runs
     * ahead of it. This test does not stub select2's own handler (there is
     * none in this harness) — it only proves OUR handler intercepts Tab
     * and moves focus, which is the fix; select2 racing it is covered by
     * Yoda's static review of the vendored bundle, not re-derivable here.
     */
    test('Tab moves focus onto the button and is not left for select2 to also act on', () => {
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        typeTerm(dom, 'example');
        const $button = $(manualButtons(dom)[0]);
        expect($button.length).toBe(1);

        const event = new window.KeyboardEvent('keydown', {
            key: 'Tab',
            bubbles: true,
            cancelable: true
        });
        dom.search.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe($button.get(0));
    });

    test('Shift+Tab is left alone — only forward Tab is intercepted', () => {
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        typeTerm(dom, 'example');

        const event = new window.KeyboardEvent('keydown', {
            key: 'Tab',
            shiftKey: true,
            bubbles: true,
            cancelable: true
        });
        dom.search.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    test('detaching removes the capture-phase Tab listener, so it does not survive teardown', () => {
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        typeTerm(dom, 'example');
        model.detachManualEntryButton(dom.$field);

        const event = new window.KeyboardEvent('keydown', {
            key: 'Tab',
            bubbles: true,
            cancelable: true
        });
        dom.search.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    test('Escape on the focused button closes the picker without leaving a stray click/keydown handler', () => {
        model.attachManualEntryButton(dom.$field, dom.token, function () {});
        typeTerm(dom, 'example');
        const button = manualButtons(dom)[0];
        button.focus();

        const event = new window.KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true
        });
        button.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });
});

describe('address-autocomplete.js surface (structural fix)', () => {
    let src;

    beforeAll(() => {
        src = readSource(SURFACE_PATH);
    });

    test('no longer intercepts select2:selecting for a manual-entry sentinel', () => {
        expect(src).not.toMatch(/select2:selecting/);
        expect(src).not.toMatch(/isManualEntryOption/);
    });

    test('wires the shared model button, activating enterDetailsManually directly', () => {
        expect(src).toMatch(
            /companySearch\.attachManualEntryButton\(\s*\$companyNameField,\s*bindToken,/
        );
        expect(src).toMatch(/self\.enterDetailsManually\(\s*\$companyNameField,\s*bindToken\s*\)/);
    });

    test('detaches the button on close, and on the re-bind path before re-init', () => {
        expect(src).toMatch(/companySearch\.detachManualEntryButton\(\s*\$companyNameField\s*\)/);
        const detachCalls = src.match(/companySearch\.detachManualEntryButton\(/g) || [];
        // re-bind path (enterDetailsManually) and select2:close.
        expect(detachCalls.length).toBeGreaterThanOrEqual(2);
    });

    test('the shared model exports the button helpers, not the retired row ones', () => {
        const modelSrc = readSource(MODEL_PATH);
        [
            'attachManualEntryButton',
            'detachManualEntryButton',
            'syncManualEntryButton',
            'buildManualEntryButton'
        ].forEach(function (name) {
            expect(modelSrc).toMatch(new RegExp(name + ':\\s*function'));
        });
        [
            'attachManualEntryRow',
            'detachManualEntryObserver',
            'renderManualEntryRow',
            'buildManualEntryOption',
            'isManualEntryOption'
        ].forEach(function (name) {
            expect(modelSrc).not.toMatch(new RegExp(name + ':\\s*function'));
        });
    });
});
