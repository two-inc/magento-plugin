/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * A 429 from the plugin's own routes arrives as a raw Magento webapi fault,
 * not the `{ok,status,body}` envelope every other answer uses — the ceiling is
 * enforced before the route runs. Read as an ordinary failure it would decline
 * the buyer and let the next keystroke walk straight back into the limit.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks, proxyEnvelope, tagged } = require('./amd-harness');

const MODEL_PATH = 'view/frontend/web/js/model/company-search.js';
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const GLOBALS = { document: document, window: window };

// The module's own last-resort copy, and a server message deliberately UNLIKE
// it so a test cannot pass by falling back instead of reading the response.
const RATE_LIMIT_FALLBACK_COPY = 'Too many requests. Please wait a moment and try again.';
const RATE_LIMIT_SERVER_COPY = 'Slow down: retry in 42 seconds.';

const IDENTITY_PATH = 'view/frontend/web/js/model/company-identity.js';

/** Mirrors RATE_LIMIT_BACKOFF_MS in company-search.js, which is module-private. */
const BACKOFF_MS = 60000;

/** `$.ajax` double whose failures carry a real HTTP status. */
function installAjaxDouble() {
    const requests = [];
    $.ajax = function (options) {
        const bound = { done: [], fail: [], always: [] };
        const jqxhr = {
            options: options,
            done: function (fn) { bound.done.push(fn); return jqxhr; },
            fail: function (fn) { bound.fail.push(fn); return jqxhr; },
            always: function (fn) { bound.always.push(fn); return jqxhr; },
            abort: function () {},
            settleDone: function (body) {
                bound.done.forEach(function (fn) { fn(proxyEnvelope(body)); });
                bound.always.forEach(function (fn) { fn(); });
            },
            settleFail: function (status, responseJSON) {
                bound.fail.forEach(function (fn) {
                    fn({ status: status, responseJSON: responseJSON }, 'error');
                });
                bound.always.forEach(function (fn) { fn(); });
            }
        };
        requests.push(jqxhr);
        return jqxhr;
    };
    return requests;
}

/**
 * One capture panel's rate-limit scope, identity-shaped because
 * `lookupCompanyAddress()` reaches the same object for the buyer's notice.
 *
 * @returns {object}
 */
function panelScope() {
    const notices = [];
    return {
        notices: notices,
        addressNotice: function (text) { notices.push(text); },
        // The panel's own form. `lookupCompanyAddress()` requires one and keys
        // its in-flight guard by it, so each panel needs its own.
        form: { length: 1 }
    };
}

function search(companySearch, term, scope) {
    return companySearch.searchCompanies({
        token: {},
        scope: scope,
        term: term,
        getCountryCode: function () { return 'gb'; }
    });
}

/**
 * The two registry routes, as issuers a rate-limit spec can drive
 * interchangeably. `callId` keeps each search off the previous one's cache
 * entry, which would answer without a request and prove nothing.
 */
const ROUTES = {
    search: function (companySearch, callId, scope) {
        search(companySearch, `exa${callId}`, scope);
    },
    'address lookup': function (companySearch, callId, scope) {
        companySearch.lookupCompanyAddress(
            { isAddressSearchEnabled: true },
            { lookupId: `lookup-${callId}` },
            scope.form,
            scope
        );
    }
};

/** One panel's own form, for the calls that only ever drive a single panel. */
const PANEL_FORM = { length: 1 };

function loadCompanySearch() {
    const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
    companySearch.clearResultCache();
    return companySearch;
}

describe('company search backs off rather than retrying into the ceiling', () => {
    test('a search naming no scope is refused rather than left with no backoff', async () => {
        // Falling back to the bind token scoped the ceiling to something a
        // re-render replaces, so every re-render walked straight back into it.
        const requests = installAjaxDouble();
        const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
        companySearch.clearResultCache();

        await expect(search(companySearch, 'exa', undefined)).resolves.toEqual({
            items: [],
            unavailable: true,
            aborted: false
        });
        expect(requests).toHaveLength(0);
    });

    test.each([
        [429, 1, 'a refused search parks the next keystroke'],
        [500, 2, 'an ordinary failure is retried on the next keystroke']
    ])('after a %i, %i request(s) are issued in total (%s)', async (status, expected) => {
        const requests = installAjaxDouble();
        const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
        companySearch.clearResultCache();
        const panel = panelScope();

        const first = search(companySearch, 'exa', panel);
        requests[0].settleFail(status);
        await first;

        const second = search(companySearch, 'exam', panel);
        if (requests[1]) requests[1].settleFail(status);

        await expect(second).resolves.toEqual({ items: [], unavailable: true, aborted: false });
        expect(requests).toHaveLength(expected);
    });

    // The ceiling is per-merchant AND per-route within one panel: it is
    // enforced before either route runs, so a 429 earned on one has to park
    // the other too — for the panel that earned it.
    test.each([
        ['search', 'search'],
        ['search', 'address lookup'],
        ['address lookup', 'search'],
        ['address lookup', 'address lookup']
    ])('a 429 on the %s route parks the same panel\'s next %s', (first, second) => {
        const requests = installAjaxDouble();
        const companySearch = loadCompanySearch();
        const panel = panelScope();

        ROUTES[first](companySearch, 1, panel);
        requests[0].settleFail(429);
        ROUTES[second](companySearch, 2, panel);

        expect(requests).toHaveLength(1);
    });

    // TWO-25554: the park is the PANEL's, not the module's. One shared counter
    // let a 429 raised by the billing panel silence the shipping panel's own
    // searching and put an "address unavailable" notice on its identity.
    test.each([
        ['search', 'search'],
        ['search', 'address lookup'],
        ['address lookup', 'search'],
        ['address lookup', 'address lookup']
    ])('a 429 on one panel\'s %s does not park the other panel\'s %s', (first, second) => {
        const requests = installAjaxDouble();
        const companySearch = loadCompanySearch();
        const parked = panelScope();
        const other = panelScope();

        ROUTES[first](companySearch, 1, parked);
        requests[0].settleFail(429);
        ROUTES[second](companySearch, 2, other);

        expect(requests).toHaveLength(2);
        expect(other.notices.filter(function (n) { return n; })).toEqual([]);
    });

    test.each([
        ['search'],
        ['address lookup']
    ])('an ordinary %s failure parks nothing', (route) => {
        const requests = installAjaxDouble();
        const companySearch = loadCompanySearch();
        const panel = panelScope();

        ROUTES[route](companySearch, 1, panel);
        requests[0].settleFail(500);
        ROUTES[route](companySearch, 2, panel);

        expect(requests).toHaveLength(2);
    });

    test('the park lifts once the backoff window has passed', async () => {
        const requests = installAjaxDouble();
        // The module runs in its own vm realm, so its `Date` is a distinct
        // intrinsic that a spy on the test realm's would never reach.
        const clock = { now: 1000000 };
        const ClockDate = Object.assign(
            function () { return new Date(); },
            { now: function () { return clock.now; } }
        );
        const companySearch = loadAmdModule(
            MODEL_PATH,
            { jquery: $ },
            Object.assign({}, GLOBALS, { Date: ClockDate })
        );
        companySearch.clearResultCache();
        const panel = panelScope();

        const first = search(companySearch, 'exa', panel);
        requests[0].settleFail(429);
        await first;

        clock.now += BACKOFF_MS - 1;
        await search(companySearch, 'exam', panel);
        expect(requests).toHaveLength(1);

        clock.now += 1;
        const third = search(companySearch, 'examp', panel);
        expect(requests).toHaveLength(2);
        requests[1].settleDone({ items: [] });
        await third;
    });

    test('a cached term still answers while the park is up', async () => {
        const requests = installAjaxDouble();
        const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
        companySearch.clearResultCache();

        const panel = panelScope();
        const cached = search(companySearch, 'acme', panel);
        requests[0].settleDone({ items: [{ name: 'Acme Widgets Ltd', national_identifier: { id: '12345678' } }] });
        await cached;

        const refused = search(companySearch, 'other', panel);
        requests[1].settleFail(429);
        await refused;

        const replay = await search(companySearch, 'acme', panel);
        expect(requests).toHaveLength(2);
        expect(replay.unavailable).toBe(false);
        expect(replay.items).toHaveLength(1);
    });
});

/**
 * The parked-lookup notice fires synchronously on the pick, and the slower
 * credit check answers after it — so nothing the order-intent verdict clears
 * may reach the panel's own notice, which the buyer still has to act on.
 */
describe('a parked address lookup keeps its notice through the intent verdict', () => {
    function wire() {
        const identity = loadAmdModule(IDENTITY_PATH, {}, GLOBALS)();
        const companySearch = loadAmdModule(MODEL_PATH, { jquery: $ }, GLOBALS);
        companySearch.clearResultCache();

        const component = loadAmdModule(
            RENDERER,
            Object.assign(defaultMocks(), {
                'Two_Gateway/js/model/company-capture': {
                    identity: identity,
                    shipping: {
                        identity: function () { return identity; },
                        subscribeMount: function () {}
                    },
                    refreshMount: function () {}
                }
            })
        );
        const ko = defaultMocks().ko;
        const tile = Object.assign({}, component, {
            companyName: ko.observable(''),
            companyId: ko.observable(''),
            generalErrorMessage: 'Something went wrong with your order.'
        });
        tile.showErrorMessage = function () {};
        component.initOrderIntentApprovedNotice.call(tile, {});

        return { identity, companySearch, tile };
    }

    test.each([
        [function (tile) { tile.processOrderIntentSuccessResponse.call(tile, { approved: true }); }, 'approved'],
        [function (tile) { tile.processOrderIntentSuccessResponse.call(tile, { approved: false }); }, 'declined'],
        [function (tile) { tile.processOrderIntentErrorResponse.call(tile, { status: 500 }); }, 'errored']
    ])('an intent verdict leaves it standing', (settleVerdict, description) => {
        const requests = installAjaxDouble();
        const { identity, companySearch, tile } = wire();

        companySearch.lookupCompanyAddress(
            { isAddressSearchEnabled: true }, { lookupId: 'lookup-1' }, PANEL_FORM, identity
        );
        requests[0].settleFail(429);

        // The park is now up, so this second pick never issues a request and
        // announces immediately.
        companySearch.lookupCompanyAddress(
            { isAddressSearchEnabled: true }, { lookupId: 'lookup-2' }, PANEL_FORM, identity
        );
        expect(identity.addressNotice()).toContain('enter it below');

        const standing = identity.addressNotice();

        settleVerdict(tile);

        expect(tagged(description, identity.addressNotice())).toEqual(tagged(description, standing));
    });
});

describe('the order-intent tile tells a rate-limited buyer to wait', () => {
    function renderer() {
        const notices = [];
        const component = loadAmdModule(RENDERER, defaultMocks());

        return Object.assign({}, component, {
            notices: notices,
            generalErrorMessage: 'Something went wrong with your order.',
            clearOrderIntentNotices: function () {},
            showOrderIntentErrorNotice: function (message) { notices.push(message); }
        });
    }

    test('the refusal message is shown, not a decline', () => {
        const ctx = renderer();

        ctx.processOrderIntentErrorResponse.call(ctx, {
            status: 429,
            responseJSON: { message: RATE_LIMIT_SERVER_COPY }
        });

        expect(ctx.notices).toEqual([RATE_LIMIT_SERVER_COPY]);
    });

    // A webapi fault carries no `error_code`, so without the status branch this
    // lands on the generic decline the switch below falls through to.
    test('a fault with no body still reads as a wait, not a decline', () => {
        const ctx = renderer();

        ctx.processOrderIntentErrorResponse.call(ctx, { status: 429 });

        expect(ctx.notices).toEqual([RATE_LIMIT_FALLBACK_COPY]);
    });

    test('the plugin\'s own in-envelope refusal renders its message', () => {
        const ctx = renderer();

        ctx.processOrderIntentErrorResponse.call(ctx, {
            responseJSON: {
                error_code: 'PROXY_REFUSED',
                error_message: 'The payment integration is not available right now.'
            }
        });

        expect(ctx.notices).toEqual(['The payment integration is not available right now.']);
    });
});
