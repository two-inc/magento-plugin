/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: which of the three company-capture options the buyer is in, and
 * the company-search mount that two of them drive.
 *
 * Magento's counterpart to the chip half of PrestaShop's `TwoCompanySearch`
 * (`renderChipSelection`, `syncRegisteredEntryVisibility`,
 * `syncSoleTraderEntryVisibility`, `syncNotListedVisibility`) and to
 * WooCommerce's `twoincCompanyCapture` + `TwoCompanySearch` chip builders. On
 * all three platforms the chips belong to the company-search component; before
 * this they were the one part of it Magento kept inside the payment tile's
 * Knockout renderer, which is what made that renderer a second, parallel
 * implementation rather than a mount.
 *
 * Owns: the three mode entry points, which chips are offered, where the one
 * shared search control lives on this checkout, that control's whole
 * lifecycle, and the country the search runs against.
 *
 * The HOST is whatever mounts this — today the payment tile's Knockout
 * renderer. The mode observables, the company identity observables and their
 * writers, and the sole-trader flow are all reached through it. What is
 * private to the mount — the `CompanySearchControl` instance and the delegated
 * country watcher's namespace — is this object's own state.
 *
 * @see view/frontend/web/js/model/sole-trader.js for the flow behind the
 *      sole-trader chip.
 * @see view/frontend/web/js/model/company-search-control.js for the select2
 *      picker this mounts.
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'Two_Gateway/js/model/company-search',
    'Two_Gateway/js/model/company-search-control'
], function ($, quote, url, companySearch, CompanySearchControl) {
    'use strict';

    /**
     * Source of the per-instance event namespace watchAddressFormCountry()
     * uses. Module-scope counter, so two mounts (two Two-family brands, or a
     * re-render) can never share a namespace and dispose() can never detach a
     * sibling's handler.
     */
    var countryWatcherSeq = 0;

    /**
     * @param {object} host the mount this capture control runs inside. Must
     *        supply `_brandConfig`, `companyNameSelector`,
     *        `searchForCompanyText`, `supportedCompanyTypes`,
     *        `isAddressAreaCompanySearchEnabled`, the `companyName` /
     *        `companyId` / `countryCode` / `captureMode` observables, and
     *        `applyCompanyData()`, `fillCustomerData()`, `fillCountryCode()`,
     *        `leaveSoleTraderMode()`.
     */
    function CompanyCapture(host) {
        this._host = host;
        this._companySearchControl = null;
        this._countryWatcherNs = null;
    }

    /** Start the feed that keeps the search country current. */
    CompanyCapture.prototype.init = function () {
        this.watchAddressFormCountry();
    };

    CompanyCapture.prototype.dispose = function () {
        // Destroy the company-search widget with the mount that owns it.
        // Without this a re-render that REUSES the input node (rather than
        // recreating it) leaves the old widget bound, with handlers closed
        // over a now-disposed renderer — picking a company would then write
        // to dead observables and the order would go out with no company on
        // it.
        //
        // Scoped to the node THIS mount bound, not to `companyNameSelector`:
        // the renderer is pushed once per Two-family brand, so a checkout
        // offering two of them has two `#company_name` inputs and a
        // document-wide destroy would tear down the sibling's live widget and
        // leave it a plain text input.
        this.destroyCompanySearchWidget();
        // The delegated address-form country handler, by THIS instance's own
        // namespace — see watchAddressFormCountry(). Without this a one-page
        // checkout's re-renders would stack one live handler per re-render,
        // each closed over a disposed renderer.
        if (this._countryWatcherNs) {
            $(document).off(this._countryWatcherNs);
            this._countryWatcherNs = null;
        }
    };

    /**
     * The "Search for company" return link this mount owns.
     *
     * Resolved through the control, which keeps its own reference to the link
     * it built — surviving the paths that have already destroyed the select2
     * widget (the manual-entry chip → clearCompany() →
     * destroyCompanySearchWidget()). Keying on the widget's own node meant
     * enterSoleTraderUi() silently hid nothing and the link stayed up in
     * sole-trader mode.
     *
     * @returns {object} jQuery set, empty before the first bind
     */
    CompanyCapture.prototype.searchForCompanyLink = function () {
        if (!this._companySearchControl) return $();
        return this._companySearchControl.getSearchForCompanyLink();
    };

    /**
     * The billing country the company search runs against.
     *
     * TWO-25326: the tile's picker searched as if the country were the
     * search API's own default (US) whatever the buyer had selected, on
     * one-page checkouts (Fire Checkout) only.
     *
     * Why only there: `countryCode()` had exactly two feeds — the
     * `countryCode` customer-data section, written ONLY by
     * address-autocomplete.js's toggleCompanyVisibility()/onCountryChanged()
     * once `$.async('#shipping-new-address-form select[name="country_id"]')`
     * resolves, and updateAddress(), which reads the country off the quote's
     * PERSISTED billing/shipping address. On Luma and Amasty the first feed
     * fires on every country change, so the observable is current before the
     * buyer can search. A one-page checkout supplying its own address markup
     * matches neither that selector nor — until the address is saved — has a
     * persisted quote address to read, so `fillCountryCode()`'s
     * `if (!countryCode) return;` left the observable at its `''` default,
     * and buildSearchAjaxOptions() put an EMPTY `country=` on the search
     * URL, which the API answers under its own default.
     *
     * The real fix is the THIRD feed added by watchAddressFormCountry()
     * below, which keeps `countryCode()` current on any checkout. This
     * getter is the belt-and-braces on top of it: the observable FIRST,
     * because it is the authoritative value (quote-derived, and now
     * DOM-driven too), and a direct DOM read only when it is still empty —
     * i.e. only in the window before anything has resolved a country at all,
     * which is exactly the state that produced the bug.
     *
     * That order matters and is not interchangeable. DOM-first was tried and
     * rejected in adversarial review: core renders `#shipping-new-address-form`
     * inside the HIDDEN new-address modal for a customer who has saved
     * addresses, and renders one billing-address form PER payment method, so
     * a DOM-first read could let an untouched form's STORE DEFAULT country
     * beat a correct quote-derived one — reintroducing the same class of bug
     * on the platforms that worked.
     *
     * TWO-25461 §1(a.3): the tile has no address fields of its own, so its
     * country is the BILLING/INVOICE-role address's — and every feed below is
     * shipping-biased in some state, so a buyer shipping to one country and
     * invoicing in another could search the wrong registry.
     *
     * `quote.billingAddress()` rather than a live read of the billing form:
     * billing is Magento's non-default form, and §1(a.3) says its value comes
     * from the sync mechanism (core's own same-as-shipping copy into the
     * quote). It is also the source getAutofillData(), getData() and
     * placeOrderIntent() already read, so this is one resolution reused
     * rather than a fourth mirror. Accepted cost: while the buyer is editing
     * the billing form and has not applied it, the quote holds the previous
     * country.
     *
     * This getter feeds the SEARCH. Sole-trader availability and the
     * country-change company discard still run off `countryCode()`.
     *
     * @returns {string} lower-cased ISO country code, or ''
     */
    CompanyCapture.prototype.searchCountryCode = function () {
        return (
            this.billingRoleCountryCode() ||
            this._host.countryCode() ||
            companySearch.currentAddressFormCountry()
        );
    };

    /**
     * The country of the address playing the billing/invoice ROLE, lower
     * cased, or '' when the quote has no billing address yet.
     *
     * Read live on every call, never cached: `quote.billingAddress()` can
     * legitimately be null for a transient window (see fillCompanyData()'s
     * placeOrderIntent() guard), and it changes as the buyer edits.
     *
     * @returns {string}
     */
    CompanyCapture.prototype.billingRoleCountryCode = function () {
        const billingAddress = quote.billingAddress();
        const countryId = billingAddress && billingAddress.countryId;
        return typeof countryId === 'string' ? countryId.toLowerCase() : '';
    };

    /**
     * Keep `countryCode()` current from the buyer's own address-form country
     * `<select>`, on any checkout (TWO-25326).
     *
     * The third feed referred to by searchCountryCode() above, and the one
     * that actually closes the Fire Checkout gap. Fixing only the search
     * URL would have left `countryCode()` empty there, and it drives two
     * more things:
     *
     *  - getSupportedCompanyTypes() → showModeTab(), so the Business /
     *    Sole trader tab never appeared at all;
     *  - clearCompanyForCountryChange(), so a company captured under the
     *    previous country survived a switch (TWO-24867's protection,
     *    silently absent on this checkout).
     *
     * DELEGATED off the document, not bound to the node: a one-page checkout
     * re-renders the address form freely, and delegation survives that with
     * no `$.async` re-resolution. Per-instance event namespace so a checkout
     * offering two Two-family brands has two independent handlers and
     * dispose() detaches only its own.
     *
     * Change events only, with NO initial seed, and that is deliberate: a
     * `change` fires when the BUYER acts on a real, visible select, whereas
     * a seed would read whatever the DOM happens to hold — including the
     * hidden new-address modal's untouched store default — and
     * fillCountryCode() treats a differing value as a country CHANGE and
     * discards the captured company. The initial value is already covered by
     * the two pre-existing feeds, and by searchCountryCode()'s DOM fallback
     * for the case where neither has produced anything.
     */
    CompanyCapture.prototype.watchAddressFormCountry = function () {
        const self = this;
        countryWatcherSeq += 1;
        this._countryWatcherNs = '.twoTileCountryWatch' + countryWatcherSeq;
        $(document).on(
            'change' + this._countryWatcherNs,
            'select[name="country_id"]',
            function () {
                self._host.fillCountryCode(companySearch.currentAddressFormCountry());
            }
        );
    };

    /**
     * TWO-25326 §7.1: whether the payment tile is the buyer's ACTIVE
     * route to the shared company-search control, as opposed to a
     * display-only surface. One admin setting, `isCompanySearchEnabled`
     * (address-area ON/OFF), decides WHERE the one shared control
     * lives — never whether it exists twice:
     *
     *  - setting OFF (`!isAddressAreaCompanySearchEnabled`) → the tile
     *    is always the control's home.
     *  - setting ON → the address-area control
     *    (address-autocomplete.js, bound to
     *    `#shipping-new-address-form`) is the control's home, EXCEPT
     *    when that form does not exist on this checkout at all — a
     *    saved address and a virtual cart both render no such form —
     *    in which case the tile remains the buyer's only route to
     *    search: a saved address and a virtual cart both mean no
     *    address-area control exists on this checkout at all, so
     *    removing the tile's own route too would leave those two flows
     *    with no way to supply a company, and Model/Two.php::authorize()
     *    then refuses the order server-side — an order-blocking
     *    regression, not a cosmetic one.
     *
     * Read live rather than cached: `updateShippingAddress()` /
     * `updateBillingAddress()` call `refreshTileCompanySearchBinding()`
     * on every quote address change specifically because a NEW vs SAVED
     * address switch flips whether `#shipping-new-address-form` exists,
     * which is exactly what this reads. DO NOT memoise this into a
     * property or a `ko.computed` that only recomputes on
     * companyName/companyId/quote.isVirtual() changes — an earlier
     * version relied on incidental re-renders for reactivity and an
     * address-type switch with no company field touched went stale
     * (found in adversarial review, 2026-08-04; fixed by the explicit
     * refresh call above, not by caching this differently).
     *
     * @returns {boolean}
     */
    CompanyCapture.prototype.isTileCompanySearchActive = function () {
        if (!this._host.isAddressAreaCompanySearchEnabled) {
            return true;
        }
        return (
            quote.isVirtual() ||
            $('#shipping-new-address-form input[name="company"]').length === 0
        );
    };

    /**
     * TWO-25503: a manually typed name carries no company number, which
     * Two's payment method requires, and with the address-area setting
     * off no address-step lookup exists on the checkout to capture one —
     * so manual entry is a dead end there and is not offered.
     *
     * @returns {boolean}
     */
    CompanyCapture.prototype.showManualEntryChip = function () {
        return !!(
            this._host.isAddressAreaCompanySearchEnabled && this.isTileCompanySearchActive()
        );
    };

    // The registry's supported-company-types answer for a billing
    // country, via the plugin's server-side relay (the merchant API
    // key never reaches the browser; the server caches per country).
    // Successful answers — including the legitimate empty list, which
    // means business-only checkout — are memoized per country; errors
    // resolve to [] (fail soft, no sole-trader option) but are NOT
    // memoized so the next country change retries.
    //
    // The memo lives on the host: it is seeded server-side into the brand's
    // checkoutConfig subtree and read back there.
    CompanyCapture.prototype.getSupportedCompanyTypes = function (countryCode) {
        var host = this._host;
        var key = countryCode.toLowerCase();
        if (Object.prototype.hasOwnProperty.call(host.supportedCompanyTypes, key)) {
            return Promise.resolve(host.supportedCompanyTypes[key]);
        }
        var URL = url.build(`rest/V1/two/supported-company-types/${encodeURIComponent(key)}`);
        return fetch(URL, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) throw new Error(`Error response from ${URL}.`);
                return response.json();
            })
            .then(function (types) {
                if (!Array.isArray(types)) throw new Error(`Malformed response from ${URL}.`);
                host.supportedCompanyTypes[key] = types;
                return types;
            })
            .catch(function (error) {
                console.error({ logger: 'twoPayment.getSupportedCompanyTypes', error });
                return [];
            });
    };

    /**
     * Found in adversarial review, 2026-08-04: enableCompanySearch() only
     * runs from three call sites (the initial registered mode, the "Search
     * for company" link, and the supported-company-types callback) — NONE of
     * which fire when a buyer switches between a NEW and a SAVED
     * shipping/billing address. `#shipping-new-address-form` appears and
     * disappears exactly on that switch, which is the live DOM signal
     * isTileCompanySearchActive() reads. Without this, a buyer who starts on
     * a new address (tile inactive, address-area control live) and then picks
     * a saved address (address-area control's host form disappears) would
     * find the tile's search widget never bound —
     * `isTileCompanySearchActive()` would newly return true, the FIELD would
     * show again (template `visible:` binding does react to that), but the
     * select2 WIDGET behind it was never initialised, leaving a plain text
     * input with no dropdown.
     *
     * Idempotent both directions: enableCompanySearch() itself no-ops if
     * the tile is not the active location (checked at its own top), and
     * destroyCompanySearchWidget() is documented safe to call when
     * nothing is bound.
     */
    CompanyCapture.prototype.refreshTileCompanySearchBinding = function () {
        if (this.isTileCompanySearchActive()) {
            this.enableCompanySearch();
        } else {
            this.destroyCompanySearchWidget();
        }
    };

    /**
     * Fill the billing address form from a picked company.
     *
     * Gated entirely by `isAddressSearchEnabled`, applied inside
     * companySearch.lookupCompanyAddress(). This method adds no gate of
     * its own: the tile picker and the address-area picker obey the same
     * single, already-resolved flag.
     *
     * That flag is itself the AND of "Autofill company address"
     * (`enable_address_search`) and "Enable company search in address
     * entry" (`enable_company_search`) — see
     * Model\Config\Repository::isAddressSearchEnabled(), TWO-25503.
     * Company search relocating to the payment tile (the latter OFF)
     * retires the convenience autofill exists for, so a merchant running
     * search from the tile gets no autofill either, regardless of the
     * dedicated setting.
     */
    CompanyCapture.prototype.addressLookup = function (selectedCompany) {
        // Scoped like the sole-trader write-back, and for the same reason:
        // this is the TILE's picker, so it writes as the billing/invoice
        // role, and the payment step has more than one address form in the
        // DOM carrying these field names. The address-area picker stays
        // document-wide — it runs on a step that renders one.
        return companySearch.lookupCompanyAddress(
            this._host._brandConfig,
            selectedCompany,
            companySearch.billingRoleFormRoot()
        );
    };

    /**
     * (Re-)bind the company-search picker to the company-name input.
     *
     * @param {object} [options]
     * @param {boolean} [options.openDropdown] open the picker as soon as
     *        the widget is bound, passed straight through to the control's
     *        own bind(). Left off on every route through this file: the
     *        initial render and a retired sole-trader option are not the
     *        buyer asking to search, and popping a dropdown open there
     *        steals focus from the payment form. The in-dropdown "Search
     *        for company" link opens its own picker inside
     *        company-search-control.js rather than coming back through
     *        here.
     */
    CompanyCapture.prototype.enableCompanySearch = function (options) {
        const host = this._host;
        // TWO-25326 §7.1: don't bind the tile's own live search widget
        // when the address-area control is this checkout's active
        // location for it — one shared control, not two simultaneous
        // ones. See isTileCompanySearchActive() for the fallback carve-
        // out (saved address / virtual cart).
        if (!this.isTileCompanySearchActive()) {
            return;
        }
        const self = this;
        // ONE instance per mount — a renderer is pushed once per
        // Two-family brand, so this is one instance per brand's tile,
        // never a second parallel select2 wiring for the same tile. No
        // `searchForCompanyId` here on purpose: the "Search for company"
        // link's own append guard has to be scoped to THIS bind's
        // container, not a document-wide id, or a checkout offering two
        // Two-family brands would mint duplicate ids and hand brand A's
        // link to brand B — see the class's own doc comment on
        // `searchForCompanyId`.
        if (!this._companySearchControl) {
            this._companySearchControl = new CompanySearchControl({
                fieldSelector: host.companyNameSelector,
                config: host._brandConfig,
                getCountryCode: function () {
                    return self.searchCountryCode();
                },
                searchForCompanyText: host.searchForCompanyText,
                onSelect: function (selectedItem) {
                    const companyId = selectedItem.companyId;
                    const companyName = selectedItem.text;
                    // applyCompanyData(), not fillCompanyData(): a pick is
                    // authoritative and must overwrite the previous
                    // company's identifier even when the new company has
                    // none of its own.
                    host.applyCompanyData({ companyId, companyName }, { authoritative: true });
                    // TWO-25193: the payment-tile picker used to stop
                    // here, leaving the billing address blank. Gated on
                    // isAddressSearchEnabled alone — see addressLookup().
                    self.addressLookup(selectedItem);
                },
                // TWO-25503: on this surface the mode control is the only
                // route to manual entry, so the separately worded
                // in-dropdown escape hatch is not built at all — where
                // showManualEntryChip() is false the tile offers no
                // manual entry. The address-area mount keeps the button:
                // that surface has no mode control.
                manualEntryEnabled: false,
                onReturnToSearch: function () {
                    host.captureMode('registered');
                },
                onBound: function () {
                    $('#select2-company_name-container').text(host.companyName());
                }
            });
        }
        this._companySearchControl.bind(options);
    };

    /**
     * Abandon the selected company: blank the name input and tear the
     * search widget down. Callers are the manual-entry chip and
     * enterSoleTraderUi().
     *
     * `companyId()` IS cleared here, and that is a deliberate change
     * (TWO-25288). While the tile had no company-number field the stale
     * observable was invisible and was flagged as out of scope; now that
     * the number is displayed read-only, leaving it set would show the
     * buyer the ABANDONED company's registry number, uneditable, beside a
     * name field they are being asked to retype — and getData() would
     * submit that number under the new name. Submitting one company's
     * organisation number under another company's name is precisely the
     * bug the selection-authority half of this ticket fixed; the field
     * becoming visible only made it observable.
     *
     * `companyName()` is deliberately NOT cleared. It is read after this
     * call on the sole-trader path — getAutofillData() prefills the signup
     * popup from it — and by the intent-approved notice. Leaving it is the
     * PRE-EXISTING behaviour (the name INPUT reads empty while the
     * observable still holds the previous company); clearing it is a
     * separate question from the one this change forces.
     *
     * With the number empty and no client-side gate, an order placed from
     * this state is refused by Model/Two.php::authorize().
     */
    CompanyCapture.prototype.clearCompany = function () {
        $(this._host.companyNameSelector).val('');
        this._host.companyId('');
        this.disableCompanySearch();
    };

    /**
     * Kept for its callers; delegates to the scoped teardown. A document-wide
     * `$(companyNameSelector).select2('destroy')` had a multi-brand hazard —
     * the renderer is pushed once per Two-family brand, so it could destroy a
     * sibling brand's live widget.
     */
    CompanyCapture.prototype.disableCompanySearch = function () {
        this.destroyCompanySearchWidget();
    };

    /**
     * Destroy only the widget this mount bound. Safe to call twice.
     */
    CompanyCapture.prototype.destroyCompanySearchWidget = function () {
        if (!this._companySearchControl) return;
        // The control's own reference to the "Search for company" link
        // is deliberately NOT cleared by destroy(): the re-enable link
        // has to stay resolvable after the widget is gone — see
        // searchForCompanyLink().
        const $field = this._companySearchControl.getField();
        // isBound()/destroy() are the scoped, idempotent teardown of
        // ONLY the widget this mount bound — the same belt-and-braces
        // need as the control's own `select2:close` handler: destroy()
        // does not always route through a `close` event first, and an
        // undetached button would stay wired to this disposed renderer
        // for the life of the page.
        if (!this._companySearchControl.destroy()) return;
        $field.attr('type', 'text');
    };

    /**
     * @param {object} [options]
     * @param {boolean} [options.openDropdown] passed straight to
     *        enableCompanySearch() — see there.
     */
    CompanyCapture.prototype.registeredOrganisationMode = function (options) {
        const host = this._host;
        host.leaveSoleTraderMode();
        host.captureMode('registered');
        this.enableCompanySearch(options);
        if (this._companySearchControl) {
            // The link and this chip are two routes to the same place, so
            // the link retires whenever search mode is (re-)entered.
            this._companySearchControl.hideSearchForCompanyLink();
        }
        host.fillCustomerData();
    };

    /**
     * Enter manual company entry — the third peer option (TWO-25503).
     *
     * Does exactly what the in-dropdown "My company is not on the list"
     * button used to do on this surface, minus the need to open the picker
     * first: abandon the company in play and tear the widget down, which
     * leaves the company-name input a plain text field the buyer can type
     * into. The address fields are core's own and were always visible, so
     * nothing has to be revealed here.
     *
     * With no organisation number, isCompanyCaptured() stays false and
     * placeOrder()'s submit gate refuses the order — unchanged by this
     * being reachable in one click.
     */
    CompanyCapture.prototype.manualEntryMode = function () {
        const host = this._host;
        host.leaveSoleTraderMode();
        host.captureMode('manual');
        if (this._companySearchControl) {
            // Before the teardown: cancelling leaves a search still on the
            // wire, whose late response would otherwise run select2's
            // highlight bookkeeping over a destroyed picker.
            this._companySearchControl.abortActiveRequest();
        }
        this.clearCompany();
        if (this._companySearchControl) {
            this._companySearchControl.showSearchForCompanyLink();
        }
        // clearCompany() destroys the widget, removing whatever had focus
        // with it; land it on the plain-text field the buyer is now being
        // asked to type into.
        $(host.companyNameSelector).trigger('focus');
    };

    return CompanyCapture;
});
