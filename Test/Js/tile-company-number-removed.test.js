/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. The payment tile no longer carries a company-number input.
 *
 * A hand-typed organisation number is not an accepted source: it produces poor
 * data quality, and genuine buyers receive invoices for orders they never
 * placed. For the accepted sources, see the writer enumeration on
 * applyCompanyData() in the renderer — deliberately NOT restated here, because
 * a second copy of that list has already drifted twice.
 *
 * Two groups of tests here, and they are NOT the same kind of test. Be honest
 * about which is which before trusting a green run:
 *
 *  1. REMOVAL PINS — 'the payment tile offers no company-number field'. These
 *     fail if the change is reverted. They are what guards the removal.
 *
 *  2. ACCEPTED-SOURCE REGRESSION PINS — most of 'an accepted organisation
 *     number still reaches the order'. These pass against the PRE-change code
 *     too, because every accepted source already wrote the observable before
 *     the input was removed. They do NOT prove anything about the removal. They
 *     exist because the removal makes the observable the ONLY carrier, so a
 *     future change that breaks one of those writer paths would now lose the
 *     number outright rather than fall back to a field — and the sole-trader
 *     routes are the ones most likely to break silently, their value being
 *     minted rather than picked.
 *
 *     ONE EXCEPTION, and it belongs to group 1 despite living in group 2's
 *     describe: 'the sole-trader number survives with no number input in the
 *     DOM' asserts there are NO writes to `input#company_id`. Pre-change
 *     fillCompanyData() did `$(this.companyIdSelector).val(companyId)`, so that
 *     test FAILS against the old code and is a removal pin.
 *
 * Group 2 is deliberately asserted through `getData()` rather than the DOM.
 * That is the actual submit path (`Observer/DataAssignObserver.php` reads
 * `additional_data`), and it reads the observable, so it stays true with no
 * input present. Asserting the DOM there would prove nothing.
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
 * Strip HTML comments before asserting on markup. The removal left an
 * explanatory comment behind that names `company_id` on purpose, and a raw
 * substring search would match it and mask a real reinstatement.
 */
function withoutComments(markup) {
    return markup.replace(/<!--[\s\S]*?-->/g, '');
}

describe('the payment tile offers no company-number field', () => {
    test('the template declares no company_id input', () => {
        const markup = withoutComments(readTemplate());

        // Scoped to <input> tags, not the whole document. A bare
        // `not.toMatch(/company_id/)` over all the markup is the strongest pin
        // but it also forbids a legitimate future reference — a `for="company_id"`
        // label, a `data-*` marker — and would fail for the wrong reason.
        //
        // Regexes rather than substrings: a reinstatement spelled with single
        // quotes (`id='company_id'`) or through a ko attr binding
        // (`attr: {id: 'company_id'}`) slips past every plain `toContain` check.
        const inputTags = markup.match(/<input\b[^>]*>/g) || [];
        expect(inputTags.length).toBeGreaterThan(0);
        inputTags.forEach(function (tag) {
            expect(tag).not.toMatch(/company_id/);
            expect(tag).not.toMatch(/companyId\b/);
        });

        // No ko binding anywhere in the template may write the observable back
        // from a field, whatever tag it sits on.
        expect(markup).not.toMatch(/value:\s*companyId\b/);
    });

    test('the template still declares the required company_name input', () => {
        // The name field stays. Address-step name capture is Magento core's
        // `company` attribute, gated on `customer/address/company_show`, which
        // this module does not own — so removing this input could leave a shop
        // with no company-name capture at all.
        const markup = withoutComments(readTemplate());

        expect(markup).toContain('id="company_name"');
        expect(markup).toContain('name="payment[company_name]"');
        expect(markup).toContain('value: companyName');
    });

    test('company_name is the only required input left inside the payment form', () => {
        // Pins what `$(formSelector).valid()` can still enforce. It is NOT
        // trivially true — the name field keeps it meaningful — but it no longer
        // says anything about the organisation number. Model/Two.php::authorize()
        // is the only enforcement for that.
        const markup = withoutComments(readTemplate());

        // Every spelling jQuery Validation would honour, not just
        // `required="true"`: a bare `required`, `required="required"`, and the
        // `data-validate` form all count.
        const requiredInputs = (markup.match(/<input\b[^>]*>/g) || []).filter(function (tag) {
            return (
                /\brequired\b/.test(tag) ||
                /data-validate\s*=\s*["'][^"']*required/.test(tag)
            );
        });

        expect(requiredInputs).toHaveLength(1);
        // The one remaining required input must be the company NAME field —
        // checking the tag itself rather than "what precedes the first match",
        // which said nothing about a second required field added later.
        expect(requiredInputs[0]).toMatch(/\bid\s*=\s*["']company_name["']/);
    });

    test('the renderer keeps no apparatus for a field that is gone', () => {
        const { renderer } = loadRendererOnly();

        expect(renderer.companyIdSelector).toBeUndefined();
        expect(renderer.needsManualCompanyId).toBeUndefined();
        expect(renderer.syncCompanyIdEditable).toBeUndefined();
        // The name field's selector is still live — company search binds to it.
        expect(renderer.companyNameSelector).toBe('input#company_name');
    });

    test('clearCompany carries no disable argument and touches no number field', () => {
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

function loadRendererOnly() {
    const dom = makeRecordingDom();
    const renderer = loadAmdModule(RENDERER, { jquery: dom.$ });
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

    test('the sole-trader number survives with no number input in the DOM', () => {
        // The point of the whole change: the value never needed a field. Prove
        // the renderer wrote nothing to the old selector while still delivering
        // the number to the submit path.
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
