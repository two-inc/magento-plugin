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
    'mage/translate'
], function ($, url, $t) {
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

    /**
     * Epoch ms before which no registry call is issued, keyed by the calling
     * panel's IDENTITY: a 429 one panel earns must not silence the other's
     * searching or put an "address unavailable" notice on its identity
     * (TWO-25554).
     *
     * @see RATE_LIMIT_BACKOFF_MS
     */
    let registrySuspensions = new WeakMap();

    function isRegistrySuspended(scope) {
        return Date.now() < (registrySuspensions.get(scope) || 0);
    }

    function suspendRegistry(scope) {
        registrySuspensions.set(scope, Date.now() + RATE_LIMIT_BACKOFF_MS);
    }

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
     * Last-REQUEST-wins guard for the address lookup, one per calling panel:
     * picking company B while A is in flight must not write A's address under
     * B, and — since TWO-25554 — a shipping-panel pick in flight must not be
     * aborted by an unrelated billing-panel pick either. Keyed by the address
     * form `lookupCompanyAddress()` was scoped to, so two independent panels
     * never share a generation counter or abort each other's request.
     */
    const addressLookupStates = new WeakMap();

    /**
     * @param {object} root jQuery-wrapped scope `lookupCompanyAddress()` was
     *        called with
     * @returns {{generation: number, pending: ?object}}
     */
    function addressLookupState(root) {
        // The set itself where a double models no element accessor — still one
        // key per root, which is all this is keyed for.
        const key = firstElement(root) || root;
        let state = addressLookupStates.get(key);
        if (!state) {
            state = { generation: 0, pending: null };
            addressLookupStates.set(key, state);
        }
        return state;
    }

    /**
     * What applyAddress() last wrote for ONE panel, field name → value.
     *
     * The DOM markers are the primary record and this is their survivor: a
     * checkout rebuilding an address fieldset destroys every attribute on it,
     * and a country switch after such a rebuild still has to retract the
     * previous country's address rather than leave it standing (TWO-25554).
     *
     * Keyed on the calling panel's IDENTITY, which is per-panel and unreachable
     * from the other one, and which a one-step checkout replacing the whole
     * payment-methods subtree — where the billing form lives — does not replace.
     */
    const addressWriteRecords = new WeakMap();

    /**
     * @param {object} identity the calling panel's own identity
     * @returns {object} that panel's live record, created empty on first use
     */
    function addressWriteRecord(identity) {
        let record = addressWriteRecords.get(identity);
        if (!record) {
            record = {};
            addressWriteRecords.set(identity, record);
        }
        return record;
    }

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
     * The checkout's two address forms.
     *
     * The billing form is matched on `data-form` because core's
     * `billing-address/form.html` gives its fieldset no id — only
     * `shipping-address/form.html` carries one.
     */
    const PRIMARY_ADDRESS_ROOT_SELECTOR = '#shipping-new-address-form';
    /** @see primaryAddressRoot — core's saved-addresses wrapper for that form. */
    const NEW_SHIPPING_ADDRESS_WRAPPER_SELECTOR = '#opc-new-shipping-address';
    const SECONDARY_ADDRESS_ROOT_SELECTOR = '[data-form="billing-new-address"]';

    /** The country select of one address form. */
    const COUNTRY_FIELD = { name: 'country', selector: 'select[name="country_id"]' };

    /**
     * The fields a country switch retracts from the panel's own form, and the
     * three whose absence is deliberate.
     *
     * `country` is excluded because the buyer has just chosen it — clearing it
     * would undo the very edit that triggered the retraction. `company` and
     * `organization` are excluded because they are owned by setCompanyData() in
     * view/address-autocomplete.js, which clears both on the same switch; a
     * second clearing path for them would mean two owners of one field.
     *
     * `region` resolves through a function rather than a selector because core
     * renders two mutually exclusive controls for it and which one is in play
     * depends on the country; see resolveRegionField().
     *
     * `written` is the name applyAddress() records the field under
     * (AUTOFILLED_FIELDS), which is not this list's own name for either street
     * line; region's depends on which control is in play, so it is resolved per
     * call instead.
     */
    const REVERTABLE_FIELDS = [
        { name: 'street0', written: 'street[0]', selector: 'input[name="street[0]"]' },
        { name: 'street1', written: 'street[1]', selector: 'input[name="street[1]"]' },
        { name: 'city', written: 'city', selector: 'input[name="city"]' },
        { name: 'postcode', written: 'postcode', selector: 'input[name="postcode"]' },
        { name: 'region', resolve: resolveRegionField }
    ];

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
                    return { $field: $select, select: select };
                }
            }
        }
        return {
            $field: scopedField($root, 'input[name="region"]'),
            select: null
        };
    }

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
     * was a moment earlier. Nor is "in use" inferable from the form having
     * content: a country switch retracts the autofill and empties it.
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
        return $root.find(selector).first();
    }

    /**
     * The control one revertable field maps to inside a given address form, and
     * the `<select>` behind it when there is one.
     *
     * @param {object} $root jQuery-wrapped address form
     * @param {object} field entry from REVERTABLE_FIELDS
     * @returns {{$field: object, select: (Element|null)}}
     */
    function revertableFieldHandle($root, field) {
        if (field.resolve) return field.resolve($root);
        return { $field: scopedField($root, field.selector), select: null };
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
        // region, state or province"), which is the select's EMPTY state and
        // never an answer: a country change repopulates the region select from
        // the new country's directory data and lands on that placeholder.
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
     * Every field applyAddress() can write, and therefore every field the
     * SAME-CALL retraction below has to be able to take back (a payload that
     * says nothing about a field it wrote for the PREVIOUS selection).
     *
     * `region_id` is a `<select>`, the rest are `<input>`s, so the selector is
     * carried per field rather than derived from the name. This is a
     * DIFFERENT list from REVERTABLE_FIELDS above, which drives the
     * country-switch revert; both judge a write against the shared
     * AUTOFILL_MARKER_ATTR, but this one exists only to route and to retract
     * stale fields WITHIN one applyAddress() call.
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
     * Find within one address form. Never document-wide: every reader and
     * writer in this module belongs to exactly one panel's form (TWO-25554).
     *
     * @param {object} $root jQuery set to search inside
     * @param {string} selector
     * @returns {object} jQuery set
     */
    function scopedFind($root, selector) {
        return $root.find(selector);
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
     * @param {object} $root the form to scope to
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
            // `.eq()` per element where it exists — a scoped lookup for one
            // field name matches at most one node, so treating the whole set as
            // the element is the same operation for a double without it.
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
     * @param {object} $root the form to scope to
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
     * Write an address payload into one form via the shared field-routing
     * engine (resolveAddressValues()/resolveRegion(), both below).
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
     * @param {object} identity the calling panel's own identity, keying the
     *        recording
     */
    function writeAddressInto(self, address, $root, identity) {
        const values = self.resolveAddressValues(address, $root);
        const names = Object.keys(values);
        // What the marker attribute records for each written field — the
        // value ITSELF for an `<input>`, but the selected option's DISPLAYED
        // TEXT for the region `<select>`, never its raw value. A region value
        // is a numeric id from the store's own directory tables, meaningless
        // outside the store that minted it; recording it would leave the
        // marker unable to agree with the reader that later judges it —
        // retractStaleFields()'s own `.val()` comparison below reads it the
        // same way.
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
        // Replaced wholesale rather than merged: a field this payload says
        // nothing about is retracted below, so a recording for it would outlive
        // the value it describes.
        const record = addressWriteRecord(identity);
        Object.keys(record).forEach(function (name) { delete record[name]; });
        Object.assign(record, recordAs);
        retractStaleFields(
            AUTOFILLED_FIELDS.filter(function (field) {
                return names.indexOf(field.name) === -1;
            }),
            $root
        );
        names.forEach(function (name) {
            scopedFind($root, fieldSelector(name)).trigger('change');
        });
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
     * ONE formatter for every display site — the dropdown row below, each
     * capture panel's own number label (displayCompanyNumber() in
     * company-capture-component.js) and the order-intent notice sentence
     * (resolveCompanyNotice() in gateway_method.js) — so a surface added later
     * cannot quietly forget the rule. Callers use the EMPTY return to decide
     * whether to render a label/brackets at all, not just what text to put in
     * one.
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
     *
     * @param {object} identity the CALLING panel's own identity (TWO-25554) —
     *        this module is shared by every panel, so the notice has to land
     *        on whichever one is actually searching, not a fixed instance.
     */
    function announceAddressUnavailable(identity) {
        identity.addressNotice(
            $t('We could not fetch this company\'s address. Please enter it below.')
        );
    }

    /**
     * Tell the buyer the address had nowhere to land. Its own wording, because
     * the sibling notice above asks them to enter it below and this fires
     * precisely when there is no form below to enter it into.
     *
     * @param {object} identity the CALLING panel's own identity
     */
    function announceAddressUndeliverable(identity) {
        identity.addressNotice(
            $t('We could not fill in this company\'s address on this page.')
        );
    }

    /** The clear half of both announcements above. */
    function withdrawAddressUnavailable(identity) {
        identity.addressNotice('');
    }

    /**
     * The country selected in ONE address form, lower-cased, read live off the
     * DOM — or '' when that form has no country select or no value in it.
     *
     * `$root` is required: a document-wide read answers for whichever form the
     * page offers first, which is the other panel's as often as not.
     *
     * @param {object} $root jQuery-wrapped address form
     * @returns {string}
     */
    function currentAddressFormCountry($root) {
        if (!$root || !$root.length) return '';
        const selected = scopedField($root, COUNTRY_FIELD.selector).val();
        return typeof selected === 'string' ? selected.toLowerCase() : '';
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
     * Clear the autofilled fields of one address form that still hold exactly
     * what the plugin put there, and forget the recording.
     *
     * @param {object} $root jQuery-wrapped address form
     * @param {object} identity the calling panel's own identity
     * @returns {Array<string>} the names of the fields cleared
     */
    function revertAddressFormFields($root, identity) {
        const record = addressWriteRecord(identity);
        const cleared = [];
        REVERTABLE_FIELDS.forEach(function (field) {
            const handle = revertableFieldHandle($root, field);
            if (!handle.$field.length) return;
            const written = field.written || (handle.select ? 'region_id' : 'region');
            const attribute = handle.$field.attr(AUTOFILL_MARKER_ATTR);
            const marker = typeof attribute === 'undefined' ? record[written] : attribute;
            const current = trimmedString(
                handle.select ? selectedOptionText(handle.select) : handle.$field.val() || ''
            );
            handle.$field.removeAttr(AUTOFILL_MARKER_ATTR);
            delete record[written];
            // An EMPTY field with a marker on it is still ours to retract — the
            // marker may be an empty-string recording, which is a real one (the
            // registry had no value for this field).
            if (typeof marker === 'undefined' || current !== trimmedString(marker)) return;
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

        /**
         * Drop every cached search result and any rate-limit backoff. Exists
         * for tests. Address-lookup state needs no reset here: it is keyed by
         * the root a test passes in, so a fresh root per test starts clean —
         * see addressLookupState().
         */
        clearResultCache: function () {
            resultCache.clear();
            registrySuspensions = new WeakMap();
        },

        /** @see formatCompanyNumber */
        HIDDEN_COMPANY_NUMBER_PREFIX: HIDDEN_COMPANY_NUMBER_PREFIX,
        formatCompanyNumber: formatCompanyNumber,
        stripBracketedToken: stripBracketedToken,
        currentAddressFormCountry: currentAddressFormCountry,
        apiClientParams: apiClientParams,
        unwrapProxyResponse: unwrapProxyResponse,

        /** @see announceAddressUnavailable */
        announceAddressUnavailable: announceAddressUnavailable,
        /** @see announceAddressUndeliverable */
        announceAddressUndeliverable: announceAddressUndeliverable,

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
         * @param {object} options.scope the calling panel's rate-limit scope.
         *        Required, and never the bind token: a re-render replaces that
         *        token, so a backoff scoped to one is never observed
         *        (TWO-25554).
         * @returns {Promise<{items: Array, unavailable: boolean, aborted: boolean}>}
         */
        searchCompanies: function (options) {
            const token = options.token;
            const scope = options.scope;
            if (!scope) {
                console.error('companySearch: searchCompanies called without a rate-limit scope');
                return Promise.resolve({ items: [], unavailable: true, aborted: false });
            }
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

            if (isRegistrySuspended(scope)) {
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
                        suspendRegistry(scope);
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
         * @param {object} root the calling panel's OWN form, scoping the write
         *        and keying both the in-flight-request guard and the rate-limit
         *        suspension. Required: a panel with no form of its own has
         *        nowhere this may land (TWO-25554).
         * @param {object} identity the calling panel's own identity, for
         *        announceAddressUnavailable()/withdrawAddressUnavailable().
         * @returns {object|null} the jqXHR, or null when gated off / no id
         */
        lookupCompanyAddress: function (config, selectedCompany, root, identity) {
            if (!config.isAddressSearchEnabled) return null;
            if (!selectedCompany || !selectedCompany.lookupId) return null;
            if (!root || !root.length || !identity) {
                // A picked company that fills nothing in, with nothing said,
                // reads as the picker having done nothing.
                if (identity) announceAddressUndeliverable(identity);
                console.debug({ logger: 'companySearch.lookupCompanyAddress.refused' });
                return null;
            }

            const notify = identity;
            const scope = identity;
            const lookupState = addressLookupState(root);
            const generation = ++lookupState.generation;
            if (lookupState.pending) {
                lookupState.pending.abort();
                lookupState.pending = null;
            }

            // Withdrawn as this pick STARTS, so no notice outlives the
            // company it was about.
            withdrawAddressUnavailable(notify);

            if (isRegistrySuspended(scope)) {
                announceAddressUnavailable(notify);
                return null;
            }

            const self = this;
            const addressResponse = proxyPost('rest/V1/two/company', {
                lookupId: selectedCompany.lookupId
            });
            lookupState.pending = addressResponse;
            const isCurrent = function () {
                return generation === lookupState.generation;
            };
            addressResponse.done(function (raw) {
                if (!isCurrent()) return;
                const envelope = unwrapProxyResponse(raw);
                const response = envelope.ok ? envelope.body : null;
                if (response && response.addresses && response.addresses.length) {
                    self.applyAddress(response.addresses[0], root, identity);
                    return;
                }
                announceAddressUnavailable(notify);
            });
            addressResponse.fail(function (jqXHR, textStatus) {
                // Recorded even for a superseded pick: the ceiling is
                // per-merchant, so a newer lookup would hit the same wall.
                if (jqXHR && jqXHR.status === 429) {
                    suspendRegistry(scope);
                }
                if (!isCurrent()) return;
                if (textStatus !== 'abort') announceAddressUnavailable(notify);
            });
            addressResponse.always(function () {
                if (lookupState.pending === addressResponse) lookupState.pending = null;
            });
            return addressResponse;
        },

        SECONDARY_ADDRESS_ROOT_SELECTOR: SECONDARY_ADDRESS_ROOT_SELECTOR,

        /**
         * @see primaryAddressRoot
         * @returns {boolean} whether the buyer's own shipping form is in play
         */
        hasPrimaryAddressForm: function () {
            return !!primaryAddressRoot().length;
        },

        /**
         * Write a company or buyer address into ONE address form.
         *
         * `root` is required — the form the CALLING PANEL owns. A write with no
         * form of its own to land in is refused rather than falling back to a
         * page-wide one, which reached the other panel's fields (TWO-25554).
         *
         * Each field written records its value in `AUTOFILL_MARKER_ATTR`, which
         * is what makes the write REVERSIBLE (revertAutofilledAddress() below)
         * without ever discarding something the buyer typed.
         *
         * There is no address-lookup gate here. `config.isAddressSearchEnabled`
         * gates lookupCompanyAddress() — an ordinary search selection — one
         * level up, and the sole-trader write-back must write regardless of
         * where company search is mounted (TWO-25461 §5).
         *
         * @param {object} address company address or buyer address record
         * @param {object} root jQuery set for the calling panel's own form
         * @param {object} identity the calling panel's own identity, keying the
         *        recording a later revert reads
         * @returns {number} 0 — no address other than `root` is ever written
         */
        applyAddress: function (address, root, identity) {
            console.debug({ logger: 'companySearch.applyAddress', address });
            if (!root || !root.length || !identity) {
                console.debug({ logger: 'companySearch.applyAddress.refused' });
                return 0;
            }
            writeAddressInto(this, address, root, identity);
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
         * @param {object} root the calling panel's own form. Required: an
         *        unscoped write lands in whichever form the page happens to
         *        offer first, which is the other panel's as often as not.
         * @returns {boolean} whether a field was written
         */
        applyTelephone: function (phone, root) {
            if (typeof phone !== 'string' || !phone.trim()) return false;
            if (!root || !root.length) return false;
            const $field = scopedFind(root, TELEPHONE_FIELD_SELECTOR);
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
         * @param {object} $root the form to scope to
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
         * the country-switch revert above), not CSS visibility — core keeps both
         * controls in the form and hides the one the current country does not
         * use.
         *
         * Appends at most once: a city that already ends with the region is left
         * alone, so a payload with no city of its own cannot grow the field on
         * every pass.
         *
         * @param {object} address company address or buyer address record
         * @param {object} $root the form to scope to
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
         * Undo an applyAddress() write in ONE form, field by field, and forget
         * the recording.
         *
         * Called when the buyer switches country: an address autofilled from
         * the previous country's registry is not a hint about the new one, it
         * is a wrong address the buyer may well not re-read before placing the
         * order.
         *
         * `root` is required — the calling panel's OWN form, and the only one
         * touched. A page-wide retraction cleared the shipping form's address
         * fields when the buyer changed their BILLING country (TWO-25554).
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
         * @param {object} root the calling panel's own form
         * @param {object} identity the calling panel's own identity, keying the
         *        recording this reads
         * @returns {number} how many fields were cleared — for tests, and so a
         *          caller can tell "nothing was ours" from "reverted"
         */
        revertAutofilledAddress: function (root, identity) {
            if (!root || !root.length || !identity) {
                console.debug({ logger: 'companySearch.revertAutofilledAddress.refused' });
                return 0;
            }
            const retracted = revertAddressFormFields(root, identity);
            console.debug({
                logger: 'companySearch.revertAutofilledAddress',
                cleared: retracted.length
            });
            return retracted.length;
        }

    };
});
