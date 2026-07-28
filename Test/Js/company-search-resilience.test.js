/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25233. Company search had no request timeout, no way to tell a
 * failed search from a genuinely empty one, no result cache, and a
 * select2 binding that duplicated itself every time a one-page checkout
 * re-rendered. These tests pin all four.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true
};

const SEARCH_RESPONSE = {
    items: [
        {
            name: 'Example Trading Ltd',
            highlight: '<em>Example</em> Trading Ltd',
            national_identifier: { id: '12345678' },
            lookup_id: 'lookup-abc-123'
        }
    ]
};

/**
 * jQuery double whose `$.ajax` hands back a jqXHR the test settles by
 * hand, so each failure mode (done / timeout / abort) can be driven
 * explicitly rather than inferred.
 */
function makeQueryDouble() {
    const recorder = {
        ajax: [],
        requests: [],
        asyncCallbacks: [],
        select2Calls: [],
        appended: [],
        removed: [],
        boundData: {}
    };

    function $(selector) {
        const obj = {
            length: selector === '.select2-search--dropdown' ? 1 : 0,
            val: function () {
                return obj;
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
            attr: function () {
                return obj;
            },
            data: function (key) {
                return recorder.boundData[key];
            },
            closest: function () {
                return obj;
            },
            find: function () {
                return obj;
            },
            append: function (html) {
                recorder.appended.push(html);
                return obj;
            },
            remove: function () {
                recorder.removed.push(selector);
                return obj;
            },
            hide: function () {
                return obj;
            },
            show: function () {
                return obj;
            },
            select2: function (opts) {
                if (typeof opts === 'object') {
                    recorder.select2Calls.push(opts);
                    // Mirror select2: once bound, the widget instance is
                    // discoverable through `.data('select2')`.
                    recorder.boundData.select2 = { $dropdown: null };
                }
                return obj;
            },
            on: function () {
                return obj;
            }
        };
        return obj;
    }

    $.async = function (selector, fn) {
        recorder.asyncCallbacks.push(fn);
        fn(selector);
    };
    $.ajax = function (opts) {
        recorder.ajax.push(opts);
        const handlers = { done: [], fail: [], always: [] };
        const jqxhr = {
            aborted: false,
            done: function (cb) {
                handlers.done.push(cb);
                return jqxhr;
            },
            fail: function (cb) {
                handlers.fail.push(cb);
                return jqxhr;
            },
            always: function (cb) {
                handlers.always.push(cb);
                return jqxhr;
            },
            abort: function () {
                jqxhr.aborted = true;
            },
            settleDone: function (data) {
                handlers.done.forEach(function (cb) {
                    cb(data);
                });
                handlers.always.forEach(function (cb) {
                    cb();
                });
            },
            settleFail: function (textStatus) {
                handlers.fail.forEach(function (cb) {
                    cb({}, textStatus);
                });
                handlers.always.forEach(function (cb) {
                    cb();
                });
            }
        };
        recorder.requests.push(jqxhr);
        return jqxhr;
    };
    $.mage = {
        cookies: {
            get: function () {
                return null;
            }
        },
        redirect: function () {}
    };
    $.Deferred = function () {
        const d = {
            resolve: function () {
                return d;
            },
            promise: function () {
                return d;
            },
            done: function () {
                return d;
            },
            fail: function () {
                return d;
            },
            always: function () {
                return d;
            }
        };
        return d;
    };
    $.extend = Object.assign;
    $.fn = {};

    return { $: $, recorder: recorder };
}

function loadCompanySearch($) {
    return loadAmdModule('view/frontend/web/js/model/company-search.js', { jquery: $ });
}

/** Observed onSearching / onUnavailable calls for one search. */
function makeHooks() {
    const calls = { searching: [], unavailable: [] };
    return {
        calls: calls,
        onSearching: function (v) {
            calls.searching.push(v);
        },
        onUnavailable: function (v) {
            calls.unavailable.push(v);
        }
    };
}

function buildOptions(companySearch, hooks) {
    return companySearch.buildSearchAjaxOptions({
        config: BASE_CONFIG,
        getCountryCode: function () {
            return 'gb';
        },
        onSearching: hooks.onSearching,
        onUnavailable: hooks.onUnavailable
    });
}

/** Wait one macrotask, so the cache's deferred success callback runs. */
function nextTick() {
    return new Promise(function (resolve) {
        setTimeout(resolve, 1);
    });
}

describe('request envelope', () => {
    test('search carries a 30s timeout and a 300ms debounce', () => {
        const { $ } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());

        // 30s deliberately clears the server's stop_after_delay(10) retry
        // envelope — the client must not give up while the API is still
        // retrying.
        expect(ajaxOptions.timeout).toBe(30000);
        expect(companySearch.REQUEST_TIMEOUT_MS).toBe(30000);

        // 300ms is the value shared with the WooCommerce and PrestaShop
        // pickers.
        expect(ajaxOptions.delay).toBe(300);
        expect(companySearch.SEARCH_DEBOUNCE_MS).toBe(300);
    });

    test('the company-detail lookup carries the same timeout', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);

        companySearch.lookupCompanyAddress(BASE_CONFIG, { lookupId: 'lookup-abc-123' });

        expect(recorder.ajax).toHaveLength(1);
        expect(recorder.ajax[0].timeout).toBe(30000);
    });
});

describe('failure is not "no companies found"', () => {
    test('a timeout raises the unavailable affordance', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const failure = jest.fn();

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, jest.fn(), failure);
        recorder.requests[0].settleFail('timeout');

        expect(hooks.calls.unavailable).toEqual([false, true]);
        expect(hooks.calls.searching).toEqual([true, false]);
        expect(failure).toHaveBeenCalled();
    });

    test('a network error raises the unavailable affordance', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, jest.fn(), jest.fn());
        recorder.requests[0].settleFail('error');

        expect(hooks.calls.unavailable).toContain(true);
    });

    test('a genuine abort stays silent', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, jest.fn(), jest.fn());
        recorder.requests[0].settleFail('abort');

        // An abort is the buyer typing on, or the widget being torn down.
        // Showing an error for that would be noise on every keystroke.
        expect(hooks.calls.unavailable).toEqual([false]);
        // The spinner still has to come down.
        expect(hooks.calls.searching).toEqual([true, false]);
    });

    test('a healthy response raises nothing', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const success = jest.fn();

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, success, jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);

        expect(success).toHaveBeenCalledWith(SEARCH_RESPONSE);
        expect(hooks.calls.unavailable).toEqual([false]);
    });
});

describe('degraded flag', () => {
    test('degraded: true raises the unavailable affordance on an HTTP 200', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const success = jest.fn();

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, success, jest.fn());
        recorder.requests[0].settleDone({ items: [], degraded: true });

        expect(hooks.calls.unavailable).toEqual([false, true]);
        // Still a success as far as select2 is concerned — whatever partial
        // results came back are shown alongside the notice.
        expect(success).toHaveBeenCalled();
    });

    test('an absent degraded field means not degraded', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);

        // The API field may not be deployed yet, so today's payload shape
        // must keep working untouched.
        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, jest.fn(), jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);

        expect(hooks.calls.unavailable).toEqual([false]);
    });

    test('isDegradedResponse only accepts a real boolean true', () => {
        const { $ } = makeQueryDouble();
        const companySearch = loadCompanySearch($);

        expect(companySearch.isDegradedResponse({ degraded: true })).toBe(true);
        expect(companySearch.isDegradedResponse({ degraded: false })).toBe(false);
        expect(companySearch.isDegradedResponse({})).toBe(false);
        expect(companySearch.isDegradedResponse(null)).toBe(false);
        expect(companySearch.isDegradedResponse(undefined)).toBe(false);
        // Truthy-but-not-true values must not trip the affordance.
        expect(companySearch.isDegradedResponse({ degraded: 'false' })).toBe(false);
        expect(companySearch.isDegradedResponse({ degraded: 1 })).toBe(false);
    });

    test('a degraded response is never cached', async () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());
        const url = 'https://api.example.test/x?q=exa';

        ajaxOptions.transport({ url: url }, jest.fn(), jest.fn());
        recorder.requests[0].settleDone({ items: [], degraded: true });

        // Caching a transient upstream failure would pin the buyer to an
        // empty result set for the rest of the session.
        ajaxOptions.transport({ url: url }, jest.fn(), jest.fn());
        await nextTick();
        expect(recorder.ajax).toHaveLength(2);
    });
});

describe('result cache', () => {
    test('a repeated search is answered without a second request', async () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());
        const url = 'https://api.example.test/companies/v2/company?country=GB&q=exa';

        ajaxOptions.transport({ url: url }, jest.fn(), jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);

        const secondSuccess = jest.fn();
        ajaxOptions.transport({ url: url }, secondSuccess, jest.fn());
        await nextTick();

        expect(recorder.ajax).toHaveLength(1);
        expect(secondSuccess).toHaveBeenCalledWith(SEARCH_RESPONSE);
    });

    test('the cache is keyed by url, so a different query still fetches', async () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());

        ajaxOptions.transport({ url: 'https://api.example.test/c?q=exa' }, jest.fn(), jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);
        ajaxOptions.transport({ url: 'https://api.example.test/c?q=exam' }, jest.fn(), jest.fn());
        await nextTick();

        expect(recorder.ajax).toHaveLength(2);
    });

    test('the cache survives a rebuilt ajax options block', async () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const url = 'https://api.example.test/c?q=exa';

        // A one-page checkout re-render destroys the select2 widget and
        // builds a fresh options block. The cache is module-scoped
        // precisely so the buyer doesn't pay for the same search twice.
        buildOptions(companySearch, makeHooks()).transport({ url: url }, jest.fn(), jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);

        const success = jest.fn();
        buildOptions(companySearch, makeHooks()).transport({ url: url }, success, jest.fn());
        await nextTick();

        expect(recorder.ajax).toHaveLength(1);
        expect(success).toHaveBeenCalledWith(SEARCH_RESPONSE);
    });

    test('aborting a cache hit suppresses its success callback', async () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());
        const url = 'https://api.example.test/c?q=exa';

        ajaxOptions.transport({ url: url }, jest.fn(), jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);

        const success = jest.fn();
        const handle = ajaxOptions.transport({ url: url }, success, jest.fn());
        handle.abort();
        await nextTick();

        // select2 aborts the in-flight search when the next keystroke
        // supersedes it; a cache hit must honour that too, or a stale query
        // repopulates the dropdown.
        expect(success).not.toHaveBeenCalled();
    });

    test('clearResultCache forces a refetch', async () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());
        const url = 'https://api.example.test/c?q=exa';

        ajaxOptions.transport({ url: url }, jest.fn(), jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);
        companySearch.clearResultCache();
        ajaxOptions.transport({ url: url }, jest.fn(), jest.fn());
        await nextTick();

        expect(recorder.ajax).toHaveLength(2);
    });
});

describe('processResults robustness', () => {
    test('a payload with no items yields no results instead of throwing', () => {
        const { $ } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());

        // A degraded response can legitimately arrive with `items` absent.
        expect(ajaxOptions.processResults({}).results).toEqual([]);
        expect(ajaxOptions.processResults({ degraded: true }).results).toEqual([]);
    });
});

describe('in-field chrome', () => {
    test('spinner and notice are no-ops when the widget is not bound', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);

        // `.data('select2')` is undefined before select2 binds — the chrome
        // must not throw or leak markup into the page in that window.
        companySearch.setSearching('input#company_name', true);
        companySearch.setUnavailable('input#company_name', true);

        expect(recorder.appended).toHaveLength(0);
    });
});

describe('re-render safety of the select2 binding', () => {
    /**
     * `$.async` is a MutationObserver and every enableCompanySearch() call
     * adds another one, so on a one-page checkout the callback fires
     * repeatedly for the same node. Binding twice leaves a duplicate widget
     * whose in-flight XHR resolves into a dropdown the buyer can't see.
     */
    function assertBindsOnce(loadRenderer) {
        const { $, recorder } = makeQueryDouble();
        const ctx = loadRenderer($, recorder);

        ctx.enableCompanySearch();
        expect(recorder.select2Calls).toHaveLength(1);

        // Fire every registered observer callback again, as a re-render does.
        recorder.asyncCallbacks.forEach(function (fn) {
            fn('input#company_name');
        });
        // And re-run the whole enable path, as a re-rendered renderer does.
        ctx.enableCompanySearch();

        expect(recorder.select2Calls).toHaveLength(1);
    }

    test('payment-step picker binds select2 exactly once per node', () => {
        assertBindsOnce(function ($) {
            const companySearch = loadCompanySearch($);
            const component = loadAmdModule(
                'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
                { jquery: $, 'Two_Gateway/js/model/company-search': companySearch }
            );
            return Object.assign(Object.create(component.prototype || {}), {
                companyNameSelector: 'input#company_name',
                companyIdSelector: 'input#company_id',
                enterDetailsManuallyButton: '#billing_enter_details_manually',
                searchForCompanyButton: '#billing_search_for_company',
                enterDetailsManuallyText: 'Enter details manually',
                searchForCompanyText: 'Search for company',
                _brandConfig: BASE_CONFIG,
                countryCode: function () {
                    return 'gb';
                },
                companyName: function () {
                    return '';
                },
                fillCompanyData: function () {},
                addressLookup: component.addressLookup,
                enableCompanySearch: component.enableCompanySearch
            });
        });
    });

    test('shipping-step picker binds select2 exactly once per node', () => {
        assertBindsOnce(function ($) {
            const companySearch = loadCompanySearch($);
            const brandConfig = function () {
                return BASE_CONFIG;
            };
            brandConfig.getActiveTwoBrandCode = function () {
                return 'two_payment';
            };
            brandConfig.getActiveTwoBrandConfig = function () {
                return BASE_CONFIG;
            };

            const component = loadAmdModule('view/frontend/web/js/view/address-autocomplete.js', {
                jquery: $,
                'Two_Gateway/js/model/brand-config': brandConfig,
                'Two_Gateway/js/model/company-search': companySearch
            });
            return Object.assign(Object.create(component.prototype || {}), {
                countrySelector: '#shipping-new-address-form select[name="country_id"]',
                companyNameSelector: 'input#company_name',
                companyIdSelector: 'input#company_id',
                enterDetailsManuallyButton: '#shipping_enter_details_manually',
                searchForCompanyButton: '#shipping_search_for_company',
                enterDetailsManuallyText: 'Enter details manually',
                searchForCompanyText: 'Search for company',
                companyNamePlaceholder: 'Enter company name to search',
                setCompanyData: function () {},
                addressLookup: component.addressLookup,
                enableCompanySearch: component.enableCompanySearch
            });
        });
    });
});
