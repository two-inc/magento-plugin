/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. The payment tile shows the captured company NAME and NUMBER, and
 * the buyer can neither edit nor remove what was captured.
 *
 * This SUPERSEDES the earlier removal of the number input. The reason that
 * input went away holds — a hand-typed organisation number is not an accepted
 * source; it produced poor data quality and genuine buyers receiving invoices
 * for orders they never placed — but removing it also stopped the buyer seeing
 * what the order would be invoiced against. Read-only keeps the first and
 * restores the second. For the accepted sources, see the writer enumeration on
 * applyCompanyData() in the renderer — deliberately NOT restated here, because
 * a second copy of that list has already drifted twice.
 *
 * Three groups of tests here, and they are NOT the same kind of test. Be honest
 * about which is which before trusting a green run:
 *
 *  1. READ-ONLY PINS — 'the payment tile shows both company fields, uneditable'.
 *     These fail against BOTH the removal and any reinstatement as an editable
 *     field. They are what guards the change.
 *
 *  2. WHAT-THE-BUYER-SEES PINS — 'what each capture mode puts in front of the
 *     buyer'. These do not restate the markup: they read the `value:` binding
 *     target out of the template, then evaluate it against a renderer driven
 *     through the real capture flow, so a field bound to the wrong observable
 *     fails here even though the markup is present and correct.
 *
 *  3. ACCEPTED-SOURCE REGRESSION PINS — most of 'an accepted organisation
 *     number still reaches the order'. These pass against the pre-change code
 *     too, because every accepted source already wrote the observable. They do
 *     NOT prove anything about the fields. They exist because the observable is
 *     the ONLY carrier — the read-only input has no `name` and so submits
 *     nothing — so a future change that breaks one of those writer paths loses
 *     the number outright rather than falling back to a field, and the
 *     sole-trader routes are the ones most likely to break silently, their
 *     value being minted rather than picked.
 *
 *     ONE EXCEPTION, and it belongs to group 1 despite living in group 3's
 *     describe: 'the sole-trader number survives with no write to the number
 *     field' asserts there are NO writes to `input#company_id`. The renderer
 *     used to do `$(this.companyIdSelector).val(companyId)`; that must not come
 *     back, because ko's `value: companyId` binding is now the only painter and
 *     a second one would let the two desync.
 *
 * Group 3 is deliberately asserted through `getData()` rather than the DOM.
 * That is the actual submit path (`Observer/DataAssignObserver.php` reads
 * `additional_data`), and it reads the observable. Asserting the DOM there
 * would prove nothing.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';

function readTemplate() {
    const full = path.join(__dirname, '..', '..', TEMPLATE);
    const markup = fs.readFileSync(full, 'utf8');
    // Guard the fixture itself. A silently-empty read would make every
    // "no longer contains" assertion below pass for the wrong reason.
    if (markup.length < 1000) {
        throw new Error('template fixture looks truncated: ' + markup.length + ' bytes');
    }
    return markup;
}

/**
 * Strip HTML comments before asserting on markup. Both fields carry long
 * explanatory comments that name `company_id`, `readonly` and `disabled` on
 * purpose, and a raw substring search would match those and report a passing
 * pin for prose.
 */
function withoutComments(markup) {
    return markup.replace(/<!--[\s\S]*?-->/g, '');
}

/**
 * The <input> tag for `id`, comments already stripped. Throws rather than
 * returning null: every caller treats a missing field as a failure, and an
 * `expect(undefined)` further down reports the wrong thing.
 */
function inputTag(id) {
    const markup = withoutComments(readTemplate());
    const tags = (markup.match(/<input\b[^>]*>/g) || []).filter(function (tag) {
        return new RegExp('\\bid\\s*=\\s*["\']' + id + '["\']').test(tag);
    });
    if (tags.length !== 1) {
        throw new Error('expected exactly one <input id="' + id + '">, found ' + tags.length);
    }
    return tags[0];
}

/**
 * What ko would paint into `id`'s value, derived rather than restated: read the
 * `value:` binding target out of the template, look that name up on a live
 * renderer, and evaluate it. A field bound to the wrong observable — or to none
 * — therefore fails here, which a markup substring check cannot catch.
 */
function renderedValue(id, renderer) {
    const bound = inputTag(id).match(/value:\s*([A-Za-z_$][\w$]*)/);
    if (!bound) {
        throw new Error('<input id="' + id + '"> has no `value:` binding');
    }
    const target = renderer[bound[1]];
    if (typeof target !== 'function') {
        throw new Error(
            '<input id="' + id + '"> is bound to `' + bound[1] + '`, which the renderer has not'
        );
    }
    return target.call(renderer);
}

/**
 * Whether `tag` carries `name` as a real HTML ATTRIBUTE — preceded by
 * whitespace and followed by `=`, `>`, `/` or more whitespace.
 *
 * Not `\bname\b`, and the difference is not pedantry: `\breadonly\b` matches
 * inside the class name `two-company-id-readonly`, because `-` is a non-word
 * character and therefore a word boundary. Every read-only assertion in this
 * file passed against a template with the attribute DELETED until this was
 * anchored — caught by mutation, not by review.
 */
function hasAttribute(tag, name) {
    return new RegExp('\\s' + name + '(?=[\\s=>/])').test(tag);
}

/**
 * Whether the buyer can type into `id`, in the state `renderer` is currently
 * in. A static `readonly`/`disabled` attribute settles it outright; otherwise
 * the ko `attr` binding's target is evaluated the same way renderedValue() does
 * it, so a `readonly` bound to an observable is answered for THIS state rather
 * than assumed.
 */
function isReadOnly(id, renderer) {
    const tag = inputTag(id);
    if (hasAttribute(tag, 'readonly') || hasAttribute(tag, 'disabled')) {
        return true;
    }
    const bound = tag.match(/\breadonly:\s*([A-Za-z_$][\w$]*)/);
    if (!bound) {
        return false;
    }
    const target = renderer[bound[1]];
    if (typeof target !== 'function') {
        throw new Error(
            '<input id="' + id + '"> binds readonly to `' + bound[1] + '`, not on the renderer'
        );
    }
    return !!target.call(renderer);
}

describe('the payment tile shows both company fields, uneditable', () => {
    test('the number field is declared, bound to companyId, and statically read-only', () => {
        const tag = inputTag('company_id');

        // `readonly`, NOT `disabled`. A disabled control is dropped from the
        // submitted form and skipped by jQuery Validation's `elements()`; the
        // distinction is what made the old editable-state derivation load-bearing
        // and is exactly what must not come back.
        expect(hasAttribute(tag, 'readonly')).toBe(true);
        expect(hasAttribute(tag, 'disabled')).toBe(false);
        expect(tag).toMatch(/value:\s*companyId\b/);

        // Static, not bound: there is no mode in which this may be typed, so no
        // observable may be able to turn it off. `readonly: <expr>` inside a
        // data-bind would match the `\breadonly\b` above, so rule it out
        // explicitly rather than relying on that assertion to have meant it.
        expect(tag).not.toMatch(/\breadonly\s*:/);
    });

    test('the number field carries no name, so it submits nothing', () => {
        // getData() → `additional_data` is the submit path. A `name` here would
        // add a SECOND carrier for the same value, free to disagree with the
        // observable, which is how a stale number reaches an order.
        expect(inputTag('company_id')).not.toMatch(/\sname\s*=/);
    });

    test('the name field is read-only only once a company has been captured', () => {
        // Not a static attribute, and the asymmetry is the point. Sole trader is
        // the one mode where this node is a plain text box holding a captured
        // name. In search mode select2 replaces it; in manual-entry mode the
        // buyer MUST be able to type. A static `readonly` bricks manual entry.
        const { renderer } = loadRendererOnly();

        renderer.showSoleTrader(true);
        renderer.fillCompanyData({
            companyName: 'Sole Trader Example',
            companyId: 'ST-SYNTH-005'
        });
        expect(isReadOnly('company_name', renderer)).toBe(true);

        renderer.showSoleTrader(false);
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('the name field is never both empty and locked', () => {
        // THE REGRESSION THIS GUARDS, and it is not hypothetical — the first
        // draft of this change shipped it. The input is `required`, and jQuery
        // Validation enforces `required` on a `[readonly]` field (its
        // `elements()` skips `:disabled` only). So a state that is both empty
        // and locked is a validation error with no buyer action that clears it.
        //
        // Reached by entering sole-trader mode where the prefetch did NOT match
        // a buyer: enterSoleTraderUi() blanks the input and the autofill never
        // lands, because fillCompanyData() early-returns unless BOTH name and
        // number are non-empty. Keying `readonly` on the mode alone stranded the
        // buyer here.
        //
        // Driven through enterSoleTraderUi(), which is the sub-path
        // soleTraderMode() runs FIRST on every branch. The rest of the unmatched
        // branch is signup-popup plumbing — getAutofillData() needs `btoa` and
        // openIframe() needs `window.open`, neither of which the AMD sandbox
        // provides — and none of it touches the state under test.
        const { renderer } = loadRendererOnly();

        renderer.enterSoleTraderUi();

        expect(renderer.showSoleTrader()).toBe(true);
        expect(renderedValue('company_id', renderer)).toBe('');
        // The name the buyer must now supply by hand — so the field cannot be
        // locked, whatever mode says.
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('a prior search does not leave the name locked after switching to sole trader', () => {
        // Same trap by a different route: a captured company, THEN the mode
        // switch. enterSoleTraderUi() → clearCompany() blanks the input AND the
        // number, and on the unmatched-prefetch branch nothing refills either.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        renderer.enterSoleTraderUi();

        expect(renderedValue('company_id', renderer)).toBe('');
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('the name field keeps its required flag and its submit name', () => {
        // Address-step name capture is Magento core's `company` attribute, gated
        // on `customer/address/company_show`, which this module does not own — so
        // weakening this input could leave a shop with no company-name capture.
        const tag = inputTag('company_name');

        expect(hasAttribute(tag, 'required')).toBe(true);
        expect(tag).toMatch(/name="payment\[company_name\]"/);
        expect(tag).toMatch(/value:\s*companyName\b/);
    });

    test('company_name is still the only required input in the payment form', () => {
        // Pins what `$(formSelector).valid()` enforces. The number field is
        // deliberately NOT required: a read-only empty required field fails
        // validation with no way for the buyer to satisfy it.
        // Model/Two.php::authorize() is the only enforcement for the number.
        const markup = withoutComments(readTemplate());

        // Every spelling jQuery Validation would honour, not just
        // `required="true"`: a bare `required`, `required="required"`, and the
        // `data-validate` form all count.
        const requiredInputs = (markup.match(/<input\b[^>]*>/g) || []).filter(function (tag) {
            return (
                hasAttribute(tag, 'required') ||
                /data-validate\s*=\s*["'][^"']*required/.test(tag)
            );
        });

        expect(requiredInputs).toHaveLength(1);
        expect(requiredInputs[0]).toMatch(/\bid\s*=\s*["']company_name["']/);
    });

    test('the renderer keeps no editable-state apparatus for the number', () => {
        // The field is back but its editability derivation is not, and must not
        // be: `readonly` is static, so nothing may compute it.
        const { renderer } = loadRendererOnly();

        expect(renderer.companyIdSelector).toBeUndefined();
        expect(renderer.needsManualCompanyId).toBeUndefined();
        expect(renderer.syncCompanyIdEditable).toBeUndefined();
        // The name field's selector is still live — company search binds to it.
        expect(renderer.companyNameSelector).toBe('input#company_name');
    });

    test('clearCompany takes no disable argument and touches no number field', () => {
        const { renderer } = loadRendererOnly();

        // Guard the subject first. `String(undefined)` is the string
        // "undefined", which contains none of the substrings below, so a
        // renamed or deleted clearCompany would make all three assertions pass
        // vacuously.
        expect(typeof renderer.clearCompany).toBe('function');

        // Asserting on the function's own SOURCE, not on `.length`. A default
        // parameter (`function (disableCompanyId = false)`) does not count
        // toward `Function.length`, so a length assertion cannot fail if the
        // parameter comes back in the form it had before — which is exactly the
        // form it had. Verified by mutation: the length version survived.
        const source = String(renderer.clearCompany);

        expect(source).not.toContain('disableCompanyId');
        expect(source).not.toContain('companyIdSelector');
        expect(source).not.toContain('company_id');
    });
});

describe('what each capture mode puts in front of the buyer', () => {
    test('search mode: the buyer sees the picked name AND its number', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        expect(renderedValue('company_name', renderer)).toBe('First Example Ltd');
        expect(renderedValue('company_id', renderer)).toBe('12345678');
        // Visible, and still not editable.
        expect(isReadOnly('company_id', renderer)).toBe(true);
    });

    test('sole-trader mode: the minted name and number show, both uneditable', () => {
        const { renderer } = loadRendererOnly();

        renderer.prefetched = {
            ready: true,
            matches: true,
            buyer: {
                organization_number: 'ST-SYNTH-004',
                company_name: 'Sole Trader Example'
            }
        };
        renderer.soleTraderMode();

        expect(renderedValue('company_name', renderer)).toBe('Sole Trader Example');
        expect(renderedValue('company_id', renderer)).toBe('ST-SYNTH-004');
        expect(isReadOnly('company_id', renderer)).toBe(true);
        // The name too, because a name AND number were actually captured — see
        // the two "never both empty and locked" cases for the branch where they
        // were not.
        expect(isReadOnly('company_name', renderer)).toBe(true);
    });

    test('manual-entry mode: the number reads blank and stays read-only', () => {
        // Driven through the real manual-entry path — clearCompany() is what the
        // sentinel row's handler calls — not by poking the observable, so this
        // fails if that path stops clearing the abandoned company's number.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        expect(renderedValue('company_id', renderer)).toBe('12345678');

        renderer.clearCompany();

        expect(renderedValue('company_id', renderer)).toBe('');
        expect(isReadOnly('company_id', renderer)).toBe(true);
        // …and the name is typeable, which is the whole point of the mode.
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('an identifier-less pick shows the name with a blank number', () => {
        // The other route to a blank number: the registry holds no identifier
        // for the picked company. Distinct from manual entry — a company IS
        // selected here.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            { authoritative: true }
        );

        expect(renderedValue('company_name', renderer)).toBe('Second Example Ltd');
        expect(renderedValue('company_id', renderer)).toBe('');
        expect(isReadOnly('company_id', renderer)).toBe(true);
    });
});

/**
 * jQuery double that records writes per selector, so a claim that the renderer
 * "no longer writes the number field" is checkable rather than assumed. The
 * default harness jQuery reports `length: 0` and returns the same inert object
 * from every setter, which cannot distinguish a write from no write.
 */
function makeRecordingDom() {
    const writes = [];
    const nodes = {};

    function node(selector) {
        if (nodes[selector]) return nodes[selector];
        const n = {
            selector: selector,
            length: 1,
            value: '',
            props: {},
            handlers: {},
            val: function (next) {
                if (!arguments.length) return n.value;
                writes.push([selector, next]);
                n.value = next;
                return n;
            },
            prop: function (name, next) {
                if (arguments.length < 2) return n.props[name];
                n.props[name] = next;
                return n;
            },
            text: function () {
                return n;
            },
            on: function (event, fn) {
                n.handlers[String(event).split('.')[0]] = fn;
                return n;
            },
            off: () => n,
            closest: (sel) => node(selector + ' >closest> ' + sel),
            find: (sel) => node(selector + ' >find> ' + sel),
            append: () => n,
            attr: () => n,
            data: () => null,
            hide: () => n,
            show: () => n,
            select2: () => n
        };
        nodes[selector] = n;
        return n;
    }

    function $(selector) {
        return node(typeof selector === 'string' ? selector : String(selector));
    }
    $.async = function (selector, cb) {
        cb(selector);
    };
    $.each = function (xs, fn) {
        (xs || []).forEach(function (x, i) {
            fn(i, x);
        });
    };
    $.ajax = function () {
        return { done: () => this, fail: () => this, always: () => this };
    };
    $.Deferred = function () {
        const d = {
            resolve: () => d,
            reject: () => d,
            promise: () => d,
            done: () => d,
            fail: () => d,
            always: () => d
        };
        return d;
    };
    $.mage = { cookies: { get: () => null, set: () => {} }, redirect: () => {} };
    $.extend = Object.assign;
    $.fn = {};

    return { $: $, node: node, writes: writes };
}

function loadRendererOnly(extraMocks) {
    const dom = makeRecordingDom();
    const renderer = loadAmdModule(
        RENDERER,
        Object.assign({ jquery: dom.$ }, extraMocks || {})
    );
    // `getCode()` comes from the Magento Component base class, which the
    // harness's Component double does not provide.
    renderer.getCode = function () {
        return 'two_payment';
    };
    return { renderer: renderer, dom: dom };
}

describe('an accepted organisation number still reaches the order', () => {
    test('a company-search pick reaches getData()', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        expect(renderer.getData().additional_data.companyId).toBe('12345678');
        expect(renderer.getData().additional_data.companyName).toBe('First Example Ltd');
    });

    test('the sole-trader synthetic number reaches getData() via applyPrefetch', () => {
        // The autofill endpoint MINTS this number; it is never picked from a
        // registry and never typed. applyPrefetch() is the path that lands it.
        const { renderer } = loadRendererOnly();

        renderer.prefetched = {
            ready: true,
            buyer: {
                organization_number: 'ST-SYNTH-001',
                company_name: 'Sole Trader Example'
            },
            matches: true
        };

        renderer.applyPrefetch();

        expect(renderer.companyId()).toBe('ST-SYNTH-001');
        expect(renderer.getData().additional_data.companyId).toBe('ST-SYNTH-001');
        expect(renderer.getData().additional_data.companyName).toBe('Sole Trader Example');
    });

    test('the sole-trader synthetic number reaches getData() via the chip click', () => {
        // soleTraderMode() is the SECOND route that lands a minted number, and
        // it is a separate call site from applyPrefetch(). Verified by mutation
        // that the applyPrefetch test above does not cover it.
        const { renderer } = loadRendererOnly();

        renderer.prefetched = {
            ready: true,
            matches: true,
            buyer: {
                organization_number: 'ST-SYNTH-003',
                company_name: 'Chip Click Example'
            }
        };

        renderer.soleTraderMode();

        expect(renderer.companyId()).toBe('ST-SYNTH-003');
        expect(renderer.getData().additional_data.companyId).toBe('ST-SYNTH-003');
    });

    test('the sole-trader number survives with no write to the number field', () => {
        // ko's `value: companyId` binding is the ONLY painter of the read-only
        // input. Prove the renderer writes nothing to that selector itself while
        // still delivering the number to the submit path — a second painter
        // could disagree with the observable, and the observable is what ships.
        const { renderer, dom } = loadRendererOnly();

        renderer.fillCompanyData({
            companyId: 'ST-SYNTH-002',
            companyName: 'Sole Trader Example'
        });

        expect(renderer.getData().additional_data.companyId).toBe('ST-SYNTH-002');

        const numberFieldWrites = dom.writes.filter(function (w) {
            return w[0] === 'input#company_id';
        });
        expect(numberFieldWrites).toEqual([]);
        // ...while the name field IS still written, so an inert double cannot
        // be what made the assertion above pass.
        const nameFieldWrites = dom.writes.filter(function (w) {
            return w[0] === 'input#company_name';
        });
        expect(nameFieldWrites).toEqual([['input#company_name', 'Sole Trader Example']]);
    });

    test('showing the number read-only does not change what the form submits', () => {
        // The regression this guards: reinstating the input as a carrier — a
        // `name`, or a DOM read in getData() — so the payload could come from
        // the field instead of the observable. Asserted against the WHOLE
        // additional_data payload rather than the two keys, because a new
        // company key smuggled in beside them is the same defect.
        const { renderer, dom } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        const additional = renderer.getData().additional_data;
        expect(additional.companyName).toBe('First Example Ltd');
        expect(additional.companyId).toBe('12345678');
        expect(
            Object.keys(additional).filter(function (key) {
                return /company/i.test(key);
            })
        ).toEqual(['companyName', 'companyId']);

        // getData() must not have consulted the DOM for either value: with a
        // recording double every node reports an empty `val()`, so a DOM read
        // would have produced '' above. Belt-and-braces on that, prove the
        // renderer never wrote the number field either.
        expect(
            dom.writes.filter(function (w) {
                return w[0] === 'input#company_id';
            })
        ).toEqual([]);
    });

    test('an identifier-less selection leaves the number empty for the server to refuse', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            { authoritative: true }
        );

        // No client-side block. Model/Two.php::authorize() refuses this order.
        expect(renderer.getData().additional_data.companyName).toBe('Second Example Ltd');
        expect(renderer.getData().additional_data.companyId).toBe('');
    });
});
