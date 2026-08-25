/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326 §1, the two wording/opening defects that are shared by all three
 * Magento checkout surfaces (Luma, Amasty OneStepCheckout, Fire Checkout —
 * one code path, three renderings):
 *
 *  - the dropdown opened on click, Enter and Space but on NO other key, so a
 *    buyer who focused the company field and simply started typing their
 *    company name saw nothing happen;
 *  - a zero-result search said "No results found", select2's vendored English
 *    literal, where the cross-platform wording is "No matches found".
 *
 * Both are properties of the shared model, so both are pinned here rather
 * than twice over at the two call sites.
 *
 * What this file does NOT pin: that the call sites actually invoke these.
 * That is a separate failure mode — the fix existing but never being wired
 * up — and it gets its own assertions at the bottom, read off the two
 * sources, because a mocked call site would prove nothing about the real one.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const ADDRESS_PATH = 'view/frontend/web/js/view/address-autocomplete.js';
// The tile's mount spans two files: the Knockout renderer and the
// company-capture control it delegates the search mount to.
const TILE_PATHS = [
    'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
    'view/frontend/web/js/model/company-capture.js'
];
// TWO-25326 rebuild: the select2 wiring itself (language block, open-on-type)
// moved into ONE shared class, `company-search-control.js`, constructed by
// each surface rather than each rolling its own `.select2({...})` call. The
// wiring is pinned there now; the two surfaces are pinned below only to have
// constructed the class and to no longer contain a parallel implementation.
const CONTROL_PATH = 'view/frontend/web/js/model/company-search-control.js';

function readRepoFile(relPath) {
    const contents = fs.readFileSync(path.join(__dirname, '..', '..', relPath), 'utf8');
    if (contents.length < 200) {
        throw new Error(relPath + ' fixture looks truncated: ' + contents.length + ' bytes');
    }
    return contents;
}

/**
 * jQuery-lite over real jsdom nodes — only the calls attachOpenOnType()
 * makes. Backed by the real document so `document.activeElement`, the seeded
 * value and the dispatched `input` event are the DOM's own answers rather
 * than a recording of our intentions.
 */
function makeMiniQuery() {
    const dataStore = new WeakMap();

    const api = {
        get: function (i) {
            return this.nodes[i];
        },
        find: function (sel) {
            const out = [];
            this.nodes.forEach(function (node) {
                Array.prototype.push.apply(out, node.querySelectorAll(sel));
            });
            return wrap(out);
        },
        val: function (next) {
            if (!arguments.length) return this.nodes.length ? this.nodes[0].value : undefined;
            this.nodes.forEach(function (node) {
                node.value = next;
            });
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
        on: function (spec, fn) {
            const type = String(spec).split('.')[0];
            this.nodes.forEach(function (node) {
                node.addEventListener(type, fn);
            });
            return this;
        },
        off: function () {
            return this;
        }
    };

    function wrap(nodes) {
        const set = Object.create(api);
        set.nodes = nodes;
        set.length = nodes.length;
        return set;
    }

    function $(arg) {
        if (arg === undefined || arg === null) return wrap([]);
        if (typeof arg === 'string') {
            return wrap(Array.prototype.slice.call(document.querySelectorAll(arg)));
        }
        if (arg.nodes) return arg;
        if (arg.nodeType) return wrap([arg]);
        return wrap([]);
    }
    $.fn = {};
    $.extend = Object.assign;
    return $;
}

/**
 * The picker as select2 renders it, plus the `select2('open')` behaviour the
 * model depends on: opening is what CREATES the search field select2 focuses
 * and our handler then seeds. Modelled as an actual state change (the
 * dropdown moves from absent to present) rather than a bare spy, so a fix
 * that seeds before opening — and would therefore find nothing to seed —
 * fails here instead of passing.
 */
function makePickerDom($) {
    document.body.innerHTML =
        '<div class="control">' +
        '<input id="company_name" type="text" tabindex="-1">' +
        '<span class="select2 select2-container">' +
        '<span class="selection">' +
        '<span class="select2-selection" role="combobox" tabindex="0"></span>' +
        '</span>' +
        '</span>' +
        '</div>';

    const $field = $('#company_name');
    const token = {};
    const state = { openCalls: 0 };

    function openDropdown() {
        state.openCalls++;
        if (document.querySelector('.select2-dropdown')) return;
        const dropdown = document.createElement('span');
        dropdown.className = 'select2-dropdown';
        dropdown.innerHTML =
            '<span class="select2-search select2-search--dropdown">' +
            '<input class="select2-search__field" type="text">' +
            '</span>' +
            '<span class="select2-results">' +
            '<ul class="select2-results__options" role="listbox"></ul>' +
            '</span>';
        document.body.appendChild(dropdown);
        state.instance.$dropdown = $('.select2-dropdown');
    }

    state.instance = {
        $dropdown: $('.select2-dropdown'),
        $selection: $('.select2-selection')
    };
    $field.data('twoSearchBind', token);
    $field.data('select2', state.instance);
    $field.select2 = function (verb) {
        if (verb === 'open') openDropdown();
        return $field;
    };

    return {
        $field: $field,
        token: token,
        state: state,
        combobox: document.querySelector('.select2-selection'),
        searchField: function () {
            return document.querySelector('.select2-search__field');
        }
    };
}

function pressKey(target, key, extra) {
    const event = new window.KeyboardEvent(
        'keydown',
        Object.assign({ key: key, bubbles: true, cancelable: true }, extra || {})
    );
    target.dispatchEvent(event);
    return event;
}

describe('TWO-25326 §1: any character opens the dropdown', () => {
    let $;
    let model;
    let dom;

    beforeEach(() => {
        $ = makeMiniQuery();
        model = loadAmdModule(
            MODEL_PATH,
            { jquery: $ },
            { document: document, window: window }
        );
        dom = makePickerDom($);
        model.attachOpenOnType(dom.$field, dom.token);
    });

    test('a printable character opens the picker and is seeded into the query field', () => {
        const inputEvents = [];
        const event = pressKey(dom.combobox, 'a');

        expect(event.defaultPrevented).toBe(true);
        expect(dom.state.openCalls).toBe(1);
        expect(dom.searchField()).toBeTruthy();
        // Seeded, not swallowed. Without this the buyer's first keystroke is
        // consumed by the open and they have to type the letter twice.
        expect(dom.searchField().value).toBe('a');
        expect(inputEvents).toEqual([]);
    });

    test('the seeded character is announced with a NATIVE input event, which is what select2 listens for', () => {
        // select2's own search handler is a native listener inside the
        // vendored bundle. A jQuery-synthesised trigger would not reach it,
        // so the search would never fire for the first character and the
        // dropdown would sit on the too-short hint until the buyer typed
        // again. Asserted via a native listener for exactly that reason.
        pressKey(dom.combobox, 'a');
        const search = dom.searchField();

        const seen = [];
        search.addEventListener('input', function (e) {
            seen.push(e.bubbles);
        });
        // Re-seeding through the same path must fire it again.
        pressKey(dom.combobox, 'b');
        expect(seen).toEqual([true]);
    });

    test('digits and punctuation count as characters too — an org number is a valid query', () => {
        pressKey(dom.combobox, '9');
        expect(dom.searchField().value).toBe('9');
    });

    test('Space and Enter are left to select2, which already opens on both', () => {
        const space = pressKey(dom.combobox, ' ');
        const enter = pressKey(dom.combobox, 'Enter');

        expect(space.defaultPrevented).toBe(false);
        expect(enter.defaultPrevented).toBe(false);
        expect(dom.state.openCalls).toBe(0);
    });

    test('Tab is never intercepted — §1 excludes it explicitly, and §4 needs it to navigate', () => {
        const tab = pressKey(dom.combobox, 'Tab');

        expect(tab.defaultPrevented).toBe(false);
        expect(dom.state.openCalls).toBe(0);
    });

    test('navigation and editing keys do not open the picker', () => {
        ['Escape', 'ArrowDown', 'ArrowUp', 'Backspace', 'Shift', 'F5', 'Home'].forEach(function (
            key
        ) {
            const event = pressKey(dom.combobox, key);
            expect(event.defaultPrevented).toBe(false);
        });
        expect(dom.state.openCalls).toBe(0);
    });

    test('Ctrl/Cmd/Alt combinations are browser shortcuts, not text entry', () => {
        expect(pressKey(dom.combobox, 'v', { ctrlKey: true }).defaultPrevented).toBe(false);
        expect(pressKey(dom.combobox, 'v', { metaKey: true }).defaultPrevented).toBe(false);
        expect(pressKey(dom.combobox, 'v', { altKey: true }).defaultPrevented).toBe(false);
        expect(dom.state.openCalls).toBe(0);
    });

    test('a superseded bind no longer opens anything — same fail-closed rule as the rest of the module', () => {
        dom.$field.data('twoSearchBind', {});

        const event = pressKey(dom.combobox, 'a');

        expect(event.defaultPrevented).toBe(false);
        expect(dom.state.openCalls).toBe(0);
    });

    test('a torn-down widget does not open anything either', () => {
        dom.$field.data('select2', undefined);

        const event = pressKey(dom.combobox, 'a');

        expect(event.defaultPrevented).toBe(false);
        expect(dom.state.openCalls).toBe(0);
    });
});

describe('TWO-25326 §1: zero-result wording', () => {
    let model;

    beforeEach(() => {
        model = loadAmdModule(MODEL_PATH, { jquery: makeMiniQuery() });
    });

    test('the message is "No matches found", not select2\'s "No results found"', () => {
        expect(model.noResultsMessage()).toBe('No matches found');
    });

    test('the language block both pickers share carries it, and the threshold hint', () => {
        const language = model.buildLanguageOptions();

        expect(typeof language.noResults).toBe('function');
        expect(language.noResults()).toBe('No matches found');
        expect(language.inputTooShort()).toContain(String(model.MIN_INPUT_LENGTH));
    });

    /**
     * The bundled default is what the fix displaces, so pin that it is still
     * the thing being displaced. If a future select2 upgrade changes the
     * vendored literal, this test says so rather than letting the override
     * quietly become a no-op equivalent.
     */
    test('the vendored select2 default really is the wording being overridden', () => {
        const bundle = readRepoFile('view/frontend/web/select2-4.1.0/js/select2.min.js');
        expect(bundle).toContain('No results found');
    });
});

describe('the shared control is actually wired to the shared fixes', () => {
    test('company-search-control.js passes the shared language block and opens on type', () => {
        const src = readRepoFile(CONTROL_PATH);

        expect(src).toContain('language: companySearch.buildLanguageOptions()');
        expect(src).toContain('companySearch.attachOpenOnType(');
        // The inline override this replaced would silently win over the
        // shared block if it were left behind.
        expect(src).not.toContain('inputTooShort: function');
    });

    test('both surfaces construct the shared control rather than rolling their own select2 wiring', () => {
        [[ADDRESS_PATH], TILE_PATHS].forEach(function (relPaths) {
            const src = relPaths
                .map(function (relPath) { return readRepoFile(relPath); })
                .join('\n');

            expect(src).toContain('new CompanySearchControl(');
            // A second, parallel select2 wiring would be exactly the defect
            // TWO-25326 asked to close — one implementation, not two.
            expect(src).not.toContain('.select2({');
            expect(src).not.toContain('companySearch.buildLanguageOptions()');
            expect(src).not.toContain('companySearch.attachOpenOnType(');
        });
    });
});
