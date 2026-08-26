/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Pins the below-threshold company-search input hint: OUR
 * translatable string naming a FIXED number, in place of select2's built-in
 * English remaining-count text.
 *
 * And the centralisation that hint depends on: the mount may not repeat a
 * literal minimum length. It must read the shared constant, or the number the
 * hint claims and the number select2 enforces can drift apart.
 *
 * Mutation-resistance notes, because this repo's AMD harness makes it easy
 * to write assertions that cannot fail:
 *  - The shared model is injected with a DELIBERATELY WRONG threshold (7).
 *    An assertion against 3 would pass whether the source read the constant
 *    or repeated a literal; asserting 7 can only pass if it reads it.
 *  - Translation is asserted on the msgid, not on a rendered label, because
 *    the harness resolves `$t` to identity.
 *  - The mount cases read the options off the widget the REAL control bound to
 *    a real jsdom node, so a mount that returned before binding fails rather
 *    than presenting as green.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const { loadAmdModule, loadCompanySearchControl } = require('./amd-harness');

const COMPONENT_PATH = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY_PATH = 'view/frontend/web/js/model/company-identity.js';
const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';

const GLOBALS = { document: document, window: window };

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true,
    supportedCompanyTypes: {}
};

/** Nothing here is 3, so a surviving literal 3 cannot pass. */
const WRONG_THRESHOLD = 7;

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

/**
 * The real shared model, but loaded so its threshold is WRONG_THRESHOLD.
 * Patching the source rather than the returned object matters: the hint
 * helper closes over the module-local constant, so an object-level override
 * would leave the message quoting 3 and the test would prove nothing about
 * the two staying in step.
 */
function loadCompanySearchWithWrongThreshold() {
    const src = readSource(MODEL_PATH);
    const needle = 'const MIN_INPUT_LENGTH = 3;';
    expect(src).toContain(needle);
    const patched = src.replace(needle, 'const MIN_INPUT_LENGTH = ' + WRONG_THRESHOLD + ';');

    const tmp = path.join(__dirname, '__tmp_company_search_threshold.js');
    fs.writeFileSync(tmp, patched, 'utf8');
    try {
        return loadAmdModule(
            path.relative(path.resolve(__dirname, '..', '..'), tmp),
            { jquery: $ },
            GLOBALS
        );
    } finally {
        fs.unlinkSync(tmp);
    }
}

/**
 * A select2 stand-in on the real jQuery, faithful on the one point this suite
 * turns on: the options block reaches the node and is discoverable there.
 */
function installSelect2Double() {
    $.fn.select2 = function (arg) {
        return this.each(function () {
            const $node = $(this);
            if (typeof arg !== 'object' || arg === null) return;
            const $container = $(
                '<span class="select2 select2-container">' +
                '<span class="select2-selection" role="combobox" tabindex="0"></span>' +
                '</span>'
            );
            const $dropdown = $(
                '<span class="select2-dropdown">' +
                '<span class="select2-search select2-search--dropdown">' +
                '<input class="select2-search__field" type="search">' +
                '</span></span>'
            );
            $node.after($container);
            $node.data('select2', {
                options: arg,
                $container: $container,
                $dropdown: $dropdown,
                $selection: $container.find('.select2-selection')
            });
        });
    };
}

/** Magento's `$.async` decorator, resolving synchronously against the fixture. */
function installAsync() {
    $.async = function (selector, fn) {
        const node = document.querySelector(selector);
        if (node) fn(node);
    };
}

/**
 * Boot the page-level component over the fixture with the real control, and
 * hand back the select2 options block the live bind produced.
 *
 * @param {object} companySearch the shared model to mount against
 * @returns {object} select2 options
 */
function boundOptions(companySearch) {
    const identity = loadAmdModule(IDENTITY_PATH, {}, GLOBALS);
    const SoleTraderStub = function () {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.launchSignup = function () { return {}; };
        this.forgetAdoptions = function () {};
    };

    const component = loadAmdModule(
        COMPONENT_PATH,
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/company-search-control': loadCompanySearchControl(
                $,
                companySearch,
                GLOBALS
            ),
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandConfig: function () { return BASE_CONFIG; }
            }
        },
        GLOBALS
    );
    component.start();

    // Bootstrapped guard: if the mount returned before binding select2, every
    // assertion below would be vacuous.
    const instance = component._control.getField().data('select2');
    expect(instance).toBeTruthy();
    return instance.options;
}

describe('below-threshold hint (element 4)', () => {
    beforeEach(() => {
        document.body.innerHTML =
            '<form id="two_gateway_form"><div class="field"><div class="control">' +
            '<input id="company_name" name="company_name" />' +
            '</div></div></form>';
        $(document).off('.twoCompanyCapture');
        installSelect2Double();
        installAsync();
    });

    test('the mount overrides select2 with our fixed-number message', () => {
        const options = boundOptions(loadCompanySearchWithWrongThreshold());

        expect(options.language).toBeTruthy();
        expect(typeof options.language.inputTooShort).toBe('function');

        // select2 hands its own args in; ours must ignore them and quote the
        // configured threshold, not the remaining count.
        const message = options.language.inputTooShort({ minimum: 3, input: 'a' });
        expect(message).toBe('Please enter ' + WRONG_THRESHOLD + ' or more characters');
        expect(message).not.toContain('%1');
    });

    test('the mount enforces the shared constant, not a literal', () => {
        const companySearch = loadCompanySearchWithWrongThreshold();
        expect(companySearch.MIN_INPUT_LENGTH).toBe(WRONG_THRESHOLD);

        expect(boundOptions(companySearch).minimumInputLength).toBe(WRONG_THRESHOLD);
    });
});

describe('the hint is one translatable string', () => {
    test('the msgid is placeholder-form and translated in every catalogue', () => {
        const msgid = 'Please enter %1 or more characters';
        const model = readSource(MODEL_PATH);
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
        // Asserting the ABSENCE of our placeholder form here would be
        // vacuous: upstream never had a `%1` message, it concatenates the
        // remaining count. So pin its own implementation verbatim instead —
        // that fails the moment anyone edits the vendored bundle to localise
        // the hint in place, which is the mistake this override exists to
        // avoid.
        expect(readSource(bundle)).toContain(
            'inputTooShort:function(e){return"Please enter "'
                + '+(e.minimum-e.input.length)+" or more characters"'
        );
    });

    test('the hint and the enforced threshold come from one source', () => {
        const companySearch = loadCompanySearchWithWrongThreshold();
        expect(companySearch.minInputLengthMessage()).toContain(
            String(companySearch.MIN_INPUT_LENGTH)
        );
    });
});
