/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'ko',
    'jquery',
    'underscore',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/quote',
    'Magento_Customer/js/customer-data',
    'Magento_Checkout/js/model/payment/additional-validators',
    'mage/translate',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/action/redirect-on-success',
    'mage/url',
    'Magento_Catalog/js/price-utils',
    'Two_Gateway/js/model/surcharge',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/company-search',
    'Two_Gateway/js/model/company-search-control',
    'Two_Gateway/js/model/minimum-order-visibility',
    'Magento_Ui/js/lib/view/utils/async',
    'mage/validation',
    'jquery/jquery-storageapi'
], function (
    ko,
    $,
    _,
    Component,
    quote,
    customerData,
    additionalValidators,
    $t,
    fullScreenLoader,
    redirectOnSuccessAction,
    url,
    priceUtils,
    surchargeModel,
    getBrandConfig,
    companySearch,
    CompanySearchControl,
    isAboveMinimums
) {
    'use strict';

    window.quote = quote;

    // True while a place-order request started by this renderer is in flight.
    // Deliberately module-scope rather than per-instance, because the
    // isPlaceOrderActionAllowed observable it guards is itself shared: it is
    // declared on the prototype of Magento_Checkout/js/view/payment/default, so
    // one ko.observable backs every payment renderer in the page and survives
    // every renderer Magento re-creates when the payment-method list refreshes.
    var placeOrderInFlight = false;

    // Count of order-intent requests currently in flight, across ALL
    // instances of this renderer sharing this module. Deliberately
    // module-scope and reference-counted rather than a per-instance
    // start/stop pair, for the same reason as placeOrderInFlight above:
    // Amasty rebuilds the payment method list (and Fire Checkout
    // re-renders this renderer outright) on every shipping/totals change,
    // which can happen WHILE an order-intent request is in flight.
    // fillCustomerData() re-runs on every fresh instance's init and
    // re-applies the customer-data section's captured company, so the
    // NEW instance can independently fire its OWN order_intent POST for
    // the SAME company while the orphaned OLD instance's request is
    // still outstanding — the per-instance `_orderIntentInFlightFor`
    // guard only dedupes re-entry on the SAME instance, not this
    // cross-instance case. With a plain per-instance
    // startLoader()/stopLoader() pair, whichever request settles FIRST
    // (typically the orphaned one, since it started earlier) would hide
    // the shared full-screen loader while the surviving instance's own
    // request — whose resolution is what actually updates the
    // currently-rendered notice text — is still pending. Refcounting
    // ties the loader's dismissal to ALL outstanding order-intent calls
    // settling, matching Luma's behaviour (where the count never exceeds
    // one, so this is a no-op) instead of the first one to resolve.
    var orderIntentRequestsInFlight = 0;

    /**
     * Whether an order-intent check is in flight, for the TILE-LOCAL spinner.
     *
     * TWO-25326: this used to drive `fullScreenLoader`, a page-covering
     * overlay — every order-intent check greyed out and blocked the whole
     * checkout while a single payment method decided whether it could offer
     * itself. The spinner for that check now lives inside the payment tile
     * (`.two-order-intent-spinner` in gateway_method.html) and nothing
     * outside the tile is covered or blocked, matching the sibling plugins.
     *
     * Module-scope, exactly like the refcount above and for the same reason:
     * Amasty rebuilds the payment-method list and Fire Checkout re-renders
     * this renderer outright on every totals/shipping change, so a re-render
     * mid-flight orphans one instance's request and starts another's. A
     * per-instance observable would be dismissed by whichever request settles
     * first — the orphan, usually — while the live instance is still waiting.
     * Bound into the template as a prototype property, so every instance's
     * tile reads this one observable.
     */
    var orderIntentInProgress = ko.observable(false);

    /**
     * Source of the per-instance event namespace watchAddressFormCountry()
     * uses. Module-scope counter, so two renderer instances (two Two-family
     * brands, or a re-render) can never share a namespace and dispose() can
     * never detach a sibling's handler.
     */
    var countryWatcherSeq = 0;

    /**
     * Sole-trader identities whose registered address has already been written
     * into this page's checkout, so a replay does not overwrite a correction the
     * buyer made afterwards (TWO-25461 §5).
     *
     * Module scope, not per renderer: Amasty and Fire Checkout rebuild the
     * payment method list on every totals change, and a per-instance record
     * would be re-armed by the very re-render the guard exists to survive.
     * Cleared when the buyer leaves sole-trader mode, the one event that means
     * "write it again next time".
     */
    const adoptedSoleTraderIds = new Set();

    /**
     * What "the same sole trader" means for that guard. The organisation number
     * where there is one; the email otherwise, so two buyers who both arrive
     * without a number are not treated as one.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     * @returns {string}
     */
    function soleTraderIdentityKey(brandCode, buyer) {
        const number = String(buyer.organization_number || '').trim();
        // Per BRAND: a checkout offering two Two-family brands renders a tile —
        // and a billing form — for each, so an adoption in one says nothing
        // about whether the other's form has been written.
        if (number) return `${brandCode}:${number}`;
        const email = String(buyer.email || '').trim().toLowerCase();
        // Nothing to tell one such buyer from another, so record nothing and let
        // the write happen every time — repeating a write is recoverable, and
        // collapsing two buyers onto one key silently drops the second's address.
        return email ? `${brandCode}:email:${email}` : '';
    }

    function startOrderIntentSpinner() {
        orderIntentRequestsInFlight += 1;
        orderIntentInProgress(true);
    }

    function stopOrderIntentSpinner() {
        orderIntentRequestsInFlight = Math.max(0, orderIntentRequestsInFlight - 1);
        if (orderIntentRequestsInFlight === 0) {
            orderIntentInProgress(false);
        }
    }

    return Component.extend({
        defaults: {
            template: 'Two_Gateway/payment/gateway_method'
        },
        redirectAfterPlaceOrder: false,
        // Brand-supplied checkout subtitle; populated in initialize() from
        // the brand's checkoutConfig subtree. Empty ('') for the vanilla
        // Two brand → the template renders no subtitle text. Brand overlays
        // (partner editions, …) supply the string + its translations.
        twoSubtitleHtml: '',
        isPaymentTermsAccepted: ko.observable(false),
        formSelector: 'form#two_gateway_form',
        companyNameSelector: 'input#company_name',
        searchForCompanyText: $t('Search for company'),
        // No `searchForCompanyButton` id selector here on purpose. The
        // shared control's own append guard is per-CONTAINER, so this
        // renderer — pushed once per Two-family brand — would otherwise mint
        // duplicate ids and a document-wide lookup would hand brand A's link
        // to brand B. Use searchForCompanyLink() instead.
        searchForCompanyLink: function () {
            // Resolved through the control, which keeps its own reference to
            // the link it built — surviving the paths that have already
            // destroyed the select2 widget (the manual-entry button →
            // clearCompany() → destroyCompanySearchWidget()). Keying on the
            // widget's own node meant enterSoleTraderUi() silently hid
            // nothing and the link stayed up in sole-trader mode.
            if (!this._companySearchControl) return $();
            return this._companySearchControl.getSearchForCompanyLink();
        },
        delegationToken: '',
        autofillToken: '',
        companyName: ko.observable(''),
        companyId: ko.observable(''),
        invoiceEmails: ko.observable(''),
        project: ko.observable(''),
        department: ko.observable(''),
        orderNote: ko.observable(''),
        poNumber: ko.observable(''),
        selectedTerm: surchargeModel.selectedTerm,
        telephone: ko.observable(''),
        countryCode: ko.observable(''),
        // Tile-local order-intent spinner flag — the module-scope observable
        // declared above, deliberately shared by every instance. See its
        // docblock for why it is not per-instance.
        orderIntentInProgress: orderIntentInProgress,
        showPopupMessage: ko.observable(false),
        // True for the duration of lookupSoleTrader()'s token-mint +
        // buyer-lookup round trip (TWO-25461 §7). Drives the in-field
        // spinner; not the popup-open state, which showPopupMessage/
        // showSoleTrader already cover.
        soleTraderLookupInFlight: ko.observable(false),
        showSoleTrader: ko.observable(false),
        showWhatIsTwo: ko.observable(false),
        showModeTab: ko.observable(false),
        termsAccepted: ko.observable(false), // Observable for terms accepted state
        BVCompanyRegex: /(?:^|\s)B(?:\.)?V(?:\.)?$/i,

        initialize: function () {
            this._super();

            // Autofill lookup result for the entered email, recorded by the
            // sole-trader chip click. ready=false until that lookup resolves;
            // matches=true when the buyer it found owns the entered email.
            this.soleTraderLookup = { ready: false, buyer: null, matches: false };
            this.soleTraderLookupEmail = null;

            // Brand-overlay config: read once at initialize time, keyed on
            // this.getCode() so acme_payment, two_payment, etc each pull
            // their own subtree from window.checkoutConfig.payment.
            this._brandConfig = getBrandConfig(this.getCode());
            var config = this._brandConfig;

            // Per-country memo of the registry's supported-company-types
            // answer (lowercased ISO → string[]), seeded server-side with
            // the quote's billing country via ConfigProvider and extended
            // live through GET /V1/two/supported-company-types as the
            // buyer edits the billing address. Drives the Business /
            // Sole trader mode tab; fetch errors fail soft (treated as
            // business-only) and are NOT memoized, so the next country
            // change retries.
            this.supportedCompanyTypes = config.supportedCompanyTypes || {};

            this.twoSubtitleHtml = config.subtitleHtml || '';
            // TWO-25386: showWhatIsTwo is the pre-existing (unused until now)
            // observable declared above.
            this.showWhatIsTwo(!!config.showAboutLink);
            this.aboutLinkUrl = config.aboutLinkUrl || '';
            this.aboutLinkText = config.aboutLinkText || '';
            this.displayTooltips = config.displayTooltips !== false;
            this.paymentTermsMessage = config.paymentTermsMessage;
            this.termsNotAcceptedMessage = config.termsNotAcceptedMessage;
            this.isPaymentTermsEnabled = config.isPaymentTermsEnabled;
            this.initOrderIntentApprovedNotice(config);
            this.companyRequiredMessage = config.companyRequiredMessage;
            this.generalErrorMessage = config.generalErrorMessage;
            this.invalidEmailListMessage = config.invalidEmailListMessage;
            this.termUnavailableMessage = config.termUnavailableMessage;
            this.soleTraderErrorMessage = config.soleTraderErrorMessage;
            this.isOrderIntentEnabled = config.isOrderIntentEnabled;
            // TWO-25326 §7.1: the ONE admin setting that decides where the
            // shared company-search control renders. ON = address area
            // (address-autocomplete.js's job); OFF = payment tile (this
            // renderer's job) — see isTileCompanySearchActive() below,
            // which is also where the saved-address/virtual-cart fallback
            // is documented.
            this.isAddressAreaCompanySearchEnabled = !!config.isCompanySearchEnabled;
            this.isInvoiceEmailsEnabled = config.isInvoiceEmailsEnabled;
            this.isDepartmentFieldEnabled = config.isDepartmentFieldEnabled;
            this.isProjectFieldEnabled = config.isProjectFieldEnabled;
            this.isOrderNoteFieldEnabled = config.isOrderNoteFieldEnabled;
            this.isPONumberFieldEnabled = config.isPONumberFieldEnabled;

            // Client-side minimum-order visibility gate. config.minimumOrder is
            // the server-resolved constraint(s) {amount, basis} already in the
            // display currency; we only compare against the live quote total.
            // Hides the method below the minimum (the case the server can miss
            // on Amasty, where shipping isn't persisted until place-order); on
            // an Amasty store view isAvailable offers the method
            // unconditionally, so this also drives showing it once the total
            // clears the minimum. config.minimumOrderUnresolved is set when the
            // server has an active minimum it could NOT convert to the display
            // currency (missing FX rate) → hide, mirroring the server gate's
            // fail-closed stance rather than failing open. Enforcement itself is
            // server-side (isAvailable on non-Amasty; authorize() + the Two API
            // at placement on Amasty) — this is display only. No minimums and
            // nothing unresolved → always visible. pureComputed + the explicit
            // dispose() teardown below are what release the totals dependency
            // when the renderer is destroyed (the deselect subscription keeps
            // the computed awake, so we rely on dispose(), not auto-sleep).
            var self = this;
            var minimums = config.minimumOrder || [];
            var minimumsUnresolved = !!config.minimumOrderUnresolved;
            this.isTwoVisible = ko.pureComputed(function () {
                return !minimumsUnresolved && isAboveMinimums(quote.getTotals()(), minimums);
            });
            // Hiding the radio is not enough on Amasty, whose global place-order
            // button lives outside this renderer: if the total drops below the
            // minimum while Two is the selected method (e.g. a cheaper shipping
            // rate picked after selection), deselect it so a hidden method can
            // never be the one submitted. authorize() is the server backstop;
            // this is the earlier, cleaner client stop. Subscription disposed
            // in dispose() so re-renders don't leak it.
            this._twoVisibilitySub = this.isTwoVisible.subscribe(function (visible) {
                var selected = quote.paymentMethod();
                if (!visible && selected && selected.method === self.getCode()) {
                    quote.paymentMethod(null);
                }
            });

            var terms = config.availableBuyerTerms || [];
            this.availableBuyerTerms = terms;
            this.showTermSelector = terms.length > 1;
            this.showSingleTerm = terms.length === 1;
            this.singleTermLabel =
                terms.length === 1 ? $t('Payment Terms %1 days').replace('%1', terms[0]) : '';

            // Empty-object termSurcharges → loading state (template shows the
            // three-dot loader). Once populated, label becomes '+€n.nn' or ''
            // if every term resolves to ~0.
            this.singleTermSurchargeLabel = ko.pureComputed(function () {
                if (terms.length !== 1) {
                    return '';
                }
                var surcharges = surchargeModel.termSurcharges();
                if (!surcharges || !Object.keys(surcharges).length) {
                    return null;
                }
                var amount = parseFloat(surcharges[terms[0]] || 0);
                if (amount < 0.005) {
                    return '';
                }
                return '+' + priceUtils.formatPrice(amount, quote.getPriceFormat());
            });
            this.termOptions = ko.pureComputed(function () {
                var surcharges = surchargeModel.termSurcharges();
                var isLoading = !surcharges || !Object.keys(surcharges).length;
                var amounts = terms.map(function (days) {
                    return parseFloat(surcharges[days] || 0);
                });
                var allZero =
                    !isLoading &&
                    amounts.every(function (a) {
                        return a < 0.005;
                    });
                return terms.map(function (days, i) {
                    return {
                        days: days,
                        daysLabel: days + ' ' + $t('days'),
                        isLoading: isLoading,
                        surchargeLabel:
                            isLoading || allZero
                                ? ''
                                : '+' + priceUtils.formatPrice(amounts[i], quote.getPriceFormat())
                    };
                });
            });

            this.registeredOrganisationMode();
            this.watchAddressFormCountry();
            this.configureFormValidation();
            this.popupMessageListener();
            return this;
        },
        /**
         * Tear down the minimum-order visibility subscription and computed so a
         * re-rendered method list (Amasty rebuilds it on shipping/total change)
         * doesn't accumulate live subscriptions to the singleton quote totals.
         */
        dispose: function () {
            // Destroy the company-search widget with the component that owns
            // it. Without this a re-render that REUSES the input node (rather
            // than recreating it) leaves the old widget bound, with handlers
            // closed over this now-disposed renderer — picking a company
            // would then write to dead observables and the order would go out
            // with no company on it.
            //
            // Scoped to the node THIS component bound, not to
            // `companyNameSelector`: the renderer is pushed once per
            // Two-family brand, so a checkout offering two of them has two
            // `#company_name` inputs and the document-wide destroy in
            // disableCompanySearch() would tear down the sibling's live
            // widget and leave it a plain text input.
            this.destroyCompanySearchWidget();
            if (this._twoVisibilitySub) {
                this._twoVisibilitySub.dispose();
                this._twoVisibilitySub = null;
            }
            if (this.isTwoVisible && this.isTwoVisible.dispose) {
                this.isTwoVisible.dispose();
            }
            // TWO-25347: tear down fillCustomerData()'s subscriptions to the
            // shared quote/customerData singletons — see that method's own
            // comment for why an undisposed set there fired stacked
            // concurrent order_intent POSTs on Fire Checkout specifically.
            if (this._customerDataSubs) {
                this._customerDataSubs.forEach((sub) => sub.dispose());
                this._customerDataSubs = null;
            }
            // The delegated address-form country handler, by THIS instance's
            // own namespace — see watchAddressFormCountry(). Without this a
            // one-page checkout's re-renders would stack one live handler per
            // re-render, each closed over a disposed renderer.
            if (this._countryWatcherNs) {
                $(document).off(this._countryWatcherNs);
                this._countryWatcherNs = null;
            }
            // The popup's `message` listener — see popupMessageListener().
            if (this._popupMessageHandler) {
                window.removeEventListener('message', this._popupMessageHandler);
                this._popupMessageHandler = null;
            }
            this._super();
        },
        /**
         * Whether the company-NAME input is locked against the buyer.
         *
         * A plain function, not a computed: the template calls it inside a ko
         * `attr` binding, so ko tracks the two observables read here as
         * dependencies of that binding and re-evaluates when either changes. No
         * subscription to dispose, and — unlike a computed built in initialize()
         * — it exists on renderers the unit tests load without booting.
         *
         * Gated on `companyId()`, NOT on sole-trader mode alone. Sole trader is
         * the only mode where this node is a plain text box holding a captured
         * name, but entering that mode does not guarantee a name has been
         * captured: enterSoleTraderUi() blanks the input, and the autofill that
         * refills it only lands when the chip's lookup matched a buyer. On the
         * unmatched branch the buyer is sent to signup and may abandon it, and
         * fillCompanyData() early-returns unless BOTH name and number are
         * non-empty — so keying on the mode alone left a BLANK, `readonly`,
         * `required` input that no buyer action could satisfy and that jQuery
         * Validation still enforces (`elements()` skips `:disabled`, not
         * `[readonly]`). Locking only once a number has actually been captured
         * means every state where the field is empty is a state the buyer can
         * type into.
         *
         * @returns {boolean}
         */
        isCompanyNameReadOnly: function () {
            return this.showSoleTrader() && !!this.companyId();
        },
        /**
         * Has a company actually been CAPTURED — name plus organisation
         * number — as opposed to merely named?
         *
         * TWO-25326, 2026-08-04 ruling: this NO LONGER hides the tile's
         * company-capture controls (the field + picker). Doug's exact
         * words on the control's visibility: it "is controlled ONLY by the
         * state of the 'enable search in address' admin setting ... and
         * search control visibility is not changed for any other reason" —
         * see isTileCompanySearchActive() and the `visible:` binding on the
         * field wrapper in gateway_method.html, which no longer reads this
         * method at all. Superseded here is the earlier design where
         * capture (and, briefly, a decline-recovery carve-out on top of it)
         * hid and re-showed that field — found in live testing to leave the
         * control gone with no way back on the common approve path.
         *
         * What THIS method still gates, post-ruling:
         *
         *  - the org-number label underneath the field in
         *    gateway_method.html (`.two-company-id-text`) — shown once
         *    capture is complete, same "name AND number" rule as below,
         *    mirroring address-autocomplete.js's renderCompanyIdText();
         *  - placeOrder()'s client-side submit gate (§6a) — a manual,
         *    name-only capture must not let the buyer submit.
         *
         * Gated on the NUMBER, not on the name alone, and that is the whole
         * distinction:
         *
         *  - a registry pick and sole-trader autofill both yield a number,
         *    so capture is complete;
         *  - manual entry yields a name and no number. §6 says name-only
         *    must NOT make the payment method usable, so capture is
         *    precisely NOT complete.
         *
         * A plain function, not a computed — same reasoning as
         * isCompanyNameReadOnly() above: the template reads it inside `visible`
         * bindings, so ko tracks both observables as dependencies of those
         * bindings and re-evaluates when either changes. Nothing to dispose,
         * and it exists on renderers the unit tests load without booting.
         *
         * @returns {boolean}
         */
        isCompanyCaptured: function () {
            return !!(this.companyName() || '').trim() && !!(this.companyId() || '').trim();
        },
        /**
         * The captured organisation number as it may be SHOWN in the tile, or
         * '' when it must not be shown (TWO-25326: an internal `TWO:`-prefixed
         * identifier). Routed through the ONE shared display formatter that
         * the dropdown row, the address-step label and the order-intent notice
         * all use — see companySearch.formatCompanyNumber().
         *
         * `companyId()` itself is untouched: it is still the single carrier and
         * getData()/placeOrderIntent() still read it, never this.
         *
         * A plain function, not a computed — same reasoning as
         * isCompanyCaptured() above: the template reads it inside `visible:`
         * and `text:` bindings, so ko tracks `companyId` as a dependency of
         * those bindings and re-evaluates when it changes.
         *
         * @returns {string}
         */
        displayCompanyId: function () {
            return companySearch.formatCompanyNumber(this.companyId());
        },
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
        searchCountryCode: function () {
            return (
                this.billingRoleCountryCode() ||
                this.countryCode() ||
                companySearch.currentAddressFormCountry()
            );
        },
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
        billingRoleCountryCode: function () {
            const billingAddress = quote.billingAddress();
            const countryId = billingAddress && billingAddress.countryId;
            return typeof countryId === 'string' ? countryId.toLowerCase() : '';
        },
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
        watchAddressFormCountry: function () {
            const self = this;
            countryWatcherSeq += 1;
            this._countryWatcherNs = '.twoTileCountryWatch' + countryWatcherSeq;
            $(document).on(
                'change' + this._countryWatcherNs,
                'select[name="country_id"]',
                function () {
                    self.fillCountryCode(companySearch.currentAddressFormCountry());
                }
            );
        },
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
        isTileCompanySearchActive: function () {
            if (!this.isAddressAreaCompanySearchEnabled) {
                return true;
            }
            return (
                quote.isVirtual() ||
                $('#shipping-new-address-form input[name="company"]').length === 0
            );
        },
        /**
         * Guarded read of orderIntentApprovedNotice() for the template's
         * `ko if`. The observable is created in
         * initOrderIntentApprovedNotice() rather than in `defaults` (see the
         * shared-observable footgun documented there), so it is genuinely
         * absent on a renderer `ko if` is evaluated against before
         * initialize() runs — unreachable in production (initialize() wires
         * it before bindings apply) but a plain `orderIntentApprovedNotice()`
         * call in the template would throw, not fail soft, if that ever
         * changed. Guarded here rather than trusted; `text:` still binds the
         * raw observable directly.
         *
         * @returns {boolean}
         */
        isOrderIntentApprovedNoticeVisible: function () {
            return !!(this.orderIntentApprovedNotice && this.orderIntentApprovedNotice());
        },
        /**
         * Same guard, for the declined notice. Deliberately a SEPARATE
         * function rather than one shared predicate — TWO-25326's
         * 2026-08-03 ruling explicitly rejects one gate driving two
         * different sentences (that was the standalone label's defect).
         * Each notice is its own on/off decision; this only protects against
         * reading an absent observable, it does not couple the two.
         *
         * @returns {boolean}
         */
        isOrderIntentDeclinedNoticeVisible: function () {
            return !!(this.orderIntentDeclinedNotice && this.orderIntentDeclinedNotice());
        },
        /**
         * Same guard, for the order-intent ERROR notice (TWO-25326,
         * 2026-08-05 four-platform convergence). The error text renders in
         * the same bordered box as the other two outcomes instead of a
         * checkout toast — see processOrderIntentErrorResponse().
         *
         * @returns {boolean}
         */
        isOrderIntentErrorNoticeVisible: function () {
            return !!(this.orderIntentErrorNotice && this.orderIntentErrorNotice());
        },
        /**
         * Blank all three order-intent outcome notices.
         *
         * Called at the START of every order-intent check as well as from the
         * company-change subscriptions, because the two are not the same
         * event and only one of them is guaranteed to fire. The subscriptions
         * fire on a company OBSERVABLE CHANGING; a check can start with the
         * observables already holding those values (a re-render re-applying
         * the captured company, a repeat pick of the company already in the
         * field), and ko notifies nothing then. Before this, the box kept the
         * PREVIOUS company's verdict on screen for the whole of the new
         * request in exactly those cases — a stale approval sitting under a
         * spinner that is checking something else.
         *
         * Guarded on each observable's existence: initialize() creates them
         * in initOrderIntentApprovedNotice(), and fillCompanyData() is
         * reachable from contexts (and specs) that never ran it.
         *
         * @returns {void}
         */
        clearOrderIntentNotices: function () {
            if (this.orderIntentApprovedNotice) this.orderIntentApprovedNotice('');
            if (this.orderIntentDeclinedNotice) this.orderIntentDeclinedNotice('');
            if (this.orderIntentErrorNotice) this.orderIntentErrorNotice('');
        },
        /**
         * Put an order-intent failure in the tile's own bordered box rather
         * than the checkout message region (TWO-25326, 2026-08-05): the
         * region is cleared on every checkout update, which is how a buyer
         * ended up with a spinner that vanished and nothing else to read.
         *
         * Falls back to the toast when the observable is absent, which is the
         * pre-initialize() case the sibling `is…Visible()` guards protect
         * against — an error is the one message that must not be swallowed
         * because its own surface was not wired yet.
         *
         * @param {string} message
         * @returns {void}
         */
        showOrderIntentErrorNotice: function (message) {
            if (this.orderIntentErrorNotice) {
                this.orderIntentErrorNotice(message);
                return;
            }
            this.showErrorMessage(message);
        },
        selectTerm: function (days) {
            surchargeModel.selectTerm(days);
        },
        showErrorMessage: function (message, duration) {
            // Route through the payment block's own messageContainer (same
            // surface as the addSuccessMessage calls elsewhere in this file)
            // rather than a bare `messageList` symbol — that symbol was never
            // imported, so showErrorMessage threw ReferenceError on every
            // invocation and the terms-not-accepted error never rendered.
            const container = this.messageContainer;
            container.addErrorMessage({ message: message });

            if (duration) {
                setTimeout(function () {
                    container.errorMessages.remove(function (item) {
                        return item === message;
                    });
                }, duration);
            }
        },
        /**
         * TWO-25503: whether the term the buyer picked is still one the server
         * offers.
         *
         * `availableBuyerTerms` is a render-time snapshot taken in
         * initialize(); the LIVE set is the key set of
         * surchargeModel.termSurcharges(), which /surcharges and /select-term
         * refresh as the quote changes and as the merchant's offerable terms
         * change. Nothing compared the two, so a term withdrawn after render
         * was posted anyway and only refused server-side.
         *
         * An empty live map is the loading state, not "no terms" (see
         * termOptions) — treating it as unavailable would refuse a legitimate
         * submit whenever a /surcharges fetch is in flight. A selection of 0
         * (no default term configured) is likewise left alone: that quote never
         * had a term to lose.
         *
         * @returns {boolean}
         */
        isSelectedTermStillAvailable: function () {
            var terms = this.availableBuyerTerms || [];
            if (!terms.length) {
                return true;
            }
            var selected = this.selectedTerm();
            if (!selected) {
                return true;
            }
            var live = surchargeModel.termSurcharges();
            if (!live || !Object.keys(live).length) {
                return true;
            }
            return Object.prototype.hasOwnProperty.call(live, String(selected));
        },
        validateEmails: function () {
            const emails = this.invoiceEmails();
            let emailArray = emails.split(',').map((email) => email.trim());

            const isValid = emailArray.every((email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));
            if (!isValid && emails) {
                // 15s, not 3s: 3s dismissed the message before a buyer who was
                // still typing in the forward-email field had read it, and a sticky
                // message stayed on screen long after the address was corrected.
                this.showErrorMessage(this.invalidEmailListMessage, 15000);
                return false;
            }
            return true;
        },
        logIsPaymentsAccepted: function (data, event) {
            console.debug({
                logger: 'logIsPaymentsAccepted',
                isPaymentTermsAccepted: this.isPaymentTermsAccepted()
            });
        },
        fillCompanyData: function ({ companyId, companyName }) {
            console.debug({ logger: 'twoPayment.fillCompanyData', companyId, companyName });
            companyName = typeof companyName == 'string' && companyName ? companyName : '';
            companyId = typeof companyId == 'string' ? companyId : '';
            if (!companyName || !companyId) return;
            this.companyName(companyName);
            $(this.companyNameSelector).val(companyName);
            $('#select2-company_name-container')?.text(companyName);
            this.companyId(companyId);
            if (this.isOrderIntentEnabled) {
                // TWO-25347 belt-and-braces: refuse a second concurrent
                // order_intent POST for the SAME captured company. The root
                // cause — fillCustomerData() stacking undisposed
                // subscriptions on every re-render, each one independently
                // reaching fillCompanyData() for an unchanged company pick
                // — is fixed in dispose()/fillCustomerData() above; this
                // guard is a second line of defence against any other path
                // that could re-enter here before the first request settles
                // (Fire Checkout re-renders this payment renderer on every
                // totals/shipping change).
                if (this._orderIntentInFlightFor === companyId) {
                    return;
                }
                this._orderIntentInFlightFor = companyId;
                // The previous verdict goes as the new check STARTS, not when
                // its answer arrives (TWO-25326, 2026-08-05). See
                // clearOrderIntentNotices() for why the company-change
                // subscriptions do not already cover this.
                this.clearOrderIntentNotices();
                startOrderIntentSpinner();
                const self = this;
                let deferred;
                try {
                    // Found in adversarial review, 2026-08-04: placeOrderIntent()
                    // can THROW synchronously rather than return a Deferred —
                    // quote.billingAddress() can legitimately be null for a
                    // transient window (see the comment on that observable
                    // elsewhere in this file), and placeOrderIntent() reads
                    // `billingAddress.countryId` unguarded. An unhandled throw
                    // here would skip `.always()` entirely, and
                    // `_orderIntentInFlightFor` would stay set to this
                    // companyId FOREVER — every later pick of the SAME
                    // company would silently no-op against the guard above,
                    // with no recovery short of a page reload.
                    deferred = self.placeOrderIntent();
                } catch (error) {
                    // Round-2 adversarial review, 2026-08-04: an earlier
                    // version of this catch rethrew, mirroring
                    // placeOrderBackend()'s shape — but every caller of
                    // fillCompanyData() is an unguarded synchronous context
                    // with its OWN statements after the call (the
                    // select2:select handler's addressLookup() call,
                    // updateAddress()'s project/department writes,
                    // updateShippingAddress()/updateBillingAddress()'s
                    // refreshTileCompanySearchBinding() call). Rethrowing
                    // aborted all of those too, on top of leaving the buyer
                    // with no visible sign anything went wrong (loader
                    // flashes and vanishes, nothing else happens). Logging +
                    // a visible message + NOT rethrowing lets the caller's
                    // remaining statements run and gives the buyer something
                    // to act on, at the cost of this one attempt's order
                    // intent — strictly better than either silent failure or
                    // aborting unrelated sibling work.
                    console.error({ logger: 'twoPayment.fillCompanyData.placeOrderIntent', error });
                    stopOrderIntentSpinner();
                    self._orderIntentInFlightFor = null;
                    // Same surface as processOrderIntentErrorResponse()'s
                    // message (TWO-25326, 2026-08-05): a synchronous throw and
                    // a failed request are the same outcome to the buyer.
                    self.showOrderIntentErrorNotice(self.generalErrorMessage);
                    return;
                }
                deferred
                    .always(function () {
                        stopOrderIntentSpinner();
                        if (self._orderIntentInFlightFor === companyId) {
                            self._orderIntentInFlightFor = null;
                        }
                    })
                    .done(function (response) {
                        // Found in adversarial review, 2026-08-04: a cross-
                        // company race. The in-flight guard above only
                        // dedupes a repeat request for the SAME company — it
                        // does nothing if the buyer picks company A, then
                        // (once A's request settles and re-arms the guard)
                        // picks company B before A's response has actually
                        // arrived back is not the risk; the risk is A's
                        // response landing AFTER the buyer has already moved
                        // on to B. resolveCompanyNotice() reads
                        // companyName()/companyId() LIVE at settle time, so a
                        // stale response for A would render A's verdict with
                        // B's name/number substituted in. Guarded by
                        // confirming the observables still match what THIS
                        // request was for before writing either notice.
                        if (self.companyId() === companyId && self.companyName() === companyName) {
                            self.processOrderIntentSuccessResponse(response);
                        }
                    })
                    .fail(function (response) {
                        if (self.companyId() === companyId && self.companyName() === companyName) {
                            self.processOrderIntentErrorResponse(response);
                        }
                    });
            }
        },
        /**
         * Apply a company the buyer (or a customer-data section) selected.
         *
         * Routes to fillCompanyData() for the normal case, and to
         * selectCompanyWithoutIdentifier() when the company has a name but no
         * identifier — a shape company search can now return, since the
         * `national_identifier` guard in company-search.js renders those hits
         * instead of taking the whole result list down.
         *
         * The split exists because fillCompanyData() early-returns on an
         * empty companyId, which is right for its other callers (an empty
         * customer-data section on init must not blank live state) but wrong
         * for a selection: a selection is authoritative. Without this, picking
         * an identifier-less company after a valid one left the PREVIOUS
         * company's organisation number in `companyId()` while the picker
         * displayed the new company's name, and getData()/placeOrderIntent()
         * submitted the two mixed together.
         *
         * `options.authoritative` says the name-set/id-empty shape came from
         * an act of selection — one of the two pickers, or a live change
         * notification on the `companyData` section, which is the shipping-step
         * picker writing it. Only those may clear a company that is already
         * selected. The one-shot section READ on init must not: `companyData`
         * is a localStorage customer-data section, so it outlives page loads
         * and previous orders, and a stale `{companyName, companyId: ''}` row
         * would otherwise overwrite a live payment-step pick's name and blank
         * its organisation number. Before the routing existed that shape was a
         * harmless no-op on the read path, and it has to stay one.
         *
         * No editable state is derived for the organisation number. The tile
         * does display it again (TWO-25288), but that input is `readonly`
         * unconditionally, so there is no state to derive — and it carries no
         * `name`, so it is a display of `companyId()` and never a writer of it.
         *
         * Writers of `companyId()` — four paths, not three; the last two are
         * easy to conflate and are NOT the same thing:
         *
         *  - a company-search pick on this step;
         *  - the sole-trader autofill response (soleTraderMode() and the
         *    postMessage handler);
         *  - the address step's `companyData` customer-data notification, which
         *    is the only surviving route for a HAND-TYPED number, and the address
         *    step enables it only where the registry holds no identifier;
         *  - updateAddress(), parsing a `company_id` custom attribute off the
         *    quote's billing/shipping address. This fires from the quote
         *    subscriptions with no address-step interaction at all — a saved
         *    customer address already carrying the attribute is enough.
         *
         * Readers: getData(), placeOrderIntent(), the authoritative guard a few
         * lines below, and the notice-clearing subscription in initialize().
         */
        applyCompanyData: function (companyData, options) {
            const data = companyData || {};
            const authoritative = !!(options && options.authoritative);
            // Refuse a company captured in a different country (TWO-24867).
            //
            // Applies to the authoritative path too: `companyData` is a
            // localStorage section that outlives the page, so "a change
            // notification is the shipping-step picker writing it" is only true
            // WITHIN a page load. A record restored from a previous visit is
            // indistinguishable from a live pick at this end, and a GB
            // organisation number applied under an ES billing address is
            // refused upstream as a generic failure the buyer cannot act on.
            //
            // Fails OPEN on an absent stamp, not closed: records written before
            // this field existed carry no country, and treating "unstamped" as
            // "wrong country" would drop a legitimate company on the first load
            // after an upgrade. They gain the stamp on the next write.
            const capturedCountry = data.companyCountry ? String(data.companyCountry) : '';
            const currentCountry = this.countryCode();
            if (
                capturedCountry &&
                currentCountry &&
                capturedCountry.toLowerCase() !== currentCountry.toLowerCase()
            ) {
                console.debug({
                    logger: 'twoPayment.applyCompanyData.countryMismatch',
                    capturedCountry,
                    currentCountry
                });
                return;
            }
            const companyName =
                typeof data.companyName == 'string' && data.companyName ? data.companyName : '';
            // String(), not a typeof test: a non-string id (a numeric
            // `national_identifier.id`, say) coerced to '' would route a
            // company that HAS an identifier down the identifier-less branch
            // and actively clear it.
            const companyId = data.companyId == null ? '' : String(data.companyId);
            if (companyName && !companyId) {
                if (!authoritative && (this.companyName() || this.companyId())) return;
                this.selectCompanyWithoutIdentifier(companyName);
            } else {
                this.fillCompanyData({ companyName: companyName, companyId: companyId });
            }
        },
        /**
         * A selected company whose registry holds no national identifier.
         * Writes the name and CLEARS any previously selected company's
         * identifier.
         *
         * No order intent is placed here, and — read this before assuming an
         * escape hatch exists — the buyer has NO way to supply the missing
         * identifier, on this step or any other. The tile's company-number
         * input is displayed again (TWO-25288) but is `readonly`. The address
         * step's own company-number field is still in the DOM and still
         * publishes to the `companyData` customer-data section if something
         * fills it, but it is CSS-hidden unconditionally
         * (`.two-company-id-hidden`, applied by
         * Plugin/Model/Checkout/LayoutProcessorPlugin.php with nothing anywhere
         * removing the class), so no buyer reaches it either.
         *
         * An order left in this state — company name set, identifier empty — is
         * therefore a dead end by design: it is refused server-side by
         * Model/Two.php::authorize(), and the buyer's only route forward is to
         * pick a company the registry does hold an identifier for, or to use
         * another payment method.
         */
        selectCompanyWithoutIdentifier: function (companyName) {
            console.debug({ logger: 'twoPayment.selectCompanyWithoutIdentifier', companyName });
            this.companyName(companyName);
            $(this.companyNameSelector).val(companyName);
            $('#select2-company_name-container')?.text(companyName);
            this.companyId('');
        },
        fillTelephone: function (telephone) {
            console.debug({ logger: 'twoPayment.fillTelephone', telephone });
            telephone = typeof telephone == 'string' ? telephone : '';
            if (!telephone) return;
            this.telephone(telephone);
        },
        /**
         * Forget the company currently in play, because it belongs to a
         * country the buyer has just left (TWO-24867).
         *
         * Name AND number, unlike clearCompany(), which leaves `companyName()`
         * standing for the sole-trader signup prefill. There is no such reader
         * here: the name came out of the previous country's registry, so
         * carrying it into a signup popup or an intent-approved notice for the
         * new country would be asserting a company the buyer has not chosen
         * there.
         *
         * The widget is deliberately left bound. disableCompanySearch() would
         * destroy it and force the buyer to click "Search for company" again to
         * do the very thing the switch implies they are about to do; the picker
         * reads `searchCountryCode()` per request (see its `getCountryCode`),
         * so the bound widget already searches the new country.
         */
        clearCompanyForCountryChange: function () {
            console.debug({ logger: 'twoPayment.clearCompanyForCountryChange' });
            // The address written for a sole trader in the previous country is
            // reverted by the address step on the same switch, so the adoption
            // has to be re-armed here too — otherwise the buyer who returns to
            // that country gets the identity back with no address behind it.
            adoptedSoleTraderIds.clear();
            this.companyName('');
            this.companyId('');
            $(this.companyNameSelector).val('');
            // No `?.` here, unlike the two sibling writers of this node. A
            // jQuery set is always truthy — empty or not — so the optional
            // chain on those lines can never short-circuit, and reproducing it
            // would imply a guard that does not exist. `.text()` on an empty
            // set is already a no-op, which is the behaviour that was wanted.
            $('#select2-company_name-container').text('');
        },
        fillCountryCode: function (countryCode) {
            console.debug({ logger: 'twoPayment.fillCountryCode', countryCode });
            countryCode = typeof countryCode == 'string' ? countryCode : '';
            if (!countryCode) return;
            const previousCountryCode = this.countryCode();
            // A CHANGE, not the first resolution. `countryCode()` starts empty
            // and is filled from the quote on load, and that first fill must
            // not discard a company the quote's own address already carries —
            // updateAddress() calls this immediately before fillCompanyData()
            // with both values off the SAME address.
            if (previousCountryCode && previousCountryCode !== countryCode) {
                this.clearCompanyForCountryChange();
            }
            this.countryCode(countryCode);
            var self = this;
            this.getSupportedCompanyTypes(countryCode).then(function (types) {
                // Guard against a stale answer when the buyer switches
                // country again before the lookup resolves.
                if (self.countryCode() !== countryCode) return;
                if (types.includes('SOLE_TRADER')) {
                    self.showModeTab(true);
                } else {
                    if (self.showSoleTrader()) {
                        self.registeredOrganisationMode();
                    }
                    self.showModeTab(false);
                }
            });
        },
        // The registry's supported-company-types answer for a billing
        // country, via the plugin's server-side relay (the merchant API
        // key never reaches the browser; the server caches per country).
        // Successful answers — including the legitimate empty list, which
        // means business-only checkout — are memoized per country; errors
        // resolve to [] (fail soft, no sole-trader option) but are NOT
        // memoized so the next country change retries.
        getSupportedCompanyTypes: function (countryCode) {
            var key = countryCode.toLowerCase();
            if (Object.prototype.hasOwnProperty.call(this.supportedCompanyTypes, key)) {
                return Promise.resolve(this.supportedCompanyTypes[key]);
            }
            var self = this;
            var URL = url.build(`rest/V1/two/supported-company-types/${encodeURIComponent(key)}`);
            return fetch(URL, { headers: { Accept: 'application/json' } })
                .then(function (response) {
                    if (!response.ok) throw new Error(`Error response from ${URL}.`);
                    return response.json();
                })
                .then(function (types) {
                    if (!Array.isArray(types)) throw new Error(`Malformed response from ${URL}.`);
                    self.supportedCompanyTypes[key] = types;
                    return types;
                })
                .catch(function (error) {
                    console.error({ logger: 'twoPayment.getSupportedCompanyTypes', error });
                    return [];
                });
        },
        updateAddress: function (address) {
            if (!address) return;
            let telephone = (address.telephone || '').replace(' ', '');
            let companyName = address.company;
            let companyId = '';
            let department = '';
            let project = '';
            let countryCode = address.countryId.toLowerCase();
            if (Array.isArray(address.customAttributes)) {
                address.customAttributes.forEach(function (item) {
                    console.debug({ logger: 'twoPayment.updateAddress', item });
                    if (item.attribute_code == 'company_id') {
                        companyId = item.value;
                    }
                    if (item.attribute_code == 'company_name') {
                        companyName = item.value;
                    }
                    if (item.attribute_code == 'project') {
                        project = item.value;
                    }
                    if (item.attribute_code == 'department') {
                        department = item.value;
                    }
                });
            }
            this.fillCountryCode(countryCode);
            this.fillTelephone(telephone);
            this.fillCompanyData({ companyName, companyId });
            if (project) this.project(project);
            if (department) this.department(department);
        },
        updateShippingAddress: function (shippingAddress) {
            console.debug({ logger: 'twoPayment.updateShippingAddress', shippingAddress });
            if (shippingAddress.getCacheKey() == quote.billingAddress().getCacheKey()) {
                this.updateAddress(shippingAddress);
            }
            // Unconditional, unlike updateAddress() above: a SAVED address
            // and a NEW address differ in whether #shipping-new-address-form
            // exists at all, which is exactly what
            // isTileCompanySearchActive() reads — so this has to re-run on
            // every shipping-address change, not only the ones the cache-key
            // check lets through.
            this.refreshTileCompanySearchBinding();
        },
        updateBillingAddress: function (billingAddress) {
            console.debug({ logger: 'twoPayment.updateBillingAddress', billingAddress });
            this.updateAddress(billingAddress);
            this.refreshTileCompanySearchBinding();
        },
        /**
         * Found in adversarial review, 2026-08-04: enableCompanySearch() only
         * runs from three call sites (initObservable() → registeredOrganisationMode(),
         * the "Search for company" link, and the supported-company-types
         * callback) — NONE of which fire when a buyer switches between a NEW
         * and a SAVED shipping/billing address. `#shipping-new-address-form`
         * appears and disappears exactly on that switch, which is the live
         * DOM signal isTileCompanySearchActive() reads. Without this, a buyer
         * who starts on a new address (tile inactive, address-area control
         * live) and then picks a saved address (address-area control's host
         * form disappears) would find the tile's search widget never bound —
         * `isTileCompanySearchActive()` would newly return true, the FIELD
         * would show again (template `visible:` binding does react to that),
         * but the select2 WIDGET behind it was never initialised, leaving a
         * plain text input with no dropdown.
         *
         * Idempotent both directions: enableCompanySearch() itself no-ops if
         * the tile is not the active location (checked at its own top), and
         * destroyCompanySearchWidget() is documented safe to call when
         * nothing is bound.
         */
        refreshTileCompanySearchBinding: function () {
            if (this.isTileCompanySearchActive()) {
                this.enableCompanySearch();
            } else {
                this.destroyCompanySearchWidget();
            }
        },
        /**
         * TWO-25347: an earlier version of this comment called the
         * undisposed subscriptions below "idempotent today... waste rather
         * than a bug". That was wrong about the network side effect
         * specifically — N stacked subscriptions run applyCompanyData() N
         * times, and each of THOSE independently drives fillCompanyData() →
         * placeOrderIntent(), so N stacked subscriptions fired N concurrent
         * order_intent POSTs for a single company pick. Fire Checkout
         * re-renders this payment renderer on every totals/shipping change
         * (see payment-availability.js), so it hit this far more often than
         * Luma/Amasty and was the one where it surfaced. Fixed below by
         * disposing the previous set on every call and disposing the
         * current set in dispose(), mirroring the `_twoVisibilitySub`
         * pattern already in place for the minimum-order subscription.
         */
        fillCustomerData: function () {
            const self = this;

            // See the docblock above for why this is here.
            if (this._customerDataSubs) {
                this._customerDataSubs.forEach((sub) => sub.dispose());
            }
            this._customerDataSubs = [];

            this._customerDataSubs.push(customerData
                .get('companyData')
                // Authoritative: a change NOTIFICATION on this section is the
                // shipping-step picker writing it, so an identifier-less
                // company picked there must land here as "name set, id
                // cleared, field editable" rather than being dropped by
                // fillCompanyData()'s empty-id early return.
                //
                // "A notification IS the shipping-step picker" holds only
                // because `companyData` has exactly one writer
                // (address-autocomplete.js's publishCompanyData(), the sole
                // call site of `customerData.set('companyData', …)`) and the
                // repo
                // ships no `sections.xml`, so the server never invalidates and
                // repopulates the section either. Add a second writer, or a
                // `sections.xml` entry, and this authoritative subscription
                // becomes a path by which a non-selection can clobber a live
                // payment-step pick — the exact thing the non-authoritative
                // one-shot read below exists to prevent.
                .subscribe((companyData) =>
                    self.applyCompanyData(companyData, { authoritative: true })
                ));
            // NOT authoritative: this is a one-shot read of a localStorage
            // section that outlives page loads and previous orders, and
            // fillCustomerData() is re-callable (registeredOrganisationMode()).
            // A stale `{companyName, companyId: ''}` row must not overwrite a
            // live payment-step pick.
            this.applyCompanyData(customerData.get('companyData')());

            this._customerDataSubs.push(customerData
                .get('shippingTelephone')
                .subscribe((telephone) => self.fillTelephone(telephone)));
            this.fillTelephone(customerData.get('shippingTelephone')());

            this._customerDataSubs.push(customerData
                .get('countryCode')
                .subscribe((countryCode) => self.fillCountryCode(countryCode)));
            this.fillCountryCode(customerData.get('countryCode')());

            this._customerDataSubs.push(
                quote.shippingAddress.subscribe((address) => self.updateShippingAddress(address))
            );
            this.updateShippingAddress(quote.shippingAddress());

            this._customerDataSubs.push(
                quote.billingAddress.subscribe((address) => self.updateBillingAddress(address))
            );
            this.updateBillingAddress(quote.billingAddress());
        },
        afterPlaceOrder: function () {
            const url = $.mage.cookies.get(this._brandConfig.redirectUrlCookieCode);
            if (url) {
                // Magento's place-order action stops the full-screen loader the
                // moment the AJAX resolves — which leaves the checkout bare for
                // the few seconds the redirect to the hosted checkout takes,
                // making buyers think nothing happened. Re-show the loader so
                // the overlay stays up until the browser actually navigates
                // away (the new page discards it).
                fullScreenLoader.startLoader();
                $.mage.redirect(url);
            }
        },
        placeOrder: function (data, event) {
            // Additional logging to check isPaymentTermsAccepted
            console.debug({
                logger: 'placeOrder',
                isPaymentTermsAccepted: this.isPaymentTermsAccepted()
            });
            if (event) event.preventDefault();
            // Clear stale validation errors from a prior placeOrder attempt so
            // resubmits don't render outdated messages (e.g. terms-not-accepted
            // lingering after the box has been ticked).
            //
            // Deliberately does NOT clear orderIntentApprovedNotice. That
            // notice reports a fact about the buyer's company that a local
            // validation failure (unticked terms, invalid email list) does not
            // invalidate, and being persistent across exactly this kind of
            // event is the point of moving it out of messageContainer. It is
            // cleared only when the company changes or a fresh intent
            // declines/errors — see initialize() and
            // processOrderIntent*Response().
            this.messageContainer.clear();

            // TWO-25326 §6a (2026-08-03 ruling): a manual (name-only, no
            // organisation number) capture must NOT make the method usable,
            // but the method stays SELECTABLE — this is a blocked SUBMIT,
            // matching WC/PS/Hyvä, not a disabled/unselectable radio button.
            // Before this, Magento had no client-side check here at all: a
            // manual capture went silently nowhere (see
            // isCompanyCaptured()'s doc comment for why the method still
            // has to accept name-only submissions rather than refuse them
            // outright — validate() does not enforce it either, since
            // company_name has no number companion field to require).
            if (!this.isCompanyCaptured()) {
                this.showErrorMessage(this.companyRequiredMessage);
                return;
            }

            // Recover a stale place-order latch.
            //
            // isPlaceOrderActionAllowed has only two writers: this renderer, which
            // sets it false before a place-order request and re-arms it in that
            // request's .always(), and core's quote.billingAddress subscription in
            // Magento_Checkout/js/view/payment/default, which sets it to
            // `address !== null`. The latter has no path back to true other than a
            // further billing-address change, so a transient null billing address
            // — routine while the buyer edits an address, and around the
            // renderer re-creation Luma performs whenever the payment-method list
            // refreshes after a shipping-method save — leaves the observable false
            // indefinitely. It is shared, too (declared on the prototype), so
            // re-rendering does not reset it, and the template only greys the
            // button with a CSS class rather than disabling it. Clicks therefore
            // kept arriving here and were swallowed in silence: checkout was
            // unrecoverable without a page reload.
            //
            // Re-arming is safe exactly when no request of ours is in flight, so
            // the double-submit protection the latch provides is preserved. The
            // one case this cannot rescue is a request that never settles at all
            // (a hung response, or an earlier-registered fail handler that throws
            // and aborts the rest of jQuery's callback list before our .always()).
            // Nothing client-side safely can: "hung" and "still working" are
            // indistinguishable from here, and guessing wrong duplicates an order.
            // Avoiding that state is what the shipping-method check below is for.
            if (!this.isPlaceOrderActionAllowed() && !placeOrderInFlight) {
                this.isPlaceOrderActionAllowed(true);
            }

            if (this.isPaymentTermsEnabled && !this.isPaymentTermsAccepted()) {
                this.processTermsNotAcceptedErrorResponse();
                return;
            }

            // Validate emails on the forward list. validateEmails() displays the
            // message itself, as processTermsNotAcceptedErrorResponse() above does
            // for its own failure, so the caller only reacts to the false return
            // — showing invalidEmailListMessage here too rendered it twice.
            if (this.isInvoiceEmailsEnabled && !this.validateEmails()) {
                return;
            }

            // Refuse a placement the server is certain to reject.
            // QuoteValidator::validateBeforeSubmit raises "The shipping method is
            // missing" before any payment authorize, so posting a shipping-less
            // quote can only fail — but it still costs a place-order request that
            // holds Magento's per-cart CartMutex lock and keeps the button latched
            // for as long as it runs, which is how a single mistimed click used to
            // end in "The cart is locked for processing" on the retry. Keeping the
            // failure client-side gives the buyer a message they can act on and
            // never takes the lock. Virtual quotes have no shipping method by
            // design and must not be blocked.
            if (!quote.isVirtual() && !quote.shippingMethod()) {
                this.showErrorMessage(
                    $t('The shipping method is missing. Select the shipping method and try again.')
                );
                return;
            }

            // Refuse a placement the backend is certain to reject because the
            // selected term is no longer on offer — see
            // isSelectedTermStillAvailable(). The buyer has to reselect, so say
            // that rather than posting and surfacing an API error.
            if (!this.isSelectedTermStillAvailable()) {
                this.showErrorMessage(this.termUnavailableMessage);
                return;
            }

            // No isPaymentTermsAccepted() conjunct here: acceptance is a
            // precondition only when the checkbox is actually rendered, which is
            // exactly the isPaymentTermsEnabled gate above. Requiring it
            // unconditionally made the button silently dead whenever terms are
            // disabled — nothing renders the checkbox, no JS writes the
            // observable, so it stays false, placeOrderBackend never runs and no
            // error is shown. Only ConfigProvider hardcoding the flag to true
            // kept that unreachable.
            if (this.validate() && additionalValidators.validate()) {
                if (placeOrderInFlight) {
                    // Keyed on our own in-flight flag rather than on
                    // isPlaceOrderActionAllowed: that observable is shared and
                    // core's quote.billingAddress subscription can set it back to
                    // true while our request is still running, which would let a
                    // second click through to a second order-create POST.
                    this.showErrorMessage($t('Your order is already being placed. Please wait.'));
                    return;
                }
                this.placeOrderBackend();
            }
        },
        placeOrderBackend: function () {
            const self = this;
            let deferred;
            placeOrderInFlight = true;
            this.isPlaceOrderActionAllowed(false);
            try {
                deferred = this.getPlaceOrderDeferredObject();
            } catch (error) {
                // A synchronous throw would otherwise leave the latch set with no
                // .always() attached to ever clear it.
                placeOrderInFlight = false;
                this.isPlaceOrderActionAllowed(true);
                throw error;
            }
            // .always() is registered BEFORE .done() on purpose. jQuery fires a
            // deferred's callbacks in registration order and a throw from one
            // aborts the rest of the list, so with .done() first an
            // afterPlaceOrder() throw would strand both the latch and the
            // in-flight flag — the one failure mode the recovery in placeOrder()
            // cannot rescue. Clearing first is safe: JS is single-threaded, so no
            // click can land between the clear and afterPlaceOrder() running.
            return deferred
                .always(function () {
                    placeOrderInFlight = false;
                    self.isPlaceOrderActionAllowed(true);
                })
                .done(function () {
                    self.afterPlaceOrder();
                    if (self.redirectAfterPlaceOrder) {
                        redirectOnSuccessAction.execute();
                    }
                });
        },
        /**
         * Wire up the persistent inline "order intent approved" notice.
         *
         * @param {object} config the brand's window.checkoutConfig subtree
         */
        initOrderIntentApprovedNotice: function (config) {
            // `null` means the active brand suppressed the notice
            // (<intent_approved_notice_enabled>false</…> in brand.xml) — the
            // template then emits no element at all.
            this.orderIntentApprovedNoticeCopy = config.orderIntentApprovedNotice || null;

            // The observable is created here rather than declared in
            // `defaults` on purpose. Entries in `defaults` are copied onto
            // each instance by reference, so a ko.observable() declared there
            // is SHARED across every renderer instance — the same footgun
            // documented against isPlaceOrderActionAllowed in placeOrder().
            // Luma re-creates this renderer whenever the payment-method list
            // refreshes, and a shared observable would carry one quote's
            // notice into the next.
            this.orderIntentApprovedNotice = ko.observable('');

            // TWO-25326 §7.3 (2026-08-03 ruling) counterpart to the notice
            // above: the persistent tile message for a clean "not approved"
            // order-intent response. Same suppression source
            // (orderIntentApprovedNoticeCopy === null means the brand
            // turned the whole intent message off), separate copy.
            this.orderIntentDeclinedNoticeCopy = config.orderIntentDeclinedNotice || null;
            this.orderIntentDeclinedNotice = ko.observable('');

            // TWO-25326 (2026-08-05 four-platform convergence): the third
            // outcome, "the check errored", in the same bordered box. No
            // brand-supplied copy behind it and no suppression switch — the
            // text is the renderer's own error message, and a brand that
            // declines to state a verdict has not thereby asked for failures
            // to be silent. Per-instance for the same reason as the two above.
            this.orderIntentErrorNotice = ko.observable('');

            // The notice is *persistent* — unlike the message-region
            // treatment it replaces, it survives checkout updates and a
            // failed placeOrder validation (see the deliberate omission in
            // placeOrder(), which clears messageContainer but not this). It
            // must NOT survive the buyer's company changing, because the
            // approval/decline it reports was for the previous company.
            // fillCompanyData() writes companyName / companyId before firing
            // the intent, so these subscriptions clear first and
            // processOrderIntent*Response() re-sets afterwards; a company
            // edited by hand in the input clears both notices and leaves
            // them cleared, which is the correct fail-closed outcome.
            var self = this;
            this.companyName.subscribe(function () {
                self.clearOrderIntentNotices();
            });
            this.companyId.subscribe(function () {
                self.clearOrderIntentNotices();
            });
        },
        /**
         * Substitute the buyer's company name/number into a
         * ConfigProvider-shipped copy template. Shared by
         * resolveOrderIntentApprovedNotice() and
         * resolveOrderIntentDeclinedNotice() — the only difference between
         * the two is which `copy` object and which fallback they pass in.
         *
         * A replacer *function* is used rather than a plain string so `$&` /
         * `$1` sequences in a company name or number are taken literally
         * instead of as replacement patterns.
         *
         * @param {?object} copy {withCompany, withoutCompany, companyNameToken, companyNumberToken}|null
         * @returns {string}
         */
        resolveCompanyNotice: function (copy) {
            if (!copy) {
                return '';
            }
            const companyName = (this.companyName() || '').trim();
            if (!companyName) {
                return copy.withoutCompany;
            }
            // The DISPLAY number, so an internal `TWO:`-prefixed identifier
            // never reaches the sentence (TWO-25326). When there is nothing to
            // show, the token goes AND SO DO ITS BRACKETS — the default copy
            // is "… by {{companyName}} ({{companyNumber}}) …", so substituting
            // an empty string would render "Company Name ()". That also fixes
            // the pre-existing empty-`companyId` case, which read the same way.
            const companyId = companySearch.formatCompanyNumber(this.companyId());
            const withNumber = companyId
                ? copy.withCompany.replace(copy.companyNumberToken, function () {
                    return companyId;
                })
                : companySearch.stripBracketedToken(copy.withCompany, copy.companyNumberToken);
            // Name LAST, so a company name that happens to contain brackets
            // cannot be mistaken for the number's own brackets above.
            return withNumber.replace(copy.companyNameToken, function () {
                return companyName;
            });
        },
        /**
         * Resolve the intent-approved notice text for the current buyer.
         * Returns '' when the active brand suppressed the notice, so callers
         * can assign the result unconditionally.
         */
        resolveOrderIntentApprovedNotice: function () {
            return this.resolveCompanyNotice(this.orderIntentApprovedNoticeCopy);
        },
        /**
         * Resolve the intent-DECLINED notice text for the current buyer
         * (TWO-25326 §7.3, 2026-08-03 ruling). Returns '' when the active
         * brand suppressed the intent message entirely.
         */
        resolveOrderIntentDeclinedNotice: function () {
            return this.resolveCompanyNotice(this.orderIntentDeclinedNoticeCopy);
        },
        processOrderIntentSuccessResponse: function (response) {
            if (response) {
                if (response.approved) {
                    // Persistent inline notice inside the payment tile, not
                    // messageContainer.addSuccessMessage(): the KO
                    // getRegion('messages') region this renderer used before
                    // is cleared on every checkout update, so on Luma the
                    // approval reassurance was effectively never seen.
                    this.clearOrderIntentNotices();
                    this.orderIntentApprovedNotice(this.resolveOrderIntentApprovedNotice());
                } else {
                    // TWO-25326 §7.3 (2026-08-03 ruling): a clean "not
                    // approved" response is a business outcome, not a
                    // technical failure, so it gets the SAME persistent
                    // tile-notice treatment as approval — a toast that a
                    // later checkout update wipes is not "the tile shows
                    // ONLY the intent message" the ruling asks for.
                    this.clearOrderIntentNotices();
                    this.orderIntentDeclinedNotice(this.resolveOrderIntentDeclinedNotice());
                }
            }
        },
        processOrderIntentErrorResponse: function (response) {
            // An intent that errored says nothing about approval; drop any
            // notice from a previous, successful intent — including a previous
            // error, so a second attempt does not stack two boxes' worth of
            // text or leave the first error's wording under a newer one.
            this.clearOrderIntentNotices();

            // `let`, not `const`: the SCHEMA_ERROR branch below reassigns
            // this to '' once it has pushed the field-level errors into
            // messageContainer itself. A `const` here made every
            // SCHEMA_ERROR response throw
            // "TypeError: Assignment to constant variable" the instant it
            // arrived — silently, since this runs inside a jQuery Deferred
            // `.fail()` handler with nothing upstream to surface a thrown
            // error to the buyer. That is very likely why a manual-entry
            // buyer saw no message at all before the §6a client-side gate
            // was added: this path was the one meant to show it, and it was
            // broken.
            let message = this.generalErrorMessage,
                self = this;
            if (response && response.responseJSON) {
                const errorCode = response.responseJSON.error_code,
                    errorMessage = response.responseJSON.error_message,
                    errorDetails = response.responseJSON.error_details;
                switch (errorCode) {
                    case 'SCHEMA_ERROR':
                        const errors = response.responseJSON.error_json;
                        if (errors) {
                            message = '';
                            self.messageContainer.clear();
                            _.each(errors, function (error) {
                                self.messageContainer.errorMessages.push(error.msg);
                            });
                        }
                        break;
                    case 'JSON_MISSING_FIELD':
                        if (errorDetails) {
                            message = errorDetails;
                        }
                        break;
                    case 'MERCHANT_NOT_FOUND_ERROR':
                    case 'ORDER_INVALID':
                        message = errorMessage;
                        if (errorDetails) {
                            message += ' - ' + errorDetails;
                        }
                        break;
                }
            }
            if (message) {
                // The tile's own bordered box, not the checkout message
                // region (TWO-25326, 2026-08-05). SCHEMA_ERROR is the one
                // exception and it opts itself out by blanking `message`
                // above: those are per-FIELD validation errors, several at a
                // time, which belong with the fields and not in a box that
                // states one outcome.
                this.showOrderIntentErrorNotice(message);
            }
        },
        processTermsNotAcceptedErrorResponse: function (response) {
            this.showErrorMessage(this.termsNotAcceptedMessage);
        },
        getEmail: function () {
            return quote.guestEmail ? quote.guestEmail : window.checkoutConfig.customerData.email;
        },
        placeOrderIntent: function () {
            let totals = quote.getTotals()(),
                billingAddress = quote.billingAddress(),
                lineItems = [];

            // Do not fire order intent for BV companies in NL
            if (billingAddress.countryId.toLowerCase() == 'nl') {
                const isBVCompany = this.BVCompanyRegex.test(this.companyName());
                console.debug({
                    logger: 'twoPayment.placeOrderIntent',
                    countryId: billingAddress.countryId,
                    isBVCompany
                });
                if (!isBVCompany) {
                    return $.Deferred().resolve(null);
                }
            }

            // Capture brand config before the iteration so the callback
            // closure has access to it — arrow-fn would also work but
            // keeping the existing `function` shape minimises diff.
            var brandConfig = this._brandConfig;
            _.each(quote.getItems(), function (item) {
                lineItems.push({
                    name: item['name'],
                    description: item['description'] ? item['description'] : '',
                    discount_amount: parseFloat(item['discount_amount']).toFixed(2),
                    gross_amount: parseFloat(item['row_total_incl_tax']).toFixed(2),
                    net_amount: parseFloat(item['row_total']).toFixed(2),
                    quantity: item['qty'],
                    unit_price: parseFloat(item['price']).toFixed(2),
                    tax_amount: parseFloat(item['tax_amount']).toFixed(2),
                    tax_rate: (parseFloat(item['tax_percent']) / 100).toFixed(6),
                    tax_class_name: '',
                    quantity_unit: brandConfig.orderIntentConfig.weightUnit,
                    image_url: item['thumbnail'],
                    type: item['is_virtual'] === '0' ? 'PHYSICAL' : 'DIGITAL'
                });
            });
            lineItems.push({
                name: 'Shipping',
                description: 'Shipping fee',
                gross_amount: parseFloat(totals['shipping_incl_tax']).toFixed(2),
                net_amount: parseFloat(totals['shipping_amount']).toFixed(2),
                quantity: 1,
                unit_price: parseFloat(totals['shipping_amount']).toFixed(2),
                tax_amount: parseFloat(totals['shipping_tax_amount']).toFixed(2),
                // Free shipping makes shipping_amount 0, and 0/0 is NaN, not
                // 0 — order_intent then 400s on every free-shipping cart
                // (found investigating TWO-25326 §6a: it blocked testing
                // the gating fix on a free-shipping cart). A zero-taxed
                // shipping line rate is genuinely 0, not "no rate" (the tax
                // AMOUNT above is already faithfully 0.00), so the guard
                // resolves to '0.000000' rather than omitting the key or
                // inventing a non-zero rate.
                //
                // Guarded on `!isFinite`, not `=== 0`, since adversarial
                // review (2026-08-04) found the narrower check still let a
                // literal "NaN" reach the wire: `shipping_amount` can arrive
                // non-numeric/undefined mid totals-recalc (Amasty's async
                // shipping-method changes), and `parseFloat(undefined) === 0`
                // is false, so that case fell through to the division branch
                // and produced `NaN / NaN` — same 400, different trigger.
                tax_rate: (
                    !isFinite(parseFloat(totals['shipping_amount'])) ||
                    parseFloat(totals['shipping_amount']) === 0
                        ? 0
                        : parseFloat(totals['shipping_tax_amount']) /
                          parseFloat(totals['shipping_amount'])
                ).toFixed(6),
                tax_class_name: '',
                quantity_unit: 'unit',
                type: 'SHIPPING_FEE'
            });

            const gross_amount = parseFloat(totals['grand_total']);
            const tax_amount =
                parseFloat(totals['tax_amount']) + parseFloat(totals['shipping_tax_amount']);
            const net_amount = gross_amount - tax_amount;
            const orderIntentRequestBody = {
                gross_amount: gross_amount.toFixed(2),
                net_amount: net_amount.toFixed(2),
                tax_amount: tax_amount.toFixed(2),
                currency: totals['quote_currency_code'],
                line_items: lineItems,
                buyer: {
                    company: {
                        organization_number: this.companyId(),
                        country_prefix: billingAddress.countryId,
                        company_name: this.companyName()
                    },
                    representative: {
                        email: this.getEmail(),
                        first_name: billingAddress.firstname,
                        last_name: billingAddress.lastname,
                        phone_number: this.getTelephone()
                    }
                },
                merchant_id: this._brandConfig.orderIntentConfig.merchant?.id,
                merchant_short_name: this._brandConfig.orderIntentConfig.merchant?.short_name
            };

            console.debug({ logger: 'twoPayment.placeOrderIntent', orderIntentRequestBody });

            const queryParams = new URLSearchParams({
                client: this._brandConfig.orderIntentConfig.extensionPlatformName,
                client_v: this._brandConfig.orderIntentConfig.extensionDBVersion
            });

            return $.ajax({
                url: `${
                    this._brandConfig.checkoutApiUrl
                }/v1/order_intent?${queryParams.toString()}`,
                type: 'POST',
                // `global: false`, and this is load-bearing for the tile-local
                // spinner rather than a micro-optimisation. Magento's
                // `loaderAjax` widget is bound on `<body>` and listens for
                // jQuery's GLOBAL `ajaxSend`/`ajaxComplete` events, raising the
                // same body-wide `processStart`/`processStop` overlay that
                // `fullScreenLoader` does. So leaving this `true` kept the
                // page-covering overlay up for the whole order-intent round
                // trip no matter what this renderer did with its own loader —
                // found in adversarial review of the local-spinner change.
                // Opting out of the global handlers is safe here: this request
                // has its own done/fail/always handling and reports failures
                // through the tile's messageContainer.
                global: false,
                contentType: 'application/json',
                headers: {},
                data: JSON.stringify(orderIntentRequestBody)
            });
        },
        validate: function () {
            return $(this.formSelector).valid();
        },
        // getCode() is inherited from Magento_Checkout/js/view/payment/default
        // and returns this.item.method — the type pushed via rendererList,
        // which the brand-specific wrapper file decides per overlay.
        getData: function () {
            return {
                method: this.getCode(),
                additional_data: {
                    companyName: this.companyName(),
                    companyId: this.companyId(),
                    project: this.project(),
                    department: this.department(),
                    orderNote: this.orderNote(),
                    poNumber: this.poNumber(),
                    invoiceEmails: this.invoiceEmails(),
                    selectedTerm: this.selectedTerm()
                }
            };
        },
        /**
         * Fill the billing address form from a picked company.
         *
         * TWO-25503: address autofill is gated on its own dedicated admin
         * setting alone — "Autofill company address" (`enable_address_search`
         * → isAddressSearchEnabled), applied inside
         * companySearch.lookupCompanyAddress(). This method adds no gate of
         * its own.
         *
         * Never on placement. "Enable company search in address entry"
         * (`enable_company_search`) decides WHERE the search control is
         * mounted, and where a merchant put the control is not an answer to
         * whether they want the address filled — so the tile picker and the
         * address-area picker now obey the same single setting. Under the
         * previous placement gate a merchant with search in the payment tile
         * got no autofill at all, "Autofill company address" notwithstanding.
         */
        addressLookup: function (selectedCompany) {
            // Scoped like the sole-trader write-back, and for the same reason:
            // this is the TILE's picker, so it writes as the billing/invoice
            // role, and the payment step has more than one address form in the
            // DOM carrying these field names. The address-area picker stays
            // document-wide — it runs on a step that renders one.
            return companySearch.lookupCompanyAddress(
                this._brandConfig,
                selectedCompany,
                companySearch.billingRoleFormRoot()
            );
        },
        /**
         * (Re-)bind the company-search picker to the company-name input.
         *
         * @param {object} [options]
         * @param {boolean} [options.openDropdown] open the picker as soon as
         *        the widget is bound. Set only by the "Search for company"
         *        link: returning to search mode should land the buyer in the
         *        search box, not on a closed picker they must click again.
         *        Leave it off for the initial render, where popping a dropdown
         *        open unasked would steal focus from the payment form.
         */
        enableCompanySearch: function (options) {
            // TWO-25326 §7.1: don't bind the tile's own live search widget
            // when the address-area control is this checkout's active
            // location for it — one shared control, not two simultaneous
            // ones. See isTileCompanySearchActive() for the fallback carve-
            // out (saved address / virtual cart).
            if (!this.isTileCompanySearchActive()) {
                return;
            }
            const self = this;
            // ONE instance per renderer — a renderer is pushed once per
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
                    fieldSelector: self.companyNameSelector,
                    config: self._brandConfig,
                    getCountryCode: function () {
                        return self.searchCountryCode();
                    },
                    searchForCompanyText: self.searchForCompanyText,
                    onSelect: function (selectedItem) {
                        const companyId = selectedItem.companyId;
                        const companyName = selectedItem.text;
                        // applyCompanyData(), not fillCompanyData(): a pick is
                        // authoritative and must overwrite the previous
                        // company's identifier even when the new company has
                        // none of its own.
                        self.applyCompanyData({ companyId, companyName }, { authoritative: true });
                        // TWO-25193: the payment-tile picker used to stop
                        // here, leaving the billing address blank. Gated on
                        // isAddressSearchEnabled alone — see addressLookup().
                        self.addressLookup(selectedItem);
                    },
                    onManualEntryActivated: function () {
                        self.clearCompany();
                        // Focused too, not just shown: clearCompany() tears
                        // the select2 widget down through
                        // destroyCompanySearchWidget(), which removes the
                        // manual-entry button — the element that had focus —
                        // from the document. Nothing else in that teardown
                        // path refocuses anything, so a buyer who reached the
                        // button by keyboard is otherwise dropped back to
                        // `<body>` with no visible focus at all.
                        self._companySearchControl.showSearchForCompanyLink(true);
                    },
                    onBound: function () {
                        $('#select2-company_name-container').text(self.companyName());
                    }
                });
            }
            this._companySearchControl.bind(options);
        },
        getTelephone: function () {
            const telephone = this.telephone();
            console.debug({ logger: 'twoPayment.getTelephone', telephone });
            return telephone;
        },
        configureFormValidation: function () {
            $.async(this.formSelector, function (form) {
                $(form).validation({
                    errorPlacement: function (error, element) {
                        let errorPlacement = element.closest('.field');
                        if (element.is(':checkbox') || element.is(':radio')) {
                            errorPlacement = element.parents('.control').children().last();
                            if (!errorPlacement.length) {
                                errorPlacement = element.siblings('label').last();
                            }
                        }
                        if (element.siblings('.tooltip').length) {
                            errorPlacement = element.siblings('.tooltip');
                        }
                        if (element.next().find('.tooltip').length) {
                            errorPlacement = element.next();
                        }
                        errorPlacement.append(error);
                    }
                });
            });
        },
        /**
         * Abandon the selected company: blank the name input and tear the
         * search widget down. Callers are the manual-entry row and
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
        clearCompany: function () {
            $(this.companyNameSelector).val('');
            this.companyId('');
            this.disableCompanySearch();
        },
        /**
         * Destroy only the widget this component bound. Safe to call twice.
         */
        destroyCompanySearchWidget: function () {
            if (!this._companySearchControl) return;
            // The control's own reference to the "Search for company" link
            // is deliberately NOT cleared by destroy(): the re-enable link
            // has to stay resolvable after the widget is gone — see
            // searchForCompanyLink().
            const $field = this._companySearchControl.getField();
            // isBound()/destroy() are the scoped, idempotent teardown of
            // ONLY the widget this component bound — the same belt-and-
            // braces need as the `select2:close` handler above: destroy()
            // does not always route through a `close` event first, and an
            // undetached button would stay wired to this disposed renderer
            // for the life of the page.
            if (!this._companySearchControl.destroy()) return;
            $field.attr('type', 'text');
        },
        /**
         * Kept for its callers; delegates to the scoped teardown. The previous
         * document-wide `$(this.companyNameSelector).select2('destroy')` had
         * the same multi-brand hazard as dispose() did — the renderer is
         * pushed once per Two-family brand, so it could destroy a sibling
         * brand's live widget.
         */
        disableCompanySearch: function () {
            this.destroyCompanySearchWidget();
        },
        getTokens() {
            const URL = url.build('rest/V1/two/get-tokens');
            const OPTIONS = {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ cartId: quote.getQuoteId() })
            };

            return fetch(URL, OPTIONS)
                .then((response) => {
                    if (response.ok) {
                        return response.json();
                    } else {
                        throw new Error(`Error response from ${URL}.`);
                    }
                })
                .then((json) => {
                    return json[0];
                })
                .catch((error) => {
                    console.error({ logger: 'twoPayment.getTokens', error });
                    throw error;
                });
        },

        getAutofillData() {
            const billingAddress = quote.billingAddress();
            const _street = billingAddress.street
                .filter((s) => s)
                .join(', ')
                .split(' ');
            const building = _street[0].replace(',', '');
            const street = _street.slice(1, _street.length).join(' ');
            const data = {
                email: this.getEmail(),
                first_name: billingAddress.firstname,
                last_name: billingAddress.lastname,
                company_name: this.companyName(),
                phone_number: this.getTelephone(),
                billing_address: {
                    building: building,
                    street: street,
                    postal_code: billingAddress.postcode,
                    city: billingAddress.city,
                    region: billingAddress.region,
                    country_code: billingAddress.countryId
                }
            };
            return btoa(JSON.stringify(data));
        },

        // True once the signup URL can be built. Both tokens are minted
        // together by lookupSoleTrader(), and neither is optional in the
        // URL — an empty one produces a signup link the hosted flow rejects.
        hasSignupTokens() {
            return !!(this.delegationToken && this.autofillToken);
        },

        // @param {Object} [options] `{ autoselect: false }` skips the hosted
        //   flow's silent autoselect of the buyer's existing registration —
        //   the only caller passing it is selectDifferentSoleTrader(). The
        //   flag is currently unread server-side (PS/WC precedent); wired
        //   through unconditionally, no client-side branching on its value.
        //
        // Re-entrancy (TWO-25461 review finding): at most one sole-trader
        // popup is ever live. A prior one still open (e.g. the first
        // adoption's popup, still open when "select a different sole
        // trader" is clicked) is CLOSED, not left running — closing it
        // rather than refocusing it also stops it from later posting a
        // stale ACCEPTED that would win a race against whichever popup the
        // buyer actually completed (popupMessageListener() additionally
        // checks event.source against the tracked handle as a second line
        // of defence). Same handle also makes a double-click on either
        // launcher a close-then-reopen instead of two concurrent tabs.
        openIframe(options) {
            if (!this.hasSignupTokens()) {
                return null;
            }
            if (this._soleTraderPopupWindow && !this._soleTraderPopupWindow.closed) {
                this._soleTraderPopupWindow.close();
            }
            const data = this.getAutofillData();
            var brandParams = this._brandConfig.brand ? `&brand=${this._brandConfig.brand}` : '';
            if (this._brandConfig.brandVersion) {
                brandParams += `&brandVersion=${this._brandConfig.brandVersion}`;
            }
            if (options && options.autoselect === false) {
                brandParams += '&autoselect=false';
            }
            const URL = `${this._brandConfig.checkoutPageUrl}/soletrader/signup?businessToken=${this.delegationToken}&autofillToken=${this.autofillToken}&autofillData=${data}${brandParams}`;
            const windowFeatures =
                'location=yes,resizable=yes,scrollbars=yes,status=yes, height=805, width=700';
            this._soleTraderPopupWindow = window.open(URL, '_blank', windowFeatures);
            return this._soleTraderPopupWindow;
        },

        // "Select a different sole trader" (TWO-25461 §7). Only rendered once
        // an identity has already been adopted (see the template's `visible:`
        // binding), so tokens are already minted — skip the passive
        // cookie/email-match pre-check entirely and launch the popup directly,
        // synchronously with the click, with autoselect=false so the hosted
        // flow doesn't silently re-pick the same registration.
        selectDifferentSoleTrader() {
            return this.openIframe({ autoselect: false });
        },

        registeredOrganisationMode() {
            // Read BEFORE the flag is flipped: this method is both the
            // "leave sole trader" action and the tile's own initialiser
            // (initObservable() calls it), and those two need different
            // behaviour below.
            const wasSoleTrader = this.showSoleTrader();
            this.showSoleTrader(false);
            this.showPopupMessage(false);
            if (wasSoleTrader) {
                // Leaving sole trader discards the sole-trader identity, the
                // mirror of enterSoleTraderUi() clearing on the way in. A
                // sole trader's minted name and synthetic number are not a
                // registered organisation, so carrying them across the mode
                // switch would submit one identity under the other's mode —
                // getData() would otherwise post the sole trader's number
                // under whatever name the buyer then searches for.
                //
                // Before enableCompanySearch(), not after: clearCompany()
                // ends in destroyCompanySearchWidget(), which would otherwise
                // tear down the widget that call had just rebuilt.
                this.clearCompany();
                // The address half of the same discard (TWO-25461 §5). Without
                // it the sole trader's registered address stays in the form and
                // goes out under whatever registered company the buyer searches
                // for next. Only fields still holding what the write put there
                // are cleared, so a buyer's own edits survive.
                companySearch.revertAutofilledAddress();
                // Re-arm the once-per-identity guard: re-entering sole-trader
                // mode has an address to write again.
                adoptedSoleTraderIds.clear();
            }
            this.enableCompanySearch();
            this.fillCustomerData();
        },

        // Enter the sole-trader UI. No token/buyer work here — that is owned
        // by the chip-click handler.
        enterSoleTraderUi() {
            this.showSoleTrader(true);
            // Resolve the link BEFORE clearCompany(), which tears the widget
            // down and nulls _$companyNameField.
            const $searchForCompany = this.searchForCompanyLink();
            this.clearCompany();
            $searchForCompany.hide();
        },

        /**
         * Sole-trader chip click — the ONLY entry point to sole-trader
         * autofill (TWO-25503). Nothing is minted, looked up or adopted off
         * the back of email entry alone: the chip click is the buyer asking,
         * and the same explicit-click rule the company-search chip follows.
         *
         * A resolved-and-matching buyer is adopted; anything else sends the
         * buyer to signup. The popup therefore opens after an await and can
         * be blocker-killed, which is what showPopupMessage()'s link fallback
         * is for — but only once the tokens exist, since a link built with an
         * empty businessToken/autofillToken is rejected by the hosted flow.
         */
        soleTraderMode() {
            this.enterSoleTraderUi();
            return this.lookupSoleTrader().then(() => {
                const pf = this.soleTraderLookup;
                if (pf.ready && pf.matches && pf.buyer) {
                    this.adoptSoleTraderBuyer(pf.buyer);
                    return;
                }
                const win = this.openIframe();
                this.showPopupMessage(!win && this.hasSignupTokens());
            });
        },

        // Mint tokens + read the buyer on the Two cookie for the entered email.
        // Deduped per email so a repeated chip click doesn't re-mint — the
        // recorded result stands.
        //
        // Always returns a promise, including on the skip paths, so a caller
        // can sequence on the tokens being minted.
        lookupSoleTrader() {
            if (!this.showModeTab()) {
                return Promise.resolve();
            }
            const email = (this.getEmail() || '').trim();
            if (!email || email === this.soleTraderLookupEmail) {
                return Promise.resolve();
            }
            this.soleTraderLookupEmail = email;
            this.soleTraderLookup = { ready: false, buyer: null, matches: false };
            // Spinner covers the whole round trip; cleared on every terminal
            // branch below (success, failure) via .finally(), never a timeout.
            this.soleTraderLookupInFlight(true);
            // A chain is tied to the email it started for. An edit starts a
            // second one, the two settle in no guaranteed order, and the loser
            // must not record its buyer or act on it: `matches` is computed
            // against the email in the form NOW, so a late-landing chain for the
            // previous email resolves to a non-match, reverts sole-trader mode
            // and — before this guard — left the OTHER buyer's address in the
            // form with no identity beside it. The minted token travels as a
            // local for the same reason.
            const generation = (this._soleTraderLookupGeneration || 0) + 1;
            this._soleTraderLookupGeneration = generation;
            const isCurrent = () => this._soleTraderLookupGeneration === generation;
            return this.getTokens()
                .then((json) => {
                    if (!isCurrent()) return null;
                    this.delegationToken = json.delegation_token;
                    this.autofillToken = json.autofill_token;
                    return this.resolveBuyer(false, json.autofill_token, isCurrent);
                })
                .catch(() => {
                    if (!isCurrent()) return;
                    this.soleTraderLookup = { ready: true, buyer: null, matches: false };
                })
                .finally(() => {
                    // Guarded on THIS call's email (review finding): an
                    // earlier, still-outstanding call's .finally must not
                    // clear the flag out from under a newer call that started
                    // after an email edit — flightDepth-style ref-counting
                    // without needing a counter, since soleTraderLookupEmail
                    // already identifies "the current one".
                    if (this.soleTraderLookupEmail === email) {
                        this.soleTraderLookupInFlight(false);
                    }
                });
        },

        // Read the buyer on the Two cookie; resolves to the buyer or null. No
        // UI side effects — the caller decides what to do with the result.
        fetchBuyer(autofillToken) {
            const URL = `${this._brandConfig.checkoutApiUrl}/autofill/v1/buyer/current`;
            return fetch(URL, {
                credentials: 'include',
                headers: {
                    'two-delegated-authority-token': autofillToken || this.autofillToken
                }
            })
                .then((response) => {
                    if (response.ok) return response.json();
                    if (response.status == 404) return null;
                    throw new Error(`Error response from ${URL}.`);
                })
                .catch(() => null);
        },

        /**
         * Read the buyer on the Two cookie and record it as the lookup
         * result, resolving to it.
         *
         * `authenticated` is the difference between the two contexts that
         * reach here, and it is not cosmetic. On the PASSIVE pre-auth path
         * nothing proves the cookie's buyer is the person checking out, so
         * the buyer only counts when its email matches the one entered on
         * the form. After the signup popup reports ACCEPTED the buyer has
         * just authenticated server-side, and the email it authenticated
         * with IS the identity — the order's contact-email field has no say
         * in it. Requiring a match there discarded an authenticated buyer
         * and left the company field permanently blank with no route
         * forward (TWO-25461).
         *
         * @param {boolean} authenticated buyer already proved this identity
         * @param {string} [autofillToken] token this lookup belongs to — the
         *        lookup passes the one IT minted, so a superseded chain cannot
         *        read the cookie under a newer chain's token
         * @param {function(): boolean} [isCurrent] answers whether this lookup is
         *        still the live one; a superseded lookup resolves to its result
         *        without recording it as the lookup result
         * @returns {Promise<object>} the result, recorded unless superseded
         */
        resolveBuyer(authenticated, autofillToken, isCurrent) {
            return this.fetchBuyer(autofillToken).then((buyer) => {
                let matches;
                if (authenticated) {
                    matches = !!buyer;
                } else {
                    const entered = (this.getEmail() || '').trim().toLowerCase();
                    matches = !!(
                        buyer &&
                        buyer.email &&
                        String(buyer.email).toLowerCase() === entered
                    );
                }
                const resolved = { ready: true, buyer: buyer, matches: matches };
                if (isCurrent && !isCurrent()) return resolved;
                this.soleTraderLookup = resolved;
                return resolved;
            });
        },

        /**
         * Adopt the sole trader an autofill buyer record describes: the identity
         * into the tile, and the registered ADDRESS into the checkout address
         * form (TWO-25461 §5).
         *
         * The single write-back path for both contexts that resolve a buyer:
         * the chip click's own lookup, and the popup's post-signup `ACCEPTED`
         * message.
         *
         * NOT gated on `isAddressAreaCompanySearchEnabled` or the server-side
         * `isAddressSearchEnabled`, per §5: those gate an ORDINARY
         * company-search selection's address write, and both are legitimately
         * off wherever company search is not mounted in the address area —
         * which is exactly where the sole-trader entry point lives.
         *
         * The address is written ONCE PER IDENTITY. Both paths can fire for
         * the same buyer (a repeated chip click, a repeated `ACCEPTED`),
         * and a replay must not overwrite a correction the buyer made to the
         * address after the first write. Adopting a DIFFERENT identity writes
         * again, and leaving sole-trader mode re-arms it.
         *
         * No order-intent trigger is added: that stays fillCompanyData()'s,
         * behind `_orderIntentInFlightFor`.
         *
         * @param {object} buyer `/autofill/v1/buyer/current` record
         */
        adoptSoleTraderBuyer(buyer) {
            if (!buyer || typeof buyer !== 'object') return;
            // Writes nothing without BOTH a name and a number, so a sole trader
            // with no trading name of their own gets no identity written here —
            // but their ADDRESS is still filled, which is why the address write
            // is not inside that call.
            this.fillCompanyData({
                companyId: buyer.organization_number,
                companyName: buyer.company_name
            });
            // `getCode()` comes from the payment renderer base and is the brand's
            // own method code; guarded because the guard must not be the thing
            // that breaks a renderer built without it.
            const brandCode = (typeof this.getCode === 'function' && this.getCode()) || '';
            const identity = soleTraderIdentityKey(brandCode, buyer);
            if (!identity || !adoptedSoleTraderIds.has(identity)) {
                // Isolated: a DOM failure in the address write must not take the
                // identity fill or the popup message with it. Recorded only once
                // the write has actually happened, so a failure — or a record
                // with no address on it — leaves the next attempt free to try
                // again rather than consuming the single chance.
                try {
                    if (this.writeSoleTraderAddress(buyer) && identity) {
                        adoptedSoleTraderIds.add(identity);
                    }
                } catch (error) {
                    console.error({ logger: 'twoPayment.adoptSoleTraderBuyer', error });
                }
            }
            this.showPopupMessage(false);
        },
        /**
         * Write the buyer's registered address into the billing-role address
         * form.
         *
         * `billing_address` is the registered address and is what fills the
         * form; `shipping_address` is a FALLBACK for a record that carries one
         * and no billing address. A null must never be allowed to blank
         * anything, hence the shape check rather than a truthiness test.
         *
         * Field routing is companySearch.applyAddress()'s, shared with the
         * ordinary company-search write — §2.6 asks for one rule for both, with
         * no sole-trader special case. The SCOPE is this surface's own decision:
         * the tile writes as the billing/invoice role (§1(a.3)), and the payment
         * step has more than one address form in the DOM to get that wrong in.
         *
         * @param {object} buyer `/autofill/v1/buyer/current` record
         * @returns {boolean} whether there was an address to write
         */
        writeSoleTraderAddress(buyer) {
            const source = buyer.billing_address || buyer.shipping_address || null;
            if (!source || typeof source !== 'object') return false;
            console.debug({ logger: 'twoPayment.writeSoleTraderAddress', source });
            companySearch.applyAddress(source, companySearch.billingRoleFormRoot());
            return true;
        },
        popupMessageListener() {
            // Kept so dispose() can detach it. A re-rendering checkout (Amasty
            // and Fire Checkout both rebuild the method list) otherwise stacks
            // one live listener per render, each closed over a disposed renderer
            // and each now writing to the live address form on one message.
            this._popupMessageHandler = (event) => {
                // event.source correlation (TWO-25461 review finding): a
                // second line of defence alongside openIframe()'s
                // close-before-reopen — a message from any popup that is not
                // the one CURRENTLY tracked (stale, already superseded) is
                // ignored, so it can never overwrite a later adoption.
                if (
                    this.showSoleTrader() &&
                    event.origin == this._brandConfig.checkoutPageUrl &&
                    event.source === this._soleTraderPopupWindow
                ) {
                    if (event.data == 'ACCEPTED') {
                        // Signup complete: the buyer authenticated in the
                        // popup, so re-read and autofill whatever identity
                        // that produced, staying in sole-trader mode. No
                        // email-match check — see resolveBuyer().
                        // Supersede any lookup still in flight. Its `matches`
                        // is computed against the form's email, so a late
                        // pre-auth answer would disagree with the identity the
                        // buyer just authenticated, revert sole-trader mode and
                        // take this adoption's address back out with it.
                        this._soleTraderLookupGeneration = (this._soleTraderLookupGeneration || 0) + 1;
                        this.resolveBuyer(true).then((pf) => {
                            if (pf.matches && pf.buyer) {
                                this.adoptSoleTraderBuyer(pf.buyer);
                            }
                        });
                    } else {
                        this.showErrorMessage(this.soleTraderErrorMessage);
                    }
                }
            };
            window.addEventListener('message', this._popupMessageHandler);
        }
    });
});
