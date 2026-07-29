/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Shared company-search primitives for the two select2 company pickers
 * in checkout: the shipping-step one (`view/address-autocomplete.js`,
 * a Magento_Ui form Component) and the payment-step one
 * (`view/payment/method-renderer/gateway_method.js`, a payment
 * renderer).
 *
 * Only the genuinely identical parts live here — the search request,
 * the result mapping (including `lookup_id`, whose omission on the
 * payment step was TWO-25193), the company-detail fetch, the address
 * write-back and the in-field searching / unavailable chrome.
 * Everything else about the two pickers differs (selectors,
 * placeholder/manual-entry chrome, KO observables vs customerData,
 * order-intent side effects) and deliberately stays in the two call
 * sites.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    /**
     * Client-side ceiling for every company-search / company-detail
     * request. 30s deliberately sits OUTSIDE the server's retry envelope
     * (`stop_after_delay(10)`), so the client never abandons a request the
     * API is still legitimately retrying — but it does put a bound on the
     * browser default of "hang until the socket dies".
     */
    const REQUEST_TIMEOUT_MS = 30000;

    /**
     * Keystroke debounce. 300ms is the value shared with the WooCommerce
     * and PrestaShop company pickers — keep the three aligned.
     */
    const SEARCH_DEBOUNCE_MS = 300;

    /**
     * Search-result cache. MODULE-scoped on purpose: one-page checkouts
     * (Fire Checkout) re-render the payment renderer on every totals or
     * shipping change, which destroys and rebuilds the select2 widget. A
     * cache owned by the widget would be thrown away each time and every
     * search the buyer already waited for would be re-issued. Keyed by the
     * fully-qualified request URL, so country / paging / limit are all part
     * of the key.
     *
     * Entries never expire within the page's lifetime, so a company
     * registered mid-session stays absent from an already-searched term
     * until the buyer reloads. That is deliberate, not a bug: buyers search
     * for their own company, which is already registered, so a TTL or
     * cache-busting would spend API calls on a case that essentially never
     * happens.
     */
    const resultCache = new Map();

    /** Bound on the cache so a long typing session can't grow it forever. */
    const CACHE_LIMIT = 50;

    /**
     * The in-flight request per bind token.
     *
     * Needed because select2 does NOT abort when the buyer drops below
     * `minimumInputLength`: its decorator's `query()` returns after firing
     * `results:message` WITHOUT delegating to the ajax adapter, and
     * `_request.abort()` lives inside that adapter. So the request stays on
     * the wire; 30s later it resolves and repaints results — or the
     * "unavailable" notice — for a term the buyer already abandoned.
     *
     * A WeakMap so a discarded bind token takes its entry with it.
     */
    const activeRequests = new WeakMap();

    /**
     * Mirrors the `minimumInputLength: 3` both call sites pass to select2.
     * Below it select2's decorator short-circuits `query()` and never reaches
     * the data adapter, so no transport runs — which the chrome has to know
     * about, or nothing ever takes the spinner down.
     */
    const MIN_INPUT_LENGTH = 3;

    /** jQuery event namespace for everything this module binds. */
    const EVENT_NS = '.twoCompanySearch';

    const SPINNER_CLASS = 'two-company-search__spinner';
    const UNAVAILABLE_CLASS = 'two-company-search__unavailable';

    /**
     * Does this response mean "the search backend could not answer
     * properly"? The API answers HTTP 200 with near-empty results when its
     * upstream provider timed out, and flags that with `degraded: true`.
     *
     * Read defensively: the field may not be deployed yet, and an absent or
     * non-boolean value must mean "not degraded" so today's healthy
     * responses keep working unchanged.
     *
     * @param {*} response parsed search response
     * @returns {boolean}
     */
    function isDegradedResponse(response) {
        return Boolean(response) && response.degraded === true;
    }

    function cacheGet(key) {
        return resultCache.has(key) ? resultCache.get(key) : null;
    }

    function cacheSet(key, value) {
        // Never cache a degraded answer — it is a transient upstream
        // failure, and caching it would pin the buyer to an empty result
        // set for the rest of the session.
        if (isDegradedResponse(value)) return;
        if (resultCache.size >= CACHE_LIMIT) {
            resultCache.delete(resultCache.keys().next().value);
        }
        resultCache.set(key, value);
    }

    return {
        REQUEST_TIMEOUT_MS: REQUEST_TIMEOUT_MS,
        SEARCH_DEBOUNCE_MS: SEARCH_DEBOUNCE_MS,
        MIN_INPUT_LENGTH: MIN_INPUT_LENGTH,
        EVENT_NS: EVENT_NS,
        isDegradedResponse: isDegradedResponse,

        /**
         * Cancel the in-flight search for a bind, if any.
         *
         * @param {object} token identity stamped by markSearchBinding()
         * @returns {boolean} true when a request was actually aborted
         */
        abortActiveRequest: function (token) {
            const handle = token && typeof token === 'object' ? activeRequests.get(token) : null;
            if (!handle) return false;
            activeRequests.delete(token);
            handle.abort();
            return true;
        },

        /** Drop every cached search result. Exists for tests. */
        clearResultCache: function () {
            resultCache.clear();
        },

        /**
         * Build the select2 `ajax` option block for the company search.
         *
         * @param {object} options
         * @param {object} options.config brand config subtree; needs
         *        `checkoutApiUrl` and `companySearchLimit`
         * @param {function(): (string|undefined)} options.getCountryCode
         *        returns the current ISO country code (any case)
         * @param {function(boolean)} [options.onSearching] called with true
         *        when a search starts and false when it settles, so the call
         *        site can show an in-field spinner
         * @param {function(boolean)} [options.onUnavailable] called with true
         *        when a search fails or comes back degraded, and false at the
         *        start of every fresh search
         * @returns {object} select2 `ajax` options
         */
        buildSearchAjaxOptions: function (options) {
            const config = options.config;
            const getCountryCode = options.getCountryCode;
            const onSearching = options.onSearching || function () {};
            const onUnavailable = options.onUnavailable || function () {};
            const token = options.token;

            return {
                dataType: 'json',
                delay: SEARCH_DEBOUNCE_MS,
                timeout: REQUEST_TIMEOUT_MS,
                url: function (params) {
                    const queryParams = new URLSearchParams({
                        country: getCountryCode()?.toUpperCase(),
                        limit: config.companySearchLimit,
                        offset: ((params.page || 1) - 1) * config.companySearchLimit,
                        q: unescape(params.term)
                    });
                    return `${config.checkoutApiUrl}/companies/v2/company?${queryParams.toString()}`;
                },
                /**
                 * select2's request layer, replaced so the search gets a
                 * cache, a timeout and a failure signal the buyer can see.
                 * select2 calls this instead of `$.ajax` and aborts the
                 * returned handle when the next keystroke supersedes this
                 * search.
                 *
                 * @param {object} params merged $.ajax settings from select2
                 * @param {function} success select2's result handler
                 * @param {function} failure select2's failure handler
                 * @returns {{abort: function}} abortable request handle
                 */
                transport: function (params, success, failure) {
                    onUnavailable(false);

                    const cached = cacheGet(params.url);
                    if (cached) {
                        // Answer from cache. Deferred a tick rather than
                        // called inline so select2 always sees the same
                        // async shape it does for a real request, and so an
                        // abort can still win.
                        let aborted = false;
                        const timer = setTimeout(function () {
                            if (aborted) return;
                            // The spinner survives an abort (see below), so a
                            // cache hit that follows one has to be what takes
                            // it down. Otherwise: type `abc`, type `abcd`
                            // (spinner up), backspace to `abc` — the abort
                            // keeps the spinner, the cache answers, and the
                            // dots spin forever over a full dropdown.
                            onSearching(false);
                            success(cached);
                        }, 0);
                        return {
                            abort: function () {
                                aborted = true;
                                clearTimeout(timer);
                            }
                        };
                    }

                    onSearching(true);
                    const request = $.ajax(params);
                    let wasAborted = false;

                    /**
                     * Our own request handle, deliberately NOT the jqXHR.
                     *
                     * select2's ajax adapter builds its failure closure as
                     * `function () { 'status' in e && (0 === e.status || '0'
                     * === e.status) || trigger('results:message', {message:
                     * 'errorLoading'}) }` where `e` is the value the
                     * TRANSPORT RETURNED — not the jqXHR it was handed. And
                     * jQuery reports BOTH a user abort and a timeout as
                     * `status === 0`, so returning the raw jqXHR makes the two
                     * indistinguishable: select2 silently swallows a timeout,
                     * never fires `results:message`, never reaches
                     * `hideLoading()`, and leaves "Searching…" in the dropdown
                     * forever — directly under our notice saying the search
                     * failed.
                     *
                     * Owning the handle lets us set `status = 0` for a real
                     * abort only. A genuine failure leaves `status` absent, so
                     * select2 renders `errorLoading` — and `displayMessage()`
                     * calls `hideLoading()`, which is the terminal state we
                     * actually want.
                     */
                    const handle = {
                        abort: function () {
                            request.abort();
                        }
                    };

                    request.done(function (response) {
                        cacheSet(params.url, response);
                        // A degraded 200 is a failure dressed as a success:
                        // near-empty results because the provider timed out.
                        // Surface it as "unavailable", not "no matches".
                        if (isDegradedResponse(response)) onUnavailable(true);
                        success(response);
                    });
                    request.fail(function (jqXHR, textStatus) {
                        // A genuine abort is the buyer typing on, or the
                        // widget being torn down — expected, and silent by
                        // design. A timeout is NOT an abort and must be
                        // visible, otherwise the buyer reads a hung backend
                        // as "my company isn't accepted here".
                        if (textStatus === 'abort') {
                            wasAborted = true;
                            handle.status = 0;
                            failure();
                            return;
                        }
                        onUnavailable(true);
                        failure();
                    });
                    // Guarded: WeakMap.set throws on a non-object key, and a
                    // crash here would take the whole picker down. Degrades to
                    // "no cancel-on-short-input" and says so, rather than
                    // failing the buyer's search. Checks the TYPE, not just
                    // truthiness — a string token is truthy and still throws.
                    if (token && typeof token === 'object') {
                        activeRequests.set(token, handle);
                    } else {
                        console.error(
                            'companySearch: buildSearchAjaxOptions called without a bind token'
                        );
                    }
                    request.always(function () {
                        if (token && activeRequests.get(token) === handle) {
                            activeRequests.delete(token);
                        }
                        // Not on abort: select2 aborts the in-flight request
                        // synchronously at the top of the next query(), 300ms
                        // before the replacement transport starts. Dropping
                        // the spinner there would make it blink off on every
                        // keystroke.
                        if (!wasAborted) onSearching(false);
                    });

                    return handle;
                },
                processResults: function (response) {
                    const items = [];
                    const responseItems = (response && response.items) || [];
                    for (let i = 0; i < responseItems.length; i++) {
                        const item = responseItems[i];
                        items.push({
                            id: item.name,
                            text: item.name,
                            html: `${item.highlight} (${item.national_identifier.id})`,
                            companyId: item.national_identifier.id,
                            // Required by lookupCompanyAddress(); dropping it
                            // silently disables address autofill.
                            lookupId: item.lookup_id
                        });
                    }
                    return {
                        results: items,
                        pagination: {
                            more: false
                        }
                    };
                },
                data: function () {
                    return {};
                }
            };
        },

        /**
         * Fetch the full company record for a search result and write its
         * first address into the checkout address form.
         *
         * No-op unless `config.isAddressSearchEnabled` is true. That flag is
         * server-side the single `enable_address_search` admin setting
         * (Model\Config\Repository::isAddressSearchEnabled), so both pickers
         * honour exactly one gate.
         *
         * @param {object} config brand config subtree
         * @param {object} selectedCompany select2 result item (needs lookupId)
         * @returns {object|null} the jqXHR, or null when gated off / no id
         */
        lookupCompanyAddress: function (config, selectedCompany) {
            if (!config.isAddressSearchEnabled) return null;
            if (!selectedCompany || !selectedCompany.lookupId) return null;

            const self = this;
            const addressResponse = $.ajax({
                dataType: 'json',
                timeout: REQUEST_TIMEOUT_MS,
                url: `${config.checkoutApiUrl}/companies/v2/company/${selectedCompany.lookupId}`
            });
            addressResponse.done(function (response) {
                if (response && response.addresses && response.addresses.length) {
                    self.applyAddress(response.addresses[0]);
                }
            });
            return addressResponse;
        },

        /**
         * Write a company address into whichever checkout address form is on
         * screen. The selectors are intentionally unscoped: the shipping step
         * renders `#shipping-new-address-form` and the payment step renders
         * `#billing-new-address-form`, and only one of them is present when a
         * company is picked from that step's search box.
         *
         * @param {object} address company address record from the API
         */
        applyAddress: function (address) {
            console.debug({ logger: 'companySearch.applyAddress', address });
            $('input[name="city"]').val(address.city);
            $('input[name="postcode"]').val(address.postal_code);
            $('input[name="street[0]"]').val(address.street_address);
            $('input[name="city"], input[name="postcode"], input[name="street[0]"]').trigger(
                'change'
            );
        },

        /**
         * Resolve the box that holds select2's typing input for a picker.
         *
         * For a single-select, select2 renders the search input inside the
         * dropdown (`.select2-search--dropdown`), not inside the closed
         * selection box — so that container IS the search field as far as
         * the buyer is concerned, and it is where the spinner and the
         * unavailable notice belong.
         *
         * Takes the BOUND ELEMENT plus the token stamped on it at bind time.
         *
         * The element alone is not enough. Both call sites re-init select2 on
         * the SAME node across a re-render, so `$field.data('select2')` always
         * resolves to the current instance — a request issued by the previous
         * widget, still in flight for up to 30s, would paint its failure onto
         * the live picker and its `onSearching(false)` would strip the live
         * spinner. The token is re-stamped on every bind, so a stale closure's
         * token no longer matches and it no-ops.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity stamped by markSearchBinding()
         * @returns {object} jQuery set — empty when stale or not bound
         */
        getSearchFieldContainer: function ($field, token) {
            if (!$field || !$field.data) return $();
            // Fails CLOSED on a missing token. An earlier revision guarded
            // with `if (token && ...)`, which meant any caller that forgot to
            // pass one silently bypassed the staleness check — which is
            // exactly how two call sites shipped with the guard inert.
            if ($field.data('twoSearchBind') !== token) return $();
            const instance = $field.data('select2');
            if (!instance || !instance.$dropdown) return $();
            return instance.$dropdown.find('.select2-search--dropdown');
        },

        /**
         * Stamp a bind identity on the picker and wire the chrome resets that
         * select2 gives us no other hook for. Call once, immediately after
         * `.select2({...})`.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity for this bind
         */
        markSearchBinding: function ($field, token) {
            const self = this;
            $field.data('twoSearchBind', token);

            const instance = $field.data('select2');
            if (!instance || !instance.$dropdown) return;

            // Below `minimumInputLength` select2's decorator returns after
            // firing `results:message` WITHOUT delegating to the ajax adapter,
            // and `_request.abort()` lives inside that adapter. So dropping
            // from three characters to two neither runs a new transport nor
            // cancels the running one: nothing clears the spinner, and 30s
            // later the abandoned request repaints results or the
            // "unavailable" notice under "Please enter 3 or more characters".
            // Cancel it ourselves, then clear the chrome.
            instance.$dropdown
                .find('.select2-search__field')
                .off('input' + EVENT_NS)
                .on('input' + EVENT_NS, function () {
                    if (this.value.length >= MIN_INPUT_LENGTH) return;
                    // Two things to cancel, not one. Besides a request already
                    // on the wire, select2's ajax adapter may be sitting on a
                    // DEBOUNCED one (`_queryTimeout`, our 300ms `delay`) that
                    // it has not fired yet — and because the
                    // minimumInputLength decorator short-circuits `query()`
                    // before reaching that adapter, it never clears the timer
                    // either. Backspacing from 4 characters to 2 inside 300ms
                    // (trivial on key-repeat) would otherwise fire a fresh
                    // search for the abandoned term, bringing the spinner back
                    // up under "Please enter 3 or more characters".
                    const instance = $field.data('select2');
                    const dataAdapter = instance && instance.dataAdapter;
                    if (dataAdapter && dataAdapter._queryTimeout) {
                        clearTimeout(dataAdapter._queryTimeout);
                        dataAdapter._queryTimeout = null;
                    }
                    self.abortActiveRequest(token);
                    self.clearSearchChrome($field, token);
                });
        },

        /**
         * Drop both the spinner and the unavailable notice.
         *
         * Needed on `select2:open`: select2 only detaches the dropdown on
         * close and only clears the search input's value, so nothing removes
         * children appended into `.select2-search--dropdown`. Without this,
         * a buyer who hits a failed search, closes the picker and reopens it
         * sees the stale "unavailable" notice above an empty search box —
         * and it survives until three or more characters are retyped.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity stamped by markSearchBinding()
         */
        clearSearchChrome: function ($field, token) {
            this.setSearching($field, false, token);
            this.setUnavailable($field, false, token);
        },

        /**
         * Show or hide the in-field searching spinner.
         *
         * Reuses the three-dot loader already used by the payment-term chips
         * (`.two-term-chip__loading`, `two-term-chip-dot` keyframes) rather
         * than introducing a second loading idiom.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {boolean} isSearching
         * @param {object} token identity stamped by markSearchBinding()
         */
        setSearching: function ($field, isSearching, token) {
            const $container = this.getSearchFieldContainer($field, token);
            if (!$container.length) return;

            if (!isSearching) {
                $container.find(`.${SPINNER_CLASS}`).remove();
                return;
            }
            if ($container.find(`.${SPINNER_CLASS}`).length) return;
            $container.append(
                `<span class="${SPINNER_CLASS}" aria-hidden="true"` +
                    '><span>.</span><span>.</span><span>.</span></span>'
            );
        },

        /**
         * Show or hide the "search unavailable" notice.
         *
         * This is the whole point of the timeout/degraded work: without it,
         * a timed-out or degraded search is pixel-identical to "no companies
         * matched", so a buyer with a perfectly valid company concludes the
         * shop won't take them. The copy points at manual entry, which both
         * pickers already offer.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {boolean} isUnavailable
         * @param {object} token identity stamped by markSearchBinding()
         */
        setUnavailable: function ($field, isUnavailable, token) {
            const $container = this.getSearchFieldContainer($field, token);
            if (!$container.length) return;

            if (!isUnavailable) {
                $container.find(`.${UNAVAILABLE_CLASS}`).remove();
                return;
            }
            if ($container.find(`.${UNAVAILABLE_CLASS}`).length) return;
            // A <span>, not a <div>: select2 renders both `.select2-dropdown`
            // and `.select2-search--dropdown` as <span>, and block-in-inline
            // makes margin/padding/width behave inconsistently. The class
            // sets `display: block`.
            $container.append(
                `<span class="${UNAVAILABLE_CLASS}" role="alert">` +
                    $t('Company search is unavailable. Try again, or enter details manually.') +
                    '</span>'
            );
        }
    };
});
