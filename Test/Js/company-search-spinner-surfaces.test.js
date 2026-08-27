/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Proves that the searching state actually reaches visible spinner
 * markup, and leaves again when the request settles.
 *
 * The spinner is easy to assume tested: the panel toggles a class and the
 * stylesheet paints it, and each half looks covered from the other's side. It
 * is not — a panel that reported "searching" to a node it never built, or to a
 * class the stylesheet does not answer, would leave the buyer watching a dead
 * field while every call-log assertion stayed green.
 *
 * Method: build the REAL panel over real jsdom nodes against the REAL model,
 * with `$.ajax` replaced by requests this suite settles by hand. The spinner
 * state is then read off the document rather than off a call log.
 *
 * @see company-search-spinner-asset.test.js — that the class the panel toggles
 *      is the one the stylesheet paints.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel, dispatchNative } = require('./amd-harness');

const SEARCH_PATH = 'view/frontend/web/js/model/company-search.js';

const GLOBALS = { document: document, window: window };
const FIELD_SELECTOR = '#company_name';
const SPINNER_SELECTOR = '.two-company-dropdown__spinner';
const SPINNER_ACTIVE_CLASS = 'two-company-dropdown__spinner--active';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50
};

/**
 * `$.ajax` replaced with jqXHRs the test settles by hand, so each outcome is
 * driven explicitly rather than inferred from a timer.
 *
 * @returns {Array} the requests handed out, newest last
 */
function installAjaxDouble() {
    const requests = [];
    $.ajax = function (options) {
        const bound = { done: [], fail: [], always: [] };
        const jqxhr = {
            options: options,
            aborted: false,
            done: function (fn) { bound.done.push(fn); return jqxhr; },
            fail: function (fn) { bound.fail.push(fn); return jqxhr; },
            always: function (fn) { bound.always.push(fn); return jqxhr; },
            abort: function () {
                jqxhr.aborted = true;
                jqxhr.settleFail('abort');
            },
            settleDone: function (data) {
                bound.done.forEach(function (fn) { fn(data); });
                bound.always.forEach(function (fn) { fn(); });
            },
            settleFail: function (textStatus) {
                bound.fail.forEach(function (fn) {
                    fn({ status: textStatus === 'timeout' ? 0 : 500 }, textStatus);
                });
                bound.always.forEach(function (fn) { fn(); });
            }
        };
        requests.push(jqxhr);
        return jqxhr;
    };
    return requests;
}

/** Let the debounce fire and the search promise settle. */
function tick() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

function spinner() {
    return document.querySelector(SPINNER_SELECTOR);
}

function spinnerIsActive() {
    return spinner().classList.contains(SPINNER_ACTIVE_CLASS);
}

describe('the searching state reaches visible spinner markup', () => {
    let requests;
    let panel;
    let $query;

    beforeEach(() => {
        document.body.innerHTML =
            '<form id="two_gateway_form"><div class="field"><div class="control">' +
            '<input id="company_name" name="company_name" />' +
            '</div></div></form>';
        requests = installAjaxDouble();

        const companySearch = loadAmdModule(SEARCH_PATH, { jquery: $ }, GLOBALS);
        companySearch.clearResultCache();
        companySearch.SEARCH_DEBOUNCE_MS = 0;

        const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
        panel = new CompanySearchPanel({ fieldSelector: FIELD_SELECTOR, config: BASE_CONFIG });
        panel.bind();
        panel.open();
        $query = $('.two-company-dropdown__query');
        expect($query).toHaveLength(1);
    });

    /**
     * Type a term and let it reach the wire.
     *
     * @param {string} term
     * @returns {Promise<object>} the request it issued
     */
    async function startSearch(term) {
        dispatchNative($query[0], 'input', term);
        await tick();
        expect(requests).toHaveLength(1);
        return requests[0];
    }

    test('nothing is painted until a search actually starts', () => {
        expect(spinnerIsActive()).toBe(false);
    });

    test('a search in flight paints a childless, aria-hidden spinner in the search box', async () => {
        await startSearch('exa');

        expect(spinnerIsActive()).toBe(true);
        // The animation is a CSS background-image, so there is no inner markup
        // for a translation or a sanitiser to mangle.
        expect(spinner().children).toHaveLength(0);
        expect(spinner().getAttribute('aria-hidden')).toBe('true');
        expect(spinner().closest('.two-company-dropdown__search')).not.toBeNull();
    });

    test.each([
        ['done', 'a healthy response'],
        ['timeout', 'a timeout'],
        ['error', 'a network failure']
    ])('outcome %p settles the spinner (%s)', async (outcome) => {
        const request = await startSearch('exa');
        expect(spinnerIsActive()).toBe(true);

        if (outcome === 'done') {
            request.settleDone({ items: [] });
        } else {
            request.settleFail(outcome);
        }
        await tick();

        expect(spinnerIsActive()).toBe(false);
    });

    test('backspacing below the threshold settles the spinner without a response', async () => {
        await startSearch('exa');

        dispatchNative($query[0], 'input', 'e');

        expect(spinnerIsActive()).toBe(false);
    });
});
