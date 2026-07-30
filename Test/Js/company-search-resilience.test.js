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
        searchBoxes: [],
        searchFields: []
    };
    const nodes = {};

    function makeNode(key) {
        const store = {};
        const handlers = [];
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
            data: function (dataKey, value) {
                if (arguments.length > 1) {
                    store[dataKey] = value;
                    return node;
                }
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
                    // Mirrors select2 4.1: destroy() does
                    // `$element.off('.select2')` and nothing more, so handlers
                    // in any OTHER namespace survive. That is exactly why the
                    // module has to clear its own namespace before re-binding.
                    node.off('.select2');
                    return node;
                }
                if (typeof opts === 'object') {
                    recorder.select2Calls.push(opts);
                    // Mirror select2 4.1: the instance is discoverable via
                    // `.data('select2')`, and it owns a `$dropdown` holding
                    // the search box our chrome writes into.
                    const searchBox = makeFakeContainer();
                    recorder.searchBoxes.push(searchBox);
                    const searchField = {
                        value: '',
                        __handlers: {},
                        off: function () {
                            return searchField;
                        },
                        on: function (spec, handler) {
                            searchField.__handlers[spec] = handler;
                            return searchField;
                        }
                    };
                    recorder.searchFields.push(searchField);
                    store.select2 = {
                        // select2's ajax data adapter holds the debounced
                        // query in `_queryTimeout`; the below-minimum handler
                        // has to clear it as well as abort the in-flight
                        // request.
                        dataAdapter: { _queryTimeout: null },
                        $dropdown: {
                            find: function (selector) {
                                if (selector === '.select2-search--dropdown') return searchBox;
                                if (selector === '.select2-search__field') return searchField;
                                return { length: 0 };
                            }
                        }
                    };
                }
                return node;
            },
            on: function (spec, handler) {
                // Record per event name AND namespace, so the tests can prove
                // handlers are not stacking across re-binds.
                handlers.push({ spec: spec, handler: handler });
                return node;
            },
            off: function (spec) {
                if (spec === undefined) {
                    handlers.length = 0;
                    return node;
                }
                const remaining = handlers.filter(function (h) {
                    // A bare namespace ('.twoCompanySearch') removes every
                    // handler bound in it; jQuery semantics.
                    if (spec.charAt(0) === '.') return h.spec.indexOf(spec) === -1;
                    return h.spec !== spec;
                });
                handlers.length = 0;
                remaining.forEach(function (h) {
                    handlers.push(h);
                });
                return node;
            },
            handlersFor: function (eventName) {
                return handlers.filter(function (h) {
                    return h.spec.split('.')[0] === eventName;
                });
            }
        };
        return node;
    }

    function asyncKey(selector) {
        return selector + '::matched';
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

    // `$.async` hands the callback the matched ELEMENT. Modelled as a node
    // distinct from `$(selector)` on purpose: that is what makes a
    // document-wide `$(companyNameSelector).select2('destroy')` provably miss
    // the widget the component actually bound (the multi-brand hazard), rather
    // than accidentally hitting the same memoised node.
    $.async = function (selector, fn) {
        recorder.asyncCallbacks.push(fn);
        fn(asyncKey(selector));
    };
    $.asyncNode = function (selector) {
        return $(asyncKey(selector));
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
            aborted: false,
            // jQuery sets status 0 for BOTH a timeout and an abort. Present
            // here on purpose: it is what makes `'status' in handle` a real
            // assertion rather than a tautology, since returning this jqXHR
            // straight through would satisfy select2's cancellation check.
            status: 0,
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

function buildOptions(companySearch, hooks, token) {
    return companySearch.buildSearchAjaxOptions({
        config: BASE_CONFIG,
        token: token || {},
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
    /**
     * The crux of the whole ticket. select2's ajax adapter builds its failure
     * closure as `'status' in e && (0 === e.status || '0' === e.status) ||
     * trigger('results:message', {message: 'errorLoading'})`, where `e` is
     * the value the TRANSPORT RETURNED — not the jqXHR. jQuery reports both a
     * user abort AND a timeout as `status === 0`, so handing back the raw
     * jqXHR makes the two indistinguishable: select2 swallows the timeout,
     * never fires `results:message`, never reaches `hideLoading()`, and
     * leaves "Searching…" in the dropdown forever. Owning the handle is what
     * lets `status = 0` mean "abort" and only that.
     */
    test('a timeout reaches select2 as a real failure, not a cancellation', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const success = jest.fn();
        const failure = jest.fn();

        const handle = ajaxOptions.transport(
            { url: 'https://api.example.test/x?q=exa' },
            success,
            failure
        );
        recorder.requests[0].settleFail('timeout');

        expect(hooks.calls.unavailable).toEqual([false, true]);
        expect(failure).toHaveBeenCalled();
        expect(success).not.toHaveBeenCalled();

        // No `status` key on the handle => select2 fires `errorLoading`, and
        // displayMessage() calls hideLoading(). A `status` of 0 here would
        // reinstate the stuck-spinner bug.
        expect('status' in handle).toBe(false);
    });

    test('a network error behaves the same way', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const failure = jest.fn();

        const handle = ajaxOptions.transport(
            { url: 'https://api.example.test/x?q=exa' },
            jest.fn(),
            failure
        );
        recorder.requests[0].settleFail('error');

        expect(hooks.calls.unavailable).toContain(true);
        expect(failure).toHaveBeenCalled();
        expect('status' in handle).toBe(false);
    });

    test('a genuine abort is marked status 0 so select2 stays silent', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const success = jest.fn();
        const failure = jest.fn();

        const handle = ajaxOptions.transport(
            { url: 'https://api.example.test/x?q=exa' },
            success,
            failure
        );
        recorder.requests[0].settleFail('abort');

        // An abort is the buyer typing on, or the widget being torn down.
        // Showing an error for that would be noise on every keystroke.
        expect(hooks.calls.unavailable).toEqual([false]);
        expect(failure).toHaveBeenCalled();
        expect(success).not.toHaveBeenCalled();
        expect(handle.status).toBe(0);

        // select2 aborts the in-flight request synchronously at the top of
        // the next query(), 300ms before the replacement transport starts.
        // Dropping the spinner there would blink it off on every keystroke.
        expect(hooks.calls.searching).toEqual([true]);
    });

    test('the handle aborts the underlying request', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());

        const handle = ajaxOptions.transport(
            { url: 'https://api.example.test/x?q=exa' },
            jest.fn(),
            jest.fn()
        );
        handle.abort();

        // select2 calls `this._request.abort()` at the top of the next
        // query(); the wrapper must forward that or every keystroke leaks a
        // request.
        expect(recorder.requests[0].aborted).toBe(true);
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

    test('a cache hit takes down a spinner an abort left up', async () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const hooks = makeHooks();
        const ajaxOptions = buildOptions(companySearch, hooks);
        const url = 'https://api.example.test/c?q=exa';

        ajaxOptions.transport({ url: url }, jest.fn(), jest.fn());
        recorder.requests[0].settleDone(SEARCH_RESPONSE);

        // Type on (spinner up), then backspace back to the cached term. The
        // abort deliberately keeps the spinner, so the cache hit is the only
        // thing that can take it down — otherwise the spinner runs forever
        // over a fully populated dropdown.
        ajaxOptions.transport({ url: url + 'm' }, jest.fn(), jest.fn());
        recorder.requests[1].settleFail('abort');
        expect(hooks.calls.searching[hooks.calls.searching.length - 1]).toBe(true);

        ajaxOptions.transport({ url: url }, jest.fn(), jest.fn());
        await nextTick();

        expect(hooks.calls.searching[hooks.calls.searching.length - 1]).toBe(false);
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

    // `national_identifier` is optional in the search response and its `id`
    // may be null or empty, so every one of these five shapes is reachable.
    // A throw here would happen inside select2's query pipeline, taking the
    // whole result list down and leaving the dropdown on "Searching…" — so
    // the hit renders with whatever it has instead.
    //
    // `toEqual` treats `lookupId: undefined` as equal to the key being ABSENT,
    // so on its own it let an implementation that dropped `lookupId`
    // altogether pass. `toStrictEqual` is not the fix here — the harness runs
    // the module inside a `vm` context, so its objects carry that realm's
    // Object.prototype and every strict compare fails with "serializes to the
    // same string". The key set is asserted explicitly instead, and the final
    // row carries a real `lookup_id`: address autofill is the one thing that
    // still works for an identifier-less hit (lookupCompanyAddress() keys on
    // `lookupId`, not on the national identifier), so losing it would strip
    // the remaining value in showing the hit at all.
    test.each([
        [
            'national_identifier absent',
            { name: 'Example Trading Ltd', highlight: '<em>Example</em> Trading Ltd' },
            undefined
        ],
        [
            'national_identifier null',
            {
                name: 'Example Trading Ltd',
                highlight: '<em>Example</em> Trading Ltd',
                national_identifier: null
            },
            undefined
        ],
        [
            'id null',
            {
                name: 'Example Trading Ltd',
                highlight: '<em>Example</em> Trading Ltd',
                national_identifier: { id: null }
            },
            undefined
        ],
        [
            'id empty',
            {
                name: 'Example Trading Ltd',
                highlight: '<em>Example</em> Trading Ltd',
                national_identifier: { id: '' }
            },
            undefined
        ],
        [
            'no identifier but a lookup_id',
            {
                name: 'Example Trading Ltd',
                highlight: '<em>Example</em> Trading Ltd',
                national_identifier: null,
                lookup_id: 'lookup-abc-123'
            },
            'lookup-abc-123'
        ]
    ])(
        '%s renders the company without an identifier suffix',
        (_label, item, expectedLookupId) => {
            const { $ } = makeQueryDouble();
            const companySearch = loadCompanySearch($);
            const ajaxOptions = buildOptions(companySearch, makeHooks());
            const run = function () {
                return ajaxOptions.processResults({ items: [item] });
            };

            expect(run).not.toThrow();
            expect(run().results).toEqual([
                {
                    id: 'Example Trading Ltd',
                    text: 'Example Trading Ltd',
                    html: '<em>Example</em> Trading Ltd',
                    companyId: '',
                    lookupId: expectedLookupId
                }
            ]);
            const result = run().results[0];
            expect(Object.keys(result).sort()).toEqual([
                'companyId',
                'html',
                'id',
                'lookupId',
                'text'
            ]);
            expect(result.lookupId).toBe(expectedLookupId);
        }
    );

    test('one unusable hit does not take the rest of the result list down', () => {
        // The point of the guard: one hit with no identifier must not cost
        // the buyer every other company that matched.
        const { $ } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const ajaxOptions = buildOptions(companySearch, makeHooks());

        const out = ajaxOptions.processResults({
            items: [
                { name: 'Other Example Ltd', highlight: '<em>Other</em> Example Ltd' },
                SEARCH_RESPONSE.items[0]
            ]
        });

        expect(out.results.map((r) => r.text)).toEqual([
            'Other Example Ltd',
            'Example Trading Ltd'
        ]);
        expect(out.results.map((r) => r.companyId)).toEqual(['', '12345678']);
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

    test('the spinner is a childless, aria-hidden element on its flat class hook', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = boundField($);

        companySearch.setSearching($field, true);

        const host = document.createElement('div');
        host.innerHTML = searchBoxOf(recorder).children[0];
        const spinner = host.querySelector('.two-company-search__spinner');

        // TWO-25288. The indicator is a CSS background-image, so it must stay
        // a single childless element: re-introducing inner nodes would paint
        // content on top of the figure the stylesheet draws.
        expect(spinner).not.toBeNull();
        expect(spinner.children).toHaveLength(0);
        expect(spinner.textContent).toBe('');

        // Purely decorative — the "search unavailable" notice is the only
        // company-search chrome that should reach a screen reader.
        expect(spinner.getAttribute('aria-hidden')).toBe('true');

        // Brand overlays override this rule with flat single-class rules of
        // their own, winning on load order alone at equal specificity — which
        // is why the selector must stay a single flat class. So the element
        // must carry the hook class itself and nothing else may be relied on
        // in its place.
        expect(Array.from(spinner.classList)).toEqual(['two-company-search__spinner']);
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

    test('a stale bind token cannot paint on the widget that replaced it', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = $(SEARCH_FIELD);

        const staleToken = {};
        $field.select2({});
        companySearch.markSearchBinding($field, staleToken);

        // Re-render: select2 re-inits on the SAME node, so `data('select2')`
        // now resolves to the NEW instance. The old widget's request can still
        // be in flight for up to 30s; resolving it must not paint here.
        const liveToken = {};
        $field.select2({});
        companySearch.markSearchBinding($field, liveToken);
        const liveBox = searchBoxOf(recorder);

        companySearch.setUnavailable($field, true, staleToken);
        expect(liveBox.children).toHaveLength(0);

        // And the live token still works.
        companySearch.setUnavailable($field, true, liveToken);
        expect(liveBox.children).toHaveLength(1);
    });

    test('a stale token cannot strip the live spinner', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = $(SEARCH_FIELD);

        const staleToken = {};
        $field.select2({});
        companySearch.markSearchBinding($field, staleToken);

        const liveToken = {};
        $field.select2({});
        companySearch.markSearchBinding($field, liveToken);
        const liveBox = searchBoxOf(recorder);

        companySearch.setSearching($field, true, liveToken);
        expect(liveBox.children).toHaveLength(1);

        // The stale widget's `always` handler fires onSearching(false).
        companySearch.setSearching($field, false, staleToken);
        expect(liveBox.children).toHaveLength(1);
    });

    test('dropping below the minimum input length clears the chrome', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = $(SEARCH_FIELD);
        const token = {};

        $field.select2({});
        companySearch.markSearchBinding($field, token);

        companySearch.setSearching($field, true, token);
        companySearch.setUnavailable($field, true, token);
        expect(searchBoxOf(recorder).children).toHaveLength(2);

        // Below `minimumInputLength` select2 short-circuits query() in its
        // decorator and never calls the data adapter, so no transport runs and
        // nothing else would ever take the spinner down.
        const searchField = recorder.searchFields[recorder.searchFields.length - 1];
        searchField.value = 'ex';
        searchField.__handlers['input' + companySearch.EVENT_NS].call(searchField);

        expect(searchBoxOf(recorder).children).toHaveLength(0);
    });

    test('dropping below the minimum input length clears the PENDING query', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = $(SEARCH_FIELD);
        const token = {};

        $field.select2({});
        companySearch.markSearchBinding($field, token);

        // select2 armed its 300ms debounce but has not fired the request yet.
        // Its minimumInputLength decorator short-circuits query() before
        // reaching the adapter, so select2 never clears this timer either:
        // backspacing from 4 chars to 2 inside 300ms would otherwise fire a
        // search for the abandoned term.
        const dataAdapter = $field.data('select2').dataAdapter;
        dataAdapter._queryTimeout = setTimeout(function () {}, 10000);

        const searchField = recorder.searchFields[recorder.searchFields.length - 1];
        searchField.value = 'ex';
        searchField.__handlers['input' + companySearch.EVENT_NS].call(searchField);

        expect(dataAdapter._queryTimeout).toBeNull();
    });

    test('dropping below the minimum input length CANCELS the request', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const $field = $(SEARCH_FIELD);
        const token = {};

        $field.select2({});
        companySearch.markSearchBinding($field, token);

        companySearch
            .buildSearchAjaxOptions({
                config: BASE_CONFIG,
                token: token,
                getCountryCode: function () {
                    return 'gb';
                }
            })
            .transport({ url: 'https://api.example.test/c?q=exa' }, jest.fn(), jest.fn());

        // select2's minimumInputLength decorator returns BEFORE delegating to
        // the ajax adapter, and `_request.abort()` lives inside that adapter —
        // so select2 never cancels here. Left running, the request resolves
        // 30s later and repaints results for an abandoned term.
        const searchField = recorder.searchFields[recorder.searchFields.length - 1];
        searchField.value = 'ex';
        searchField.__handlers['input' + companySearch.EVENT_NS].call(searchField);

        expect(recorder.requests[0].aborted).toBe(true);
    });

    test('abortActiveRequest reports whether there was anything to cancel', () => {
        const { $, recorder } = makeQueryDouble();
        const companySearch = loadCompanySearch($);
        const token = {};

        expect(companySearch.abortActiveRequest(token)).toBe(false);

        companySearch
            .buildSearchAjaxOptions({
                config: BASE_CONFIG,
                token: token,
                getCountryCode: function () {
                    return 'gb';
                }
            })
            .transport({ url: 'https://api.example.test/c?q=exa' }, jest.fn(), jest.fn());

        expect(companySearch.abortActiveRequest(token)).toBe(true);
        expect(recorder.requests[0].aborted).toBe(true);
        // Deregistered, so a second call is a no-op rather than a double abort.
        expect(companySearch.abortActiveRequest(token)).toBe(false);
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
            enterDetailsManuallyButton: '#billing_enter_details_manually',
            searchForCompanyButton: '#billing_search_for_company',
            enterDetailsManuallyText: 'Enter details manually',
            searchForCompanyText: 'Search for company',
            _brandConfig: BASE_CONFIG,
            countryCode: function () {
                return 'gb';
            },
            // Defensive `subscribe` stub. Nothing on this path subscribes to
            // the observable any more — the company-number editable-state
            // derivation that used to (TWO-25288) is gone — but the stub is
            // cheap and keeps the fixture usable if a subscriber returns.
            companyName: Object.assign(
                function () {
                    return '';
                },
                {
                    subscribe: function () {
                        return { dispose: function () {} };
                    }
                }
            ),
            fillCompanyData: function () {},
            addressLookup: component.addressLookup,
            enableCompanySearch: component.enableCompanySearch,
            disableCompanySearch: component.disableCompanySearch,
            destroyCompanySearchWidget: component.destroyCompanySearchWidget,
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
        expect($.asyncNode(SEARCH_FIELD).data('select2')).toBeDefined();

        ctx.dispose();

        // Without this, a re-render that REUSES the input node leaves the old
        // widget bound with handlers closed over the disposed renderer.
        expect(recorder.destroyCalls).toBe(1);
        expect($.asyncNode(SEARCH_FIELD).data('select2')).toBeUndefined();
    });

    /**
     * The renderer is pushed once per Two-family brand, so a checkout offering
     * two of them has two `#company_name` inputs. dispose() must tear down the
     * node THIS component bound, not everything a document-wide selector
     * matches — otherwise disposing one renderer silently turns the other
     * brand's picker into a plain text input.
     */
    test('dispose only destroys the node this component bound', () => {
        const { $, recorder } = makeQueryDouble();
        const ctx = loadRenderer($);

        ctx.enableCompanySearch();
        // Another node that the component's own selector ALSO matches — the
        // duplicate `#company_name` a second Two-family brand renders. A
        // document-wide destroy would take this one out.
        const $sibling = $(SEARCH_FIELD);
        $sibling.select2({});

        ctx.dispose();

        expect(recorder.destroyCalls).toBe(1);
        expect($sibling.data('select2')).toBeDefined();
        expect($.asyncNode(SEARCH_FIELD).data('select2')).toBeUndefined();
    });

    /**
     * The re-enable link is only visible on paths that have already destroyed
     * the widget (manual entry → clearCompany → destroy), so resolving it from
     * `_$companyNameField` found nothing and left the link up in sole-trader
     * mode. It must resolve from the cached container instead.
     */
    test('the re-enable link stays resolvable after the widget is destroyed', () => {
        const { $ } = makeQueryDouble();
        const ctx = loadRenderer($);

        ctx.enableCompanySearch();
        expect(ctx.searchForCompanyLink().length).toBe(1);

        ctx.destroyCompanySearchWidget();

        expect(ctx._$companyNameField).toBeNull();
        expect(ctx.searchForCompanyLink().length).toBe(1);
    });

    test('dispose is safe when no widget was ever bound', () => {
        const { $ } = makeQueryDouble();
        const ctx = loadRenderer($);

        expect(function () {
            ctx.dispose();
        }).not.toThrow();
    });

    /**
     * select2's destroy() only does `$element.off('.select2')`, so handlers we
     * bind outside that namespace survive every re-init. Left unchecked they
     * stack one copy per re-render, and a single company pick then fires N
     * `select2:select` handlers — N address lookups, N-1 of them closed over
     * disposed renderers, which is the dead-observable bug all over again.
     */
    test('re-render does not stack duplicate select2 handlers', () => {
        const { $ } = makeQueryDouble();
        const ctx = loadRenderer($);
        const $field = $.asyncNode(SEARCH_FIELD);

        ctx.enableCompanySearch();
        expect($field.handlersFor('select2:select')).toHaveLength(1);

        ctx.enableCompanySearch();
        ctx.enableCompanySearch();

        expect($field.handlersFor('select2:select')).toHaveLength(1);
        expect($field.handlersFor('select2:open')).toHaveLength(1);
    });

    /**
     * Covers the CALL SITES, not the model. An earlier revision threaded the
     * bind token only into `clearSearchChrome` — which runs on `select2:open`
     * and is by definition the live widget — while the two hooks that can
     * actually paint from a stale widget passed none. Because the guard then
     * failed open on a missing token, every model-level token test still
     * passed and the bug shipped. This drives the real hooks.
     */
    test("a stale widget's hooks cannot paint after a re-render", () => {
        const { $, recorder } = makeQueryDouble();
        const ctx = loadRenderer($);

        ctx.enableCompanySearch();
        const staleOptions = recorder.select2Calls[0].ajax;

        // Re-render: same node, fresh widget, fresh box.
        ctx.enableCompanySearch();
        const liveBox = recorder.searchBoxes[recorder.searchBoxes.length - 1];

        // The stale widget's request finally times out.
        staleOptions.transport({ url: 'https://api.example.test/x?q=exa' }, jest.fn(), jest.fn());
        recorder.requests[recorder.requests.length - 1].settleFail('timeout');

        expect(liveBox.children).toHaveLength(0);
    });

    test("the live widget's hooks DO paint", () => {
        const { $, recorder } = makeQueryDouble();
        const ctx = loadRenderer($);

        ctx.enableCompanySearch();
        const liveOptions = recorder.select2Calls[0].ajax;
        const liveBox = recorder.searchBoxes[recorder.searchBoxes.length - 1];

        liveOptions.transport({ url: 'https://api.example.test/x?q=exa' }, jest.fn(), jest.fn());
        recorder.requests[recorder.requests.length - 1].settleFail('timeout');

        // Guards against "fails closed" degenerating into "never works".
        expect(liveBox.children).toHaveLength(1);
        expect(liveBox.children[0]).toContain('two-company-search__unavailable');
    });

    test('shipping-step picker does not stack handlers either', () => {
        const { $ } = makeQueryDouble();
        const ctx = loadShippingComponent($);
        const $field = $.asyncNode(SEARCH_FIELD);

        ctx.enableCompanySearch();
        ctx.enableCompanySearch();

        expect($field.handlersFor('select2:select')).toHaveLength(1);
        expect($field.handlersFor('select2:open')).toHaveLength(1);
    });

    function loadShippingComponent($) {
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
            companyNameSelector: SEARCH_FIELD,
            enterDetailsManuallyButton: '#shipping_enter_details_manually',
            searchForCompanyButton: '#shipping_search_for_company',
            enterDetailsManuallyText: 'Enter details manually',
            searchForCompanyText: 'Search for company',
            companyNamePlaceholder: 'Enter company name to search',
            setCompanyData: function () {},
            addressLookup: component.addressLookup,
            enableCompanySearch: component.enableCompanySearch
        });
    }

    test('shipping-step picker also re-initialises on re-render', () => {
        const { $, recorder } = makeQueryDouble();
        const ctx = loadShippingComponent($);

        ctx.enableCompanySearch();
        ctx.enableCompanySearch();

        expect(recorder.select2Calls).toHaveLength(2);
    });
});
