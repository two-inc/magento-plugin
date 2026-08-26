/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Shared company-search primitives for the two select2 company pickers
 * in checkout: the shipping-step one (`view/address-autocomplete.js`,
 * a Magento_Ui form Component) and the payment-step one
 * (`model/company-capture.js`, the payment tile's mount).
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
     * reason as MIN_INPUT_LENGTH above: the two mounts (address-autocomplete.js
     * and company-capture.js, both via company-search-control.js) must agree with
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
     * and its literals never reach Magento's translation dictionaries. Both
     * pickers override it via select2's `language.inputTooShort` option with
     * this instead — a plugin-owned, translatable string quoting a FIXED
     * threshold — through the shared `buildLanguageOptions()` that
     * CompanySearchControl passes at its single mount point.
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

    /**
     * The checkout's two address forms, and which of the two the mirror below
     * treats as its source.
     *
     * The shipping form is the DEFAULT one: core always renders it, the
     * company picker lives in it, and it is the one the buyer fills first. The
     * billing form is the NON-DEFAULT one: it appears only once the buyer
     * unchecks "My billing and shipping address are the same", and core renders
     * one PER PAYMENT METHOD — so the second selector routinely matches several
     * nodes, and every one of them is judged and written independently.
     *
     * The billing form is matched on `data-form`, not on an id, because core's
     * `billing-address/form.html` gives its fieldset no id at all — only
     * `shipping-address/form.html` carries one (`#shipping-new-address-form`).
     */
    const PRIMARY_ADDRESS_ROOT_SELECTOR = '#shipping-new-address-form';
    /** @see primaryAddressRoot — core's saved-addresses wrapper for that form. */
    const NEW_SHIPPING_ADDRESS_WRAPPER_SELECTOR = '#opc-new-shipping-address';
    const SECONDARY_ADDRESS_ROOT_SELECTOR = '[data-form="billing-new-address"]';

    /**
     * Every field of an address the plugin can write, and therefore every field
     * the sync pin has to judge.
     *
     * Completeness is the whole point, not a nicety: the pin's question is
     * "has the buyer authored anything in this address", and a writable field
     * left off this list is a field a buyer can type into without the pin ever
     * noticing. `street1` and `region` are named here specifically because the
     * sibling platform's first attempt reasoned they were safe to skip — a
     * buyer typing a second address line is stating an independent answer
     * exactly as much as one typing a city.
     *
     * Fields the plugin NEVER writes (the name fields, the telephone) are
     * deliberately absent. Every value in one of those is buyer-authored by
     * definition, so counting them would pin the billing address the moment it
     * rendered with a name in it — i.e. always.
     *
     * `region` resolves through a function rather than a selector because core
     * renders two mutually exclusive controls for it and which one is in play
     * depends on the country; see resolveRegionField().
     */
    const REGION_FIELD = { name: 'region', resolve: resolveRegionField };
    const STREET1_FIELD = { name: 'street1', selector: 'input[name="street[1]"]' };
    const COUNTRY_FIELD = { name: 'country', selector: 'select[name="country_id"]' };
    const MIRRORED_FIELDS = [
        { name: 'company', selector: 'input[name="company"]' },
        { name: 'organization', selector: 'input[name="custom_attributes[company_id]"]' },
        { name: 'street0', selector: 'input[name="street[0]"]' },
        STREET1_FIELD,
        { name: 'city', selector: 'input[name="city"]' },
        { name: 'postcode', selector: 'input[name="postcode"]' },
        REGION_FIELD,
        COUNTRY_FIELD
    ];

    /**
     * The fields a country switch retracts, and the two it deliberately does
     * not.
     *
     * `country` is excluded because the buyer has just chosen it — clearing it
     * would undo the very edit that triggered the retraction. `company` and
     * `organization` are excluded because they are owned by setCompanyData() in
     * view/address-autocomplete.js, which clears both on the same switch; a
     * second clearing path for them would mean two owners of one field.
     */
    const REVERTABLE_FIELD_NAMES = ['street0', 'street1', 'city', 'postcode', 'region'];

    /**
     * What the mirror last wrote into each billing address, and what each
     * billing address was first rendered holding — both keyed by
     * secondaryAddressKey().
     *
     * Module state rather than DOM attributes, and it has to be BOTH. A marker
     * attribute is the only record of a write made since the last render, and
     * it is the more precise of the two because it dies with the node it
     * describes; module state is the only record that SURVIVES core rebuilding
     * the form, which Fire Checkout does on every totals change. Neither
     * survives a page load, and that failure mode is the safe one: a lost
     * record leaves non-empty fields with nothing on record as having written
     * them, which reads as buyer-authored and pins the address. A lost record
     * costs one missed re-sync; the opposite default would cost the buyer's own
     * data.
     */
    const mirrorWriteRecords = new Map();
    const secondaryAddressBaselines = new Map();

    /** @see secondaryAddressKey — the no-id fallback's own identity. */
    const FALLBACK_KEY_ATTR = 'data-two-mirror-key';
    let fallbackKeySequence = 0;

    /**
     * Trim, and fold case. Both halves are the ruling on how a content match is
     * decided: a buyer who retyped "acme trading ltd" over "Acme Trading Ltd",
     * or who left a trailing space behind, has not authored a different answer.
     *
     * @param {*} value
     * @returns {string}
     */
    function normalizeMirroredValue(value) {
        return String(value == null ? '' : value).trim().toLowerCase();
    }

    function trimmedString(value) {
        return String(value == null ? '' : value).trim();
    }

    /**
     * The state/region control in play for one address form, or an empty
     * handle when the form has none.
     *
     * Core renders BOTH a `region_id` select and a free-text `region` input into
     * every address fieldset and toggles which is visible from the country's own
     * directory data, so "which one is in play" cannot be answered by presence.
     * It is answered by the select's option list instead: a country with regions
     * gets real options, a country without gets none, and that test is readable
     * in a test harness where computed visibility is not.
     *
     * @param {object} $root jQuery-wrapped address form
     * @returns {{$field: object, select: (Element|null)}}
     */
    function resolveRegionField($root) {
        const $select = scopedField($root, 'select[name="region_id"]');
        const select = firstElement($select);
        if (select && select.options) {
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value) {
                    return { $field: $select, select: select, unscoped: !$root };
                }
            }
        }
        return {
            $field: scopedField($root, 'input[name="region"]'),
            select: null,
            unscoped: !$root
        };
    }

    /**
     * One field, looked up inside an address form — or document-wide when there
     * is no form to scope to.
     *
     * The unscoped branch is not a fallback for convenience: on a virtual cart
     * or a saved shipping address the shipping form does not exist at all, the
     * picker lives in the payment tile, and the only address form on the page IS
     * the billing one. Writing document-wide there is the pre-existing behaviour
     * and the correct one — the form being written is the buyer's own working
     * form, not a mirror of anything.
     *
     * @param {?object} $root jQuery-wrapped address form, or null
     * @param {string} selector
     * @returns {object} jQuery set
     */
    /**
     * The default address form, but only when core is actually using it.
     *
     * Presence is not enough, and neither is plain visibility. Core renders the
     * shipping form two different ways (`shipping.html`):
     *
     *  - INLINE (`render if="isFormInline"`), for a buyer with no saved
     *    addresses. There is no wrapper, and the form is always the one in use;
     *  - inside `#opc-new-shipping-address`, whose own `visible:` binding is
     *    `isFormPopUpVisible()`, for a buyer with saved addresses. There the
     *    form exists — holding store defaults and nothing else — for the whole
     *    of a checkout completed against a saved address. Reading country or
     *    company from it would propagate values nobody chose, and writing into
     *    it would write nowhere the buyer can see.
     *
     * So the question is asked of that WRAPPER, and of its own display only —
     * never of its ancestors. Luma collapses the whole shipping step once the
     * buyer reaches payment, which hides an ancestor of the wrapper while
     * leaving the form the buyer just filled in exactly as authoritative as it
     * was a moment earlier.
     *
     * Both halves of that were found by live verification rather than reasoning:
     * a first revision walked the whole ancestor chain and silently disabled the
     * mirror from the payment step onwards, and a second inferred "in use" from
     * the form having content, which broke the moment a country switch retracted
     * the autofill and emptied it.
     *
     * @returns {object} jQuery set — empty when there is no usable default form
     */
    function primaryAddressRoot() {
        const $root = $(PRIMARY_ADDRESS_ROOT_SELECTOR);
        if (!$root.length) return $root;
        // Fails OPEN on a double with no `.closest()` too, same reason as the
        // `.get()` check below: assume no wrapper rather than disable the
        // whole mechanism against a fixture that never models one.
        const $wrapper = typeof $root.closest === 'function'
            ? $root.closest(NEW_SHIPPING_ADDRESS_WRAPPER_SELECTOR)
            : $();
        if (!$wrapper.length) return $root;
        const node = typeof $wrapper.get === 'function' ? $wrapper.get(0) : null;
        // Fails OPEN on anything that cannot be asked the question: a test
        // double's node is not an element, and a harness `window` need not carry
        // `getComputedStyle`. Refusing to act there would disable the whole
        // mechanism rather than test it.
        if (!node || node.nodeType !== 1) return $root;
        if (typeof window === 'undefined' || typeof window.getComputedStyle !== 'function') {
            return $root;
        }
        const style = window.getComputedStyle(node);
        return style && style.display === 'none' ? $() : $root;
    }

    function scopedField($root, selector) {
        return $root ? $root.find(selector).first() : $(selector);
    }

    /**
     * The control one mirrored field maps to inside a given address form, and
     * the `<select>` behind it when there is one.
     *
     * @param {object} $root jQuery-wrapped address form
     * @param {object} field entry from MIRRORED_FIELDS
     * @returns {{$field: object, select: (Element|null)}}
     */
    function mirroredFieldHandle($root, field) {
        if (field.resolve) return field.resolve($root);
        return { $field: scopedField($root, field.selector), select: null, unscoped: !$root };
    }

    /**
     * The name a `<select>`'s currently selected option DISPLAYS, or '' when
     * nothing is selected.
     *
     * Every comparison and every record for a select is made on this rather
     * than on the option's value, because a region value is a numeric id from
     * the store's own directory tables. An id means nothing outside the store
     * that minted it, and — more immediately — core rebuilds the region option
     * list when the country changes, so an id recorded before the change can
     * stand for a different region after it.
     *
     * @param {Element} select
     * @returns {string}
     */
    function selectedOptionText(select) {
        if (!select || !select.options) return '';
        const option = select.options[select.selectedIndex];
        // A valueless option is core's own placeholder ("Please select a
        // region, state or province"), which is the select's EMPTY state, not
        // an answer. Returning its label instead of '' was a real defect caught
        // live: a country change repopulates the region select from the new
        // country's directory data and lands on that placeholder, so a billing
        // address the buyer had never touched acquired a non-empty region and
        // pinned itself against every subsequent sync.
        if (!option || !option.value) return '';
        return trimmedString(option.text);
    }

    /**
     * The name the option carrying `value` displays, or ''. The inverse of
     * regionOptionValue(), used to record a region write by name.
     *
     * @param {Element} select
     * @param {string} value
     * @returns {string}
     */
    function optionTextForValue(select, value) {
        if (!select || !select.options) return '';
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value === value) return trimmedString(select.options[i].text);
        }
        return '';
    }

    /**
     * A stable identity for one billing address form, so a record written
     * before core rebuilt the form still describes the same address after.
     *
     * Derived from the "same as shipping" checkbox core stamps with the payment
     * method's own code (`billing-address-same-as-shipping-<code>`), because a
     * checkout offering two brands renders one billing form per brand and the
     * two must not share a record. The fieldset itself carries no id to use.
     *
     * @param {object} $root jQuery-wrapped billing address form
     * @returns {string}
     */
    function secondaryAddressKey($root) {
        // Fails OPEN on a double with no `.closest()`: treat as "not a billing
        // address" rather than throw, same convention as primaryAddressRoot().
        const $block = typeof $root.closest === 'function'
            ? $root.closest('.checkout-billing-address')
            : $();
        // Not a billing address at all — the default form, or the document-wide
        // lookup. There is no per-address record to consult, and minting a key
        // would both stamp an attribute onto a form that is not one of these and
        // burn a key another form would then not get.
        if (!$block.length) return '';
        const id = $block
            .find('input[name="billing-address-same-as-shipping"]')
            .first()
            .attr('id');
        if (id) return id;
        // No id to key on. A SHARED constant here would be the worst of the
        // options: two billing forms would answer to one record, so the second
        // one to appear would be judged against writes made into the first —
        // empty fields against a non-empty record, which pins a pristine
        // address. A per-node stamp keeps them separate instead. It dies with
        // the node, so a rebuild loses the record and re-pins, which is the
        // safe direction.
        const existing = $root.attr(FALLBACK_KEY_ATTR);
        if (existing) return existing;
        fallbackKeySequence += 1;
        const minted = 'two-billing-address-' + fallbackKeySequence;
        $root.attr(FALLBACK_KEY_ATTR, minted);
        return minted;
    }

    /**
     * Every field applyAddress() can write, and therefore every field the
     * SAME-CALL retraction below has to be able to take back (a payload that
     * says nothing about a field it wrote for the PREVIOUS selection).
     *
     * `region_id` is a `<select>`, the rest are `<input>`s, so the selector is
     * carried per field rather than derived from the name. This is a
     * DIFFERENT list from MIRRORED_FIELDS/REVERTABLE_FIELD_NAMES above: those
     * drive the country-switch revert and the sync pin, both of which judge a
     * write against the shared AUTOFILL_MARKER_ATTR regardless of which list
     * named the field; this one exists only to route and to retract stale
     * fields WITHIN one applyAddress() call.
     */
    const AUTOFILLED_FIELDS = [
        { name: 'city', selector: 'input[name="city"]' },
        { name: 'postcode', selector: 'input[name="postcode"]' },
        { name: 'street[0]', selector: 'input[name="street[0]"]' },
        { name: 'street[1]', selector: 'input[name="street[1]"]' },
        { name: 'region', selector: 'input[name="region"]' },
        { name: 'region_id', selector: 'select[name="region_id"]' }
    ];

    /**
     * Address forms an address write can be scoped to, billing/invoice role
     * first — the role the payment tile writes as (TWO-25461 §1(a.3)).
     *
     * Built from the same PRIMARY/SECONDARY selectors the mirror above uses,
     * plus a bare-id fallback for a third-party checkout that renders one:
     * there is no `billing-new-address-form` id anywhere in core, which
     * matches SECONDARY_ADDRESS_ROOT_SELECTOR by `data-form` instead.
     *
     * Attribute selectors rather than `#id` throughout: jQuery answers a bare
     * `#id` through `document.getElementById` and returns AT MOST ONE
     * element, while core renders one billing form per payment method.
     */
    const ADDRESS_FORM_ROOT_SELECTORS = [
        SECONDARY_ADDRESS_ROOT_SELECTOR,
        '[id="billing-new-address-form"]',
        PRIMARY_ADDRESS_ROOT_SELECTOR
    ];

    /**
     * A value off an address payload, trimmed, with null/undefined coalesced to
     * ''. The API sends '' for a field the registry has nothing for and omits
     * the key entirely on some records, and those two mean the same thing here.
     *
     * @param {*} value
     * @returns {string}
     */
    function addressValue(value) {
        return value == null ? '' : String(value).trim();
    }

    /**
     * Does the payload speak to this field at all? An empty string does — the
     * API sends one for a field the registry has nothing for — but a missing or
     * null key does not, and the two get different treatment (see
     * applyAddress()).
     *
     * @param {*} value
     * @returns {boolean}
     */
    function hasValue(value) {
        return value !== undefined && value !== null;
    }

    /**
     * Find within a scope, or document-wide when there is none.
     *
     * @param {?object} $root jQuery set to search inside, or null
     * @param {string} selector
     * @returns {object} jQuery set
     */
    function scopedFind($root, selector) {
        return $root ? $root.find(selector) : $(selector);
    }

    /**
     * The raw DOM node behind the first match of a jQuery set, tolerant of
     * which accessor a test double models: `.get(0)` where it exists,
     * indexing directly where it does not (both are real jQuery's own way of
     * reaching it).
     *
     * @param {object} $set jQuery set
     * @returns {?Element}
     */
    function firstElement($set) {
        if (!$set || !$set.length) return null;
        if (typeof $set.get === 'function') return $set.get(0);
        return $set[0] !== undefined ? $set[0] : null;
    }

    /**
     * The selector for a writable field name, or '' for a name that is not one
     * — a field the same-call retraction cannot reach must not be writable at
     * all, so the two are read off one list (AUTOFILLED_FIELDS).
     *
     * @param {string} name
     * @returns {string}
     */
    function fieldSelector(name) {
        const field = AUTOFILLED_FIELDS.filter(function (candidate) {
            return candidate.name === name;
        })[0];
        return field ? field.selector : '';
    }

    /**
     * Clear the given fields where they still hold EXACTLY what this module
     * recorded writing, and forget the recording either way.
     *
     * The asymmetry is the point: anything the buyer has since edited, and
     * anything never autofilled at all, has no matching recording and is left
     * alone. Over-clearing silently deletes buyer input from a keystroke they
     * may have made minutes ago, which is worse than leaving a stale value they
     * can see and correct.
     *
     * `change` fires only for fields actually cleared, so Magento's
     * address-form bookkeeping sees the same event shape a buyer edit produces.
     *
     * @param {Array} fields entries from AUTOFILLED_FIELDS
     * @param {?object} $root scope, or null for document-wide
     * @returns {number} how many fields were cleared
     */
    function retractStaleFields(fields, $root) {
        let cleared = 0;
        fields.forEach(function (field) {
            // PER ELEMENT, never over the set: `.attr()` and `.val()` read the
            // FIRST match while `.val(x)` writes every one, so a set-level check
            // lets one form's marker decide another form's field. The payment
            // step has several forms carrying these names, and the sole-trader
            // write is scoped to one of them — so the first match is routinely
            // a form this module never wrote to.
            const $set = scopedFind($root, field.selector);
            // `.eq()` per element where it exists — some test doubles model a
            // scoped find without it, safely: a SCOPED lookup for one field
            // name matches at most one node, so treating the whole set as the
            // element is the same operation there. Only the unscoped
            // ($root === null) document-wide case can legitimately match
            // several, and every fixture exercising that one does implement
            // `.eq()`.
            const elements = typeof $set.eq === 'function'
                ? Array.from({ length: $set.length }, function (_, i) { return $set.eq(i); })
                : $set.length ? [$set] : [];
            elements.forEach(function ($input) {
                const marker = $input.attr(AUTOFILL_MARKER_ATTR);
                // `undefined` means never autofilled. An empty-string marker is
                // a real recording (the registry had no value for this field)
                // and must still be honoured, so this tests for absence rather
                // than falsiness.
                if (typeof marker === 'undefined') return;
                $input.removeAttr(AUTOFILL_MARKER_ATTR);
                // The region select's marker records its option's DISPLAYED
                // text (see writeAddressInto()), never the raw option value —
                // so the comparison here has to read it the same way.
                const current = field.name === 'region_id'
                    ? selectedOptionText(firstElement($input))
                    : $input.val() || '';
                if (current === marker) {
                    $input.val('');
                    $input.trigger('change');
                    cleared += 1;
                }
            });
        });
        return cleared;
    }

    /**
     * Route an external address payload's street parts onto the form's two
     * address lines (TWO-25461 §2.6). The same rule for an autofill buyer
     * record and a registered-company search hit — deliberately NOT special
     * cased per source.
     *
     *  - a `building`/`apartment` is the more specific locator and takes LINE
     *    1, moving `street` to line 2. With both present they are joined
     *    most-specific-first, the way an address is read aloud ("Apartment 4,
     *    Mill House");
     *  - with neither present, `street` takes line 1 and line 2 is left ALONE —
     *    absent from the returned object rather than written empty, so a
     *    payload that says nothing about a second line cannot blank one the
     *    buyer typed.
     *
     * NO de-duplication between the two lines even when the text is identical:
     * real addresses legitimately repeat a line, so suppressing the second one
     * would discard something the registry actually sent.
     *
     * `street_address` is the company-detail response's spelling for the
     * street, `street` the autofill buyer record's. Coalesced here so both
     * callers share one mapping rather than each translating its own payload.
     *
     * A shop configured for a single street line has no second line to route
     * to, so the two parts are joined onto line 1 there rather than the street
     * being written to a field that does not exist and lost.
     *
     * @param {object} address company address or buyer address record
     * @param {?object} $root scope, or null for document-wide
     * @returns {object} field name → value, keyed as the form names them
     */
    function resolveStreetLines(address, $root) {
        const locator = [addressValue(address.apartment), addressValue(address.building)]
            .filter(Boolean)
            .join(', ');
        if (!hasValue(address.street_address) && !hasValue(address.street) && !locator) {
            return {};
        }
        const street = addressValue(
            hasValue(address.street_address) ? address.street_address : address.street
        );
        if (!locator) return { 'street[0]': street };
        if (!scopedFind($root, fieldSelector('street[1]')).length) {
            return { 'street[0]': street ? `${locator}, ${street}` : locator };
        }
        return { 'street[0]': locator, 'street[1]': street };
    }

    /**
     * The value of the option in a region `<select>` that stands for a
     * free-text region name, or null when nothing matches.
     *
     * Best-effort, and the limit is worth stating: the payload carries a region
     * NAME with no code beside it while Magento needs a region ID, so the only
     * available join is on what the option says. Its text is matched, and its
     * `title` too where the theme puts the region code there — several
     * registries answer "CA" where the shop shows "California", and a shop whose
     * options carry no code simply falls through. Matching nothing writes nothing
     * rather than guessing at an ID.
     *
     * @param {HTMLSelectElement} select
     * @param {string} region trimmed region name from the payload
     * @returns {?string} option value, or null
     */
    function regionOptionValue(select, region) {
        const wanted = region.toLowerCase();
        const options = (select && select.options) || [];
        for (let i = 0; i < options.length; i++) {
            const label = String(options[i].text || '').trim().toLowerCase();
            const code = String(options[i].title || '').trim().toLowerCase();
            if (!options[i].value) continue;
            if (label === wanted || (code && code === wanted)) return options[i].value;
        }
        return null;
    }

    /**
     * Translation from this module's own write-engine field names (which use
     * the DOM's own bracket spelling for the two street lines, and separate
     * 'region'/'region_id' names depending which control was in play) to the
     * single canonical name MIRRORED_FIELDS/the sync pin reads
     * (`street0`/`street1`/`region`).
     *
     * Kept as its own small table rather than folded into AUTOFILLED_FIELDS or
     * MIRRORED_FIELDS: the two lists exist for different reasons (see
     * AUTOFILLED_FIELDS above), and this is the one place they have to agree.
     */
    const MIRROR_FIELD_NAME_ALIASES = {
        'street[0]': 'street0',
        'street[1]': 'street1',
        region_id: 'region'
    };

    /**
     * Write an address payload into one form via the shared field-routing
     * engine (resolveAddressValues()/resolveRegion(), both below), and report
     * what landed under MIRRORED_FIELDS' own naming — the form the mirror's
     * pin bookkeeping (recordMirrorWrites() and friends) reads.
     *
     * The two-phase write/then-trigger split is load-bearing, not stylistic:
     * every value must be in the DOM before the FIRST `change` fires, because
     * a handler on any of these fields serialises the whole form (Magento's
     * own totals/shipping estimate, and every one-page checkout that posts the
     * address on field change) — firing mid-write would send one of those out
     * describing an address that is half the previous company's.
     *
     * @param {object} self the module's own `return {}` object — needed
     *        because resolveAddressValues()/resolveRegion() are instance
     *        methods on it
     * @param {object} address company address or buyer address record
     * @param {?object} $root jQuery set to scope every field read and write
     *        to; document-wide when null
     * @returns {object} field name (MIRRORED_FIELDS convention) → value written
     */
    function writeAddressInto(self, address, $root) {
        const values = self.resolveAddressValues(address, $root);
        const names = Object.keys(values);
        // What the marker attribute records for each written field — the
        // value ITSELF for an `<input>`, but the selected option's DISPLAYED
        // TEXT for the region `<select>`, never its raw value. A region value
        // is a numeric id from the store's own directory tables, meaningless
        // outside the store that minted it; recording it would leave the
        // marker unable to agree with either reader that later judges it —
        // retractStaleFields()'s own `.val()` comparison below, which reads it
        // the same way, and the sync pin's selectedOptionText() comparison,
        // which never reads a select any other way.
        const recordAs = {};
        names.forEach(function (name) {
            recordAs[name] = values[name];
            if (name === 'region_id') {
                const node = firstElement(scopedFind($root, fieldSelector(name)));
                if (node) recordAs[name] = optionTextForValue(node, values[name]) || values[name];
            }
        });
        names.forEach(function (name) {
            const $field = scopedFind($root, fieldSelector(name));
            $field.val(values[name]).attr(AUTOFILL_MARKER_ATTR, recordAs[name]);
        });
        retractStaleFields(
            AUTOFILLED_FIELDS.filter(function (field) {
                return names.indexOf(field.name) === -1;
            }),
            $root
        );
        names.forEach(function (name) {
            scopedFind($root, fieldSelector(name)).trigger('change');
        });
        const written = {};
        names.forEach(function (name) {
            written[MIRROR_FIELD_NAME_ALIASES[name] || name] = recordAs[name];
        });
        return written;
    }

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
     * An organisation-number value carrying this literal prefix is an
     * internal reference minted on our side rather than a registry number
     * the buyer would recognise, so it is NEVER shown to them.
     */
    const HIDDEN_COMPANY_NUMBER_PREFIX = 'TWO:';

    /**
     * The organisation number as it may be SHOWN, or '' when it must not be
     * shown at all (TWO-25326).
     *
     * ONE formatter for every display site — the dropdown row below, the
     * address-step label (renderCompanyIdText() in address-autocomplete.js),
     * the payment tile's own label and the order-intent notice sentence
     * (displayCompanyId() / resolveCompanyNotice() in gateway_method.js) — so
     * a surface added later cannot quietly forget the rule. Callers use the
     * EMPTY return to decide whether to render a label/brackets at all, not
     * just what text to put in one.
     *
     * Display only. The raw value is untouched everywhere it is SUBMITTED
     * (getData(), placeOrderIntent(), the address form's `company_id`
     * attribute), because it is the identifier the API is asked about — the
     * buyer merely never reads it.
     *
     * Case-insensitive on the prefix, and trimmed first: the rule is about
     * which VALUES are internal, and a stored `two: 123` or ` TWO:123` is the
     * same internal reference as `TWO:123`. No registry organisation number
     * begins with letters followed by a colon, so nothing legitimate is
     * hidden by being permissive here.
     *
     * @param {*} value raw organisation number
     * @returns {string} the value to display, or '' to display nothing
     */
    function formatCompanyNumber(value) {
        if (value === null || value === undefined) return '';
        const text = String(value).trim();
        if (!text) return '';
        if (text.toUpperCase().indexOf(HIDDEN_COMPANY_NUMBER_PREFIX) === 0) return '';
        return text;
    }

    /**
     * Remove a copy token from a notice template ALONG WITH the brackets it
     * sits in.
     *
     * The default notice copy is `… by {{companyName}} ({{companyNumber}}) …`
     * (ConfigProvider::getOrderIntentApprovedNotice()), so substituting an
     * empty number would read "Company Name ()" — explicitly ruled out. The
     * brackets belong to the number, not to the sentence, so they go with it.
     *
     * Handles a token NOT in brackets too (a brand copy override is free to
     * place `%3` anywhere), then collapses the double space that leaves.
     *
     * @param {string} text notice template
     * @param {string} token sentinel to remove
     * @returns {string}
     */
    function stripBracketedToken(text, token) {
        if (!text) return '';
        if (!token) return String(text);
        const escaped = token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return String(text)
            .replace(new RegExp('[ \\t]*[([]\\s*' + escaped + '\\s*[)\\]]', 'g'), '')
            .replace(new RegExp(escaped, 'g'), '')
            .replace(/[ \t]{2,}/g, ' ')
            .trim();
    }

    /**
     * Selectors for the checkout's address-form country `<select>`, in
     * PRIORITY ORDER, most specific first.
     *
     * Two entries only, and the shortness is the point. The first is core's own
     * shipping address form, which Luma and Amasty both render and which the
     * address-area picker already reads directly; the second is a deliberate
     * catch-all for a one-page checkout that supplies its own address markup
     * (Fire Checkout), where nothing else in the plugin can find the buyer's
     * country at all.
     *
     * Deliberately NOT in the list: `#billing-new-address-form`. Core renders
     * one billing-address form PER PAYMENT METHOD, so a checkout offering two
     * Two-family brands has several, all but one untouched and carrying the
     * store default country — `.first()` would pick arbitrarily between them.
     * `#co-shipping-form` is not in it either: it is an ANCESTOR of
     * `#shipping-new-address-form` in core, so it could only ever match the
     * same node the first entry already does.
     *
     * The catch-all can still resolve a select the buyer has not touched (core
     * renders the new-address form inside a HIDDEN modal for a customer with
     * saved addresses). That is why the only consumer, searchCountryCode() in
     * company-capture.js, reads this AFTER its own observable rather than before
     * — see that method for the full reasoning.
     *
     * @type {string[]}
     */
    const COUNTRY_SELECT_SELECTORS = [
        '#shipping-new-address-form select[name="country_id"]',
        'select[name="country_id"]'
    ];

    /**
     * The country the buyer currently has selected in the checkout's address
     * form, lower-cased, read LIVE off the DOM — or '' when no address-form
     * country select is present or none has a value.
     *
     * A DOM read rather than a quote- or customer-data-derived one because it
     * answers "what has the buyer chosen", which is knowable before any of that
     * reaches the quote, and knowable on every checkout regardless of which
     * components a given one-page checkout mounts. It is NOT authoritative on
     * its own — see the note above on untouched selects.
     *
     * @returns {string}
     */
    function currentAddressFormCountry() {
        for (let i = 0; i < COUNTRY_SELECT_SELECTORS.length; i++) {
            const $select = $(COUNTRY_SELECT_SELECTORS[i]).first();
            if (!$select.length) continue;
            const value = $select.val();
            if (typeof value === 'string' && value) return value.toLowerCase();
        }
        return '';
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

    /**
     * Every mirrored field of one billing address, paired with what the plugin
     * has on record as having put there.
     *
     * A field the form does not have is left out entirely: there is nothing to
     * compare, and treating an absent field as a mismatch would pin every
     * address whose country's format omits it.
     *
     * Two independent records are consulted for "what we last wrote", and both
     * have to be, for the reasons on mirrorWriteRecords. Each state also
     * carries the value the field was FIRST RENDERED with, which counts only
     * while nothing at all is on record as having been written there — that is
     * what stops a country select pinning the address before the buyer has
     * touched it, since core renders it pre-selected to the store default and a
     * select has no reachable empty state.
     *
     * @param {object} $root jQuery-wrapped billing address form
     * @returns {Array<{name: string, current: string, written: Array<string>,
     *          rendered: string}>}
     */
    function mirroredFieldStates($root) {
        const rendered = secondaryAddressBaselines.get(secondaryAddressKey($root)) || {};
        const states = [];
        MIRRORED_FIELDS.forEach(function (field) {
            const handle = mirroredFieldHandle($root, field);
            if (!handle.$field.length) return;
            states.push({
                name: field.name,
                current: handle.select
                    ? selectedOptionText(handle.select)
                    : trimmedString(handle.$field.val()),
                written: recordedWritesForField($root, field),
                rendered: trimmedString(rendered[field.name])
            });
        });
        return states;
    }

    /**
     * The values the plugin is on record as having written into one field of
     * one form — its marker attribute and, for a billing form, the module
     * record that outlives a rebuild. Empty entries are dropped: an empty
     * record is the absence of a write, not a write of ''.
     *
     * @param {?object} $root jQuery-wrapped address form, or null
     * @param {object} field entry from MIRRORED_FIELDS
     * @returns {Array<string>}
     */
    function recordedWritesForField($root, field) {
        const handle = mirroredFieldHandle($root, field);
        const written = [];
        const marker = handle.$field.attr(AUTOFILL_MARKER_ATTR);
        if (typeof marker !== 'undefined') written.push(marker);
        const key = $root ? secondaryAddressKey($root) : '';
        if (key) {
            const recorded = mirrorWriteRecords.get(key) || {};
            if (typeof recorded[field.name] !== 'undefined') written.push(recorded[field.name]);
        }
        return written.filter(function (value) {
            return trimmedString(value) !== '';
        });
    }

    /**
     * Whether one field still holds what the plugin put there.
     *
     * @param {{current: string, written: Array<string>, rendered: string}} state
     * @returns {boolean}
     */
    function fieldStillHoldsWhatWeWrote(state) {
        const current = normalizeMirroredValue(state.current);
        const matchesWrite = state.written.some(function (value) {
            return normalizeMirroredValue(value) === current;
        });
        if (matchesWrite) return true;
        if (state.written.length) {
            // Something of ours went in here and this is not it. Emptying the
            // field counts: the buyer deleting our value is an edit like any
            // other, and refilling it would be the plugin arguing with them.
            return false;
        }
        // Nothing was ever written here, so the question is whether the buyer
        // has answered the field at all. This is the ordinary state of a
        // freshly opened billing address form.
        return current === '' || current === normalizeMirroredValue(state.rendered);
    }

    /**
     * Whether a billing address is PINNED — the buyer has made it their own,
     * and nothing may be written into ANY of its fields.
     *
     * Address-wide, not per-field, and that is the ruling rather than an
     * implementation convenience: one field that no longer holds what the
     * plugin put there pins the whole address and no field of it is synced.
     *
     * Stated plainly because it is the behaviour and not a corner: the mirror
     * only ever writes into a pristine billing address, and once the buyer
     * touches anything in it, it stays frozen until its contents come back to
     * matching. There is deliberately no control offered to resume syncing —
     * the content match IS the resumption path.
     *
     * @param {object} $root jQuery-wrapped billing address form
     * @returns {boolean}
     */
    function secondaryAddressIsPinned($root) {
        return mirroredFieldStates($root).some(function (state) {
            return !fieldStillHoldsWhatWeWrote(state);
        });
    }

    /**
     * Record what the mirror has just written into one billing address, so a
     * later evaluation — after core has rebuilt the form and destroyed the
     * marker attributes — can still tell those values from the buyer's own.
     *
     * @param {object} $root jQuery-wrapped billing address form
     * @param {object} values field name to recorded value
     */
    function recordMirrorWrites($root, values) {
        const key = secondaryAddressKey($root);
        if (!key) return;
        mirrorWriteRecords.set(key, Object.assign({}, mirrorWriteRecords.get(key) || {}, values));
    }

    /**
     * Retract the record for one field of one billing address, and the marker
     * beside it.
     *
     * The one case that needs this is the region after a country write. Core
     * rebuilds the region option list from the new country's directory data and
     * resets the control while doing it, so a region record written under the
     * previous country describes a value the form can no longer hold — and an
     * empty field against a non-empty record is exactly the mismatch the pin
     * reads as a buyer edit. Retracting is the honest answer: nothing of ours is
     * in there any more.
     *
     * @param {object} $root jQuery-wrapped billing address form
     * @param {string} name field name from MIRRORED_FIELDS
     */
    function clearMirrorWriteRecord($root, name) {
        const key = secondaryAddressKey($root);
        if (!key) return;
        const recorded = Object.assign({}, mirrorWriteRecords.get(key) || {});
        delete recorded[name];
        mirrorWriteRecords.set(key, recorded);
        const field = MIRRORED_FIELDS.filter(function (entry) {
            return entry.name === name;
        })[0];
        if (field) mirroredFieldHandle($root, field).$field.removeAttr(AUTOFILL_MARKER_ATTR);
    }

    /**
     * Write one value into one field, marking it as the plugin's own.
     *
     * `recordAs` is what a later comparison reads, and it differs from the
     * written value for a select — see selectedOptionText() for why a region is
     * only ever compared and recorded by name.
     *
     * `change` is fired because that is the event Knockout's `value:` binding
     * listens for; a bare `.val()` write reaches the DOM and not the checkout's
     * own data provider, so it would be lost on the next re-render and would
     * never reach the quote.
     *
     * @param {object} handle from mirroredFieldHandle()
     * @param {string} value what goes into the control
     * @param {string} recordAs what to attribute to the plugin
     * @returns {boolean} whether anything was written
     */
    function writeMirroredField(handle, value, recordAs) {
        // An UNSCOPED lookup has no presence answer to give: it is used only
        // where there is no address form to scope to, and jQuery's `.val()` and
        // `.attr()` are already no-ops on an empty set. Guarding it would change
        // long-standing behaviour on the one path that cannot tell the
        // difference. A scoped lookup does know, and a field its form does not
        // have must not be reported as written.
        if (!handle.$field.length && !handle.unscoped) return false;
        handle.$field.attr(AUTOFILL_MARKER_ATTR, recordAs);
        handle.$field.val(value);
        handle.$field.trigger('change');
        return true;
    }

    /**
    /** Every billing address form on the page, as individual jQuery sets. */
    function secondaryAddressRoots() {
        const roots = [];
        const $matches = $(SECONDARY_ADDRESS_ROOT_SELECTOR);
        // A zero-length set needs no `.each()` at all — safe against a double
        // that never models one, as long as it correctly reports no matches.
        if ($matches.length && typeof $matches.each === 'function') {
            $matches.each(function () {
                roots.push($(this));
            });
        }
        return roots;
    }

    /**
     * Clear the autofilled fields of one address form that still hold exactly
     * what the plugin put there, and forget the recording.
     *
     * @param {?object} $root jQuery-wrapped address form, or null for unscoped
     * @returns {number} how many fields were cleared
     */
    function revertAddressFormFields($root) {
        const cleared = [];
        MIRRORED_FIELDS.forEach(function (field) {
            if (REVERTABLE_FIELD_NAMES.indexOf(field.name) === -1) return;
            const handle = mirroredFieldHandle($root, field);
            if ($root && !handle.$field.length) return;
            const marker = handle.$field.attr(AUTOFILL_MARKER_ATTR);
            const current = trimmedString(
                handle.select ? selectedOptionText(handle.select) : handle.$field.val() || ''
            );
            // An EMPTY field with a marker on it is still ours to retract — the
            // marker may be an empty-string recording, which is a real one (the
            // registry had no value for this field).
            const markerClaims = typeof marker !== 'undefined' && current === trimmedString(marker);
            // The module record is consulted as well, and it has to be: a
            // rebuild of the form destroys every marker attribute, and the
            // record is the ONLY surviving evidence that these values are the
            // plugin's. Without this a country switch after a rebuild retracted
            // nothing while still retiring the record, leaving the previous
            // country's address in the billing form attributed to nobody — which
            // the pin then reads as buyer-authored and freezes permanently.
            const recordClaims =
                current !== '' &&
                recordedWritesForField($root, field).some(function (value) {
                    return normalizeMirroredValue(value) === normalizeMirroredValue(current);
                });
            handle.$field.removeAttr(AUTOFILL_MARKER_ATTR);
            if (!markerClaims && !recordClaims) return;
            handle.$field.val('');
            handle.$field.trigger('change');
            cleared.push(field.name);
        });
        return cleared;
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

        /** @see formatCompanyNumber */
        HIDDEN_COMPANY_NUMBER_PREFIX: HIDDEN_COMPANY_NUMBER_PREFIX,
        formatCompanyNumber: formatCompanyNumber,
        stripBracketedToken: stripBracketedToken,
        COUNTRY_SELECT_SELECTORS: COUNTRY_SELECT_SELECTORS,
        currentAddressFormCountry: currentAddressFormCountry,

        /**
         * Build the select2 `ajax` option block for the company search.
         *
         * @param {object} options
         * @param {object} options.config brand config subtree; needs
         *        `checkoutApiUrl`, `companySearchLimit` and `orderIntentConfig`
         *        (for `extensionPlatformName`/`extensionDBVersion`)
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
                        q: unescape(params.term),
                        // Identifies the calling plugin to this unauthenticated
                        // browser-facing endpoint, avoiding a CORS preflight per
                        // keystroke — same params/source as gateway_method.js's
                        // order_intent call.
                        client: config.orderIntentConfig?.extensionPlatformName,
                        client_v: config.orderIntentConfig?.extensionDBVersion
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
                        // Display copy of the identifier, which is '' for an
                        // internal `TWO:`-prefixed value (TWO-25326) — the row
                        // then renders exactly as it does for a company with no
                        // identifier at all, name only and no empty brackets.
                        // `companyId` below still carries the RAW value: it is
                        // what gets submitted, and hiding it from the buyer is
                        // not the same as not having it.
                        const displayIdentifier = formatCompanyNumber(identifier);
                        items.push({
                            id: item.name,
                            text: item.name,
                            html: displayIdentifier
                                ? `${item.highlight} (${displayIdentifier})`
                                : item.highlight,
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
         * server-side the AND of `enable_address_search` and
         * `enable_company_search` (Model\Config\Repository::isAddressSearchEnabled,
         * TWO-25503) — company search relocated to the payment tile retires
         * the convenience autofill exists for — and this single gate is the
         * one both pickers share. Neither picker adds a gate of its own.
         *
         * @param {object} config brand config subtree
         * @param {object} selectedCompany select2 result item (needs lookupId)
         * @param {object} [root] scope for the write — see applyAddress()
         * @returns {object|null} the jqXHR, or null when gated off / no id
         */
        lookupCompanyAddress: function (config, selectedCompany, root) {
            if (!config.isAddressSearchEnabled) return null;
            if (!selectedCompany || !selectedCompany.lookupId) return null;

            const self = this;
            const queryParams = new URLSearchParams({
                client: config.orderIntentConfig?.extensionPlatformName,
                client_v: config.orderIntentConfig?.extensionDBVersion
            });
            const addressResponse = $.ajax({
                dataType: 'json',
                timeout: REQUEST_TIMEOUT_MS,
                url: `${config.checkoutApiUrl}/companies/v2/company/${selectedCompany.lookupId}?${queryParams.toString()}`
            });
            addressResponse.done(function (response) {
                if (response && response.addresses && response.addresses.length) {
                    self.applyAddress(response.addresses[0], root);
                }
            });
            return addressResponse;
        },

        SECONDARY_ADDRESS_ROOT_SELECTOR: SECONDARY_ADDRESS_ROOT_SELECTOR,

        /**
         * The form a BILLING/INVOICE-role write belongs in, as a scope for
         * applyAddress() — or null when this checkout renders no address form
         * the write could be scoped to.
         *
         * A scope is needed because the payment step is not a one-form page:
         * Luma leaves `#shipping-new-address-form` in the DOM there, and core
         * renders a billing address form PER PAYMENT METHOD, all carrying the
         * same field names. An unscoped write reaches every one of them.
         *
         * A billing form wins over a shipping one whether or not it is visible —
         * role beats visibility, and a hidden billing form still belongs to this
         * buyer's invoice address. Visibility only picks between several forms
         * matching the SAME selector, which is how the buyer's selected payment
         * method's form is told from the other methods'.
         *
         * The shipping form is the last resort rather than a wrong answer: with
         * "my billing and shipping address are the same" — core's default — no
         * billing form is rendered at all and the shipping form IS the invoice
         * address, which is the sync mechanism §1(a.3) says to read the
         * billing-role value from on a shipping-first platform.
         *
         * @returns {?object} jQuery set of exactly one form, or null
         */
        billingRoleFormRoot: function () {
            for (let i = 0; i < ADDRESS_FORM_ROOT_SELECTORS.length; i++) {
                const $candidates = $(ADDRESS_FORM_ROOT_SELECTORS[i]);
                if (!$candidates.length) continue;
                for (let n = 0; n < $candidates.length; n++) {
                    if ($candidates.eq(n).is(':visible')) return $candidates.eq(n);
                }
                return $candidates.eq(0);
            }
            return null;
        },

        /**
         * Write a company or buyer address into a checkout address form.
         *
         * Scoped to `root` when given — the payment-step picker's
         * sole-trader signup-completion write (TWO-25461 §5) uses this to land
         * in whichever billing-role form is in play (billingRoleFormRoot()),
         * regardless of shipping-form state and regardless of any billing
         * form's sync pin: that write is deliberately final, not a mirror
         * candidate, so it is NOT recorded via recordMirrorWrites() — an
         * unrecorded write differs from both the pin's baseline and any mirror
         * record, which reads as buyer-authored and freezes the form against
         * being overwritten by a later, unrelated shipping-address sync.
         *
         * Unscoped, this writes into the buyer's own working address form —
         * the shipping form when core is rendering one, document-wide when it
         * is not, see primaryAddressRoot() — and then mirrors it into every
         * billing form still in sync.
         *
         * Each field written also records the value in `AUTOFILL_MARKER_ATTR`,
         * which is what makes the write REVERSIBLE (revertAutofilledAddress()
         * below) without ever discarding something the buyer typed, and is
         * half of what the sync pin reads.
         *
         * There is no address-lookup gate here. `config.isAddressSearchEnabled`
         * gates lookupCompanyAddress() — an ordinary search selection — one
         * level up, and the sole-trader write-back must write regardless of
         * where company search is mounted (TWO-25461 §5).
         *
         * @param {object} address company address or buyer address record
         * @param {object} [root] jQuery set to scope the write to a single
         *        form, bypassing the shipping→billing mirror entirely
         * @returns {number} how many billing addresses were synced (always 0
         *          when `root` scopes the write to a single form)
         */
        applyAddress: function (address, root) {
            console.debug({ logger: 'companySearch.applyAddress', address });
            if (root) {
                writeAddressInto(this, address, root);
                return 0;
            }
            const $primary = primaryAddressRoot();
            if ($primary.length) {
                writeAddressInto(this, address, $primary);
                return this.mirrorAddressToSecondaryAddresses(address);
            }
            // No default address form in use — a virtual cart, or a buyer
            // checking out against a saved shipping address. The billing form is
            // then the buyer's OWN working form rather than a mirror of
            // anything, so it is written directly and NOT judged by the pin;
            // that is the pre-existing behaviour of a pick made in the payment
            // tile, which carries its own positional gate for whether autofill
            // should happen there at all (see the tile's addressLookup()).
            //
            // Written PER FORM rather than through one document-wide selector,
            // which matters twice: the region routing inspects the form's own
            // option list, so a document-wide decision would apply one form's
            // answer to all of them; and the writes are recorded per form, so a
            // later evaluation attributes them instead of reading them as
            // buyer-authored.
            const self = this;
            const roots = secondaryAddressRoots();
            if (!roots.length) {
                writeAddressInto(self, address, null);
                return 0;
            }
            roots.forEach(function ($root) {
                recordMirrorWrites($root, writeAddressInto(self, address, $root));
            });
            return 0;
        },

        /**
         * Which form field each part of the payload belongs in, resolved before
         * anything is written so the city can carry an appended region without
         * being written twice.
         *
         * Keys absent from the payload are absent from the result: see
         * applyAddress() on why an omission is not a blank.
         *
         * @param {object} address company address or buyer address record
         * @param {?object} $root scope, or null for document-wide
         * @returns {object} field name → value to write
         */
        resolveAddressValues: function (address, $root) {
            const values = {};
            if (hasValue(address.city)) values.city = addressValue(address.city);
            if (hasValue(address.postal_code)) values.postcode = addressValue(address.postal_code);
            Object.assign(values, resolveStreetLines(address, $root));
            Object.assign(values, this.resolveRegion(address, $root, values));
            return values;
        },

        /**
         * Where the payload's `region` can land, in the order the address format
         * allows (TWO-25461 §2.6):
         *
         *  1. the region `<select>`, when the country has predefined regions AND
         *     an option matches the region text (best-effort — see
         *     regionOptionValue());
         *  2. the free-text region input, for a country with no predefined
         *     regions;
         *  3. failing both, appended to the city with a comma ("Ashford, Kent")
         *     — a lossy home, but a visible and correctable one, where dropping
         *     the region silently is neither.
         *
         * Which control is in play is resolveRegionField()'s call (shared with
         * the sync pin's own field-state reading above), not CSS visibility —
         * core keeps both controls in the form and hides the one the current
         * country does not use.
         *
         * Appends at most once: a city that already ends with the region is left
         * alone, so a payload with no city of its own cannot grow the field on
         * every pass.
         *
         * @param {object} address company address or buyer address record
         * @param {?object} $root scope, or null for document-wide
         * @param {object} values values resolved so far, for the city append
         * @returns {object} field name → value, empty when the region has no home
         */
        resolveRegion: function (address, $root, values) {
            const region = addressValue(address.region);
            if (!region) return {};

            const handle = resolveRegionField($root);
            if (handle.select) {
                const optionValue = regionOptionValue(handle.select, region);
                // An unmatched region falls through to the city rather than
                // guessing at a region id — a wrong id is a wrong address, and
                // on a required select an unmatched write would also blank the
                // buyer's own answer.
                if (optionValue !== null) return { region_id: optionValue };
            } else {
                // Fails OPEN when the field double models no `.is()` (several
                // sibling test suites don't): treating that as "not visible"
                // would silently drop every free-text region write against
                // those fixtures, which is a worse failure than the visibility
                // gate ever existed to prevent.
                const isVisible = typeof handle.$field.is === 'function'
                    ? handle.$field.is(':visible')
                    : true;
                if (handle.$field.length && isVisible) return { region: region };
            }

            const $city = scopedFind($root, 'input[name="city"]');
            if (!$city.length) return {};
            if (!hasValue(values.city)) {
                // The payload said nothing about a city. An EMPTY field has no
                // answer of its own to protect, and the region still has to
                // land somewhere or it is silently dropped from a payload
                // that carried it — but a field the buyer already typed into
                // is their own answer, and appending onto a city this write
                // is not otherwise touching would hand their own text to the
                // next revert (no marker was ever recorded for it).
                return trimmedString($city.val()) ? {} : { city: region };
            }
            const city = values.city;
            if (city.toLowerCase().endsWith(region.toLowerCase())) return {};
            return { city: city ? `${city}, ${region}` : region };
        },

        /**
         * Mirror a company address into every billing address form that is
         * still in sync, skipping every one the buyer has made their own.
         *
         * @param {object} address company address record from the API
         * @returns {number} how many billing addresses were written
         */
        mirrorAddressToSecondaryAddresses: function (address) {
            // The country FIRST, and it is not an optimisation. The store's
            // default country is frequently not the buyer's, so the billing form
            // can be sitting on a different country from the address about to be
            // written into it — and the region routing reads that country's own
            // option list to decide between the state control and the city
            // append. Mirroring the country first means both forms route the
            // same region the same way; leaving it meant one form selected a
            // state while the other appended a state name into its city.
            this.mirrorFieldsToSecondaryAddresses(['country']);
            const self = this;
            let synced = 0;
            secondaryAddressRoots().forEach(function ($root) {
                if (secondaryAddressIsPinned($root)) return;
                recordMirrorWrites($root, writeAddressInto(self, address, $root));
                synced += 1;
            });
            console.debug({ logger: 'companySearch.mirrorAddressToSecondaryAddresses', synced });
            return synced;
        },

        /**
         * Propagate named fields of the DEFAULT address onto every billing
         * address that is still in sync — the country/company propagation the
         * two-address model asks for.
         *
         * Reads the shipping form live rather than taking values from the
         * caller, so there is exactly one answer to "what does the default
         * address say" and it is the one on screen.
         *
         * Writing the country retracts the region record beside it, because
         * core rebuilds the region option list from the new country's directory
         * data — see clearMirrorWriteRecord() for why leaving the record would
         * pin the address on the next evaluation.
         *
         * @param {Array<string>} names field names from MIRRORED_FIELD_NAMES
         * @returns {number} how many billing addresses were written
         */
        mirrorFieldsToSecondaryAddresses: function (names) {
            const $primary = primaryAddressRoot();
            if (!$primary.length) return 0;
            const fields = MIRRORED_FIELDS.filter(function (field) {
                return names.indexOf(field.name) !== -1;
            });
            let synced = 0;
            secondaryAddressRoots().forEach(function ($root) {
                if (secondaryAddressIsPinned($root)) return;
                const written = {};
                fields.forEach(function (field) {
                    const source = mirroredFieldHandle($primary, field);
                    const target = mirroredFieldHandle($root, field);
                    if (!source.$field.length || !target.$field.length) return;
                    const value = source.$field.val();
                    const recordAs = source.select
                        ? selectedOptionText(source.select)
                        : trimmedString(value);
                    if (writeMirroredField(target, value, recordAs)) {
                        written[field.name] = recordAs;
                    }
                });
                recordMirrorWrites($root, written);
                if (Object.prototype.hasOwnProperty.call(written, 'country')) {
                    clearMirrorWriteRecord($root, 'region');
                }
                synced += 1;
            });
            console.debug({
                logger: 'companySearch.mirrorFieldsToSecondaryAddresses',
                names,
                synced
            });
            return synced;
        },

        /**
         * Record what a billing address form was FIRST rendered holding, so the
         * pin can tell the store's own defaults from the buyer's answers.
         *
         * Called the moment core puts the form in the document — before the
         * buyer can have touched it — because that is the only moment the
         * distinction is knowable. Core renders the country select pre-selected
         * to the store default and a select has no reachable empty state, so
         * without this baseline a billing address whose default country differs
         * from the buyer's shipping country would read as buyer-edited and pin
         * itself before the buyer had ever looked at it.
         *
         * First capture wins. A re-render replaces the nodes but not the
         * buyer's answers, so re-capturing would quietly adopt whatever they
         * had typed as a store default.
         *
         * @param {object} root DOM node or jQuery set for the billing form
         */
        captureSecondaryAddressBaseline: function (root) {
            const $root = $(root);
            if (!$root.length) return;
            const key = secondaryAddressKey($root);
            if (!key || secondaryAddressBaselines.has(key)) return;
            // Too late to trust what the form is holding: the mirror has already
            // written into some billing address on this page, and every billing
            // form renders from the same quote billing address, so a form
            // appearing now can be carrying a value the buyer authored in a
            // sibling form. Seal an EMPTY baseline instead — only a genuinely
            // empty field then counts as unanswered, which pins rather than
            // overwrites. Reachable when the buyer switches payment method,
            // since each method has a billing form of its own.
            if (mirrorWriteRecords.size) {
                secondaryAddressBaselines.set(key, {});
                return;
            }
            const rendered = {};
            MIRRORED_FIELDS.forEach(function (field) {
                const handle = mirroredFieldHandle($root, field);
                if (!handle.$field.length) return;
                rendered[field.name] = handle.select
                    ? selectedOptionText(handle.select)
                    : trimmedString(handle.$field.val());
            });
            // A form whose COUNTRY select has not rendered yet is a form whose
            // fields have not rendered yet. Core inserts the fieldset before its
            // child field components resolve their templates, and `$.async`
            // fires on the insertion — so storing this would seal a baseline of
            // nothing, and every field of the real render would then read as
            // buyer-authored and pin the address before the buyer saw it.
            // Declining leaves the next `$.async` fire to capture it properly.
            if (!Object.prototype.hasOwnProperty.call(rendered, 'country')) {
                console.debug({
                    logger: 'companySearch.captureSecondaryAddressBaseline.deferred',
                    key
                });
                return;
            }
            secondaryAddressBaselines.set(key, rendered);
            console.debug({
                logger: 'companySearch.captureSecondaryAddressBaseline',
                key,
                rendered
            });
        },

        /** @see secondaryAddressIsPinned */
        secondaryAddressIsPinned: function (root) {
            return secondaryAddressIsPinned($(root));
        },

        /** Drop every mirror record and baseline. Exists for tests. */
        resetMirrorState: function () {
            mirrorWriteRecords.clear();
            secondaryAddressBaselines.clear();
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
         * Reaches the billing address too, and is PIN-AWARE there: a pinned
         * billing address is skipped whole, never field by field. Clearing one
         * field of it because that field happened to still match would break the
         * address-wide rule the pin exists to enforce — the buyer owns the whole
         * address once they have touched any of it, including the parts they
         * left alone.
         *
         * @returns {number} how many fields were cleared — for tests, and so a
         *          caller can tell "nothing was ours" from "reverted"
         */
        revertAutofilledAddress: function () {
            const $primary = primaryAddressRoot();
            let cleared = revertAddressFormFields($primary.length ? $primary : null).length;
            if ($primary.length) {
                secondaryAddressRoots().forEach(function ($root) {
                    if (secondaryAddressIsPinned($root)) return;
                    // Only the fields actually emptied are retracted. Retracting
                    // the whole revertable set unconditionally was a real defect:
                    // a field the retraction declined to clear kept its value and
                    // lost its record in the same pass, which is exactly the
                    // "non-empty field, nothing on record" shape the pin reads as
                    // a buyer edit.
                    const retracted = revertAddressFormFields($root);
                    retracted.forEach(function (name) {
                        clearMirrorWriteRecord($root, name);
                    });
                    cleared += retracted.length;
                });
            }
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
                    $t('Company search is temporarily unavailable. Please try again, or enter details manually.') +
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
