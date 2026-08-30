/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Mount-agnostic company-search primitives: the search request, the result
 * mapping (including `lookup_id`, whose omission was TWO-25193), the
 * company-detail fetch and the address write-back.
 *
 * Nothing here knows what the control looks like. The popover that renders
 * these results is `company-search-panel.js`; where it is mounted and what its
 * chips mean is `company-capture-component.js`.
 */
define([
    'jquery',
    'mage/url',
    'Magento_Ui/js/model/messageList',
    'mage/translate'
], function ($, url, messageList, $t) {
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
     * How long searching stays parked after the proxy answers 429. Matches
     * the server's window, so the buyer's next keystroke does not walk
     * straight back into the ceiling it just hit.
     */
    const RATE_LIMIT_BACKOFF_MS = 60000;

    /** Epoch ms before which no search is issued. @see RATE_LIMIT_BACKOFF_MS */
    let searchSuspendedUntil = 0;

    /**
     * Search-result cache. MODULE-scoped on purpose: one-page checkouts
     * (Fire Checkout) re-render the payment renderer on every totals or
     * shipping change, which rebuilds the panel. A cache owned by the panel
     * would be thrown away each time and every search the buyer already waited
     * for would be re-issued. Keyed by country and search term.
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
     * The in-flight request per bind token. A WeakMap so a discarded bind
     * token takes its entry with it.
     */
    const activeRequests = new WeakMap();

    /**
     * Shortest term the search will act on. The ONE place this number is
     * written: it gates the request AND is interpolated into the hint the
     * buyer reads, so the two cannot drift apart.
     */
    const MIN_INPUT_LENGTH = 3;

    /**
     * The "keep typing" hint shown while the term is below MIN_INPUT_LENGTH.
     *
     * Resolved per call, not once at module load, because Magento's JS
     * dictionary can arrive after this module is defined. Magento's `$t`
     * does not interpolate, hence the explicit replace.
     *
     * @returns {string} translated hint naming MIN_INPUT_LENGTH
     */
    function minInputLengthMessage() {
        return $t('Enter %1 or more characters').replace('%1', MIN_INPUT_LENGTH);
    }

    /**
     * The zero-results message. TWO-25326 §1 pins the cross-platform wording
     * as "No matches found".
     *
     * @returns {string} translated zero-results message
     */
    function noResultsMessage() {
        return $t('No matches found');
    }

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
     * The name fields and the telephone are deliberately absent: their values
     * are the buyer's own — applyTelephone() writes the one exception, and
     * deliberately leaves no record — so counting them would pin the billing
     * address the moment it rendered with a name in it, i.e. always.
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

    /** @see applyTelephone — the one field written outside AUTOFILLED_FIELDS. */
    const TELEPHONE_FIELD_SELECTOR = 'input[name="telephone"]';

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
     * saved addresses). That is why the only consumer, countryCode() in
     * company-capture-component.js, reads this last of all.
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
    /**
     * Who is calling, for Two's unauthenticated browser-facing endpoints. One
     * builder so the call sites cannot drift apart.
     *
     * `merchant` is the short name off the memoised verify_api_key record the
     * server already ships in checkoutConfig, never a second live call from the
     * browser. Absent keys are omitted rather than sent as "undefined".
     *
     * @param {object} config the brand's checkout config subtree
     * @returns {object}
     */
    function apiClientParams(config) {
        const intent = (config && config.orderIntentConfig) || {};
        const params = {};
        if (intent.extensionPlatformName) params.client = intent.extensionPlatformName;
        if (intent.extensionDBVersion) params.client_v = intent.extensionDBVersion;
        const shortName = intent.merchant && intent.merchant.short_name;
        if (shortName) params.merchant = shortName;
        return params;
    }

    /**
     * POST to one of the plugin's own registry-proxy routes.
     *
     * Registry calls run server-side so the merchant API key authenticates
     * them and a configured firewall token can be attached — neither ever
     * reaches the browser.
     *
     * @param {string} path storefront-relative REST path
     * @param {object} data request body
     * @returns {object} jqXHR
     */
    function proxyPost(path, data) {
        return $.ajax({
            url: url.build(path),
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            timeout: REQUEST_TIMEOUT_MS,
            data: JSON.stringify(data)
        });
    }

    /**
     * Unwrap `{ok, status, body}` from a proxy route.
     *
     * Magento's webapi layer hands a `: string` return back as a one-element
     * array of JSON in some serialisation paths and as the bare string in
     * others; both shapes reach here.
     *
     * @param {*} raw
     * @returns {{ok: boolean, status: number, body: *}}
     */
    function unwrapProxyResponse(raw) {
        const first = Array.isArray(raw) ? raw[0] : raw;
        let parsed = first;
        if (typeof first === 'string') {
            try {
                parsed = JSON.parse(first);
            } catch (e) {
                return { ok: false, status: 0, body: null };
            }
        }
        if (!parsed || typeof parsed !== 'object') return { ok: false, status: 0, body: null };
        return { ok: !!parsed.ok, status: parsed.status || 0, body: parsed.body };
    }

    /**
     * Tell the buyer the address did not arrive. Without this the fields stay
     * blank with nothing said, which reads as the picker having done nothing.
     */
    function announceAddressUnavailable() {
        messageList.addErrorMessage({
            message: $t('We could not fetch this company\'s address. Please enter it below.')
        });
    }

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

    /**
     * Map a search response into the rows the panel renders.
     *
     * @param {object} response parsed `/companies/v2/company` response
     * @returns {Array<{id: string, text: string, html: string,
     *          companyId: string, lookupId: string}>}
     */
    function mapSearchResults(response) {
        const items = [];
        const responseItems = (response && response.items) || [];
        for (let i = 0; i < responseItems.length; i++) {
            const item = responseItems[i];
            /*
             * `national_identifier` is optional in the search response — the
             * company may have none in its home registry, and the object
             * itself may be absent, null, or carry a null/empty `id`.
             *
             * So render the company with whatever it has. The identifier is
             * only the buyer's disambiguator between two similarly-named
             * companies; dropping the hit instead would remove a company they
             * can no longer select at all. Selecting it is what gives them a
             * route to the organisation number: the panel treats an empty
             * `companyId` as authoritative and clears any previously selected
             * company's identifier. WITHOUT that, an empty `companyId` here
             * silently kept the previous company's organisation number and
             * submitted it under this company's name.
             *
             * The buyer cannot then TYPE that number ANYWHERE: the tile's
             * company-number field is read-only (TWO-25288) and the address
             * step's is CSS-hidden unconditionally. An identifier-less company
             * is therefore refused server-side by Model/Two.php::authorize().
             */
            const identifier =
                item.national_identifier && item.national_identifier.id
                    ? String(item.national_identifier.id)
                    : '';
            // Display copy of the identifier, which is '' for an internal
            // `TWO:`-prefixed value (TWO-25326) — the row then renders exactly
            // as it does for a company with no identifier at all, name only
            // and no empty brackets. `companyId` below still carries the RAW
            // value: it is what gets submitted, and hiding it from the buyer is
            // not the same as not having it.
            const displayIdentifier = formatCompanyNumber(identifier);
            items.push({
                id: item.name,
                text: item.name,
                html: displayIdentifier
                    ? `${item.highlight} (${displayIdentifier})`
                    : item.highlight,
                companyId: identifier,
                // Required by lookupCompanyAddress(); dropping it silently
                // disables address autofill.
                lookupId: item.lookup_id
            });
        }
        return items;
    }

    return {
        REQUEST_TIMEOUT_MS: REQUEST_TIMEOUT_MS,
        SEARCH_DEBOUNCE_MS: SEARCH_DEBOUNCE_MS,
        MIN_INPUT_LENGTH: MIN_INPUT_LENGTH,
        AUTOFILL_MARKER_ATTR: AUTOFILL_MARKER_ATTR,
        isDegradedResponse: isDegradedResponse,
        minInputLengthMessage: minInputLengthMessage,
        noResultsMessage: noResultsMessage,


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

        /** Drop every cached search result and any rate-limit backoff. Exists for tests. */
        clearResultCache: function () {
            resultCache.clear();
            searchSuspendedUntil = 0;
        },

        /** @see formatCompanyNumber */
        HIDDEN_COMPANY_NUMBER_PREFIX: HIDDEN_COMPANY_NUMBER_PREFIX,
        formatCompanyNumber: formatCompanyNumber,
        stripBracketedToken: stripBracketedToken,
        COUNTRY_SELECT_SELECTORS: COUNTRY_SELECT_SELECTORS,
        currentAddressFormCountry: currentAddressFormCountry,
        apiClientParams: apiClientParams,

        unwrapProxyResponse: unwrapProxyResponse,

        /**
         * Run one company search and hand back rows the panel can render.
         *
         * Answers from cache where the same country and term have already been
         * searched, is bounded by REQUEST_TIMEOUT_MS, and is abortable through
         * `abortActiveRequest(token)`. Debouncing belongs to the caller: the
         * panel owns the query field, so it is what knows when a keystroke has
         * superseded the previous one.
         *
         * Never rejects. A failed or degraded search resolves `unavailable`,
         * which the buyer reads as "the search is down" rather than "your
         * company is not here" — the distinction TWO-25326 exists for.
         *
         * @param {object} options
         * @param {string} options.term the buyer's query
         * @param {function(): (string|undefined)} options.getCountryCode
         *        returns the current ISO country code (any case)
         * @param {object} options.token bind identity, so an abort raised
         *        against a torn-down panel cannot cancel the live one's search
         * @returns {Promise<{items: Array, unavailable: boolean, aborted: boolean}>}
         */
        searchCompanies: function (options) {
            const token = options.token;
            const country = options.getCountryCode()?.toUpperCase();
            const cacheKey = `search|${country}|${options.term}`;

            // Before the park below: a cached answer costs no request, so
            // parking it would degrade the panel for nothing.
            const cached = cacheGet(cacheKey);
            if (cached) {
                return Promise.resolve({
                    items: mapSearchResults(cached),
                    unavailable: false,
                    aborted: false
                });
            }

            if (Date.now() < searchSuspendedUntil) {
                return Promise.resolve({ items: [], unavailable: true, aborted: false });
            }

            return new Promise(function (resolve) {
                const request = proxyPost('rest/V1/two/company-search', {
                    country: country,
                    query: options.term
                });
                const handle = {
                    abort: function () {
                        request.abort();
                    }
                };
                // Guarded: WeakMap.set throws on a non-object key, and a crash
                // here would take the whole panel down. Checks the TYPE, not
                // just truthiness — a string token is truthy and still throws.
                if (token && typeof token === 'object') {
                    activeRequests.set(token, handle);
                } else {
                    console.error('companySearch: searchCompanies called without a bind token');
                }

                request.done(function (raw) {
                    const envelope = unwrapProxyResponse(raw);
                    if (!envelope.ok) {
                        resolve({ items: [], unavailable: true, aborted: false });
                        return;
                    }
                    cacheSet(cacheKey, envelope.body);
                    // A degraded 200 is a failure dressed as a success:
                    // near-empty results because the provider timed out.
                    resolve({
                        items: mapSearchResults(envelope.body),
                        unavailable: isDegradedResponse(envelope.body),
                        aborted: false
                    });
                });
                request.fail(function (jqXHR, textStatus) {
                    // 429 arrives as a raw Magento webapi fault, not an
                    // envelope — the ceiling is enforced before the route
                    // runs. Park searching rather than let each keystroke
                    // re-hit it.
                    if (jqXHR && jqXHR.status === 429) {
                        searchSuspendedUntil = Date.now() + RATE_LIMIT_BACKOFF_MS;
                    }
                    // A genuine abort is the buyer typing on, or the panel
                    // being torn down — expected, and silent by design. A
                    // timeout is NOT an abort and must be visible, or the
                    // buyer reads a hung backend as "my company is not
                    // accepted here".
                    const aborted = textStatus === 'abort';
                    resolve({ items: [], unavailable: !aborted, aborted: aborted });
                });
                request.always(function () {
                    if (token && activeRequests.get(token) === handle) {
                        activeRequests.delete(token);
                    }
                });
            });
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
         * @param {object} selectedCompany search result row (needs lookupId)
         * @param {object} [root] scope for the write — see applyAddress()
         * @returns {object|null} the jqXHR, or null when gated off / no id
         */
        lookupCompanyAddress: function (config, selectedCompany, root) {
            if (!config.isAddressSearchEnabled) return null;
            if (!selectedCompany || !selectedCompany.lookupId) return null;

            const self = this;
            const addressResponse = proxyPost('rest/V1/two/company', {
                lookupId: selectedCompany.lookupId
            });
            addressResponse.done(function (raw) {
                const envelope = unwrapProxyResponse(raw);
                const response = envelope.ok ? envelope.body : null;
                if (response && response.addresses && response.addresses.length) {
                    self.applyAddress(response.addresses[0], root);
                    return;
                }
                announceAddressUnavailable();
            });
            addressResponse.fail(function (jqXHR, textStatus) {
                if (textStatus !== 'abort') announceAddressUnavailable();
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
         * @see primaryAddressRoot
         * @returns {boolean} whether the buyer's own shipping form is in play
         */
        hasPrimaryAddressForm: function () {
            return !!primaryAddressRoot().length;
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
         * Write a verified buyer's own phone number into the telephone field.
         *
         * The deliberate exception to applyAddress() never touching telephone,
         * and left unrecorded because the buyer's own number is not something a
         * country switch invalidates the way a registry address is.
         *
         * @param {string} phone
         * @param {object} [root] jQuery set to scope the write to a single
         *        form; defaults to billingRoleFormRoot(), as applyAddress()
         * @returns {boolean} whether a field was written
         */
        applyTelephone: function (phone, root) {
            if (typeof phone !== 'string' || !phone.trim()) return false;
            const $field = scopedFind(root || this.billingRoleFormRoot(), TELEPHONE_FIELD_SELECTOR);
            if (!$field.length) return false;
            $field.val(phone.trim()).trigger('change');
            return true;
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
        }

    };
});
