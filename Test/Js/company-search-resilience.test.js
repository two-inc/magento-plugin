/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25233. Company search had no request timeout, no way to tell a failed
 * search from a genuinely empty one, no result cache, and left a stale
 * widget bound when a one-page checkout re-rendered. These tests pin all of
 * that, plus the two select2-specific traps found in review: a jQuery
 * timeout reports `status === 0`, which select2's own failure handler treats
 * as an abort and therefore never clears its "Searching…" row; and select2
 * 4.1's constructor already destroys any existing instance on the same node,
 * so guarding against re-init is harmful rather than protective.
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

const SEARCH_FIELD = 'input#company_name';

/**
 * A container that records what gets appended to it and can remove it again
 * by class, so the spinner / notice assertions exercise the real DOM writes
 * instead of silently no-opping on an empty jQuery set.
 */
function makeFakeContainer() {
    const children = [];
    const container = {
        __fake: true,
        length: 1,
        children: children,
        append: function (html) {
            children.push(html);
            return container;
        },
        find: function (selector) {
            const needle = selector.replace(/^\./, '');
            const matched = children.filter(function (html) {
                return html.indexOf(needle) !== -1;
            });
            return {
                length: matched.length,
                remove: function () {
                    matched.forEach(function (html) {
                        children.splice(children.indexOf(html), 1);
                    });
                }
            };
        }
    };
    return container;
}

/**
 * jQuery double whose `$.ajax` hands back a jqXHR the test settles by hand,
 * so each outcome (done / timeout / abort) is driven explicitly rather than
 * inferred. Nodes are memoised per selector so `.data('select2')` reflects
 * whatever the last `select2()` call bound — that is what lets the
 * destroy-on-dispose and re-init assertions be meaningful.
 */
function makeQueryDouble() {
    const recorder = {
        ajax: [],
        requests: [],
        asyncCallbacks: [],
        select2Calls: [],
        destroyCalls: 0,
        searchBoxes: []
    };
    const nodes = {};

    function makeNode(key) {
        const store = {};
        const node = {
            __fake: true,
            length: 1,
            key: key,
            val: function () {
                return node;
            },
            trigger: function () {
                return node;
            },
            prop: function () {
                return node;
            },
            text: function () {
                return node;
            },
            attr: function () {
                return node;
            },
            data: function (dataKey) {
                return store[dataKey];
            },
            closest: function () {
                return node;
            },
            find: function () {
                return node;
            },
            append: function () {
                return node;
            },
            remove: function () {
                return node;
            },
            hide: function () {
                return node;
            },
            show: function () {
                return node;
            },
            select2: function (opts) {
                if (opts === 'destroy') {
                    recorder.destroyCalls++;
                    delete store.select2;
                    return node;
                }
                if (typeof opts === 'object') {
                    recorder.select2Calls.push(opts);
                    // Mirror select2 4.1: the instance is discoverable via
                    // `.data('select2')`, and it owns a `$dropdown` holding
                    // the search box our chrome writes into.
                    const searchBox = makeFakeContainer();
                    recorder.searchBoxes.push(searchBox);
                    store.select2 = {
                        $dropdown: {
                            find: function (selector) {
                                return selector === '.select2-search--dropdown'
                                    ? searchBox
                                    : { length: 0 };
                            }
                        }
                    };
                }
                return node;
            },
            on: function () {
                return node;
            }
        };
        return node;
    }

    function $(target) {
        if (target && target.__fake) return target;
        if (target === undefined) {
            // The empty set company-search falls back to.
            return {
                length: 0,
                find: function () {
                    return { length: 0, remove: function () {} };
                },
                append: function () {}
            };
        }
        const key = String(target);
        if (!nodes[key]) nodes[key] = makeNode(key);
        return nodes[key];
    }

    $.async = function (selector, fn) {
        recorder.asyncCallbacks.push(fn);
        fn(selector);
    };
    $.ajax = function (opts) {
        recorder.ajax.push(opts);
        const handlers = { done: [], fail: [], always: [] };
        const jqxhr = {
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
            abort: function () {},
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
                    cb({ status: textStatus === 'timeout' ? 0 : 500 }, textStatus);
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

    return { $: $, recorder: recorder, node: $ };
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
    test('a timeout raises the notice AND gives select2 a terminal result', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const success = jest.fn();
        const failure = jest.fn();

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, success, failure);
        recorder.requests[0].settleFail('timeout');

        expect(hooks.calls.unavailable).toEqual([false, true]);

        // The load-bearing part. jQuery reports a timeout as status 0, and
        // select2's own failure handler treats status 0 as an abort: it never
        // fires `results:message`, so `hideLoading()` is never reached and
        // the dropdown shows "Searching…" forever — under the very notice
        // saying the search failed. Routing through select2's SUCCESS path
        // with an empty result set is what gives it a terminal state.
        expect(failure).not.toHaveBeenCalled();
        expect(success).toHaveBeenCalledWith({ items: [] });
    });

    test('a network error behaves the same way', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const success = jest.fn();
        const failure = jest.fn();

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, success, failure);
        recorder.requests[0].settleFail('error');

        expect(hooks.calls.unavailable).toContain(true);
        expect(failure).not.toHaveBeenCalled();
        expect(success).toHaveBeenCalledWith({ items: [] });
    });

    test('a genuine abort stays silent and keeps the spinner up', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const success = jest.fn();
        const failure = jest.fn();

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, success, failure);
        recorder.requests[0].settleFail('abort');

        // An abort is the buyer typing on, or the widget being torn down.
        // Showing an error for that would be noise on every keystroke.
        expect(hooks.calls.unavailable).toEqual([false]);
        expect(failure).toHaveBeenCalled();
        expect(success).not.toHaveBeenCalled();

        // select2 aborts the in-flight request synchronously at the top of
        // the next query(), 300ms before the replacement transport starts.
        // Dropping the spinner there would blink it off on every keystroke.
        expect(hooks.calls.searching).toEqual([true]);
    });

    test('a healthy response raises nothing and settles the spinner', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const success = jest.fn();

        ajaxOptions.transport({ url: 'https://api.example.test/x?q=exa' }, success, jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);

        expect(success).toHaveBeenCalledWith(SEARCH_RESPONSE);
        expect(hooks.calls.unavailable).toEqual([false]);
        expect(hooks.calls.searching).toEqual([true, false]);
    });
});

describe('degraded flag', () => {
    test('degraded: true raises the notice on an HTTP 200', () => {
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
        // Truthy-but-not-true values must not trip the notice.
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

        // A one-page checkout re-render rebuilds the select2 widget and a
        // fresh options block. The cache is module-scoped precisely so the
        // buyer doesn't pay for the same search twice.
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

        // The synthetic `{items: []}` fed to select2 on a failure, and a
        // degraded response, both land here.
        expect(ajaxOptions.processResults({}).results).toEqual([]);
        expect(ajaxOptions.processResults({ items: [] }).results).toEqual([]);
        expect(ajaxOptions.processResults({ degraded: true }).results).toEqual([]);
    });
});

describe('in-field chrome', () => {
    /** Bind select2 to a node so the chrome has a real container to write to. */
    function boundField($) {
        const $field = $(SEARCH_FIELD);
        $field.select2({});
        return $field;
    }

    function searchBoxOf(recorder) {
        return recorder.searchBoxes[recorder.searchBoxes.length - 1];
    }

    test('the spinner is written into the search box and removed again', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = boundField($);

        companySearch.setSearching($field, true);
        expect(searchBoxOf(recorder).children).toHaveLength(1);
        expect(searchBoxOf(recorder).children[0]).toContain('two-company-search__spinner');

        companySearch.setSearching($field, false);
        expect(searchBoxOf(recorder).children).toHaveLength(0);
    });

    test('the spinner is never duplicated', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = boundField($);

        companySearch.setSearching($field, true);
        companySearch.setSearching($field, true);

        expect(searchBoxOf(recorder).children).toHaveLength(1);
    });

    test('the notice is written as a span, not a div', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = boundField($);

        companySearch.setUnavailable($field, true);

        const html = searchBoxOf(recorder).children[0];
        // select2 renders both `.select2-dropdown` and
        // `.select2-search--dropdown` as <span>; a <div> inside is invalid
        // nesting and the anonymous block box makes spacing inconsistent.
        expect(html).toContain('<span class="two-company-search__unavailable"');
        expect(html).not.toContain('<div');
        expect(html).toContain('role="alert"');
    });

    test('clearSearchChrome removes both spinner and notice', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = boundField($);

        companySearch.setSearching($field, true);
        companySearch.setUnavailable($field, true);
        expect(searchBoxOf(recorder).children).toHaveLength(2);

        // select2 only detaches the dropdown on close and only blanks the
        // search input, so without this a reopened picker still shows the
        // previous search's notice.
        companySearch.clearSearchChrome($field);
        expect(searchBoxOf(recorder).children).toHaveLength(0);
    });

    test('a destroyed widget cannot paint chrome anywhere', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = boundField($);
        const staleBox = searchBoxOf(recorder);

        // select2('destroy') drops `.data('select2')`. A request issued by
        // that widget can still be in flight for up to 30s; resolving it must
        // not decorate the live picker (or throw).
        $field.select2('destroy');
        companySearch.setSearching($field, true);
        companySearch.setUnavailable($field, true);

        expect(staleBox.children).toHaveLength(0);
    });

    test('chrome is a no-op before select2 binds', () => {
        const { $ } = makeQueryDouble();
        const companySearch = loadCompanySearch($);

        // `.data('select2')` is undefined in the window before binding; the
        // chrome must not throw there.
        expect(function () {
            companySearch.setSearching($(SEARCH_FIELD), true);
            companySearch.setUnavailable($(SEARCH_FIELD), true);
            companySearch.clearSearchChrome($(SEARCH_FIELD));
        }).not.toThrow();
    });
});

describe('re-render safety of the select2 binding', () => {
    function loadRenderer($) {
        const companySearch = loadCompanySearch($);
        const component = loadAmdModule(
            'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
            { jquery: $, 'Two_Gateway/js/model/company-search': companySearch }
        );
        return Object.assign(Object.create(component.prototype || {}), {
            companyNameSelector: SEARCH_FIELD,
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
            enableCompanySearch: component.enableCompanySearch,
            disableCompanySearch: component.disableCompanySearch,
            dispose: component.dispose,
            _super: function () {}
        });
    }

    /**
     * The inverse of the guard this PR originally shipped. select2 4.1's
     * constructor opens with `GetData(el, 'select2').destroy()`, so re-init
     * is how the widget — and its handlers' `self` closure — get re-pointed
     * at the current component. Early-returning would keep a widget alive
     * whose closures reference a DISPOSED renderer, so picking a company
     * would write to dead observables and the order would ship with no
     * company on it.
     */
    test('re-render re-initialises select2 rather than skipping it', () => {
        const { $, recorder } = makeQueryDouble();
        const ctx = loadRenderer($);

        ctx.enableCompanySearch();
        expect(recorder.select2Calls).toHaveLength(1);

        ctx.enableCompanySearch();
        expect(recorder.select2Calls).toHaveLength(2);
    });

    test('dispose destroys the company-search widget', () => {
        const { $, recorder } = makeQueryDouble();
        const ctx = loadRenderer($);

        ctx.enableCompanySearch();
        expect($(SEARCH_FIELD).data('select2')).toBeDefined();

        ctx.dispose();

        // Without this, a re-render that REUSES the input node leaves the old
        // widget bound with handlers closed over the disposed renderer.
        expect(recorder.destroyCalls).toBe(1);
        expect($(SEARCH_FIELD).data('select2')).toBeUndefined();
    });

    test('shipping-step picker also re-initialises on re-render', () => {
        const { $, recorder } = makeQueryDouble();
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
        const ctx = Object.assign(Object.create(component.prototype || {}), {
            countrySelector: '#shipping-new-address-form select[name="country_id"]',
            companyNameSelector: SEARCH_FIELD,
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

        ctx.enableCompanySearch();
        ctx.enableCompanySearch();

        expect(recorder.select2Calls).toHaveLength(2);
    });
});
