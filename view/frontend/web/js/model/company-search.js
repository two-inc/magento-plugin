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
     * The `minimumInputLength` both call sites pass to select2 — they read
     * this constant rather than repeating a literal, so the enforced
     * threshold and the hint below can never drift apart.
     *
     * Below it select2's decorator short-circuits `query()` and never reaches
     * the data adapter, so no transport runs — which the chrome has to know
     * about, or nothing ever takes the spinner down.
     */
    const MIN_INPUT_LENGTH = 3;

    /**
     * select2's `dropdownCssClass` option for both company-search pickers
     * (address step and payment tile). A single exported constant, same
     * reason as MIN_INPUT_LENGTH above: the two call sites (`select2({...})`
     * in address-autocomplete.js and gateway_method.js) must agree with
     * style.css's dropdown-row selector, and a hardcoded literal at each
     * site can drift silently if one is renamed without the others.
     */
    const DROPDOWN_CSS_CLASS = 'two-company-search-dropdown';

    /**
     * The "keep typing" hint shown while the term is below MIN_INPUT_LENGTH.
     *
     * select2 ships its own English `inputTooShort` message, hard-coded
     * inside the vendored bundle and phrased as the REMAINING character
     * count. Neither is acceptable: the vendored bundle is not ours to edit
     * and its literals never reach Magento's translation dictionaries. The
     * address-step picker overrides it via select2's `language.inputTooShort`
     * option with this instead — a plugin-owned, translatable string quoting
     * a FIXED threshold.
     *
     * The payment-step picker does NOT. It passes `minimumInputLength` only,
     * so it still renders select2's built-in English remaining-count text.
     * That is deliberately out of scope here; this change covers the
     * address-step surface only.
     *
     * Resolved per call, not once at module load, because Magento's JS
     * dictionary can arrive after this module is defined. Magento's `$t`
     * does not interpolate, hence the explicit replace.
     *
     * @returns {string} translated hint naming MIN_INPUT_LENGTH
     */
    function minInputLengthMessage() {
        return $t('Please enter %1 or more characters').replace('%1', MIN_INPUT_LENGTH);
    }

    /** jQuery event namespace for everything this module binds. */
    const EVENT_NS = '.twoCompanySearch';

    /**
     * A SECOND namespace, for the manual-entry row's own handlers.
     *
     * They bind to the same node as the below-threshold cancel handler in
     * markSearchBinding(), and both listen for `input`. Sharing one namespace
     * would make each one's `.off()` silently unbind the other.
     */
    const MANUAL_ENTRY_NS = '.twoManualEntry';

    const SPINNER_CLASS = 'two-company-search__spinner';
    const UNAVAILABLE_CLASS = 'two-company-search__unavailable';
    const MANUAL_ENTRY_CLASS = 'two-company-search__manual-entry';

    /**
     * The manual-entry row's label. Resolved per call, not once at module
     * load: Magento's JS dictionary can arrive after this module is defined.
     *
     * @returns {string} translated label
     */
    function manualEntryText() {
        return $t('My company is not on the list');
    }

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

    /**
     * Append `id` to a space-separated IDREF attribute if it is not already
     * present.
     *
     * Used only for `aria-controls` on the search field: the button sits
     * OUTSIDE `.select2-results__options` (the whole point of the
     * sibling-not-child fix), so select2's own `aria-controls` wiring on the
     * search field — which only ever names the listbox `<ul>` — never
     * mentions it. `aria-owns` is deliberately NOT used for this: `$search`
     * carries `role="searchbox"`, a leaf widget, and AT does not expose
     * owned children of a leaf role, so an `aria-owns` pointing at the
     * button would add an attribute with no accessible effect and assert a
     * parent/child relationship that isn't structurally true.
     *
     * @param {object} $el jQuery set — no-op if empty
     * @param {string} attr attribute name
     * @param {string} id id to add
     */
    function appendToIdrefList($el, attr, id) {
        if (!$el || !$el.length || !id) return;
        const ids = ($el.attr(attr) || '').split(/\s+/).filter(Boolean);
        if (ids.indexOf(id) !== -1) return;
        ids.push(id);
        $el.attr(attr, ids.join(' '));
    }

    /**
     * Inverse of appendToIdrefList() — strips `id` back out, called when the
     * button it names is removed (below-threshold, or teardown).
     *
     * @param {object} $el jQuery set — no-op if empty
     * @param {string} attr attribute name
     * @param {string} id id to remove
     */
    function removeFromIdrefList($el, attr, id) {
        if (!$el || !$el.length || !id) return;
        const ids = ($el.attr(attr) || '').split(/\s+/).filter(Boolean).filter(function (existing) {
            return existing !== id;
        });
        if (ids.length) {
            $el.attr(attr, ids.join(' '));
        } else {
            $el.removeAttr(attr);
        }
    }

    /**
     * Close a picker's select2 dropdown, if it has a live instance.
     *
     * Deliberately does NOT also refocus the combobox itself: select2's own
     * `close` handler already does that (`$selection.trigger('focus')`,
     * `AttachBody`'s base `close()`), so doing it again here would be a
     * redundant re-focus of the element that already has focus, not a fix
     * for anything.
     *
     * `typeof $field.select2 === 'function'` guards against test doubles
     * that model `.data('select2')` without modelling the plugin method
     * itself — a real jQuery + vendored select2 bundle always has both.
     *
     * @param {object} $field jQuery-wrapped picker input
     */
    function closeDropdown($field) {
        if ($field && $field.length && $field.data('select2') && typeof $field.select2 === 'function') {
            $field.select2('close');
        }
    }

    /**
     * Tell select2 to recalculate the dropdown's geometry.
     *
     * select2 only recalculates height/position from its OWN mutations
     * (`results:all`, `results:append`, `results:message`, `select`,
     * `unselect`) or a window scroll/resize — appending or removing the
     * manual-entry button is neither. It runs synchronously from the search
     * field's `input` handler, ahead of the ajax debounce (the whole point
     * of the threshold-not-has-searched design), so without this nudge the
     * panel keeps whatever height it was measured at before the button
     * existed — it can grow past the viewport edge or overlap the field
     * above it for the entire pre-results window, exactly the
     * "always visible without scroll" property the button exists to
     * guarantee. `resize.select2.<id>` is the same namespaced event
     * `_attachPositioningHandler` binds while open, so this reaches select2's
     * own repositioning and nothing else. Call on every insert AND removal —
     * removal shrinks the panel by exactly the same synchronous, pre-results
     * margin insertion grows it by.
     *
     * @param {object} $field jQuery-wrapped picker input
     */
    function nudgeSelect2Resize($field) {
        const instance = $field.data('select2');
        if (instance) $(window).trigger('resize.select2.' + instance.id);
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
        DROPDOWN_CSS_CLASS: DROPDOWN_CSS_CLASS,
        EVENT_NS: EVENT_NS,
        isDegradedResponse: isDegradedResponse,
        minInputLengthMessage: minInputLengthMessage,

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
                        /*
                         * `national_identifier` is optional in the search
                         * response — the company may have none in its home
                         * registry, and the object itself may be absent, null,
                         * or carry a null/empty `id`. Reading it unguarded
                         * threw, and a throw here happens inside select2's
                         * query pipeline: it takes the WHOLE result list down,
                         * not just this hit, and leaves the dropdown stuck on
                         * "Searching…" with no error the buyer can act on.
                         *
                         * So render the company with whatever it has. The
                         * identifier is only the buyer's disambiguator between
                         * two similarly-named companies; dropping the hit
                         * instead would remove a company they can no longer
                         * select at all. Without one they see the name alone,
                         * and selecting it is what gives them a route to the
                         * organisation number: the pickers treat an empty
                         * `companyId` as authoritative and clear any previously
                         * selected company's identifier. See applyCompanyData()
                         * / selectCompanyWithoutIdentifier() in
                         * view/payment/method-renderer/gateway_method.js —
                         * WITHOUT that, an empty `companyId` here silently
                         * kept the previous company's organisation number and
                         * submitted it under this company's name.
                         *
                         * The buyer cannot then TYPE that number ANYWHERE. The
                         * payment tile used to re-enable a company-number field
                         * of its own; TWO-25288 made that field read-only, so an
                         * empty `companyId` stays empty — it is shown to the
                         * buyer, not offered to them. The address step's field
                         * is not a fallback either: it is CSS-hidden
                         * unconditionally. An identifier-less company is
                         * therefore refused server-side by
                         * Model/Two.php::authorize().
                         */
                        const identifier =
                            item.national_identifier && item.national_identifier.id
                                ? String(item.national_identifier.id)
                                : '';
                        items.push({
                            id: item.name,
                            text: item.name,
                            html: identifier ? `${item.highlight} (${identifier})` : item.highlight,
                            companyId: identifier,
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
            // from MIN_INPUT_LENGTH characters to one below it neither runs a
            // new transport nor cancels the running one: nothing clears the
            // spinner, and 30s later the abandoned request repaints results
            // or the "unavailable" notice under the below-threshold hint.
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
                    // up under the below-threshold hint.
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
         * and it survives until MIN_INPUT_LENGTH characters are retyped.
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
         * The spinner is a single childless element: the animation it shows
         * is a loading GIF painted by CSS as a background-image, so there is
         * no inner markup to keep in sync and nothing for a translation or a
         * sanitiser to mangle. See `two-company-search__spinner` in
         * view/frontend/web/css/style.css.
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
            $container.append(`<span class="${SPINNER_CLASS}" aria-hidden="true"></span>`);
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
        },

        /**
         * Resolve the `<ul>` select2 renders its results into.
         *
         * Same staleness contract as getSearchFieldContainer(): fails closed on
         * a token that no longer matches the bind stamped on the node, so a
         * torn-down widget's handlers cannot paint onto its replacement.
         *
         * Found by class rather than through the internals of the results
         * object, because the class is part of select2's public styling
         * surface while the property name is not.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity stamped by markSearchBinding()
         * @returns {object} jQuery set — empty when stale or not bound
         */
        getResultsList: function ($field, token) {
            if (!$field || !$field.data) return $();
            if ($field.data('twoSearchBind') !== token) return $();
            const instance = $field.data('select2');
            if (!instance || !instance.$dropdown) return $();
            // The nested lists select2 renders for grouped results carry the
            // same class plus a modifier. Results are flat today, so matching
            // them would be unreachable rather than wrong — but if grouping
            // ever arrives, an unqualified selector puts a manual-entry row
            // inside every group.
            return instance.$dropdown.find(
                '.select2-results__options:not(.select2-results__options--nested)'
            );
        },

        /**
         * What the buyer has typed into the picker's search box right now.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity stamped by markSearchBinding()
         * @returns {string}
         */
        currentSearchTerm: function ($field, token) {
            const $container = this.getSearchFieldContainer($field, token);
            if (!$container.length) return '';
            return $container.find('.select2-search__field').val() || '';
        },

        /**
         * Build the manual-entry affordance as a real, focusable `<button>`
         * (#30.x.15).
         *
         * The `<li role="option">` version this replaces put the row INSIDE
         * `.select2-results__options` on purpose, so it inherited select2's
         * own keyboard model and its `aria-owns` reachability. In practice
         * that traded one accessibility gap for two others, discovered live
         * after that version shipped:
         *
         *  - `.select2-results__options` is exactly the element select2
         *    applies its own scroll-and-clip to, so the row was only ever
         *    visible once the buyer scrolled past however many results came
         *    back, and arrowing down through every one of them was the ONLY
         *    way to reach it by keyboard;
         *  - Enter closed over select2's own `select2:selecting` dispatch and
         *    worked, but Space does not: select2's core only special-cases
         *    Enter/Up/Down/Escape on the search field's keydown, so an
         *    unhandled Space fell through to its native default — typing a
         *    literal space character into the search box — rather than
         *    activating the highlighted row.
         *
         * A real `<button>` fixes Enter AND Space activation for free —
         * nothing here has to special-case either key. Tab is NOT free,
         * though: select2 4.1's own search-field keypress handler treats Tab
         * exactly like Enter (`t===ENTER||t===TAB`, both trigger
         * `results:select` and `preventDefault()`), so without help Tab can
         * never leave the search field to reach a sibling button. attachManualEntryButton()
         * installs a capture-phase listener on the search field to win that
         * race — see its own doc comment. Escape is handled here, on the
         * button itself, for the same reason: once focus has moved off the
         * search field select2 never sees the keypress.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity stamped by markSearchBinding()
         * @param {function} onActivate called once the buyer has actually
         *        activated the button (click, Enter or Space) — never on
         *        construction
         * @returns {object} jQuery-wrapped `<button>`
         */
        buildManualEntryButton: function ($field, token, onActivate) {
            const self = this;
            const label = manualEntryText();

            /**
             * Run onActivate() once, deferred a tick — same reason as the old
             * `select2:selecting` interception it replaces: `onActivate()`
             * tears the select2 widget down (it switches the field into
             * manual-entry / plain-text mode), and doing that synchronously
             * from inside the dispatching event's own handler would pull the
             * button's own DOM out from under an event that is still
             * unwinding on it.
             *
             * The timer is stashed on the button (`twoManualEntryTimer`) so
             * detachManualEntryButton() can cancel it: without that, closing
             * the dropdown in the gap between activation and the deferred
             * tick still fires onActivate() a moment later, against a picker
             * the buyer has already left.
             */
            function activate() {
                const timer = setTimeout(function () {
                    $button.removeData('twoManualEntryTimer');
                    if (!self.getSearchFieldContainer($field, token).length) return;
                    onActivate();
                }, 0);
                $button.data('twoManualEntryTimer', timer);
            }

            const $button = $('<button></button>')
                .attr({ type: 'button' })
                .addClass(MANUAL_ENTRY_CLASS)
                .text(label)
                .on('click' + MANUAL_ENTRY_NS, activate)
                .on('keydown' + MANUAL_ENTRY_NS, function (e) {
                    const isEscape = e.key === 'Escape' || e.which === 27;
                    const isTab = e.key === 'Tab' || e.which === 9;
                    if (!isEscape && !isTab) return;
                    // Escape is select2's own "close the dropdown" key, but
                    // once focus has moved onto this button (a sibling of the
                    // listbox, outside select2's own keydown delegation)
                    // select2 never sees the keypress.
                    //
                    // Tab (either direction) is handled the same way,
                    // deliberately, rather than trying to move focus
                    // somewhere "natural": with AttachBody this button's
                    // dropdown is appended as the LAST child of `<body>`, so
                    // forward Tab from here has nothing sensible to land on
                    // in real document order (it walks out of the page
                    // entirely), and Shift+Tab has no defined "previous"
                    // element either. Closing and returning to the combobox
                    // keeps the buyer inside the checkout form instead of
                    // ejecting them into browser chrome.
                    e.preventDefault();
                    if (isTab) e.stopPropagation();
                    closeDropdown($field);
                });
            return $button;
        },

        /**
         * Bring the manual-entry button into line with the current search
         * term: present and last while the term is at or above the
         * threshold, absent below it.
         *
         * A SIBLING of the results `<ul>`, not a child of it — appended into
         * `.select2-results`, the same wrapper the list itself lives in — so
         * it sits outside the part of the dropdown select2 clips and scrolls.
         * That is what makes it always visible the moment it should be,
         * regardless of how many results came back, without the buyer ever
         * having to scroll to find it. Still inside the dropdown panel, so it
         * reads as part of the same surface rather than a detached footer.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity stamped by markSearchBinding()
         * @param {function} onActivate see buildManualEntryButton()
         * @returns {object} jQuery set — the button, or empty when not shown
         */
        syncManualEntryButton: function ($field, token, onActivate) {
            const $results = this.getResultsList($field, token);
            if (!$results.length) return $();

            const $wrapper = $results.parent();
            const term = this.currentSearchTerm($field, token);
            const $existing = $wrapper.children(`.${MANUAL_ENTRY_CLASS}`);

            // Threshold, not "has searched". The buyer who cannot find their
            // company is the one who most needs this button, and making them
            // wait out a debounce and a request first is exactly the wrong
            // order.
            if (term.length < MIN_INPUT_LENGTH) {
                if ($existing.length) {
                    removeFromIdrefList(
                        this.getSearchFieldContainer($field, token).find('.select2-search__field'),
                        'aria-controls',
                        $existing.attr('id')
                    );
                    nudgeSelect2Resize($field);
                }
                $existing.remove();
                return $();
            }
            // Already there, immediately after the current results list:
            // nothing to do. Load-bearing rather than an optimisation — an
            // unconditional rebuild on every keystroke would tear down and
            // replace a button the buyer might be mid-interaction with.
            if ($existing.length && $existing.prev().is($results)) {
                return $existing;
            }
            $existing.remove();
            const $button = this.buildManualEntryButton($field, token, onActivate);
            // A stable id, not a generated one: it is what gets appended to
            // the search field's `aria-controls` below, so a screen reader
            // can resolve it back to this exact button rather than a
            // dangling reference on the next repaint.
            $button.attr('id', ($results.attr('id') || 'select2-two-company-search') + '-manual-entry');
            $results.after($button);
            nudgeSelect2Resize($field);

            const $searchField = this.getSearchFieldContainer($field, token).find(
                '.select2-search__field'
            );
            appendToIdrefList($searchField, 'aria-controls', $button.attr('id'));

            return $button;
        },

        /**
         * Wire the manual-entry button up for a bind. Call on `select2:open`.
         *
         * A single trigger is enough here, unlike the pseudo-option version:
         * `input` on the search box, so the button appears as soon as the
         * term reaches the threshold and disappears when it drops below —
         * independently of the debounced request. No MutationObserver is
         * needed to survive select2 repainting the results list on a fresh
         * page of results: the button is a SIBLING of that list now, not a
         * child of it, so replacing the list's contents never touches it.
         *
         * Rebinding is safe: the handler is namespaced and cleared first, so
         * reopening the picker cannot accumulate duplicates.
         *
         * Also installs a CAPTURE-phase native `keydown` listener for Tab on
         * the search field. select2 4.1's own `_registerEvents` binds a
         * keypress handler on this same field at CONSTRUCTION time that
         * treats Tab exactly like Enter (`t===ENTER||t===TAB` both trigger
         * `results:select` and call `preventDefault()`) — with NO
         * `shiftKey` guard, so Shift+Tab is hijacked identically. Without
         * this, Tab either selects the (auto-highlighted) first result and
         * closes the dropdown, or no-ops with `preventDefault()` still
         * firing — either way, focus never leaves the search field to
         * reach the button, and Shift+Tab actively COMMITS a company the
         * buyer never chose. A jQuery bubble-phase binding added here (or
         * on `select2:open`, later than select2's own constructor-time
         * bind) cannot win that race; only a capture-phase listener runs
         * before select2 sees the event at all. Shift+Tab is intercepted
         * too, but not routed anywhere "previous" — see closeDropdown()'s
         * doc comment on the button's own keydown handler for why closing
         * is the safe choice for both directions here.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity stamped by markSearchBinding()
         * @param {function} onActivate see buildManualEntryButton()
         */
        attachManualEntryButton: function ($field, token, onActivate) {
            const self = this;
            const $results = this.getResultsList($field, token);
            if (!$results.length) return;

            const $searchField = this.getSearchFieldContainer($field, token).find(
                '.select2-search__field'
            );

            $searchField
                .off('input' + MANUAL_ENTRY_NS)
                .on('input' + MANUAL_ENTRY_NS, function () {
                    self.syncManualEntryButton($field, token, onActivate);
                });

            const searchFieldNode = $searchField.get(0);
            if (searchFieldNode) {
                // Detached first: rebinding must not stack a second capture
                // listener on the same node across a re-open.
                if (searchFieldNode._twoManualEntryTabHandler) {
                    searchFieldNode.removeEventListener(
                        'keydown',
                        searchFieldNode._twoManualEntryTabHandler,
                        true
                    );
                }
                const tabHandler = function (e) {
                    if (e.key !== 'Tab') return;
                    if (e.shiftKey) {
                        // Shift+Tab: select2's own handler would otherwise
                        // silently select the auto-highlighted first result
                        // and close the picker — worse than the forward-Tab
                        // defect this fix exists for, since it commits a
                        // choice the buyer never made. There is no sibling
                        // element to send focus "back" to here (the search
                        // field is where the event fired), so close and let
                        // select2's own close() refocus the combobox.
                        e.preventDefault();
                        e.stopPropagation();
                        closeDropdown($field);
                        return;
                    }
                    const $wrapper = self.getResultsList($field, token).parent();
                    const $button = $wrapper.children(`.${MANUAL_ENTRY_CLASS}`);
                    if (!$button.length) return;
                    e.preventDefault();
                    e.stopPropagation();
                    $button.trigger('focus');
                };
                searchFieldNode.addEventListener('keydown', tabHandler, true);
                searchFieldNode._twoManualEntryTabHandler = tabHandler;
            }

            this.syncManualEntryButton($field, token, onActivate);
        },

        /**
         * Stop watching a picker's search field for the manual-entry
         * button's `input` handler and its capture-phase Tab handler, cancel
         * any pending activation timer, and drop the button itself. Call
         * when the widget is torn down or closed.
         *
         * Deliberately takes only `$field`, no token — same signature as the
         * `detachManualEntryObserver()` this replaces. Teardown must clean up
         * whatever is CURRENTLY attached to this field's live select2
         * instance regardless of which bind token is active, which is the
         * opposite of every staleness check elsewhere in this module: those
         * refuse to act on behalf of a replaced widget, this one exists
         * specifically to retire the widget that is being replaced.
         *
         * Every step below is duck-typed rather than assumed chainable.
         * Real jQuery always satisfies it; what does not is a handful of
         * OTHER test suites' minimal `$dropdown.find()` doubles, built
         * before this method existed and modelling only the selectors
         * their own re-render/dispose assertions need. Guarding here keeps
         * this cleanup a no-op against those, rather than requiring every
         * unrelated fixture in the repo to grow a full results-list model
         * of DOM it otherwise never touches.
         *
         * @param {object} $field jQuery-wrapped picker input
         */
        detachManualEntryButton: function ($field) {
            if (!$field || !$field.data) return;
            const instance = $field.data('select2');
            if (!instance || !instance.$dropdown) return;

            const $searchBox = instance.$dropdown.find('.select2-search--dropdown');
            if ($searchBox && typeof $searchBox.find === 'function') {
                const $searchField = $searchBox.find('.select2-search__field');
                if ($searchField && typeof $searchField.off === 'function') {
                    $searchField.off('input' + MANUAL_ENTRY_NS);
                }
                const searchFieldNode = $searchField && $searchField.get && $searchField.get(0);
                if (searchFieldNode && searchFieldNode._twoManualEntryTabHandler) {
                    searchFieldNode.removeEventListener(
                        'keydown',
                        searchFieldNode._twoManualEntryTabHandler,
                        true
                    );
                    delete searchFieldNode._twoManualEntryTabHandler;
                }
            }

            // Found by class directly, scoped to the dropdown — not via the
            // results `<ul>`'s parent. That indirection is fragile for
            // teardown specifically: if the `<ul>` can't be resolved for any
            // reason, the lookup used to fail open and leave the button (and
            // its bound `click`/`keydown` handlers, and its pending
            // activation timer) wired to a renderer that is about to be
            // disposed — the exact leak this method exists to prevent. A
            // class lookup scoped to the dropdown cannot miss for that
            // reason, and doesn't care whether nested result groups exist.
            const $dropdown = instance.$dropdown;
            const $button =
                $dropdown && typeof $dropdown.find === 'function'
                    ? $dropdown.find(`.${MANUAL_ENTRY_CLASS}`)
                    : $();
            if ($button && $button.length) {
                const timer = $button.data && $button.data('twoManualEntryTimer');
                if (timer) clearTimeout(timer);
                if (typeof $button.off === 'function') {
                    $button.off('click' + MANUAL_ENTRY_NS).off('keydown' + MANUAL_ENTRY_NS);
                }
                const $searchField2 = $searchBox && $searchBox.find
                    ? $searchBox.find('.select2-search__field')
                    : $();
                removeFromIdrefList($searchField2, 'aria-controls', $button.attr && $button.attr('id'));
                if (typeof $button.remove === 'function') {
                    $button.remove();
                }
            }
        }
    };
});
