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

    /**
     * The zero-results message.
     *
     * select2's vendored bundle hardcodes English "No results found"
     * (`noResults:function(){return"No results found"}`). TWO-25326 §1 pins
     * the cross-platform wording as "No matches found", so both pickers
     * override it — same reason as minInputLengthMessage() above: the
     * vendored literal is neither ours to edit nor reachable by Magento's
     * translation dictionaries.
     *
     * @returns {string} translated zero-results message
     */
    function noResultsMessage() {
        return $t('No matches found');
    }

    /**
     * Everything focusable by Tab, in document order.
     *
     * Deliberately a hand-rolled walk rather than select2's own bookkeeping:
     * the dropdown is appended to `<body>` by AttachBody, so "the next thing
     * after the dropdown" is meaningless. What the buyer means by "Tab out of
     * the picker" is "the next form control after the COMBOBOX", and the
     * combobox does sit in natural document order inside the address form.
     * So every caller passes the combobox as the reference element, never
     * anything inside the dropdown.
     *
     * Visibility is decided by walking computed styles up the ancestor
     * chain, NOT by `offsetParent === null`. Both work in a browser, but
     * offsetParent additionally reports null for `position: fixed` elements
     * and for anything in a detached tree, and it is meaningless under jsdom
     * — where it is always null, so a test could never observe this function
     * returning anything at all. The style walk is the same answer in both
     * places, which is what makes the behaviour testable rather than only
     * assertable by hand in a live browser.
     *
     * What the walk has to exclude, concretely: the select2-hidden original
     * `<input name="company">` (also `tabindex="-1"`, so doubly filtered),
     * and the "Search for company" link, which is jQuery `.hide()`n — an
     * inline `display: none` — for the whole time search mode is active. Tab
     * must skip it, or leaving the dropdown would land on a control the
     * buyer cannot see.
     *
     * @returns {Array<Element>} tabbable elements, document order
     */
    function isVisibleForTabbing(el) {
        let node = el;
        while (node && node.nodeType === 1) {
            const style = window.getComputedStyle(node);
            if (style && (style.display === 'none' || style.visibility === 'hidden')) {
                return false;
            }
            node = node.parentElement;
        }
        return true;
    }

    function tabbableElements() {
        const selector = [
            'a[href]',
            'button',
            'input:not([type="hidden"])',
            'select',
            'textarea',
            '[tabindex]'
        ].join(',');
        return Array.prototype.filter.call(document.querySelectorAll(selector), function (el) {
            if (el.disabled) return false;
            if (el.getAttribute('aria-hidden') === 'true') return false;
            const tabindex = el.getAttribute('tabindex');
            if (tabindex !== null && parseInt(tabindex, 10) < 0) return false;
            return isVisibleForTabbing(el);
        });
    }

    /**
     * Move focus to the tab stop before or after `referenceEl`.
     *
     * Returns false — and moves nothing — when there is no such element, so
     * the caller can fall back to the browser's native Tab rather than
     * swallowing the key and trapping the buyer (TWO-25326 §4: "no keyboard
     * trap anywhere in this control under any state").
     *
     * @param {Element} referenceEl element to move away from
     * @param {boolean} backwards true for Shift+Tab
     * @returns {boolean} whether focus was actually moved
     */
    function focusAdjacentTabbable(referenceEl, backwards) {
        if (!referenceEl) return false;
        const tabbable = tabbableElements();
        const index = tabbable.indexOf(referenceEl);
        if (index === -1) return false;
        const next = tabbable[backwards ? index - 1 : index + 1];
        if (!next) return false;
        next.focus();
        return true;
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

    /**
     * Attribute applyAddress() records its own write in, so a later revert can
     * tell an autofilled value apart from one the buyer typed.
     *
     * A DOM attribute rather than module state on purpose: the checkout
     * re-renders the address form (Fire Checkout does it on every totals
     * change), and the recording has to survive exactly as long as the input
     * it describes — no longer. A module-level Map would outlive the node and
     * start describing its replacement.
     */
    const AUTOFILL_MARKER_ATTR = 'data-two-autofilled-value';

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
     * The focusable combobox select2 renders in place of the original input —
     * `.select2-selection`, the element that carries `role="combobox"` and
     * `tabindex="0"`.
     *
     * This, not the dropdown and not the hidden original `<input>`, is the
     * picker's position in the page's natural tab order, so it is the
     * reference every focus-advancing path uses.
     *
     * Resolved from the instance's OWN `$selection`, and tolerant of what
     * that actually is. In select2 4.1 the core does
     * `this.$selection = this.selection.render()`, and SingleSelection's
     * `render()` returns the `.select2-selection` span itself — so
     * `$selection` IS the combobox, not a wrapper around it. A `.find()`
     * alone therefore returns nothing, silently, and every focus-advancing
     * path degrades to "Tab does nothing" with no error to show for it.
     * Checked directly first, then by descendant, so a future select2 that
     * hands back the `.selection` wrapper instead still resolves.
     *
     * @param {object} $field jQuery-wrapped picker input
     * @returns {Element|null}
     */
    function comboboxElement($field) {
        const instance = $field && $field.data && $field.data('select2');
        if (!instance || !instance.$selection) return null;
        const $selection = instance.$selection;
        const direct = typeof $selection.get === 'function' ? $selection.get(0) : null;
        if (direct && direct.classList && direct.classList.contains('select2-selection')) {
            return direct;
        }
        const nested =
            typeof $selection.find === 'function'
                ? $selection.find('.select2-selection').get(0)
                : null;
        return nested || direct || null;
    }

    /**
     * Close the dropdown and put focus on the next (or previous) tab stop
     * around the combobox — TWO-25326 §1/§4: "close the dropdown by tabbing
     * out of it, in which case focus moves to the next tabstop".
     *
     * The refocus has to happen AFTER the close, not before: select2's own
     * `close()` ends by focusing the combobox, so a focus moved first is
     * immediately taken back. Doing it in this order means the buyer briefly
     * passes through the combobox and lands where they asked to go.
     *
     * @param {object} $field jQuery-wrapped picker input
     * @param {boolean} backwards true for Shift+Tab
     * @returns {boolean} whether focus was actually moved — false means the
     *          caller must let the browser's native Tab through instead
     */
    function closeAndAdvanceFocus($field, backwards) {
        const combobox = comboboxElement($field);
        closeDropdown($field);
        return focusAdjacentTabbable(combobox, backwards);
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
        AUTOFILL_MARKER_ATTR: AUTOFILL_MARKER_ATTR,
        isDegradedResponse: isDegradedResponse,
        minInputLengthMessage: minInputLengthMessage,
        noResultsMessage: noResultsMessage,

        /**
         * The `language` option block both pickers pass to select2.
         *
         * Shared rather than repeated so the two surfaces cannot drift on
         * wording — the whole reason TWO-25326 §1 lists "No results found"
         * as a per-platform defect is that each surface was inheriting a
         * different default.
         *
         * Functions, not strings: Magento's JS translation dictionary can
         * arrive after this module is defined, so each message is resolved at
         * render time.
         *
         * @returns {object} select2 `language` option
         */
        buildLanguageOptions: function () {
            return {
                inputTooShort: function () {
                    return minInputLengthMessage();
                },
                noResults: function () {
                    return noResultsMessage();
                }
            };
        },

        /**
         * Open the dropdown when the buyer types a character into the closed
         * combobox — TWO-25326 §1: "clicking into the company-name field, or
         * pressing a key (other than Tab) while it has focus, opens the
         * dropdown".
         *
         * select2 4.1 does not do this. Its core keypress handler only opens
         * on ENTER, SPACE and Alt+Down
         * (`(t===ENTER||t===SPACE||t===DOWN&&e.altKey)&&(n.open(),...)`);
         * every other printable character falls through and is simply lost,
         * so a buyer who focuses the field and starts typing their company
         * name sees nothing happen at all.
         *
         * ENTER and SPACE are deliberately left to select2 — it already
         * opens on both, and intercepting them here would only double up.
         * Everything else with a single-character `key` is treated as the
         * first character of a search: the dropdown opens, and the character
         * is seeded into the query field with an `input` event so select2's
         * own debounce/threshold pipeline picks it up exactly as if it had
         * been typed there. Without the seed the character would be
         * swallowed, and the buyer would have to type their first letter
         * twice.
         *
         * Modifier combinations (Ctrl/Cmd/Alt) are left alone: those are
         * browser and OS shortcuts, not text entry.
         *
         * @param {object} $field jQuery-wrapped picker input
         * @param {object} token identity stamped by markSearchBinding()
         */
        attachOpenOnType: function ($field, token) {
            const self = this;
            const combobox = comboboxElement($field);
            if (!combobox) return;

            $(combobox)
                .off('keydown' + EVENT_NS)
                .on('keydown' + EVENT_NS, function (e) {
                    if (e.ctrlKey || e.metaKey || e.altKey) return;
                    if (typeof e.key !== 'string' || e.key.length !== 1) return;
                    // select2 opens on Space by itself; Enter likewise (and
                    // its `key` is longer than one character anyway).
                    if (e.key === ' ') return;
                    if (!$field.data('select2')) return;
                    if ($field.data('twoSearchBind') !== token) return;

                    e.preventDefault();
                    $field.select2('open');

                    const $search = self
                        .getSearchFieldContainer($field, token)
                        .find('.select2-search__field');
                    if (!$search.length) return;
                    $search.val(e.key);
                    // A native `input` event, not jQuery's `.trigger('input')`:
                    // select2's own search handler is a native listener added
                    // by the vendored bundle, and jQuery-synthesised events do
                    // not reach native listeners. Our own manual-entry
                    // `input` handler is jQuery-bound, and jQuery DOES observe
                    // native events, so one dispatch feeds both.
                    // `window.Event`, not the bare `Event` global: this module
                    // is also loaded outside a browser window scope by the
                    // Jest AMD harness, where only the globals the sandbox
                    // declares exist. Same object in a browser.
                    $search.get(0).dispatchEvent(new window.Event('input', { bubbles: true }));
                });
        },

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
         * Each field also records the value this wrote, in
         * `AUTOFILL_MARKER_ATTR`. That recording is what makes the write
         * REVERSIBLE on a country switch (revertAutofilledAddress() below)
         * without ever discarding something the buyer typed: a buyer edit
         * leaves the field's value and its recording different, and that
         * difference IS the "buyer owns this now" signal.
         *
         * The marker is refreshed even when the incoming value already matches
         * what is in the field. Otherwise two companies sharing a postcode
         * would leave the second write unrecorded, and the field would read as
         * buyer-typed for the rest of the page's life.
         *
         * @param {object} address company address record from the API
         */
        applyAddress: function (address) {
            console.debug({ logger: 'companySearch.applyAddress', address });
            const values = {
                city: address.city,
                postcode: address.postal_code,
                'street[0]': address.street_address
            };
            // No presence check: jQuery's `.val()` and `.attr()` are no-ops on
            // an empty set, which is the pre-existing behaviour for a step
            // whose form is not on screen, and a guard here would only make
            // the two writes disagree about when they run.
            Object.keys(values).forEach(function (name) {
                const value = values[name] == null ? '' : String(values[name]);
                $(`input[name="${name}"]`).val(value).attr(AUTOFILL_MARKER_ATTR, value);
            });
            $('input[name="city"], input[name="postcode"], input[name="street[0]"]').trigger(
                'change'
            );
        },

        /**
         * Undo an applyAddress() write, field by field, and forget the
         * recording.
         *
         * Called when the buyer switches country: an address autofilled from
         * the previous country's registry is not a hint about the new one, it
         * is a wrong address the buyer may well not re-read before placing the
         * order.
         *
         * Only fields still holding EXACTLY what applyAddress() put there are
         * cleared. Anything the buyer has since edited — and anything that was
         * never autofilled at all, including a whole form they filled by hand —
         * has no matching recording and is left alone. That asymmetry is the
         * point: over-clearing here silently deletes buyer input on a keystroke
         * they may have made minutes ago, which is worse than leaving a stale
         * value they can see and correct.
         *
         * `change` is fired only for fields actually cleared, so Magento's
         * address-form bookkeeping sees the same event shape it does for a
         * buyer edit.
         *
         * @returns {number} how many fields were cleared — for tests, and so a
         *          caller can tell "nothing was ours" from "reverted"
         */
        revertAutofilledAddress: function () {
            let cleared = 0;
            ['city', 'postcode', 'street[0]'].forEach(function (name) {
                const $input = $(`input[name="${name}"]`);
                const marker = $input.attr(AUTOFILL_MARKER_ATTR);
                // `undefined` means never autofilled. An empty-string marker is
                // a real recording (the registry had no value for this field)
                // and must still be honoured, so this tests for absence rather
                // than falsiness.
                if (typeof marker === 'undefined') return;
                $input.removeAttr(AUTOFILL_MARKER_ATTR);
                if (($input.val() || '') !== marker) return;
                $input.val('');
                $input.trigger('change');
                cleared += 1;
            });
            console.debug({ logger: 'companySearch.revertAutofilledAddress', cleared });
            return cleared;
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
                    // select2 never sees the keypress. Close, and let
                    // select2's own close() put focus back on the combobox —
                    // TWO-25326 §1: Escape reverts focus to company-name.
                    if (isEscape) {
                        e.preventDefault();
                        closeDropdown($field);
                        return;
                    }
                    e.stopPropagation();
                    if (e.shiftKey) {
                        // Shift+Tab is the exact inverse of the forward Tab
                        // shortcut that got the buyer here (query field ->
                        // this button), so it goes back to the query field
                        // rather than out of the picker. Leaving the dropdown
                        // open is the point: the buyer is stepping back INTO
                        // the search, not abandoning it.
                        e.preventDefault();
                        const $search = self
                            .getSearchFieldContainer($field, token)
                            .find('.select2-search__field');
                        if ($search.length) {
                            $search.trigger('focus');
                            return;
                        }
                        closeDropdown($field);
                        return;
                    }
                    // Forward Tab: close the dropdown AND advance to the next
                    // control after the combobox (TWO-25326 §4). Only
                    // preventDefault when we actually moved focus ourselves —
                    // if there is no next tab stop to find, swallowing the key
                    // would be a keyboard trap, so the native Tab is allowed
                    // through instead.
                    if (closeAndAdvanceFocus($field, false)) {
                        e.preventDefault();
                    }
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
                const hadButton = $existing.length > 0;
                if (hadButton) {
                    removeFromIdrefList(
                        this.getSearchFieldContainer($field, token).find('.select2-search__field'),
                        'aria-controls',
                        $existing.attr('id')
                    );
                }
                $existing.remove();
                // Nudge AFTER removal, not before: `resize.select2.<id>`
                // reaches `_positionDropdown`/`_resizeDropdown`
                // synchronously, and they measure `$dropdown.outerHeight()`
                // at call time. Nudging with the button still in the DOM
                // just recomputes the geometry that was already in effect —
                // the panel then shrinks under a stale position instead of
                // one measured for its new height.
                if (hadButton) nudgeSelect2Resize($field);
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
                    // Fails CLOSED on a stale bind, same convention as
                    // getSearchFieldContainer()/getResultsList(): a listener
                    // that outlives its own bind (the gap between a
                    // superseding re-bind and this node's own teardown)
                    // must not act on behalf of a widget it no longer owns.
                    if (!self.getSearchFieldContainer($field, token).length) return;
                    // select2's own handler must never see a Tab: it treats
                    // Tab exactly like Enter, with no shiftKey guard, so it
                    // would commit the auto-highlighted first result — a
                    // company the buyer never chose.
                    e.stopPropagation();
                    if (e.shiftKey) {
                        // Shift+Tab leaves the picker backwards: close, and
                        // land on the control BEFORE the combobox. Same
                        // no-trap rule as everywhere else here — if there is
                        // nothing before it, let the native Tab through.
                        if (closeAndAdvanceFocus($field, true)) {
                            e.preventDefault();
                        }
                        return;
                    }
                    const $wrapper = self.getResultsList($field, token).parent();
                    const $button = $wrapper.children(`.${MANUAL_ENTRY_CLASS}`);
                    if (!$button.length) {
                        // Below MIN_INPUT_LENGTH there is no button to reach,
                        // so the right behaviour is to leave the picker:
                        // close it and move to the next control after the
                        // combobox (TWO-25326 §1 — tabbing out of the
                        // dropdown moves focus to the next tabstop).
                        if (closeAndAdvanceFocus($field, false)) {
                            e.preventDefault();
                        }
                        return;
                    }
                    // The deliberate shortcut (TWO-25326 §4): forward Tab
                    // from the query field reaches the "not on the list"
                    // button directly, without walking the results.
                    e.preventDefault();
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
