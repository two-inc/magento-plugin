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
    'Magento_Catalog/js/price-utils',
    'Two_Gateway/js/model/surcharge',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/company-search',
    'Two_Gateway/js/model/company-identity',
    'Two_Gateway/js/model/company-capture',
    'Two_Gateway/js/model/minimum-order-visibility',
    'mage/url',
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
    priceUtils,
    surchargeModel,
    getBrandConfig,
    companySearch,
    identity,
    companyCapture,
    isAboveMinimums,
    url
) {
    'use strict';

    window.quote = quote;

    /*
     * Knockout's view of the page-level identity, which is framework-free so
     * that Hyvä loads the same file. `companyName` mirrors both ways because the
     * tile's `value:` binding writes back; `mirroring` stops that write echoing
     * into a second pass.
     */
    const capturedName = ko.observable(identity.companyName());
    const capturedId = ko.observable(identity.companyId());
    const soleTraderAdopted = ko.observable(identity.soleTraderAdopted());
    const soleTraderBusy = ko.observable(identity.soleTraderBusy());
    let mirroring = false;

    identity.subscribe(function () {
        mirroring = true;
        capturedName(identity.companyName());
        capturedId(identity.companyId());
        soleTraderAdopted(identity.soleTraderAdopted());
        soleTraderBusy(identity.soleTraderBusy());
        mirroring = false;
    });

    // Only `companyName` mirrors back, because it is the only two-way binding.
    // A write to any of the other three would be reverted by the identity's next
    // notification rather than reaching it.
    capturedName.subscribe(function (value) {
        if (mirroring) return;
        identity.companyName(value);
    });

    // The tile-side host the company-capture component binds at; the tile's own
    // field is visible only while that component has chosen it over the address
    // step's.
    const TILE_COMPANY_FIELD_SELECTOR = '#two_gateway_form input#company_name';

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
        // The page-level identity's own observables, aliased onto the prototype
        // so template bindings and getData() read the one company the buyer
        // picked — which outlives every tile rebuild.
        companyName: capturedName,
        companyId: capturedId,
        soleTraderAdopted: soleTraderAdopted,
        soleTraderBusy: soleTraderBusy,
        invoiceEmails: ko.observable(''),
        project: ko.observable(''),
        department: ko.observable(''),
        orderNote: ko.observable(''),
        poNumber: ko.observable(''),
        selectedTerm: surchargeModel.selectedTerm,
        telephone: ko.observable(''),
        // Tile-local order-intent spinner flag — the module-scope observable
        // declared above, deliberately shared by every instance. See its
        // docblock for why it is not per-instance.
        orderIntentInProgress: orderIntentInProgress,
        showWhatIsTwo: ko.observable(false),
        termsAccepted: ko.observable(false), // Observable for terms accepted state
        BVCompanyRegex: /(?:^|\s)B(?:\.)?V(?:\.)?$/i,

        initialize: function () {
            this._super();

            // Brand-overlay config: read once at initialize time, keyed on
            // this.getCode() so acme_payment, two_payment, etc each pull
            // their own subtree from window.checkoutConfig.payment.
            this._brandConfig = getBrandConfig(this.getCode());
            var config = this._brandConfig;

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

            this.fillCustomerData();
            this.configureFormValidation();
            return this;
        },
        /**
         * Tear down the minimum-order visibility subscription and computed so a
         * re-rendered method list (Amasty rebuilds it on shipping/total change)
         * doesn't accumulate live subscriptions to the singleton quote totals.
         */
        dispose: function () {
            // The notice subscriptions are on the page-level identity, which
            // outlives this renderer — undisposed, every re-render would leave
            // another live subscriber writing to a destroyed instance.
            if (this._noticeSubs) {
                this._noticeSubs.forEach((sub) => sub.dispose());
                this._noticeSubs = null;
            }
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
            this._super();
        },
        /**
         * Whether the company-NAME input is locked against the buyer.
         *
         * A plain function, not a computed: the template calls it inside a ko
         * `attr` binding, so ko tracks the two observables read here as
         * dependencies of that binding and re-evaluates when either changes.
         *
         * Gated on the captured number, not on sole-trader mode alone: this
         * input is `required` and jQuery Validation enforces that on a
         * `[readonly]` field, so a sole trader whose signup captured nothing
         * would otherwise face a blank, locked, unsatisfiable field.
         *
         * @returns {boolean}
         */
        isCompanyNameReadOnly: function () {
            return identity.isSoleTrader() && !!this.companyId();
        },
        /**
         * Whether the tile's own company field is the component's live mount.
         * Hidden rather than removed when the address step is: the component
         * resolves its mount by the field's presence in the DOM.
         *
         * @returns {boolean}
         */
        isTileCompanyFieldVisible: function () {
            if (!companyCapture.config()) return false;
            return companyCapture.mountSelector() === TILE_COMPANY_FIELD_SELECTOR;
        },
        /**
         * Re-point the component's mount after a quote address change, which is
         * what makes the address step's own company field come and go. Silent
         * before the component has booted — the sidebar hook that starts it and
         * this renderer have no ordering guarantee, and it re-points itself on
         * start().
         */
        refreshCompanyMount: function () {
            if (companyCapture.config()) companyCapture.refreshMount();
        },
        /**
         * Name AND organisation number, as opposed to merely named — a manual,
         * name-only capture must not make the method usable. Gates the
         * org-number label in the tile and placeOrder()'s submit check.
         *
         * A plain function, not a computed — same reasoning as
         * isCompanyNameReadOnly() above.
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
         * Same guard, for the company-address lookup failure — not one of the
         * order-intent notices, see initOrderIntentApprovedNotice().
         *
         * @returns {boolean}
         */
        isAddressNoticeVisible: function () {
            return !!(this.addressNotice && this.addressNotice());
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
            identity.write({ companyName, companyId });
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
                    // Deliberately does NOT rethrow: every caller of
                    // fillCompanyData() is an unguarded synchronous context with
                    // its own statements after the call, and rethrowing aborted
                    // those too while leaving the buyer nothing to act on.
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
         * `options.authoritative` says the name-set/id-empty shape came from an
         * act of selection. The one-shot `companyData` READ on init must not be
         * authoritative: it is a localStorage section outliving page loads, and
         * a stale `{companyName, companyId: ''}` row would blank a live pick.
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
            const currentCountry = companyCapture.countryCode();
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
            identity.write({ companyName, companyId: '' }, { authoritative: true });
        },
        fillTelephone: function (telephone) {
            console.debug({ logger: 'twoPayment.fillTelephone', telephone });
            telephone = typeof telephone == 'string' ? telephone : '';
            if (!telephone) return;
            this.telephone(telephone);
        },
        updateAddress: function (address) {
            if (!address) return;
            let telephone = (address.telephone || '').replace(' ', '');
            let companyName = address.company;
            let companyId = '';
            let department = '';
            let project = '';
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
            // Unconditional, unlike updateAddress() above: a SAVED address and a
            // NEW address differ in whether #shipping-new-address-form exists,
            // which is what the component picks its mount by — so this has to
            // re-run on every shipping-address change, not only the ones the
            // cache-key check lets through.
            this.refreshCompanyMount();
        },
        updateBillingAddress: function (billingAddress) {
            console.debug({ logger: 'twoPayment.updateBillingAddress', billingAddress });
            this.updateAddress(billingAddress);
            this.refreshCompanyMount();
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
            // NOT authoritative: a one-shot read of a localStorage section that
            // outlives page loads and previous orders, so a stale
            // `{companyName, companyId: ''}` row must not overwrite a live pick.
            this.applyCompanyData(customerData.get('companyData')());

            this._customerDataSubs.push(customerData
                .get('shippingTelephone')
                .subscribe((telephone) => self.fillTelephone(telephone)));
            this.fillTelephone(customerData.get('shippingTelephone')());

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

            // TWO-25326 §6a: a name-only capture blocks the SUBMIT, not the
            // selection — the method stays selectable, matching WC/PS/Hyvä.
            // validate() cannot enforce it: company_name has no number
            // companion field to require.
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
            // Its OWN box, not the intent-error one: the credit check normally
            // answers after the address lookup, and clearOrderIntentNotices()
            // would blank an address failure the buyer still has to act on.
            this.addressNotice = identity.addressNotice;

            var self = this;
            this._noticeSubs = [
                this.companyName.subscribe(function () {
                    self.clearOrderIntentNotices();
                }),
                this.companyId.subscribe(function () {
                    self.clearOrderIntentNotices();
                })
            ];
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

            // A 429 is transient — a wait, not a decline. It arrives either as
            // a raw Magento webapi fault or inside a proxy envelope.
            if (response && response.status === 429) {
                this.showOrderIntentErrorNotice(
                    (response.responseJSON && response.responseJSON.message) ||
                    $t('Too many requests. Please wait a moment and try again.')
                );
                return;
            }

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
                    case 'PROXY_REFUSED':
                        message = errorMessage;
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
                }
            };

            console.debug({ logger: 'twoPayment.placeOrderIntent', orderIntentRequestBody });

            // Proxied through the plugin's own backend so the merchant API
            // key and any configured firewall token stay server-side; the
            // merchant identity in the body is replaced there too.
            const deferred = $.Deferred();
            $.ajax({
                url: url.build('rest/V1/two/order-intent'),
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
                data: JSON.stringify({ payload: JSON.stringify(orderIntentRequestBody) })
            })
                .done(function (raw) {
                    const envelope = companySearch.unwrapProxyResponse(raw);
                    if (envelope.ok) {
                        deferred.resolve(envelope.body);
                    } else {
                        // Shaped like a jqXHR: processOrderIntentErrorResponse
                        // reads both fields off one, and drops to a generic
                        // decline without the status.
                        deferred.reject({ status: envelope.status, responseJSON: envelope.body });
                    }
                })
                .fail(function (jqXHR) {
                    deferred.reject(jqXHR);
                });
            return deferred.promise();
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
         * "Select a different sole trader" — the tile's only sole-trader
         * affordance. Silent before the component has booted, which is the
         * state in which no sole trader can have been adopted anyway.
         */
        selectDifferentSoleTrader() {
            const soleTrader = companyCapture.soleTrader();
            if (!soleTrader) return null;
            return soleTrader.selectDifferentSoleTrader();
        }
    });
});
