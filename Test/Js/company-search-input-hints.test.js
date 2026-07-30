/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Pins the two company-search input hints on the shipping-step
 * (address) surface:
 *
 *  - the empty-field hint, painted through select2's `templateSelection`
 *    when the picker has nothing selected;
 *  - the below-threshold hint, which must be OUR translatable string naming
 *    a FIXED number, not select2's built-in English remaining-count text.
 *
 * And the centralisation both hints depend on: neither surface may repeat a
 * literal minimum length. Each must read the shared constant, or the number
 * the hint claims and the number select2 enforces can drift apart.
 *
 * Mutation-resistance notes, because this repo's AMD harness makes it easy
 * to write assertions that cannot fail:
 *  - The shared model is injected with a DELIBERATELY WRONG threshold (7).
 *    An assertion against 3 would pass whether the source read the constant
 *    or repeated a literal; asserting 7 can only pass if it reads it.
 *  - Translation is asserted on the msgid, not on a rendered label, because
 *    the harness resolves `$t` to identity.
 *  - Every case first asserts the surface actually bound select2. Without
 *    that, a surface that returned early would present as green.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true
};

/** Nothing here is 3, so a surviving literal 3 cannot pass. */
const WRONG_THRESHOLD = 7;

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

function makeJQuery(recorder) {
    function $() {
        const obj = {
            length: 0,
            val: function () { return arguments.length ? obj : ''; },
            trigger: function () { return obj; },
            prop: function () { return obj; },
            text: function () { return obj; },
            attr: function () { return obj; },
            off: function () { return obj; },
            on: function () { return obj; },
            hide: function () { return obj; },
            show: function () { return obj; },
            closest: function () { return obj; },
            append: function () { return obj; },
            find: function () { return obj; },
            data: function () { return obj; },
            select2: function (opts) {
                if (typeof opts === 'object') {
                    recorder.select2Options = opts;
                    recorder.select2Calls += 1;
                }
                return obj;
            }
        };
        return obj;
    }
    $.async = function (selector, fn) { fn(selector); };
    $.ajax = function () {
        const jqxhr = {
            done: function () { return jqxhr; },
            fail: function () { return jqxhr; },
            always: function () { return jqxhr; }
        };
        return jqxhr;
    };
    $.mage = { cookies: { get: function () { return null; } }, redirect: function () {} };
    $.extend = Object.assign;
    $.fn = {};
    return $;
}

/**
 * The real shared model, but loaded so its threshold is WRONG_THRESHOLD.
 * Patching the source rather than the returned object matters: the hint
 * helper closes over the module-local constant, so an object-level override
 * would leave the message quoting 3 and the test would prove nothing about
 * the two staying in step.
 */
function loadCompanySearchWithWrongThreshold($) {
    const relPath = 'view/frontend/web/js/model/company-search.js';
    const src = readSource(relPath);
    const needle = 'const MIN_INPUT_LENGTH = 3;';
    expect(src).toContain(needle);
    const patched = src.replace(needle, 'const MIN_INPUT_LENGTH = ' + WRONG_THRESHOLD + ';');

    const tmp = path.join(__dirname, '__tmp_company_search_threshold.js');
    fs.writeFileSync(tmp, patched, 'utf8');
    try {
        return loadAmdModule(path.relative(path.resolve(__dirname, '..', '..'), tmp), {
            jquery: $
        });
    } finally {
        fs.unlinkSync(tmp);
    }
}

function loadShippingSurface($, companySearch) {
    const brandConfig = function () { return BASE_CONFIG; };
    brandConfig.getActiveTwoBrandCode = function () { return 'two_payment'; };
    brandConfig.getActiveTwoBrandConfig = function () { return BASE_CONFIG; };

    const component = loadAmdModule('view/frontend/web/js/view/address-autocomplete.js', {
        jquery: $,
        'Two_Gateway/js/model/brand-config': brandConfig,
        'Two_Gateway/js/model/company-search': companySearch
    });

    const ctx = Object.assign(Object.create(component.prototype || {}), {
        countrySelector: '#shipping-new-address-form select[name="country_id"]',
        companyNameSelector: '#shipping-new-address-form input[name="company"]',
        enterDetailsManuallyButton: '#shipping_enter_details_manually',
        searchForCompanyButton: '#shipping_search_for_company',
        enterDetailsManuallyText: 'Enter details manually',
        searchForCompanyText: 'Search for company',
        companyNamePlaceholder: component.companyNamePlaceholder,
        setCompanyData: function () {},
        addressLookup: component.addressLookup,
        enableCompanySearch: component.enableCompanySearch
    });
    ctx.enableCompanySearch();
    return { component: component, ctx: ctx };
}

function boundOptions(recorder) {
    // Bootstrapped guard: if the surface returned before binding select2,
    // every assertion below would be vacuous.
    expect(recorder.select2Calls).toBeGreaterThan(0);
    expect(recorder.select2Options).toBeTruthy();
    return recorder.select2Options;
}

describe('empty-field hint (element 3)', () => {
    test('shipping surface paints the placeholder when nothing is selected', () => {
        const recorder = { select2Options: null, select2Calls: 0 };
        const $ = makeJQuery(recorder);
        const companySearch = loadCompanySearchWithWrongThreshold($);
        const { component } = loadShippingSurface($, companySearch);

        expect(component.companyNamePlaceholder).toBe('Enter company name to search');

        const opts = boundOptions(recorder);
        expect(typeof opts.templateSelection).toBe('function');
        // No selection yet -> the placeholder, not an empty rendered box.
        expect(opts.templateSelection({})).toBe('Enter company name to search');
        // A real selection must win over it.
        expect(opts.templateSelection({ text: 'Example Trading Ltd' })).toBe(
            'Example Trading Ltd'
        );
    });

    test('the placeholder is a translatable msgid, present in every catalogue', () => {
        const msgid = 'Enter company name to search';
        expect(readSource('view/frontend/web/js/view/address-autocomplete.js')).toContain(
            "$t('" + msgid + "')"
        );
        ['nb_NO', 'nl_NL', 'sv_SE'].forEach((locale) => {
            expect(readSource('i18n/' + locale + '.csv')).toContain('"' + msgid + '",');
        });
    });
});

describe('below-threshold hint (element 4)', () => {
    test('shipping surface overrides select2 with our fixed-number message', () => {
        const recorder = { select2Options: null, select2Calls: 0 };
        const $ = makeJQuery(recorder);
        const companySearch = loadCompanySearchWithWrongThreshold($);
        loadShippingSurface($, companySearch);

        const opts = boundOptions(recorder);
        expect(opts.language).toBeTruthy();
        expect(typeof opts.language.inputTooShort).toBe('function');

        // select2 hands its own args in; ours must ignore them and quote the
        // configured threshold, not the remaining count.
        const message = opts.language.inputTooShort({ minimum: 3, input: 'a' });
        expect(message).toBe('Please enter ' + WRONG_THRESHOLD + ' or more characters');
        expect(message).not.toContain('%1');
    });

    test('the hint msgid is placeholder-form and translated in every catalogue', () => {
        const msgid = 'Please enter %1 or more characters';
        const model = readSource('view/frontend/web/js/model/company-search.js');
        expect(model).toContain("$t('" + msgid + "')");
        // The literal-number form must be gone, or translators keep a row
        // that no longer matches any msgid the code emits.
        expect(model).not.toContain("$t('Please enter 3 or more characters')");

        ['nb_NO', 'nl_NL', 'sv_SE'].forEach((locale) => {
            const csv = readSource('i18n/' + locale + '.csv');
            expect(csv).toContain('"' + msgid + '","');
            expect(csv).not.toContain('"Please enter 3 or more characters"');
            // Magento drops rows whose translation equals the msgid.
            expect(csv).not.toContain('"' + msgid + '","' + msgid + '"');
        });
    });

    test('the vendored select2 bundle is left untouched', () => {
        const bundle = 'view/frontend/web/select2-4.1.0/js/select2.min.js';
        expect(fs.existsSync(path.resolve(__dirname, '..', '..', bundle))).toBe(true);
        expect(readSource(bundle)).not.toContain('Please enter %1 or more characters');
    });
});

describe('threshold centralisation', () => {
    test('the shipping surface enforces the shared constant, not a literal', () => {
        const recorder = { select2Options: null, select2Calls: 0 };
        const $ = makeJQuery(recorder);
        const companySearch = loadCompanySearchWithWrongThreshold($);
        expect(companySearch.MIN_INPUT_LENGTH).toBe(WRONG_THRESHOLD);
        loadShippingSurface($, companySearch);

        expect(boundOptions(recorder).minimumInputLength).toBe(WRONG_THRESHOLD);
    });

    test('the payment surface enforces the shared constant, not a literal', () => {
        const recorder = { select2Options: null, select2Calls: 0 };
        const $ = makeJQuery(recorder);
        const companySearch = loadCompanySearchWithWrongThreshold($);

        const component = loadAmdModule(
            'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
            { jquery: $, 'Two_Gateway/js/model/company-search': companySearch }
        );
        const ctx = Object.assign(Object.create(component.prototype || {}), {
            companyNameSelector: 'input#company_name',
            enterDetailsManuallyButton: '#billing_enter_details_manually',
            searchForCompanyButton: '#billing_search_for_company',
            enterDetailsManuallyText: 'Enter details manually',
            searchForCompanyText: 'Search for company',
            _brandConfig: BASE_CONFIG,
            countryCode: function () { return 'gb'; },
            companyName: Object.assign(function () { return ''; }, {
                subscribe: function () { return { dispose: function () {} }; }
            }),
            fillCompanyData: function () {},
            addressLookup: component.addressLookup,
            enableCompanySearch: component.enableCompanySearch
        });
        ctx.enableCompanySearch();

        expect(boundOptions(recorder).minimumInputLength).toBe(WRONG_THRESHOLD);
    });

    test('the hint and the enforced threshold come from one source', () => {
        const $ = makeJQuery({ select2Options: null, select2Calls: 0 });
        const companySearch = loadCompanySearchWithWrongThreshold($);
        expect(companySearch.minInputLengthMessage()).toContain(
            String(companySearch.MIN_INPUT_LENGTH)
        );
    });
});
