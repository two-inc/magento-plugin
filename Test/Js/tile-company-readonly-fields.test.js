/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288 first showed the captured company NAME and NUMBER read-only, as a
 * second `<input readonly>`. Doug's canonical-design ruling that followed
 * (applies across all four platforms) replaced that input outright: the
 * number is now a plain text LABEL, right-aligned immediately below the
 * company-name field, visible only once a number has actually been captured.
 *
 * This file pins the label-based version. Three groups, and they are NOT the
 * same kind of test. Be honest about which is which before trusting a green
 * run:
 *
 *  1. READ-ONLY / NO-INPUT PINS — 'the payment tile shows the number as an
 *     uneditable label, not a field'. These fail against BOTH the old
 *     `<input readonly>` shape and any future reinstatement of an editable
 *     field, because there is no `<input id="company_id">` left to satisfy
 *     either shape.
 *
 *  2. WHAT-THE-BUYER-SEES PINS — 'what each capture mode puts in front of the
 *     buyer'. These do not restate the markup: they read the `text:` binding
 *     target out of the template, then evaluate it against a renderer driven
 *     through the real capture flow, so a label bound to the wrong observable
 *     fails here even though the markup is present and correct.
 *
 *  3. ACCEPTED-SOURCE REGRESSION PINS — most of 'an accepted organisation
 *     number still reaches the order'. These pass against the pre-change code
 *     too, because every accepted source already wrote the observable. They
 *     do NOT prove anything about the label. They exist because the
 *     observable is the ONLY carrier — the label has no `name` and submits
 *     nothing — so a future change that breaks one of those writer paths
 *     loses the number outright rather than falling back to a field, and the
 *     sole-trader routes are the ones most likely to break silently, their
 *     value being minted rather than picked.
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
 * Strip explanatory PROSE comments before asserting on markup — but NOT
 * knockout's own `<!-- ko ... -->` / `<!-- /ko -->` virtual-element comments,
 * which are real template syntax (e.g. the i18n macro around "Company
 * Number") rather than developer prose. The name field and the number label
 * both carry long explanatory comments that name `company_id`, `readonly`
 * and `input` on purpose, and a raw substring search would match those and
 * report a passing pin for prose; stripping ko's own comments too would
 * instead make every i18n-wrapped string invisible to these assertions.
 */
function withoutComments(markup) {
    return markup.replace(/<!--[\s\S]*?-->/g, function (comment) {
        const inner = comment.slice(4, -3).trim();
        return /^(ko\b|\/ko$)/.test(inner) ? comment : '';
    });
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
 * The `<div class="two-company-id-label">...</div>` block, comments already
 * stripped, INCLUDING its children (the caption span and the number span).
 * Non-greedy up to the first `</div>` — safe because this block's own
 * content is two flat `<span>` elements with no nested `<div>`. Throws
 * rather than returning null, same reasoning as inputTag() above.
 */
function companyIdLabelTag() {
    const markup = withoutComments(readTemplate());
    const tags = (markup.match(/<div\b[^>]*class="two-company-id-label"[^>]*>[\s\S]*?<\/div>/g) || []);
    if (tags.length !== 1) {
        throw new Error('expected exactly one <div class="two-company-id-label">, found ' + tags.length);
    }
    return tags[0];
}

/**
 * The `<span data-bind="text: companyId">` inside the number label — the
 * element that actually paints the number, as opposed to the static
 * "Company Number" caption span alongside it.
 */
function companyIdNumberSpanTag() {
    const tag = companyIdLabelTag();
    const spans = (tag.match(/<span\b[^>]*>/g) || []).filter(function (span) {
        return /text:\s*companyId\b/.test(span);
    });
    if (spans.length !== 1) {
        throw new Error(
            'expected exactly one <span data-bind="text: companyId">, found ' + spans.length
        );
    }
    return spans[0];
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
 * What ko would paint as the number label's text, derived the same way
 * renderedValue() does for an input's `value:` binding, but for the label's
 * `text:` binding.
 */
function renderedLabelText(renderer) {
    const bound = companyIdNumberSpanTag().match(/text:\s*([A-Za-z_$][\w$]*)/);
    if (!bound) {
        throw new Error('the number label has no `text:` binding');
    }
    const target = renderer[bound[1]];
    if (typeof target !== 'function') {
        throw new Error(
            'the number label is bound to `' + bound[1] + '`, which the renderer has not'
        );
    }
    return target.call(renderer);
}

/**
 * Whether the number label would currently render, evaluated the same way
 * renderedLabelText() does — read the `visible:` expression out of the
 * template, PIN it to the exact `!!companyId()` shape (so a drift to some
 * other observable or a always-true/always-false stub fails the string
 * match), then evaluate the same observable directly against the live
 * renderer for the actual truth value.
 */
function labelVisible(renderer) {
    const tag = companyIdLabelTag();
    if (!/visible:\s*!!companyId\(\)/.test(tag)) {
        throw new Error('the number label\'s `visible:` binding is not the pinned `!!companyId()` shape');
    }
    return !!renderer.companyId();
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
 * the ko `attr` binding's target is evaluated the same way renderedValue()
 * does it, so a `readonly` bound to an observable is answered for THIS state
 * rather than assumed.
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

describe('the payment tile shows the number as an uneditable label, not a field', () => {
    test('there is no input#company_id left in the template', () => {
        const markup = withoutComments(readTemplate());
        expect(markup).not.toMatch(/<input\b[^>]*\bid\s*=\s*["']company_id["']/);
    });

    test('the number label is a plain div, carries no name and no value binding to write to', () => {
        const tag = companyIdLabelTag();

        expect(tag).not.toMatch(/\sname\s*=/);
        expect(tag).not.toMatch(/\svalue\s*=/);
        // Not an input, not a button, not anything with default interactive
        // semantics — a plain div reads unambiguously as non-editable.
        expect(tag).toMatch(/^<div\b/);
    });

    test('the number label is bound to companyId and gated on it being present', () => {
        const tag = companyIdLabelTag();

        expect(tag).toMatch(/text:\s*companyId\b/);
        expect(tag).toMatch(/visible:\s*!!companyId\(\)/);
    });

    test('the label carries a visible "Company Number" caption, not a bare number', () => {
        // Regression caught in adversarial review: the first draft dropped
        // the old <label for="company_id"> along with the input it labelled,
        // leaving a sighted buyer (and a screen reader) with an unexplained
        // number and no indication of what it was. The caption text must
        // actually be present in the markup, not just asserted by comment.
        const tag = companyIdLabelTag();

        expect(tag).toMatch(/Company Number/);
        // Two spans: one static caption, one bound to the number. Not one
        // span doing both jobs — that would make the caption unable to
        // update independently were it ever translated per-request.
        const spanCount = (tag.match(/<span\b/g) || []).length;
        expect(spanCount).toBe(2);
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
        // THE REGRESSION THIS GUARDS, and it is not hypothetical — an earlier
        // draft of the read-only-input version shipped it, and nothing about
        // the label change touches this path. The input is `required`, and
        // jQuery Validation enforces `required` on a `[readonly]` field (its
        // `elements()` skips `:disabled` only). So a state that is both empty
        // and locked is a validation error with no buyer action that clears it.
        //
        // Reached by entering sole-trader mode where the prefetch did NOT match
        // a buyer: enterSoleTraderUi() blanks the input and the autofill never
        // lands, because fillCompanyData() early-returns unless BOTH name and
        // number are non-empty. Keying `readonly` on the mode alone stranded the
        // buyer here.
        const { renderer } = loadRendererOnly();

        renderer.enterSoleTraderUi();

        expect(renderer.showSoleTrader()).toBe(true);
        expect(renderer.companyId()).toBe('');
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

        expect(renderer.companyId()).toBe('');
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
        // Pins what `$(formSelector).valid()` enforces. The number is
        // deliberately not a validatable input at all any more.
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
        // The number is shown but has no editability derivation of its own —
        // it never had one to remove, and a label reinstating one would be a
        // sign the label is on its way to becoming a field again.
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
        expect(source).not.toContain('input#company_id');
    });
});

describe('what each capture mode puts in front of the buyer', () => {
    test('search mode: the buyer sees the picked name AND its number label', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        expect(renderedValue('company_name', renderer)).toBe('First Example Ltd');
        expect(renderedLabelText(renderer)).toBe('12345678');
        expect(labelVisible(renderer)).toBe(true);
    });

    test('sole-trader mode: the minted name shows locked, the number label shows', () => {
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
        expect(renderedLabelText(renderer)).toBe('ST-SYNTH-004');
        expect(labelVisible(renderer)).toBe(true);
        // The name too, because a name AND number were actually captured — see
        // the two "never both empty and locked" cases for the branch where they
        // were not.
        expect(isReadOnly('company_name', renderer)).toBe(true);
    });

    test('manual-entry mode: the number label disappears and stays gone', () => {
        // Driven through the real manual-entry path — clearCompany() is what the
        // sentinel row's handler calls — not by poking the observable, so this
        // fails if that path stops clearing the abandoned company's number.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        expect(labelVisible(renderer)).toBe(true);

        renderer.clearCompany();

        expect(renderer.companyId()).toBe('');
        expect(labelVisible(renderer)).toBe(false);
        // …and the name is typeable, which is the whole point of the mode.
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('an identifier-less pick shows the name with no number label', () => {
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
        expect(labelVisible(renderer)).toBe(false);
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

    test('the sole-trader number survives with no write to any company_id node', () => {
        // ko's `text: companyId` binding is the ONLY painter of the label.
        // Prove the renderer writes nothing to a `company_id` selector itself
        // while still delivering the number to the submit path — a second
        // painter could disagree with the observable, and the observable is
        // what ships.
        const { renderer, dom } = loadRendererOnly();

        renderer.fillCompanyData({
            companyId: 'ST-SYNTH-002',
            companyName: 'Sole Trader Example'
        });

        expect(renderer.getData().additional_data.companyId).toBe('ST-SYNTH-002');

        const numberFieldWrites = dom.writes.filter(function (w) {
            return /company_id/.test(w[0]);
        });
        expect(numberFieldWrites).toEqual([]);
        // ...while the name field IS still written, so an inert double cannot
        // be what made the assertion above pass.
        const nameFieldWrites = dom.writes.filter(function (w) {
            return w[0] === 'input#company_name';
        });
        expect(nameFieldWrites).toEqual([['input#company_name', 'Sole Trader Example']]);
    });

    test('showing the number does not change what the form submits', () => {
        // The regression this guards: reinstating a carrier for the number
        // beside the observable — a `name`, or a DOM read in getData() — so
        // the payload could come from the DOM instead. Asserted against the
        // WHOLE additional_data payload rather than the two keys, because a
        // new company key smuggled in beside them is the same defect.
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
        // renderer never wrote any company_id node either.
        expect(
            dom.writes.filter(function (w) {
                return /company_id/.test(w[0]);
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
