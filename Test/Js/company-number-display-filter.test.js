/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326: an organisation-number value carrying the literal `TWO:` prefix
 * is an internal reference minted on our side, not a registry number the buyer
 * would recognise, and it must NEVER be displayed — anywhere in the plugin.
 *
 * Three surfaces, one shared formatter
 * (`companySearch.formatCompanyNumber()`), because a per-site patch is a rule
 * the next surface silently opts out of:
 *
 *   (a) the label under the company-name field on the address step
 *       (renderCompanyIdText() in address-autocomplete.js) and its payment-tile
 *       twin (`.two-company-id-text`, bound to tileDisplayCompanyId());
 *   (b) the search-results rows (searchCompanies());
 *   (c) the order-intent status sentence (resolveCompanyNotice()) — where the
 *       BRACKETS the number normally sits in have to go with it, so the
 *       sentence reads "Company Name", never "Company Name ()".
 *
 * The raw value stays intact on every SUBMITTING path: it is the identifier
 * the API is asked about. Hiding it from the buyer is not the same as not
 * having it, and the specs below pin that distinction rather than only the
 * hiding.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule, defaultMocks, proxyEnvelope } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';
const ADDRESS = 'view/frontend/web/js/view/address-autocomplete.js';

/** Plain (non-ko) observable factory, matching the sibling specs. */
function plainObservable(initial) {
    let v = initial;
    const fn = function (next) {
        if (!arguments.length) return v;
        v = next;
        return fn;
    };
    return fn;
}

/**
 * The real company-search module. No jQuery double needed: nothing exercised
 * here touches the DOM.
 *
 * @returns {object}
 */
function loadCompanySearch() {
    return loadAmdModule(SEARCH);
}

describe('formatCompanyNumber — the one shared display filter (TWO-25326)', () => {
    const companySearch = loadCompanySearch();

    test('hides a TWO:-prefixed value', () => {
        expect(companySearch.formatCompanyNumber('TWO:abc-123')).toBe('');
    });

    test('hides it whatever the case, and through surrounding whitespace', () => {
        expect(companySearch.formatCompanyNumber('two:abc-123')).toBe('');
        expect(companySearch.formatCompanyNumber('Two:abc-123')).toBe('');
        expect(companySearch.formatCompanyNumber('  TWO:abc-123  ')).toBe('');
    });

    test('shows a genuine registry number unchanged', () => {
        expect(companySearch.formatCompanyNumber('923609016')).toBe('923609016');
        expect(companySearch.formatCompanyNumber('GB123456789')).toBe('GB123456789');
    });

    test('does not hide a number that merely CONTAINS the prefix later on', () => {
        // Anchored at position 0 — "begins with", not "contains". A value like
        // this is not an internal reference and hiding it would lose a real
        // number.
        expect(companySearch.formatCompanyNumber('123TWO:456')).toBe('123TWO:456');
    });

    test('treats absent / empty / non-string values as nothing to show', () => {
        expect(companySearch.formatCompanyNumber(null)).toBe('');
        expect(companySearch.formatCompanyNumber(undefined)).toBe('');
        expect(companySearch.formatCompanyNumber('')).toBe('');
        expect(companySearch.formatCompanyNumber('   ')).toBe('');
        // Numeric ids are a real shape off `national_identifier.id`.
        expect(companySearch.formatCompanyNumber(123456)).toBe('123456');
    });
});

describe('(b) the search-results rows never render a TWO: number', () => {
    /**
     * Run a response through production's own searchCompanies(), the way the
     * panel does.
     *
     * @param {object[]} items search-response items
     * @returns {Promise<object[]>} the rows the panel would render
     */
    function results(items) {
        const settlers = [];
        const $ = defaultMocks().jquery;
        $.ajax = function () {
            const jqxhr = {
                done: function (cb) { settlers.push(cb); return jqxhr; },
                fail: function () { return jqxhr; },
                always: function () { return jqxhr; },
                abort: function () {}
            };
            return jqxhr;
        };
        const search = loadAmdModule(SEARCH, { jquery: $ }).searchCompanies({
            config: {},
            token: {},
            term: 'acme',
            getCountryCode: function () { return 'gb'; }
        });
        settlers.forEach(function (cb) { cb(proxyEnvelope({ items: items })); });
        return search.then(function (result) { return result.items; });
    }

    test('a TWO:-prefixed identifier renders the name alone, with no empty brackets', async () => {
        const mapped = await results([
            {
                name: 'Acme Widgets Ltd',
                highlight: '<b>Acme</b> Widgets Ltd',
                lookup_id: 'lookup-1',
                national_identifier: { id: 'TWO:internal-ref' }
            }
        ]);
        expect(mapped[0].html).toBe('<b>Acme</b> Widgets Ltd');
        expect(mapped[0].html).not.toContain('TWO:');
        expect(mapped[0].html).not.toContain('()');
        // …but the value is still carried, because selecting the row still has
        // to submit the identifier the registry gave us.
        expect(mapped[0].companyId).toBe('TWO:internal-ref');
        expect(mapped[0].lookupId).toBe('lookup-1');
    });

    test('a genuine identifier still renders in brackets', async () => {
        const mapped = await results([
            {
                name: 'Acme Widgets Ltd',
                highlight: '<b>Acme</b> Widgets Ltd',
                lookup_id: 'lookup-1',
                national_identifier: { id: '923609016' }
            }
        ]);
        expect(mapped[0].html).toBe('<b>Acme</b> Widgets Ltd (923609016)');
        expect(mapped[0].companyId).toBe('923609016');
    });
});

describe('(c) the order-intent notice drops the number AND its brackets', () => {
    const companySearch = loadCompanySearch();
    const component = loadAmdModule(RENDERER, {
        'Two_Gateway/js/model/company-search': companySearch
    });

    const COPY = {
        // The literal default from ConfigProvider::getOrderIntentApprovedNotice().
        withCompany: 'This order by {{companyName}} ({{companyNumber}}) is likely to be accepted by Two',
        withoutCompany: 'This order is likely to be accepted by Two',
        companyNameToken: '{{companyName}}',
        companyNumberToken: '{{companyNumber}}'
    };

    /**
     * @param {string} name companyName() value
     * @param {string} id companyId() value
     * @returns {string} resolved notice
     */
    function notice(name, id) {
        const ctx = Object.assign({}, component, {
            companyName: plainObservable(name),
            companyId: plainObservable(id)
        });
        return ctx.resolveCompanyNotice(COPY);
    }

    test('a TWO: number yields no brackets at all', () => {
        expect(notice('Acme Widgets Ltd', 'TWO:internal-ref')).toBe(
            'This order by Acme Widgets Ltd is likely to be accepted by Two'
        );
    });

    test('an EMPTY number yields no brackets either — the pre-existing "Company Name ()" case', () => {
        expect(notice('Acme Widgets Ltd', '')).toBe(
            'This order by Acme Widgets Ltd is likely to be accepted by Two'
        );
    });

    test('no notice ever contains an empty bracket pair or the hidden prefix', () => {
        ['TWO:internal-ref', '', '   ', 'two:x'].forEach(function (id) {
            const text = notice('Acme Widgets Ltd', id);
            expect(text).not.toContain('()');
            expect(text).not.toContain('( )');
            expect(text.toUpperCase()).not.toContain('TWO:');
        });
    });

    test('a genuine number still renders in brackets', () => {
        expect(notice('Acme Widgets Ltd', '923609016')).toBe(
            'This order by Acme Widgets Ltd (923609016) is likely to be accepted by Two'
        );
    });

    test('a brand copy override that places the token OUTSIDE brackets is handled too', () => {
        const ctx = Object.assign({}, component, {
            companyName: plainObservable('Acme Widgets Ltd'),
            companyId: plainObservable('TWO:internal-ref')
        });
        expect(
            ctx.resolveCompanyNotice(
                Object.assign({}, COPY, {
                    withCompany: 'Approved for {{companyName}} no {{companyNumber}} today'
                })
            )
        ).toBe('Approved for Acme Widgets Ltd no today');
    });

    test('a company NAME containing brackets is not mistaken for the number\'s brackets', () => {
        expect(notice('Acme (Holdings) Ltd', '923609016')).toBe(
            'This order by Acme (Holdings) Ltd (923609016) is likely to be accepted by Two'
        );
        expect(notice('Acme (Holdings) Ltd', 'TWO:internal-ref')).toBe(
            'This order by Acme (Holdings) Ltd is likely to be accepted by Two'
        );
    });
});

describe('(a) the tile label goes through the same filter', () => {
    const companySearch = loadCompanySearch();
    const component = loadAmdModule(RENDERER, {
        'Two_Gateway/js/model/company-search': companySearch
    });

    /**
     * @param {string} id companyId() value
     * @returns {object} renderer context
     */
    function ctxWith(id) {
        return Object.assign({}, component, {
            companyName: plainObservable('Acme Widgets Ltd'),
            companyId: plainObservable(id),
            // The label rides the TILE field, which is the mounted panel's own
            // (TWO-25554) — hence the tile observables, not the resolved pair.
            tileCompanyName: plainObservable('Acme Widgets Ltd'),
            tileCompanyId: plainObservable(id),
            isTileCompanyFieldVisible: function () { return true; }
        });
    }

    test('tileDisplayCompanyId() hides a TWO: number and shows a real one', () => {
        expect(ctxWith('TWO:internal-ref').tileDisplayCompanyId()).toBe('');
        expect(ctxWith('923609016').tileDisplayCompanyId()).toBe('923609016');
    });

    test('getData() still submits the RAW number the label refuses to show', () => {
        const ctx = Object.assign({}, ctxWith('TWO:internal-ref'), {
            getCode: function () {
                return 'two_payment';
            },
            project: plainObservable(''),
            department: plainObservable(''),
            orderNote: plainObservable(''),
            poNumber: plainObservable(''),
            invoiceEmails: plainObservable(''),
            selectedTerm: plainObservable(null)
        });
        expect(ctx.getData().additional_data.companyId).toBe('TWO:internal-ref');
    });

    test('the template binds the label to tileDisplayCompanyId and hides it when that is empty', () => {
        const markup = fs
            .readFileSync(
                path.resolve(
                    __dirname,
                    '..',
                    '..',
                    'view/frontend/web/template/payment/gateway_method.html'
                ),
                'utf8'
            )
            .replace(/<!--[\s\S]*?-->/g, '');
        const tag = markup.match(/<div\b[^>]*class="two-company-id-text"[^>]*>/);
        expect(tag).not.toBeNull();
        const bind = tag[0].match(/data-bind="([^"]*)"/)[1];

        /**
         * Evaluate a `data-bind` sub-expression the way ko does — with the view
         * model as implicit scope. Both halves are EVALUATED, not string-matched:
         * `text: tileDisplayCompanyId` (no parens) satisfies a string match but
         * makes ko render the function's own source into the tile, which is
         * exactly the defect this evaluation catches.
         *
         * @param {string} expr binding expression
         * @param {object} renderer view model
         * @returns {*}
         */
        function evaluate(expr, renderer) {
            // eslint-disable-next-line no-new-func
            return new Function('renderer', 'with (renderer) { return (' + expr + '); }').call(
                null,
                renderer
            );
        }

        const visible = bind.match(/visible:\s*(.+?)\s*,\s*text:/)[1];
        const text = bind.match(/text:\s*(.+?)\s*,\s*attr:/)[1];

        expect(!!evaluate(visible, ctxWith('923609016'))).toBe(true);
        expect(evaluate(text, ctxWith('923609016'))).toBe('923609016');
        expect(!!evaluate(visible, ctxWith('TWO:internal-ref'))).toBe(false);
        expect(evaluate(text, ctxWith('TWO:internal-ref'))).toBe('');
    });
});

describe('(a) the address-step label goes through the same filter', () => {
    test('renderCompanyIdText() renders no label for a TWO: number, and one for a real number', () => {
        const companySearch = loadCompanySearch();

        // A recording jQuery double: enough of the chain
        // renderCompanyIdText() walks (`$(field)` → `.closest('.control')` →
        // `.find().remove()` → `.append()`) to observe WHETHER a label was
        // appended and with what text.
        const appended = [];
        function node(length) {
            const obj = {
                length: length,
                closest: function () {
                    return node(length);
                },
                find: function () {
                    return node(length);
                },
                remove: function () {
                    return obj;
                },
                append: function (child) {
                    appended.push(child);
                    return obj;
                },
                addClass: function () {
                    return obj;
                },
                attr: function () {
                    return obj;
                },
                text: function (value) {
                    obj._text = value;
                    return obj;
                },
                val: function () {
                    return '';
                },
                data: function () {
                    return { select2: true };
                },
                on: function () {
                    return obj;
                },
                off: function () {
                    return obj;
                }
            };
            return obj;
        }
        function $() {
            return node(1);
        }
        $.async = function () {};
        $.ajax = function () {
            return {
                done: function () { return this; },
                fail: function () { return this; },
                always: function () { return this; }
            };
        };

        const Component = loadAmdModule(ADDRESS, {
            jquery: $,
            'Two_Gateway/js/model/company-search': companySearch
        });

        /**
         * @param {string} id captured organisation number
         * @returns {object[]} whatever the render appended
         */
        function render(id) {
            appended.length = 0;
            const ctx = Object.assign({}, Component, {
                isCompanySearchActive: function () {
                    return true;
                },
                capturedCompanyId: function () {
                    return id;
                }
            });
            ctx.renderCompanyIdText();
            return appended;
        }

        expect(render('923609016')).toHaveLength(1);
        expect(render('923609016')[0]._text).toBe('923609016');
        // The whole label is absent, not merely blank.
        expect(render('TWO:internal-ref')).toHaveLength(0);
        expect(render('two:internal-ref')).toHaveLength(0);
    });
});
