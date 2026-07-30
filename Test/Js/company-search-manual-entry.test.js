/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288 element 5. The manual-entry affordance on the address step.
 *
 * What changed, and therefore what these tests have to hold down:
 *  - it is now the LAST ROW INSIDE the results list, not a div appended
 *    outside it. That is the whole accessibility fix: outside the list it sat
 *    outside the listbox the combobox owns, so no key reached it and no
 *    screen reader announced it;
 *  - it appears as soon as the term reaches the shared threshold, on `input`,
 *    ahead of the debounced request — the buyer whose company is not in the
 *    registry must not have to wait out a search that cannot help them;
 *  - it survives every re-render, because select2 empties the list on each
 *    new result set;
 *  - activating it is intercepted and cancelled, so the sentinel row never
 *    reaches the code that treats a selection as a company.
 *
 * Mutation-resistance notes, because this repo's harness makes vacuous
 * assertions easy:
 *  - the model DOM tests run against REAL jsdom nodes through a small
 *    jQuery-lite double, so `length`, class lists, attributes and sibling
 *    order are the document's answers, not a stub's;
 *  - the threshold is injected DELIBERATELY WRONG (5). Asserting against 3
 *    would pass whether the source read the shared constant or kept a
 *    literal;
 *  - translation is asserted on the msgid, since `$t` resolves to identity
 *    here;
 *  - the surface tests assert the picker actually bound select2 before
 *    asserting anything about its handlers.
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

/*
 * Re-declared here rather than read off the model: these are private to it,
 * the same way its spinner and notice class names are, and none has a
 * production consumer outside the module. A test that read them from the
 * module could not tell a renamed constant from a correct one.
 */
const MANUAL_ENTRY_ID = '__two_manual_entry__';
const MANUAL_ENTRY_CLASS = 'two-company-search__manual-entry';
const MANUAL_ENTRY_NS = '.twoManualEntry';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true
};

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
 * jQuery-lite over real jsdom nodes.
 *
 * Only the calls the model actually makes, but each one backed by the real
 * DOM so the assertions are the document's answers. A stubbed jQuery that
 * reports `length: 0` for everything — the trap this repo has already been
 * caught by — cannot be used to prove where a node ended up.
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
            const parsed = splitNs(spec);
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
    $.ajax = function () {
        throw new Error('no request may be issued while showing the manual-entry row');
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
        $search: $('.select2-search__field'),
        results: $('.select2-results__options').get(0),
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

function manualRows(dom) {
    return dom.results.querySelectorAll('.' + MANUAL_ENTRY_CLASS);
}

function tick() {
    return new Promise(function (resolve) {
        setTimeout(resolve, 0);
    });
}

describe('the manual-entry row is a real, keyboard-reachable option', () => {
    let $;
    let model;

    beforeEach(() => {
        $ = makeMiniQuery();
        model = loadModelWithWrongThreshold($);
    });

    test('it is an <li> option carrying everything select2 needs to navigate to it', () => {
        const $row = model.buildManualEntryOption('select2-company-results');
        const row = $row.get(0);

        expect(row).toBeTruthy();
        expect(row.tagName).toBe('LI');
        expect(row.getAttribute('role')).toBe('option');
        // The navigable set in the bundled select2 is keyed off this class;
        // without it the row is unreachable by arrow key.
        expect(row.classList.contains('select2-results__option')).toBe(true);
        expect(row.classList.contains('select2-results__option--selectable')).toBe(true);
        expect(row.classList.contains(MANUAL_ENTRY_CLASS)).toBe(true);

        expect(row.getAttribute('aria-selected')).toBe('false');
        // NOT `data-selected`: the vendored bundle contains no such attribute,
        // so writing one would only be a misleading claim about what select2
        // reads.
        expect(row.hasAttribute('data-selected')).toBe(false);

        // Marked selected — either way — activation routes to "close the
        // dropdown" and the row does nothing at all.
        expect(row.classList.contains('select2-results__option--selected')).toBe(false);
        expect(row.getAttribute('aria-disabled')).toBeNull();

        expect(row.textContent).toBe(MSGID);
    });

    test('its payload carries the id select2 stringifies and the result id ARIA reads', () => {
        const $row = model.buildManualEntryOption('select2-company-results');
        const payload = $row.data('data');

        expect(payload).toBeTruthy();
        // Absent, select2 compares this row under the literal string
        // "undefined" when it reconciles the list against the selection, so it
        // matches any other id-less row. No crash — a real one, which is why
        // it would have shipped unnoticed.
        expect(typeof payload.id).toBe('string');
        expect(payload.id).toBe(MANUAL_ENTRY_ID);
        // Must equal the row's own DOM id, or aria-activedescendant points at
        // nothing and the row is announced as the wrong one.
        expect(payload._resultId).toBe($row.get(0).getAttribute('id'));
        // Derived from the list it goes into, so two pickers on one page
        // cannot emit the same DOM id — which would make
        // aria-activedescendant ambiguous.
        expect($row.get(0).getAttribute('id')).toMatch(/^select2-company-results-/);
        expect(model.buildManualEntryOption('select2-other-results').get(0).getAttribute('id')).toMatch(
            /^select2-other-results-/
        );
        expect(payload.text).toBe(MSGID);
        // No `html`. The one path that would read it writes it through
        // innerHTML after the identity escaper, undoing the .text() write.
        expect('html' in payload).toBe(false);
    });

    test('the label is set as text, never as markup', () => {
        // This picker disables select2's markup escaping so server-side
        // highlighting can render, which makes the catalogue an injection
        // point if the label is ever interpolated into HTML.
        const source = readSource(MODEL_PATH);
        expect(source).toContain("$t('" + MSGID + "')");
        expect(source).not.toMatch(/<li[^>]*>\$\{/);
    });

    test('isManualEntryOption tells the sentinel from a real company', () => {
        expect(model.isManualEntryOption({ id: MANUAL_ENTRY_ID })).toBe(true);
        expect(model.isManualEntryOption({ id: 'Example Trading Ltd', companyId: '1' })).toBe(
            false
        );
        expect(model.isManualEntryOption(null)).toBe(false);
        expect(model.isManualEntryOption(undefined)).toBe(false);
        expect(model.isManualEntryOption(MANUAL_ENTRY_ID)).toBe(false);
    });
});

describe('when the row is shown', () => {
    let $;
    let model;
    let dom;

    beforeEach(() => {
        $ = makeMiniQuery();
        model = loadModelWithWrongThreshold($);
        dom = makePickerDom($);
    });

    test('it appears at the SHARED threshold, not a literal, and goes away below it', () => {
        expect(model.MIN_INPUT_LENGTH).toBe(WRONG_THRESHOLD);

        dom.search.value = 'exam'; // 4 — below the injected threshold
        model.renderManualEntryRow(dom.$field, dom.token);
        expect(manualRows(dom)).toHaveLength(0);

        dom.search.value = 'examp'; // 5 — at it
        model.renderManualEntryRow(dom.$field, dom.token);
        expect(manualRows(dom)).toHaveLength(1);

        dom.search.value = 'exam';
        model.renderManualEntryRow(dom.$field, dom.token);
        expect(manualRows(dom)).toHaveLength(0);
    });

    test('it is INSIDE the results list, and last', () => {
        addRealResult(dom, 'Example Trading Ltd');
        addRealResult(dom, 'Example Holdings AS');
        dom.search.value = 'example';
        model.renderManualEntryRow(dom.$field, dom.token);

        const rows = dom.results.children;
        expect(rows).toHaveLength(3);
        expect(rows[2].classList.contains(MANUAL_ENTRY_CLASS)).toBe(true);
        // The defect this replaces: the affordance living outside the list.
        expect(manualRows(dom)).toHaveLength(1);
        expect(dom.results.getAttribute('role')).toBe('listbox');
    });

    test('a later page of results does not leave it stranded mid-list', () => {
        dom.search.value = 'example';
        model.renderManualEntryRow(dom.$field, dom.token);
        addRealResult(dom, 'Example Logistics Ltd');
        model.renderManualEntryRow(dom.$field, dom.token);

        const rows = dom.results.children;
        expect(rows).toHaveLength(2);
        expect(rows[1].classList.contains(MANUAL_ENTRY_CLASS)).toBe(true);
        expect(manualRows(dom)).toHaveLength(1);
    });

    test('rendering repeatedly never doubles it', () => {
        dom.search.value = 'example';
        model.renderManualEntryRow(dom.$field, dom.token);
        model.renderManualEntryRow(dom.$field, dom.token);
        model.renderManualEntryRow(dom.$field, dom.token);
        expect(manualRows(dom)).toHaveLength(1);
    });

    test("a stale bind cannot paint a row onto the live picker", () => {
        const staleToken = dom.token;
        dom.search.value = 'example';

        // Establish that this very call DOES paint while the bind is live, so
        // the assertion below cannot pass merely because the list started
        // empty — the vacuity trap in an "expect empty after" assertion.
        model.renderManualEntryRow(dom.$field, staleToken);
        expect(manualRows(dom)).toHaveLength(1);

        // Re-render: same node, fresh bind identity, and select2 has emptied
        // the list on its way through.
        dom.results.innerHTML = '';
        dom.$field.data('twoSearchBind', {});

        model.renderManualEntryRow(dom.$field, staleToken);
        expect(manualRows(dom)).toHaveLength(0);
    });

    test('the results-list lookup itself fails closed on a stale bind', () => {
        // Asserted DIRECTLY, not through renderManualEntryRow: that path also
        // consults the search-box lookup, whose own staleness guard would keep
        // it green with this one deleted — so the contract would be held by
        // nothing.
        const staleToken = dom.token;
        expect(model.getResultsList(dom.$field, staleToken).length).toBe(1);

        dom.$field.data('twoSearchBind', {});
        expect(model.getResultsList(dom.$field, staleToken).length).toBe(0);
    });

    test('the search-term lookup fails closed on a stale bind too', () => {
        // Same reason as the results-list case above, and found the same way:
        // a mutation that made this helper read the bind token off the node
        // instead of honouring the one it was handed survived the whole suite.
        const staleToken = dom.token;
        dom.search.value = 'example';
        expect(model.currentSearchTerm(dom.$field, staleToken)).toBe('example');

        dom.$field.data('twoSearchBind', {});
        expect(model.currentSearchTerm(dom.$field, staleToken)).toBe('');
    });

    test('a nested group list is never mistaken for the results list', () => {
        const nested = document.createElement('ul');
        nested.className = 'select2-results__options select2-results__options--nested';
        dom.results.appendChild(nested);

        const $found = model.getResultsList(dom.$field, dom.token);
        expect($found.length).toBe(1);
        expect($found.get(0)).toBe(dom.results);
    });
});

describe('what drives the row', () => {
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
        // fails the test: the row must not wait on a debounce or a search.
        model.attachManualEntryRow(dom.$field, dom.token);
        // Attaching alone must not show the row — this one is falsifiable only
        // by an always-show regression; the transitions below are what pin the
        // threshold.
        expect(manualRows(dom)).toHaveLength(0);

        typeTerm(dom, 'examp');
        expect(manualRows(dom)).toHaveLength(1);

        typeTerm(dom, 'exam');
        expect(manualRows(dom)).toHaveLength(0);
    });

    test('reopening the picker does not stack handlers', () => {
        model.attachManualEntryRow(dom.$field, dom.token);
        model.attachManualEntryRow(dom.$field, dom.token);
        model.attachManualEntryRow(dom.$field, dom.token);

        expect($.boundHandlers(dom.search, 'input', MANUAL_ENTRY_NS)).toHaveLength(1);
    });

    test('its namespace does not collide with the below-threshold cancel handler', () => {
        // Both bind `input` to the same node. One namespace and each one's
        // `.off()` silently unbinds the other.
        expect(MANUAL_ENTRY_NS).not.toBe(model.EVENT_NS);
        model.markSearchBinding(dom.$field, dom.token);
        model.attachManualEntryRow(dom.$field, dom.token);

        expect($.boundHandlers(dom.search, 'input', model.EVENT_NS)).toHaveLength(1);
        expect($.boundHandlers(dom.search, 'input', MANUAL_ENTRY_NS)).toHaveLength(1);
    });

    test('it comes back after select2 empties the list for a new result set', async () => {
        model.attachManualEntryRow(dom.$field, dom.token);
        typeTerm(dom, 'example');
        expect(manualRows(dom)).toHaveLength(1);

        // What select2 does on every new result set.
        dom.results.innerHTML = '';
        addRealResult(dom, 'Example Trading Ltd');
        await tick();

        const rows = dom.results.children;
        expect(manualRows(dom)).toHaveLength(1);
        expect(rows[rows.length - 1].classList.contains(MANUAL_ENTRY_CLASS)).toBe(
            true
        );
    });

    test('neither closure survives its own bind being superseded', async () => {
        const staleToken = dom.token;
        model.attachManualEntryRow(dom.$field, staleToken);

        // Live first, so the zero-assertions below cannot pass on a list that
        // was never going to get a row anyway.
        typeTerm(dom, 'example');
        expect(manualRows(dom)).toHaveLength(1);

        // Re-render: select2 empties the list and the picker is re-bound on the
        // same node under a FRESH identity. Every closure from the old bind is
        // still attached, and each holds its own token.
        dom.results.innerHTML = '';
        dom.$field.data('twoSearchBind', {});

        // The input closure must no longer paint. Reading the node's identity
        // instead of its own token would match the new bind and paint onto the
        // live picker.
        typeTerm(dom, 'example ltd');
        expect(manualRows(dom)).toHaveLength(0);

        // Nor may the old observer paint when the new bind renders results.
        addRealResult(dom, 'Example Trading Ltd');
        await tick();
        expect(manualRows(dom)).toHaveLength(0);

        // And re-attaching under the stale token must be inert too.
        model.attachManualEntryRow(dom.$field, staleToken);
        typeTerm(dom, 'example holdings');
        expect(manualRows(dom)).toHaveLength(0);
    });

    test('detaching stops the watcher, so a torn-down picker holds nothing', async () => {
        model.attachManualEntryRow(dom.$field, dom.token);
        typeTerm(dom, 'example');
        model.detachManualEntryObserver(dom.$field);

        dom.results.innerHTML = '';
        addRealResult(dom, 'Example Trading Ltd');
        await tick();

        expect(manualRows(dom)).toHaveLength(0);
    });
});

/* ------------------------------------------------------------------ *
 * The address surface: what it wires the row up to.
 * ------------------------------------------------------------------ */
function makeSurfaceQuery(recorder) {
    function $() {
        const obj = {
            length: 0,
            val: function () {
                return arguments.length ? obj : '';
            },
            trigger: function () {
                return obj;
            },
            prop: function () {
                return obj;
            },
            text: function () {
                return obj;
            },
            attr: function (name, value) {
                if (arguments.length > 1) recorder.attrWrites.push([name, value]);
                return obj;
            },
            off: function () {
                return obj;
            },
            on: function (spec, handler) {
                recorder.handlers[spec.split('.')[0]] = handler;
                return obj;
            },
            hide: function () {
                recorder.hidden += 1;
                return obj;
            },
            show: function () {
                recorder.shown += 1;
                return obj;
            },
            closest: function () {
                return obj;
            },
            append: function (html) {
                recorder.appended.push(String(html));
                return obj;
            },
            find: function () {
                return obj;
            },
            data: function () {
                return obj;
            },
            select2: function (opts) {
                if (typeof opts === 'object') {
                    recorder.select2Options = opts;
                    recorder.select2Calls += 1;
                } else if (opts === 'destroy') {
                    recorder.destroyCalls += 1;
                }
                return obj;
            }
        };
        return obj;
    }
    $.async = function (selector, fn) {
        fn(selector);
    };
    $.ajax = function () {
        const jqxhr = {
            done: function () {
                return jqxhr;
            },
            fail: function () {
                return jqxhr;
            },
            always: function () {
                return jqxhr;
            }
        };
        return jqxhr;
    };
    $.mage = { cookies: { get: () => null }, redirect: function () {} };
    $.extend = Object.assign;
    $.fn = {};
    return $;
}

function loadShippingSurface($, companySearch, overrides) {
    const brandConfig = function () {
        return BASE_CONFIG;
    };
    brandConfig.getActiveTwoBrandCode = function () {
        return 'two_payment';
    };
    brandConfig.getActiveTwoBrandConfig = function () {
        return BASE_CONFIG;
    };

    const component = loadAmdModule(SURFACE_PATH, {
        jquery: $,
        'Two_Gateway/js/model/brand-config': brandConfig,
        'Two_Gateway/js/model/company-search': companySearch
    });

    const ctx = Object.assign(
        Object.create(component.prototype || {}),
        {
            countrySelector: '#shipping-new-address-form select[name="country_id"]',
            companyNameSelector: '#shipping-new-address-form input[name="company"]',
            searchForCompanyButton: '#shipping_search_for_company',
            searchForCompanyText: 'Search for company',
            companyNamePlaceholder: 'Enter company name to search',
            setCompanyData: jest.fn(),
            addressLookup: jest.fn(),
            enableCompanySearch: component.enableCompanySearch,
            enterDetailsManually: component.enterDetailsManually
        },
        overrides || {}
    );
    ctx.enableCompanySearch();
    return { component: component, ctx: ctx };
}

/** Inert stand-in for the shared model, recording what the surface asks of it. */
function makeModelSpy() {
    return {
        EVENT_NS: '.twoCompanySearch',
        MIN_INPUT_LENGTH: 3,
        REQUEST_TIMEOUT_MS: 30000,
        SEARCH_DEBOUNCE_MS: 300,
        minInputLengthMessage: function () {
            return 'Please enter 3 or more characters';
        },
        buildSearchAjaxOptions: function () {
            return {};
        },
        lookupCompanyAddress: function () {
            return null;
        },
        applyAddress: function () {},
        isDegradedResponse: function () {
            return false;
        },
        markSearchBinding: function () {},
        clearSearchChrome: function () {},
        setSearching: function () {},
        setUnavailable: function () {},
        abortActiveRequest: jest.fn(),
        isManualEntryOption: function (data) {
            return Boolean(data) && typeof data === 'object' && data.id === MANUAL_ENTRY_ID;
        },
        attachManualEntryRow: jest.fn(),
        detachManualEntryObserver: jest.fn()
    };
}

function newRecorder() {
    return {
        handlers: {},
        appended: [],
        attrWrites: [],
        select2Options: null,
        select2Calls: 0,
        destroyCalls: 0,
        shown: 0,
        hidden: 0
    };
}

describe('the address step wires the row up', () => {
    test('opening the picker attaches the row, and appends nothing outside the list', () => {
        const recorder = newRecorder();
        const $ = makeSurfaceQuery(recorder);
        const model = makeModelSpy();
        loadShippingSurface($, model);

        // Bootstrapped guard: without this, a surface that returned early
        // would present as green.
        expect(recorder.select2Calls).toBeGreaterThan(0);
        expect(typeof recorder.handlers['select2:open']).toBe('function');

        recorder.handlers['select2:open']();
        expect(model.attachManualEntryRow).toHaveBeenCalledTimes(1);

        // The only thing this surface may still append outside the list is the
        // reverse link. The old manual-entry div must be gone.
        const outside = recorder.appended.join('\n');
        expect(outside).toContain('shipping_search_for_company');
        expect(outside).not.toContain('enter_details_manually');
    });

    test('activating the row is cancelled and switches to manual entry', () => {
        const recorder = newRecorder();
        const $ = makeSurfaceQuery(recorder);
        const model = makeModelSpy();
        const { ctx } = loadShippingSurface($, model);

        const selecting = recorder.handlers['select2:selecting'];
        expect(typeof selecting).toBe('function');

        const preventDefault = jest.fn();
        const shownBefore = recorder.shown;
        selecting({
            params: { args: { data: { id: MANUAL_ENTRY_ID, text: MSGID } } },
            preventDefault: preventDefault
        });

        // Cancelled, or the sentinel id is written in as a company name and an
        // address lookup fires for it.
        expect(preventDefault).toHaveBeenCalledTimes(1);
        // Company in play cleared, picker torn down, reverse link revealed.
        expect(ctx.setCompanyData).toHaveBeenCalledWith();
        expect(recorder.destroyCalls).toBe(1);
        expect(model.detachManualEntryObserver).toHaveBeenCalledTimes(1);
        expect(recorder.shown).toBe(shownBefore + 1);
        expect(recorder.attrWrites).toContainEqual(['type', 'text']);
    });

    test('activating the row cancels the search still on the wire, first', () => {
        const recorder = newRecorder();
        const $ = makeSurfaceQuery(recorder);
        const model = makeModelSpy();
        const { ctx } = loadShippingSurface($, model);

        recorder.handlers['select2:open']();
        const bindToken = model.attachManualEntryRow.mock.calls[0][1];

        recorder.handlers['select2:selecting']({
            params: { args: { data: { id: MANUAL_ENTRY_ID, text: MSGID } } },
            preventDefault: jest.fn()
        });

        // Same bind, by IDENTITY. The token is an empty object, so a
        // structural comparison passes against any other empty object and
        // would prove nothing about which request gets cancelled.
        expect(model.abortActiveRequest).toHaveBeenCalledTimes(1);
        expect(model.abortActiveRequest.mock.calls[0][0]).toBe(bindToken);
        // BEFORE the teardown: the dropdown is still open at this point
        // (the selection was cancelled), so a late response would run select2's
        // highlight and scroll bookkeeping over a torn-down picker.
        expect(model.abortActiveRequest.mock.invocationCallOrder[0]).toBeLessThan(
            ctx.setCompanyData.mock.invocationCallOrder[0]
        );
    });

    test('closing the picker stops watching its results list', () => {
        const recorder = newRecorder();
        const $ = makeSurfaceQuery(recorder);
        const model = makeModelSpy();
        loadShippingSurface($, model);

        expect(typeof recorder.handlers['select2:close']).toBe('function');
        // PRECONDITION, not evidence: nothing has closed the picker yet.
        expect(model.detachManualEntryObserver).not.toHaveBeenCalled();

        recorder.handlers['select2:close']();
        expect(model.detachManualEntryObserver).toHaveBeenCalledTimes(1);
    });

    test('a real company selection is left completely alone', () => {
        const recorder = newRecorder();
        const $ = makeSurfaceQuery(recorder);
        const model = makeModelSpy();
        const { ctx } = loadShippingSurface($, model);

        const preventDefault = jest.fn();
        recorder.handlers['select2:selecting']({
            params: {
                args: { data: { id: 'Example Trading Ltd', text: 'Example Trading Ltd' } }
            },
            preventDefault: preventDefault
        });

        expect(preventDefault).not.toHaveBeenCalled();
        expect(recorder.destroyCalls).toBe(0);
        expect(ctx.setCompanyData).not.toHaveBeenCalled();
    });

    test('the old outside-the-list affordance is gone from the surface entirely', () => {
        const source = readSource(SURFACE_PATH);
        expect(source).not.toContain('enter_details_manually');
        expect(source).not.toContain('Enter details manually');
        expect(source).toContain('attachManualEntryRow');
        expect(source).toContain('select2:selecting');
    });
});

describe('the vendored bundle still works the way the row depends on', () => {
    // Pinned verbatim, the way this repo already pins the bundle's own
    // below-threshold message: an upgrade that renames either of these keeps
    // every behavioural test above green (they run against our own DOM) while
    // silently making the row unreachable in a real browser.
    const BUNDLE = 'view/frontend/web/select2-4.1.0/js/select2.min.js';

    test('every activation path still walks the selectable class we set', () => {
        // All three, not just arrow-down: the class occurs eight times in the
        // bundle, so pinning one handler leaves a rename in either of the
        // others green while the row stops being reachable that way.
        const bundle = readSource(BUNDLE);
        expect(bundle).toContain(
            '"results:next",function(){var e,t=i.getHighlightedResults(),' +
                'n=i.$results.find(".select2-results__option--selectable")'
        );
        expect(bundle).toContain(
            '"results:previous",function(){var e,t=i.getHighlightedResults(),' +
                'n=i.$results.find(".select2-results__option--selectable")'
        );
        expect(bundle).toContain(
            '$results.on("mouseup",".select2-results__option--selectable"'
        );
    });

    test('aria-activedescendant is still taken from the payload result id', () => {
        // Asserted over EVERY write of the attribute, not as one `toContain`:
        // the bundle carries two copies of this expression, so pinning the
        // string survived a mutation that renamed one of them.
        const writes = readSource(BUNDLE).match(/attr\("aria-activedescendant",[^)]*\)/g) || [];
        expect(writes.length).toBeGreaterThan(0);
        writes.forEach((write) => {
            expect(write).toContain('_resultId');
        });
    });

    test('the bundle has no notion of a data-selected attribute', () => {
        expect(readSource(BUNDLE)).not.toContain('data-selected');
    });
});

describe('translation', () => {
    test('the label is a translatable msgid, translated in every catalogue', () => {
        expect(readSource(MODEL_PATH)).toContain("$t('" + MSGID + "')");
        // Read off disk, not hardcoded: a fourth catalogue added later must not
        // be able to ship this string untranslated and green.
        const locales = fs
            .readdirSync(path.resolve(__dirname, '..', '..', 'i18n'))
            .filter((name) => name.endsWith('.csv'))
            .map((name) => name.replace(/\.csv$/, ''));
        expect(locales.length).toBeGreaterThanOrEqual(3);

        locales.forEach((locale) => {
            const csv = readSource('i18n/' + locale + '.csv');
            expect(csv).toContain('"' + MSGID + '","');
            // Magento drops rows whose translation equals the msgid, so an
            // identity row is the same as no row at all.
            expect(csv).not.toContain('"' + MSGID + '","' + MSGID + '"');
        });
    });
});
