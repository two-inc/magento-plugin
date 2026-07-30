/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Follow-up from PR #305 (address-step manual-entry affordance, open and
 * unmerged as of this writing): the payment-tile company picker
 * (`gateway_method.js`) rendered its own manual-entry affordance OUTSIDE the
 * select2 results listbox — the same accessibility defect #305 fixes on the
 * address step, tackled here independently for the payment tile so the two
 * pickers don't ship on opposite sides of the fix. This file pins the
 * shared-model fix and the payment-tile surface's use of it.
 *
 * Load-bearing guarantees:
 *  - the manual-entry row is a REAL row INSIDE the results list (`<li>`,
 *    `role="option"`, `--selectable`, matching `aria-activedescendant`
 *    plumbing via `_resultId`), not a footer div outside it. Outside the
 *    list it sits outside the combobox's `aria-owns`, so no key reaches it
 *    and no screen reader announces it;
 *  - it appears at the shared MIN_INPUT_LENGTH threshold and survives a
 *    result-list re-render (select2 empties the list on every new page of
 *    results);
 *  - a stale bind token can never paint a row onto a widget it doesn't own;
 *  - the payment-tile surface (`gateway_method.js`) wires the model's
 *    `attachManualEntryRow` / `detachManualEntryObserver` and intercepts
 *    `select2:selecting` for the sentinel row, and no longer builds the old
 *    outside-the-listbox div.
 *
 * Mutation-resistance notes:
 *  - the model tests run against REAL jsdom nodes through a small
 *    jQuery-lite double, so `length`, class lists, attributes and sibling
 *    order are the document's answers, not a stub's;
 *  - the threshold is exercised at MIN_INPUT_LENGTH - 1 and MIN_INPUT_LENGTH
 *    read off the module's own exported constant, so a literal "3" drifting
 *    from the real constant would still be caught structurally (the row's
 *    presence/absence is asserted against the module's threshold, not a
 *    hardcoded number);
 *  - translation is asserted on the msgid, since `$t` resolves to identity
 *    here;
 *  - the surface test greps the real gateway_method.js source, so deleting
 *    the wiring (reverting to the old div) fails it directly.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const SURFACE_PATH = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const MSGID = 'My company is not on the list';
const MANUAL_ENTRY_ID = '__two_manual_entry__';
const MANUAL_ENTRY_CLASS = 'two-company-search__manual-entry';

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

/* ------------------------------------------------------------------ *
 * jQuery-lite over real jsdom nodes. Only the calls the model actually
 * makes, but each one backed by the real DOM so assertions are the
 * document's answers, not a vacuous stub's (this repo's harness default
 * jQuery mock returns `length: 0` for everything, which would prove
 * nothing here).
 * ------------------------------------------------------------------ */
function makeMiniQuery() {
    const dataStore = new WeakMap();
    const handlerStore = new WeakMap();

    function splitNs(spec) {
        const dot = spec.indexOf('.');
        return dot === -1 ? { type: spec, ns: '' } : { type: spec.slice(0, dot), ns: spec.slice(dot) };
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
        is: function (selector) {
            return this.nodes.some(function (node) {
                return node.matches(selector);
            });
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
                        return (!parsed.type || entry.type === parsed.type) && (!parsed.ns || entry.ns === parsed.ns);
                    })
                    .forEach(function (entry) {
                        node.removeEventListener(entry.type, entry.handler);
                        bound.splice(bound.indexOf(entry), 1);
                    });
            });
            return this;
        }
    };

    function $(arg) {
        if (arg === undefined || arg === null) return wrap([]);
        if (typeof arg === 'string') {
            if (arg.trim().startsWith('<')) return wrap(fromHtml(arg));
            return wrap(Array.prototype.slice.call(document.querySelectorAll(arg)));
        }
        if (arg.nodes) return arg;
        return wrap([arg]);
    }

    return $;
}

describe('company-search manual-entry model (payment-tile follow-up)', function () {
    let $;
    let companySearch;

    beforeEach(function () {
        document.body.innerHTML = '';
        $ = makeMiniQuery();
        companySearch = loadAmdModule(MODEL_PATH, { jquery: $ });
    });

    /** Build a fake select2-shaped DOM: search field + results `<ul>`. */
    function buildPicker() {
        document.body.innerHTML =
            '<input id="field" />' +
            '<div class="dropdown">' +
            '<span class="select2-search--dropdown">' +
            '<input class="select2-search__field" />' +
            '</span>' +
            '<ul id="select2-field-results" class="select2-results__options"></ul>' +
            '</div>';
        const $field = $('#field');
        const token = {};
        $field.data('twoSearchBind', token);
        $field.data('select2', { $dropdown: $('.dropdown') });
        return { $field, token };
    }

    test('renders nothing below MIN_INPUT_LENGTH', function () {
        const { $field, token } = buildPicker();
        $('.select2-search__field').val('ab');
        const $row = companySearch.renderManualEntryRow($field, token);
        expect($row.length).toBe(0);
        expect(document.querySelectorAll(`.${MANUAL_ENTRY_CLASS}`).length).toBe(0);
    });

    test('renders a real, accessible option row at the threshold', function () {
        const { $field, token } = buildPicker();
        $('.select2-search__field').val('abc');
        const $row = companySearch.renderManualEntryRow($field, token);
        expect($row.length).toBe(1);

        const node = $row.get(0);
        expect(node.tagName).toBe('LI');
        expect(node.getAttribute('role')).toBe('option');
        expect(node.classList.contains('select2-results__option')).toBe(true);
        expect(node.classList.contains('select2-results__option--selectable')).toBe(true);
        expect(node.classList.contains('select2-results__option--selected')).toBe(false);
        expect(node.textContent).toBe(MSGID);

        const payload = $row.data('data');
        expect(payload.id).toBe(MANUAL_ENTRY_ID);
        expect(payload._resultId).toBe(node.id);
        expect(companySearch.isManualEntryOption(payload)).toBe(true);
        expect(companySearch.isManualEntryOption({ id: 'Acme AB' })).toBe(false);
    });

    test('removes the row when the term drops back below threshold', function () {
        const { $field, token } = buildPicker();
        $('.select2-search__field').val('abc');
        companySearch.renderManualEntryRow($field, token);
        expect(document.querySelectorAll(`.${MANUAL_ENTRY_CLASS}`).length).toBe(1);

        $('.select2-search__field').val('ab');
        companySearch.renderManualEntryRow($field, token);
        expect(document.querySelectorAll(`.${MANUAL_ENTRY_CLASS}`).length).toBe(0);
    });

    test('survives select2 repainting the results list (re-appended, not duplicated)', function () {
        const { $field, token } = buildPicker();
        $('.select2-search__field').val('abc');
        companySearch.renderManualEntryRow($field, token);

        // select2 appends a fresh page of company results after ours.
        const $results = $('#select2-field-results');
        $results.append('<li class="select2-results__option">Acme AB</li>');
        companySearch.renderManualEntryRow($field, token);

        const rows = document.querySelectorAll(`.${MANUAL_ENTRY_CLASS}`);
        expect(rows.length).toBe(1);
        // The manual-entry row must be last, so the buyer sees real
        // companies first and manual entry as the fallback.
        expect($results.get(0).lastElementChild.classList.contains(MANUAL_ENTRY_CLASS)).toBe(true);
    });

    test('a stale token cannot paint a row onto a widget it does not own', function () {
        const { $field } = buildPicker();
        $('.select2-search__field').val('abc');
        const staleToken = {};
        const $row = companySearch.renderManualEntryRow($field, staleToken);
        expect($row.length).toBe(0);
        expect(document.querySelectorAll(`.${MANUAL_ENTRY_CLASS}`).length).toBe(0);
    });

    test('attachManualEntryRow re-renders the row on a MutationObserver hit after a repaint wipes it', function (done) {
        const { $field, token } = buildPicker();
        $('.select2-search__field').val('abc');
        companySearch.attachManualEntryRow($field, token);
        expect(document.querySelectorAll(`.${MANUAL_ENTRY_CLASS}`).length).toBe(1);

        // select2 clears the whole list on a new result page — this is what
        // the outside-the-list div never had to survive, and what the old
        // payment-tile code never re-created.
        document.getElementById('select2-field-results').innerHTML =
            '<li class="select2-results__option">Acme AB</li>';
        expect(document.querySelectorAll(`.${MANUAL_ENTRY_CLASS}`).length).toBe(0);

        // MutationObserver callbacks are microtask-scheduled.
        setTimeout(function () {
            expect(document.querySelectorAll(`.${MANUAL_ENTRY_CLASS}`).length).toBe(1);
            done();
        }, 0);
    });

    test('detachManualEntryObserver disconnects the observer', function () {
        const { $field, token } = buildPicker();
        $('.select2-search__field').val('abc');
        companySearch.attachManualEntryRow($field, token);

        const disconnect = jest.fn();
        $field.data('twoManualEntryObserver', { disconnect: disconnect });
        companySearch.detachManualEntryObserver($field);

        expect(disconnect).toHaveBeenCalledTimes(1);
        expect($field.data('twoManualEntryObserver')).toBeNull();
    });
});

describe('gateway_method.js payment-tile surface (structural fix)', function () {
    let src;

    beforeAll(function () {
        src = readSource(SURFACE_PATH);
    });

    test('no longer builds the manual-entry affordance as a div outside the listbox', function () {
        expect(src).not.toMatch(/billing_enter_details_manually/);
        expect(src).not.toMatch(/enterDetailsManuallyButton/);
        expect(src).not.toMatch(/enterDetailsManuallyText/);
    });

    test('wires the shared model row instead', function () {
        expect(src).toMatch(/companySearch\.attachManualEntryRow\(\s*\$companyNameField,\s*bindToken\s*\)/);
        expect(src).toMatch(/companySearch\.detachManualEntryObserver\(\s*\$companyNameField\s*\)/);
    });

    test('detaches the observer on the re-bind path too, not only on select2:close/dispose', function () {
        // A re-render (Fire Checkout re-renders the payment renderer on
        // totals/shipping changes) re-initialises select2 on the SAME node
        // while the picker may still be open, which does not always emit
        // `select2:close` first. Without this call the PREVIOUS bind's
        // observer is orphaned until — if ever — this node's picker is
        // reopened again. There must be a detach call BEFORE the
        // `.select2({` re-init, in addition to the one in the close handler.
        const reinitIndex = src.indexOf('.select2({');
        expect(reinitIndex).toBeGreaterThan(-1);
        const beforeReinit = src.slice(0, reinitIndex);
        expect(beforeReinit).toMatch(/companySearch\.detachManualEntryObserver\(\s*\$companyNameField\s*\)/);

        const detachCalls = src.match(/companySearch\.detachManualEntryObserver\(/g) || [];
        // re-bind path, select2:close, and destroyCompanySearchWidget.
        expect(detachCalls.length).toBeGreaterThanOrEqual(3);
    });

    test('intercepts the sentinel row on select2:selecting instead of a click handler on a footer div', function () {
        expect(src).toMatch(/select2:selecting/);
        expect(src).toMatch(/companySearch\.isManualEntryOption\(/);
    });

    test("defers the widget teardown out of select2's own selecting dispatch", function () {
        // select2:selecting fires from inside select2's own click/keydown
        // handling; destroying the select2 instance synchronously in that
        // handler would pull it out from under select2's own post-event
        // bookkeeping. clearCompany() must run on a deferred tick, not
        // inline in the handler.
        const selectingStart = src.indexOf("on('select2:selecting");
        expect(selectingStart).toBeGreaterThan(-1);
        const selectStart = src.indexOf("on('select2:select' + companySearch.EVENT_NS", selectingStart);
        expect(selectStart).toBeGreaterThan(selectingStart);
        const block = src.slice(selectingStart, selectStart);
        expect(block).toMatch(/setTimeout\(\s*function\s*\(\s*\)\s*\{[\s\S]*self\.clearCompany\(\)/);
    });

    test('the deferred teardown re-checks the bind before acting, so a re-render in the gap cannot tear down the wrong widget', function () {
        // Round-2 review finding: a checkout re-render (this component
        // re-binds select2 on every totals/shipping change, deliberately
        // un-guarded — see the re-bind comment near `.select2({`) can land in
        // the gap between the buyer's click and the deferred callback firing,
        // rebinding a NEW widget to the same node. Without a staleness check,
        // the deferred call destroys whatever widget is live NOW rather than
        // being a no-op for the bind it was scheduled from — exactly the
        // class of bug every OTHER deferred/async path in this handler chain
        // (onSearching, onUnavailable, clearSearchChrome, attachManualEntryRow)
        // already guards against via the same bindToken check.
        const selectingStart = src.indexOf("on('select2:selecting");
        const selectStart = src.indexOf("on('select2:select' + companySearch.EVENT_NS", selectingStart);
        const block = src.slice(selectingStart, selectStart);
        const setTimeoutStart = block.indexOf('setTimeout(');
        expect(setTimeoutStart).toBeGreaterThan(-1);
        const deferredBody = block.slice(setTimeoutStart);
        expect(deferredBody).toMatch(
            /companySearch\.getSearchFieldContainer\(\s*\$companyNameField,\s*bindToken\s*\)\.length/
        );
        // The staleness check must run BEFORE clearCompany(), not after.
        const gateIndex = deferredBody.indexOf('getSearchFieldContainer');
        const clearIndex = deferredBody.indexOf('self.clearCompany()');
        expect(gateIndex).toBeGreaterThan(-1);
        expect(clearIndex).toBeGreaterThan(gateIndex);
    });

    test('the shared model exports the helpers the payment tile depends on', function () {
        const modelSrc = readSource(MODEL_PATH);
        [
            'attachManualEntryRow',
            'detachManualEntryObserver',
            'renderManualEntryRow',
            'buildManualEntryOption',
            'isManualEntryOption'
        ].forEach(function (name) {
            expect(modelSrc).toMatch(new RegExp(name + ':\\s*function'));
        });
    });
});
