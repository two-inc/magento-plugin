/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25233. Company search had no request timeout, no way to tell a failed
 * search from a genuinely empty one, no result cache, and left a stale control
 * bound when a one-page checkout re-rendered. These tests pin all of that.
 *
 * The distinction the whole ticket turns on: a timeout, a network error and a
 * degraded HTTP 200 are FAILURES the buyer must read as "the search is down",
 * while an abort is the buyer typing on and must be silent. `searchCompanies()`
 * never rejects, so that distinction lives entirely in the shape it resolves —
 * `unavailable` vs `aborted` — and both halves are asserted here, plus what the
 * panel actually paints for each.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanySearchPanel, installAsyncSimulation } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const COMPONENT_PATH = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY_PATH = 'view/frontend/web/js/model/company-identity.js';

const GLOBALS = { document: document, window: window };
const MOUNT_SELECTOR = '#two_gateway_form input#company_name';
const PANEL = '.two-company-dropdown';
const QUERY = '.two-company-dropdown__query';
const RESULTS = '.two-company-dropdown__results';
const MESSAGE = '.two-company-dropdown__message';
const ROW = '.two-company-dropdown__row';
const SPINNER_ACTIVE = 'two-company-dropdown__spinner--active';

const UNAVAILABLE_COPY = 'Company search is unavailable right now. Please try again shortly.';
/** TWO-25326: "the search is down" must not read like "your company is not here". */
const UNAVAILABLE_MODIFIER = 'two-company-dropdown__message--unavailable';

const BASE_CONFIG = {
    checkoutApiUrl: 'https://api.example.test',
    companySearchLimit: 50,
    isCompanySearchEnabled: true,
    isAddressSearchEnabled: true,
    orderIntentConfig: {
        extensionPlatformName: 'magento2',
        extensionDBVersion: '1.0.0'
    }
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
 * `$.ajax` replaced with jqXHRs the test settles by hand, so each outcome
 * (done / timeout / error / abort) is driven explicitly rather than inferred.
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

function loadCompanySearch() {
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    companySearch.clearResultCache();
    return companySearch;
}

/**
 * One search, with the country the panel would supply.
 *
 * @param {object} companySearch
 * @param {string} term
 * @param {object} [token] bind identity
 * @returns {Promise<{items: Array, unavailable: boolean, aborted: boolean}>}
 */
function search(companySearch, term, token) {
    return companySearch.searchCompanies({
        config: BASE_CONFIG,
        token: token || {},
        term: term,
        getCountryCode: function () { return 'gb'; }
    });
}

/** Wait one macrotask, so a cache hit's deferred resolution runs. */
function nextTick() {
    return new Promise(function (resolve) { setTimeout(resolve, 1); });
}

describe('request envelope', () => {
    let requests;

    beforeEach(() => {
        requests = installAjaxDouble();
    });

    test('search carries a 30s timeout, and the debounce the panel applies is 300ms', () => {
        const companySearch = loadCompanySearch();

        search(companySearch, 'exa');

        // 30s deliberately clears the server's stop_after_delay(10) retry
        // envelope — the client must not give up while the API is still
        // retrying.
        expect(requests[0].options.timeout).toBe(30000);
        expect(companySearch.REQUEST_TIMEOUT_MS).toBe(30000);

        // 300ms is the value shared with the WooCommerce and PrestaShop pickers.
        expect(companySearch.SEARCH_DEBOUNCE_MS).toBe(300);
    });

    test('the company-detail lookup carries the same timeout', () => {
        const companySearch = loadCompanySearch();

        companySearch.lookupCompanyAddress(BASE_CONFIG, { lookupId: 'lookup-abc-123' });

        expect(requests).toHaveLength(1);
        expect(requests[0].options.timeout).toBe(30000);
    });

    // Both unauthenticated-from-browser endpoints must identify the calling
    // plugin the same way gateway_method.js's order_intent call does, so the
    // API can skip a CORS preflight per keystroke.
    test.each([
        [
            'company search',
            'search url',
            (companySearch) => { search(companySearch, 'exa'); }
        ],
        [
            'address lookup by id',
            'lookup url',
            (companySearch) => {
                companySearch.lookupCompanyAddress(BASE_CONFIG, { lookupId: 'lookup-abc-123' });
            }
        ]
    ])('%s carries client/client_v (%s)', (_label, _desc, issue) => {
        issue(loadCompanySearch());
        const params = new URLSearchParams(requests[0].options.url.split('?')[1]);

        expect(params.get('client')).toBe('magento2');
        expect(params.get('client_v')).toBe('1.0.0');
    });

    test('the search url carries the country, limit and term', () => {
        search(loadCompanySearch(), 'exa');
        const params = new URLSearchParams(requests[0].options.url.split('?')[1]);

        expect(params.get('country')).toBe('GB');
        expect(params.get('limit')).toBe('50');
        expect(params.get('q')).toBe('exa');
    });
});

describe('failure is not "no companies found"', () => {
    let requests;

    beforeEach(() => {
        requests = installAjaxDouble();
    });

    /**
     * The crux of the whole ticket. A search that failed and a search that
     * genuinely matched nothing both come back with zero rows, so the only
     * thing separating "the search is down" from "your company is not here" is
     * the `unavailable` flag. An abort — the buyer typing on, or the panel
     * being torn down — is neither, and must stay silent.
     */
    test.each([
        ['timeout', true, false, 'a hung backend the buyer must be told about'],
        ['error', true, false, 'a network failure, same treatment'],
        ['abort', false, true, 'the buyer typing on — silent by design']
    ])(
        '%p resolves unavailable=%p aborted=%p (%s)',
        async (textStatus, unavailable, aborted) => {
            const companySearch = loadCompanySearch();
            const result = search(companySearch, 'exa');
            requests[0].settleFail(textStatus);

            await expect(result).resolves.toEqual({
                items: [],
                unavailable: unavailable,
                aborted: aborted
            });
        }
    );

    test('a healthy response resolves the mapped rows and raises nothing', async () => {
        const companySearch = loadCompanySearch();
        const result = search(companySearch, 'exa');
        requests[0].settleDone(SEARCH_RESPONSE);

        const settled = await result;
        expect(settled.unavailable).toBe(false);
        expect(settled.aborted).toBe(false);
        expect(settled.items.map((item) => item.text)).toEqual(['Example Trading Ltd']);
    });

    test('abortActiveRequest aborts the underlying request and reports whether it had one', () => {
        const companySearch = loadCompanySearch();
        const token = {};

        expect(companySearch.abortActiveRequest(token)).toBe(false);

        search(companySearch, 'exa', token);

        expect(companySearch.abortActiveRequest(token)).toBe(true);
        expect(requests[0].aborted).toBe(true);
        // Deregistered, so a second call is a no-op rather than a double abort.
        expect(companySearch.abortActiveRequest(token)).toBe(false);
    });

    test("one bind's abort cannot cancel another bind's search", () => {
        const companySearch = loadCompanySearch();
        const liveToken = {};

        search(companySearch, 'exa', liveToken);

        expect(companySearch.abortActiveRequest({})).toBe(false);
        expect(requests[0].aborted).toBe(false);
    });
});

describe('degraded flag', () => {
    let requests;

    beforeEach(() => {
        requests = installAjaxDouble();
    });

    test('degraded: true is unavailable on an HTTP 200, with whatever came back', async () => {
        const companySearch = loadCompanySearch();
        const result = search(companySearch, 'exa');
        requests[0].settleDone(Object.assign({ degraded: true }, SEARCH_RESPONSE));

        const settled = await result;
        expect(settled.unavailable).toBe(true);
        expect(settled.aborted).toBe(false);
        expect(settled.items).toHaveLength(1);
    });

    test('an absent degraded field means not degraded', async () => {
        const companySearch = loadCompanySearch();
        const result = search(companySearch, 'exa');
        // The API field may not be deployed yet, so today's payload shape
        // must keep working untouched.
        requests[0].settleDone(SEARCH_RESPONSE);

        await expect(result).resolves.toMatchObject({ unavailable: false });
    });

    test.each([
        [{ degraded: true }, true, 'a real boolean true'],
        [{ degraded: false }, false, 'a real boolean false'],
        [{}, false, 'the field absent'],
        [null, false, 'no response at all'],
        [undefined, false, 'undefined'],
        [{ degraded: 'false' }, false, 'a truthy string'],
        [{ degraded: 1 }, false, 'a truthy number']
    ])('isDegradedResponse(%p) is %p (%s)', (response, expected) => {
        expect(loadCompanySearch().isDegradedResponse(response)).toBe(expected);
    });

    test('a degraded response is never cached', async () => {
        const companySearch = loadCompanySearch();
        search(companySearch, 'exa');
        requests[0].settleDone({ items: [], degraded: true });

        // Caching a transient upstream failure would pin the buyer to an
        // empty result set for the rest of the session.
        search(companySearch, 'exa');
        await nextTick();

        expect(requests).toHaveLength(2);
    });
});

describe('result cache', () => {
    let requests;

    beforeEach(() => {
        requests = installAjaxDouble();
    });

    test('a repeated search is answered without a second request', async () => {
        const companySearch = loadCompanySearch();
        search(companySearch, 'exa');
        requests[0].settleDone(SEARCH_RESPONSE);

        const second = await search(companySearch, 'exa');

        expect(requests).toHaveLength(1);
        expect(second.items.map((item) => item.text)).toEqual(['Example Trading Ltd']);
        expect(second.unavailable).toBe(false);
    });

    test('the cache is keyed by url, so a different query still fetches', async () => {
        const companySearch = loadCompanySearch();
        search(companySearch, 'exa');
        requests[0].settleDone(SEARCH_RESPONSE);

        search(companySearch, 'exam');
        await nextTick();

        expect(requests).toHaveLength(2);
    });

    test('the cache outlives the panel that filled it', async () => {
        // A one-page checkout re-render rebuilds the panel. The cache is
        // module-scoped precisely so the buyer doesn't pay for the same search
        // twice.
        const companySearch = loadCompanySearch();
        search(companySearch, 'exa', {});
        requests[0].settleDone(SEARCH_RESPONSE);

        const second = await search(companySearch, 'exa', {});

        expect(requests).toHaveLength(1);
        expect(second.items).toHaveLength(1);
    });

    test('clearResultCache forces a refetch', async () => {
        const companySearch = loadCompanySearch();
        search(companySearch, 'exa');
        requests[0].settleDone(SEARCH_RESPONSE);

        companySearch.clearResultCache();
        search(companySearch, 'exa');
        await nextTick();

        expect(requests).toHaveLength(2);
    });
});

describe('result mapping robustness', () => {
    let requests;

    beforeEach(() => {
        requests = installAjaxDouble();
    });

    /**
     * @param {object} response
     * @returns {Promise<Array>} the mapped rows
     */
    async function map(response) {
        const companySearch = loadCompanySearch();
        const result = search(companySearch, 'exa');
        requests[requests.length - 1].settleDone(response);
        return (await result).items;
    }

    test.each([
        [{}, 'no items key at all'],
        [{ items: [] }, 'an empty list'],
        [{ degraded: true }, 'a degraded payload with nothing in it']
    ])('%p yields no rows instead of throwing (%s)', async (response) => {
        await expect(map(response)).resolves.toEqual([]);
    });

    // `national_identifier` is optional in the search response and its `id`
    // may be null or empty, so every one of these shapes is reachable. A throw
    // here would take the whole result list down; the hit renders with
    // whatever it has instead.
    //
    // `toEqual` treats `lookupId: undefined` as equal to the key being ABSENT,
    // so on its own it let an implementation that dropped `lookupId`
    // altogether pass. The key set is asserted explicitly instead, and the
    // final row carries a real `lookup_id`: address autofill is the one thing
    // that still works for an identifier-less hit (lookupCompanyAddress() keys
    // on `lookupId`, not on the national identifier).
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
    ])('%s renders the company without an identifier suffix', async (_label, item, lookupId) => {
        const rows = await map({ items: [item] });

        expect(rows).toEqual([
            {
                id: 'Example Trading Ltd',
                text: 'Example Trading Ltd',
                html: '<em>Example</em> Trading Ltd',
                companyId: '',
                lookupId: lookupId
            }
        ]);
        expect(Object.keys(rows[0]).sort()).toEqual([
            'companyId',
            'html',
            'id',
            'lookupId',
            'text'
        ]);
        expect(rows[0].lookupId).toBe(lookupId);
    });

    test('one unusable hit does not take the rest of the result list down', async () => {
        // The point of the guard: one hit with no identifier must not cost the
        // buyer every other company that matched.
        const rows = await map({
            items: [
                { name: 'Other Example Ltd', highlight: '<em>Other</em> Example Ltd' },
                SEARCH_RESPONSE.items[0]
            ]
        });

        expect(rows.map((row) => row.text)).toEqual(['Other Example Ltd', 'Example Trading Ltd']);
        expect(rows.map((row) => row.companyId)).toEqual(['', '12345678']);
    });
});

/* ------------------------------------------------------------------ *
 * What the buyer actually reads. The resolve shape above only matters
 * because the panel paints it, so these drive the REAL panel over real
 * jsdom nodes with a search whose outcome each case dictates.
 * ------------------------------------------------------------------ */
describe('what the panel paints for each outcome', () => {
    let resolvers;
    let panel;

    beforeEach(() => {
        document.body.innerHTML = '<div class="control"><input id="company_name" type="text"></div>';
        resolvers = [];
        const companySearch = loadCompanySearch();
        companySearch.SEARCH_DEBOUNCE_MS = 0;
        companySearch.searchCompanies = function () {
            return new Promise(function (resolve) { resolvers.push(resolve); });
        };

        const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
        panel = new CompanySearchPanel({ fieldSelector: '#company_name', config: BASE_CONFIG });
        panel.bind();
        panel.open();
    });

    afterEach(() => {
        panel.destroy();
    });

    /**
     * Type a term and let the debounce reach the (deferred) search.
     *
     * @param {string} term
     * @returns {Promise}
     */
    function type(term) {
        $(QUERY).val(term).trigger('input');
        return nextTick();
    }

    function messageText() {
        const node = document.querySelector(MESSAGE);
        return node ? node.textContent : null;
    }

    test('a degraded or failed search reads as unavailable, not as no matches', async () => {
        await type('exa');
        resolvers[0]({ items: [], unavailable: true, aborted: false });
        await nextTick();

        expect(messageText()).toBe(UNAVAILABLE_COPY);
        expect(messageText()).not.toBe('No matches found');
    });

    test('a genuinely empty result reads as no matches', async () => {
        await type('exa');
        resolvers[0]({ items: [], unavailable: false, aborted: false });
        await nextTick();

        expect(messageText()).toBe('No matches found');
    });

    test('an aborted request paints nothing at all', async () => {
        await type('exa');
        const before = document.querySelector(RESULTS).innerHTML;

        resolvers[0]({ items: [], unavailable: false, aborted: true });
        await nextTick();

        // Neither a message nor rows: the buyer has typed on, and whatever the
        // next search says is what they should read.
        expect(document.querySelector(RESULTS).innerHTML).toBe(before);
        expect(document.querySelectorAll(ROW)).toHaveLength(0);
    });

    test('a superseded response cannot paint over the one that replaced it', async () => {
        await type('exa');
        await type('exam');

        // The FIRST search answers last, which is what an out-of-order network
        // does. It must not repaint a term the buyer has typed past.
        resolvers[1]({ items: [{ text: 'Second', html: 'Second' }], unavailable: false, aborted: false });
        await nextTick();
        resolvers[0]({ items: [{ text: 'First', html: 'First' }], unavailable: false, aborted: false });
        await nextTick();

        expect(Array.from(document.querySelectorAll(ROW)).map((row) => row.textContent))
            .toEqual(['Second']);
    });

    test.each([
        [
            'the search being down',
            async () => {
                await type('exa');
                resolvers[0]({ items: [], unavailable: true, aborted: false });
                await nextTick();
            },
            true
        ],
        [
            'a genuinely empty result',
            async () => {
                await type('exa');
                resolvers[0]({ items: [], unavailable: false, aborted: false });
                await nextTick();
            },
            false
        ],
        [
            'a term below the threshold',
            async () => { await type('ex'); },
            false
        ]
    ])('%s -> the message carries the unavailable modifier: %p', async (_label, drive, modified) => {
        await drive();

        expect(document.querySelector(MESSAGE).classList.contains(UNAVAILABLE_MODIFIER))
            .toBe(modified);
    });

    test("a row renders the API's match highlighting, not the plain text", async () => {
        await type('exa');
        resolvers[0]({
            items: [{ text: 'Example Trading Ltd', html: '<em>Exa</em>mple Trading Ltd' }],
            unavailable: false,
            aborted: false
        });
        await nextTick();

        const row = document.querySelector(ROW);
        expect(row.querySelector('em')).not.toBeNull();
        expect(row.querySelector('em').textContent).toBe('Exa');
        expect(row.textContent).toBe('Example Trading Ltd');
    });

    test('a cached answer takes down a spinner an abort left up', async () => {
        await type('exa');
        resolvers[0]({ items: [], unavailable: false, aborted: true });
        await nextTick();
        const spinner = document.querySelector('.two-company-dropdown__spinner');
        expect(spinner.classList.contains(SPINNER_ACTIVE)).toBe(true);

        await type('exam');
        resolvers[1]({ items: [], unavailable: false, aborted: false });
        await nextTick();

        expect(spinner.classList.contains(SPINNER_ACTIVE)).toBe(false);
    });
});

describe('dropping below the minimum input length', () => {
    let requests;
    let panel;
    let companySearch;

    beforeEach(() => {
        document.body.innerHTML = '<div class="control"><input id="company_name" type="text"></div>';
        requests = installAjaxDouble();
        companySearch = loadCompanySearch();
        companySearch.SEARCH_DEBOUNCE_MS = 0;

        const CompanySearchPanel = loadCompanySearchPanel($, companySearch, GLOBALS);
        panel = new CompanySearchPanel({ fieldSelector: '#company_name', config: BASE_CONFIG });
        panel.bind();
        panel.open();
    });

    afterEach(() => {
        panel.destroy();
    });

    test('it cancels the request already on the wire', async () => {
        $(QUERY).val('exa').trigger('input');
        await nextTick();
        expect(requests).toHaveLength(1);

        $(QUERY).val('ex').trigger('input');

        // Left running, the request resolves up to 30s later and repaints
        // results for a term the buyer has backspaced away from.
        expect(requests[0].aborted).toBe(true);
    });

    test('it drops a debounced search that has not fired yet', async () => {
        companySearch.SEARCH_DEBOUNCE_MS = 50;
        $(QUERY).val('exa').trigger('input');

        $(QUERY).val('ex').trigger('input');
        await new Promise((resolve) => setTimeout(resolve, 80));

        expect(requests).toHaveLength(0);
    });

    test('it restores the hint rather than leaving the last search on screen', async () => {
        $(QUERY).val('exa').trigger('input');
        await nextTick();
        requests[0].settleDone(SEARCH_RESPONSE);
        await nextTick();
        expect(document.querySelectorAll(ROW)).toHaveLength(1);

        $(QUERY).val('ex').trigger('input');

        expect(document.querySelector(MESSAGE).textContent)
            .toBe(companySearch.minInputLengthMessage());
    });
});

/* ------------------------------------------------------------------ *
 * Re-render safety, driven through the page-level mount, because "the live
 * panel painted, the stale one did not" is only meaningful when both are real
 * DOM subtrees.
 * ------------------------------------------------------------------ */

function mount() {
    const identity = loadAmdModule(IDENTITY_PATH, {}, GLOBALS);
    const companySearch = loadCompanySearch();
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
            'Two_Gateway/js/model/company-search-panel': loadCompanySearchPanel(
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
    return { component: component, identity: identity, companySearch: companySearch };
}

/** Replace the mounted field, as a one-page checkout's re-render does. */
function reRender() {
    document.querySelector('.control').innerHTML =
        '<input id="company_name" name="company_name" />';
    $.async.fireAll();
}

describe('re-render safety of the panel binding', () => {
    let mounted;

    beforeEach(() => {
        document.body.innerHTML =
            '<form id="two_gateway_form"><div class="field"><div class="control">' +
            '<input id="company_name" name="company_name" />' +
            '</div></div></form>';
        $(document).off('.twoCompanyCapture');
        installAsyncSimulation($);
        $.async.reset();
        installAjaxDouble();
        mounted = mount();
    });

    test('a re-render re-points the one panel rather than building a second', () => {
        reRender();

        expect(document.querySelectorAll(PANEL)).toHaveLength(1);
        expect(document.querySelectorAll(QUERY)).toHaveLength(1);
        expect(mounted.component._panel.getField()[0])
            .toBe(document.querySelector(MOUNT_SELECTOR));
    });

    test('re-pointing the mount when nothing moved does not rebuild the panel', () => {
        const panelNode = document.querySelector(PANEL);

        mounted.component.refreshMount();
        mounted.component.refreshMount();

        expect(document.querySelector(PANEL)).toBe(panelNode);
    });

    /**
     * Handlers bound outside the panel's own namespace would stack one copy per
     * re-render, and a single company pick would then fire N address lookups,
     * N-1 of them closed over superseded binds.
     */
    test('a re-render does not stack duplicate selection handlers', async () => {
        mounted.companySearch.lookupCompanyAddress = jest.fn();
        reRender();
        reRender();

        mounted.component._panel._renderResults([
            { text: 'Example Trading Ltd', html: 'Example Trading Ltd', companyId: '12345678' }
        ]);
        $(ROW).first().trigger('mousedown');

        expect(mounted.companySearch.lookupCompanyAddress).toHaveBeenCalledTimes(1);
    });

    test('a repeated teardown reports that there was nothing left to cancel', () => {
        const panel = mounted.component._panel;
        panel._token = {};

        expect(panel.abortActiveRequest()).toBe(false);
        expect(panel.abortActiveRequest()).toBe(false);
    });

    test('a search issued by the replaced node cannot paint on the panel that replaced it', async () => {
        const panel = mounted.component._panel;
        const staleToken = panel.getBindToken();
        panel.open();

        reRender();
        expect(panel.getBindToken()).not.toBe(staleToken);

        // The stale bind's own abort must not reach the live request, and its
        // response has nothing left listening for it.
        expect(mounted.companySearch.abortActiveRequest(staleToken)).toBe(false);
        expect(document.querySelectorAll(ROW)).toHaveLength(0);
    });

    test('the live panel DOES paint', () => {
        // Guards against "fails closed" degenerating into "never works".
        const panel = mounted.component._panel;
        panel.open();

        panel._renderResults([{ text: 'Example Trading Ltd', html: 'Example Trading Ltd' }]);

        expect(document.querySelectorAll(ROW)).toHaveLength(1);
    });
});
