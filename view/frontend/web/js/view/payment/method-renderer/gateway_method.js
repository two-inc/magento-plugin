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
        // No `searchForCompanyButton` id selector here on purpose. The append
        // guard is per-field, so this renderer — pushed once per Two-family
        // brand — would otherwise mint duplicate ids and a document-wide
        // lookup would hand brand A's link to brand B. Use
        // searchForCompanyLink() instead.
        searchForCompanyLink: function () {
            // Resolved from the cached CONTAINER, not from
            // `_$companyNameField`: the paths where this link is visible are
            // exactly the paths that have already destroyed the widget and
            // nulled that node (the manual-entry row → clearCompany() →
            // destroyCompanySearchWidget()). Keying on the node meant
            // enterSoleTraderUi() silently hid nothing and the link stayed up
            // in sole-trader mode.
            const $container = this._$companyFieldContainer;
            if (!$container || !$container.find) return $();
            return $container.find('.search_for_company');
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
        showPopupMessage: ko.observable(false),
        showSoleTrader: ko.observable(false),
        showWhatIsTwo: ko.observable(false),
        showModeTab: ko.observable(false),
        termsAccepted: ko.observable(false), // Observable for terms accepted state
        BVCompanyRegex: /(?:^|\s)B(?:\.)?V(?:\.)?$/i,

        initialize: function () {
            this._super();

            // Autofill prefetch result for the entered email (belt-and-braces
            // for the sole-trader chip): mint tokens + read the buyer on the
            // Two cookie when sole trader becomes available, so the chip click
            // can resolve synchronously. ready=false until the first prefetch
            // resolves; matches=true when that buyer owns the entered email.
            this.prefetched = { ready: false, buyer: null, matches: false };
            this.prefetchedEmail = null;

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
            this.paymentTermsMessage = config.paymentTermsMessage;
            this.termsNotAcceptedMessage = config.termsNotAcceptedMessage;
            this.isPaymentTermsEnabled = config.isPaymentTermsEnabled;
            this.initOrderIntentApprovedNotice(config);
            this.orderIntentDeclinedMessage = config.orderIntentDeclinedMessage;
            this.generalErrorMessage = config.generalErrorMessage;
            this.invalidEmailListMessage = config.invalidEmailListMessage;
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

            this.registeredOrganisationMode();
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
            this._super();
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
                fullScreenLoader.startLoader();
                const self = this;
                this.placeOrderIntent()
                    .always(function () {
                        fullScreenLoader.stopLoader();
                    })
                    .done(function (response) {
                        self.processOrderIntentSuccessResponse(response);
                    })
                    .fail(function (response) {
                        self.processOrderIntentErrorResponse(response);
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
         * No editable state is derived for the organisation number: the tile has
         * no company-number input to derive one for (TWO-25288).
         *
         * Writers of `companyId()` — four paths, not three; the last two are
         * easy to conflate and are NOT the same thing:
         *
         *  - a company-search pick on this step;
         *  - the sole-trader autofill response (applyPrefetch(), soleTraderMode(),
         *    and the postMessage handler);
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
         * No order intent is placed here, and the buyer has no way to supply
         * the missing identifier on this step any more — the tile's
         * company-number input is gone (TWO-25288). The one remaining manual
         * route is the address step's field, which publishes what it is given
         * to the `companyData` customer-data section; that arrives back here as
         * an authoritative notification and reaches fillCompanyData(), so that
         * route does get intent-checked.
         *
         * An order left in this state — company name set, identifier empty — is
         * refused server-side by Model/Two.php::authorize().
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
        fillCountryCode: function (countryCode) {
            console.debug({ logger: 'twoPayment.fillCountryCode', countryCode });
            countryCode = typeof countryCode == 'string' ? countryCode : '';
            if (!countryCode) return;
            this.countryCode(countryCode);
            var self = this;
            this.getSupportedCompanyTypes(countryCode).then(function (types) {
                // Guard against a stale answer when the buyer switches
                // country again before the lookup resolves.
                if (self.countryCode() !== countryCode) return;
                if (types.includes('SOLE_TRADER')) {
                    self.showModeTab(true);
                    // Prefetch the autofill buyer for the entered email so a known
                    // sole trader is auto-selected and the chip click can open the
                    // signup popup synchronously. No-op when the email is unknown.
                    self.prefetchSoleTrader();
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
        },
        updateBillingAddress: function (billingAddress) {
            console.debug({ logger: 'twoPayment.updateBillingAddress', billingAddress });
            this.updateAddress(billingAddress);
        },
        /**
         * PRE-EXISTING, not introduced here, flagged so it is not mistaken for
         * new: none of the subscriptions below are disposed, and
         * fillCustomerData() is re-callable (registeredOrganisationMode(),
         * reached from applyPrefetch()). N calls therefore leave N stacked
         * subscriptions on each section, so one notification runs
         * applyCompanyData() N times. Idempotent today, so it is waste rather
         * than a bug — out of scope for this change.
         */
        fillCustomerData: function () {
            const self = this;

            customerData
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
                );
            // NOT authoritative: this is a one-shot read of a localStorage
            // section that outlives page loads and previous orders, and
            // fillCustomerData() is re-callable (registeredOrganisationMode(),
            // reached from applyPrefetch()). A stale `{companyName,
            // companyId: ''}` row must not overwrite a live payment-step pick.
            this.applyCompanyData(customerData.get('companyData')());

            customerData
                .get('shippingTelephone')
                .subscribe((telephone) => self.fillTelephone(telephone));
            this.fillTelephone(customerData.get('shippingTelephone')());

            customerData
                .get('countryCode')
                .subscribe((countryCode) => self.fillCountryCode(countryCode));
            this.fillCountryCode(customerData.get('countryCode')());

            quote.shippingAddress.subscribe((address) => self.updateShippingAddress(address));
            this.updateShippingAddress(quote.shippingAddress());

            quote.billingAddress.subscribe((address) => self.updateBillingAddress(address));
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

            // The notice is *persistent* — unlike the message-region
            // treatment it replaces, it survives checkout updates and a
            // failed placeOrder validation (see the deliberate omission in
            // placeOrder(), which clears messageContainer but not this). It
            // must NOT survive the buyer's company changing, because the
            // approval it reports was for the previous company.
            // fillCompanyData() writes companyName / companyId before firing
            // the intent, so these subscriptions clear first and
            // processOrderIntentSuccessResponse re-sets afterwards; a company
            // edited by hand in the input clears the notice and leaves it
            // cleared, which is the correct fail-closed outcome.
            var self = this;
            this.companyName.subscribe(function () {
                self.orderIntentApprovedNotice('');
            });
            this.companyId.subscribe(function () {
                self.orderIntentApprovedNotice('');
            });
        },
        /**
         * Resolve the intent-approved notice text for the current buyer.
         *
         * Returns '' when the active brand suppressed the notice, so callers
         * can assign the result unconditionally.
         *
         * The company name is substituted client-side because it is only
         * known here; ConfigProvider ships both resolved copy variants plus
         * the token to replace. A replacer *function* is used rather than a
         * plain string so `$&` / `$1` sequences in a company name are taken
         * literally instead of as replacement patterns.
         */
        resolveOrderIntentApprovedNotice: function () {
            const copy = this.orderIntentApprovedNoticeCopy;
            if (!copy) {
                return '';
            }
            const companyName = (this.companyName() || '').trim();
            if (!companyName) {
                return copy.withoutCompany;
            }
            return copy.withCompany.replace(copy.companyNameToken, function () {
                return companyName;
            });
        },
        processOrderIntentSuccessResponse: function (response) {
            if (response) {
                if (response.approved) {
                    // Persistent inline notice inside the payment tile, not
                    // messageContainer.addSuccessMessage(): the KO
                    // getRegion('messages') region this renderer used before
                    // is cleared on every checkout update, so on Luma the
                    // approval reassurance was effectively never seen.
                    this.orderIntentApprovedNotice(this.resolveOrderIntentApprovedNotice());
                } else {
                    this.orderIntentApprovedNotice('');
                    this.showErrorMessage(this.orderIntentDeclinedMessage);
                }
            }
        },
        processOrderIntentErrorResponse: function (response) {
            // An intent that errored says nothing about approval; drop any
            // notice from a previous, successful intent.
            this.orderIntentApprovedNotice('');

            const message = this.generalErrorMessage,
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
                this.showErrorMessage(message);
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
                tax_rate: (
                    parseFloat(totals['shipping_tax_amount']) /
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
                        company_name: this.companyName(),
                        website: window.BASE_URL
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
                global: true,
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
         * Fill the billing address form from a picked company. No-op unless
         * the merchant has address search enabled (ConfigProvider exposes
         * `enable_address_search` as isAddressSearchEnabled).
         */
        addressLookup: function (selectedCompany) {
            return companySearch.lookupCompanyAddress(this._brandConfig, selectedCompany);
        },
        enableCompanySearch: function () {
            let self = this;
            require(['Two_Gateway/select2-4.1.0/js/select2.min'], function () {
                // No `$.async('input#company_id')` block here. The tile has no
                // company-number input to resolve, derive an editable state
                // for, or subscribe the observables to — TWO-25288 removed it,
                // because a hand-typeable organisation number is not an
                // accepted source for one.
                $.async(self.companyNameSelector, function (companyNameField) {
                    // `$.async` is a MutationObserver, and every call to
                    // enableCompanySearch() adds another one, so on a
                    // one-page checkout (Fire Checkout) this fires
                    // repeatedly. Re-binding is deliberately NOT guarded
                    // against: select2 4.1's own constructor destroys any
                    // existing instance on the same node
                    // (`GetData(el, 'select2').destroy()`), so re-init is the
                    // correct way to re-point the widget — and its handlers'
                    // `self` closure — at the current component. Skipping the
                    // re-init would leave the previous widget alive with
                    // closures over a DISPOSED renderer, so picking a company
                    // would write to dead observables. What re-render safety
                    // needs instead is the teardown in dispose() below.
                    const $companyNameField = $(companyNameField);
                    // Remember the node we bound, so dispose() can destroy
                    // THIS widget rather than whatever a document-wide
                    // selector happens to match.
                    self._$companyNameField = $companyNameField;
                    // Survives the widget teardown on purpose — see
                    // searchForCompanyLink().
                    self._$companyFieldContainer = $companyNameField.closest('.field');
                    // Identity for this bind. Re-stamped below, so a previous
                    // widget's still-in-flight response can't paint chrome on
                    // the widget that replaced it.
                    const bindToken = {};
                    // select2's destroy() only does `$element.off('.select2')`
                    // — our own handlers are not in that namespace and would
                    // survive every re-init, stacking one more copy per
                    // re-render. N stacked `select2:select` handlers means one
                    // company pick fires N address lookups, N-1 of them closed
                    // over disposed renderers. Clear ours first.
                    $companyNameField.off(companySearch.EVENT_NS);
                    // select2's destroy() doesn't disconnect it either (it
                    // isn't select2's own state), and the same re-render that
                    // makes the stale-handler comment above true can also
                    // leave this the ONLY teardown point reached: a re-render
                    // while the picker is open replaces the select2 instance
                    // without necessarily emitting `select2:close` first, so
                    // an observer from the PREVIOUS bind would otherwise pin
                    // its now-detached results list alive until this node's
                    // picker happens to be reopened again.
                    companySearch.detachManualEntryObserver($companyNameField);
                    $companyNameField
                        .select2({
                            minimumInputLength: companySearch.MIN_INPUT_LENGTH,
                            width: '100%',
                            escapeMarkup: function (markup) {
                                return markup;
                            },
                            templateResult: function (data) {
                                return data.html;
                            },
                            templateSelection: function (data) {
                                return data.text;
                            },
                            ajax: companySearch.buildSearchAjaxOptions({
                                config: self._brandConfig,
                                token: bindToken,
                                getCountryCode: function () {
                                    return self.countryCode();
                                },
                                // Bound to THIS node, not to the selector.
                                // A search issued by a widget that has since
                                // been destroyed then finds no instance on
                                // its own element and no-ops, rather than
                                // painting a stale failure onto whatever
                                // picker is live now.
                                onSearching: function (isSearching) {
                                    companySearch.setSearching(
                                        $companyNameField,
                                        isSearching,
                                        bindToken
                                    );
                                },
                                onUnavailable: function (isUnavailable) {
                                    companySearch.setUnavailable(
                                        $companyNameField,
                                        isUnavailable,
                                        bindToken
                                    );
                                }
                            })
                        })
                        .on('select2:open' + companySearch.EVENT_NS, function () {
                            // select2 only detaches the dropdown on close and
                            // only blanks the search input, so anything we
                            // appended into the search box survives. Clear it
                            // here or a reopened picker still shows the last
                            // search's "unavailable" notice.
                            companySearch.clearSearchChrome($companyNameField, bindToken);
                            // The manual-entry affordance is a row INSIDE the
                            // results list, so the model owns its whole
                            // lifecycle (appear at the threshold, survive
                            // each re-render) — matching the address-step
                            // picker's fix for the same defect: a footer
                            // node outside the listbox sat outside the
                            // combobox's `aria-owns`, unreachable by
                            // keyboard and unannounced to a screen reader.
                            // Bound to this node and this bind token, so a
                            // stale widget cannot paint a row onto its
                            // replacement.
                            companySearch.attachManualEntryRow($companyNameField, bindToken);
                            document.querySelector('.select2-search__field').focus();
                        })
                        .on('select2:close' + companySearch.EVENT_NS, function () {
                            // Every open re-attaches, so nothing is lost by
                            // dropping the watcher here — and a checkout that
                            // re-renders the form while the picker is closed
                            // would otherwise leave this node's observer
                            // pinning a detached results list for the life of
                            // the page.
                            companySearch.detachManualEntryObserver($companyNameField);
                        })
                        /*
                         * `select2:selecting` is the PREVENTABLE pre-event
                         * for a selection, and the manual-entry row is not a
                         * company: letting it through would write the
                         * sentinel id into the company name and fire an
                         * address lookup for it. Cancelling here also covers
                         * mouse, Enter and touch in one place, because all
                         * three arrive as a selection.
                         */
                        .on('select2:selecting' + companySearch.EVENT_NS, function (e) {
                            const data = e.params && e.params.args && e.params.args.data;
                            if (!companySearch.isManualEntryOption(data)) return;
                            e.preventDefault();
                            // Resolved here rather than closed over, and
                            // scoped to this bind's container for the same
                            // duplicate-id reason as below.
                            const $searchForCompany = $companyNameField
                                .closest('.field')
                                .find('.search_for_company');
                            // Deferred a tick, deliberately: `select2:selecting`
                            // fires from INSIDE select2's own click/keydown
                            // dispatch, and `clearCompany()` destroys the
                            // select2 instance that dispatch is still running
                            // on. Tearing the widget down synchronously here
                            // would pull it out from under select2's own
                            // post-preventDefault bookkeeping for this event.
                            // A zero-delay defer runs after that dispatch has
                            // fully unwound, once `e.preventDefault()` above
                            // has already told select2 to skip the pick.
                            setTimeout(function () {
                                self.clearCompany();
                                $searchForCompany.show();
                            }, 0);
                        })
                        .on('select2:select' + companySearch.EVENT_NS, function (e) {
                            const selectedItem = e.params.data;
                            const companyId = selectedItem.companyId;
                            const companyName = selectedItem.text;
                            // applyCompanyData(), not fillCompanyData(): a pick
                            // is authoritative and must overwrite the previous
                            // company's identifier even when the new company
                            // has none of its own.
                            self.applyCompanyData(
                                { companyId, companyName },
                                { authoritative: true }
                            );
                            // TWO-25193: the payment-step picker used to stop
                            // here, leaving the billing address blank. Gate is
                            // config.isAddressSearchEnabled, applied inside
                            // lookupCompanyAddress — same one the shipping-step
                            // picker uses.
                            self.addressLookup(selectedItem);
                        });
                    companySearch.markSearchBinding($companyNameField, bindToken);
                    $('#select2-company_name-container').text(self.companyName());
                    // Scoped to the container of the node THIS bind owns.
                    // With two Two-family renderers there is one
                    // `#billing_search_for_company` id in the page, so a
                    // document-wide lookup would hand the link in brand A's
                    // field to whichever component bound last.
                    const $field = $companyNameField.closest('.field');
                    if ($field.find('.search_for_company').length == 0) {
                        $field.append(
                            `<div class="search_for_company" title="${self.searchForCompanyText}">` +
                                `<span>${self.searchForCompanyText}</span>` +
                                '</div>'
                        );
                    }
                    // Re-bound unconditionally (the div survives a re-render,
                    // so an append guard would leave this closed over a stale
                    // component) and scoped to this bind's own container.
                    const $searchForCompany = $field.find('.search_for_company');
                    $searchForCompany
                        .off('click' + companySearch.EVENT_NS)
                        .on('click' + companySearch.EVENT_NS, function () {
                            self.enableCompanySearch();
                            $searchForCompany.hide();
                        });
                    $searchForCompany.hide();
                });
            });
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
         * PRE-EXISTING, flagged rather than changed: this writes the DOM field
         * only and never clears `companyName()` / `companyId()`, so after the
         * manual-entry row is picked the name input reads empty while the
         * observables still hold the previously selected company. Out of scope
         * here; noted so it is not mistaken for new behaviour.
         *
         * The `disableCompanyId` parameter is gone with the company-number
         * input it used to lock (TWO-25288).
         */
        clearCompany: function () {
            $(this.companyNameSelector).val('');
            this.disableCompanySearch();
        },
        /**
         * Destroy only the widget this component bound. Safe to call twice.
         */
        destroyCompanySearchWidget: function () {
            const $field = this._$companyNameField;
            // `_$companyFieldContainer` is deliberately NOT cleared here: the
            // re-enable link lives in that container and has to stay
            // resolvable after the widget is gone.
            this._$companyNameField = null;
            if (!$field || !$field.data || !$field.data('select2')) return;
            // Belt-and-braces alongside the `select2:close` handler above:
            // destroy() does not always route through a `close` event first,
            // and an undetached observer would keep the torn-down results
            // list alive for the life of the page.
            companySearch.detachManualEntryObserver($field);
            $field.off(companySearch.EVENT_NS);
            $field.select2('destroy');
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

        openIframe() {
            const data = this.getAutofillData();
            var brandParams = this._brandConfig.brand ? `&brand=${this._brandConfig.brand}` : '';
            if (this._brandConfig.brandVersion) {
                brandParams += `&brandVersion=${this._brandConfig.brandVersion}`;
            }
            const URL = `${this._brandConfig.checkoutPageUrl}/soletrader/signup?businessToken=${this.delegationToken}&autofillToken=${this.autofillToken}&autofillData=${data}${brandParams}`;
            const windowFeatures =
                'location=yes,resizable=yes,scrollbars=yes,status=yes, height=805, width=610';
            return window.open(URL, '_blank', windowFeatures);
        },

        registeredOrganisationMode() {
            this.showSoleTrader(false);
            this.showPopupMessage(false);
            this.enableCompanySearch();
            this.fillCustomerData();
        },

        // Enter the sole-trader UI. No token/buyer work here — that is owned by
        // the email-driven prefetch and the chip-click handler.
        enterSoleTraderUi() {
            this.showSoleTrader(true);
            // Resolve the link BEFORE clearCompany(), which tears the widget
            // down and nulls _$companyNameField.
            const $searchForCompany = this.searchForCompanyLink();
            this.clearCompany();
            $searchForCompany.hide();
        },

        // Sole-trader chip click. Resolves against the prefetched autofill
        // result so the signup popup (when needed) opens in the same
        // synchronous gesture as the click and is not popup-blocker-killed.
        soleTraderMode() {
            this.enterSoleTraderUi();
            const pf = this.prefetched;
            if (pf.ready && pf.matches && pf.buyer) {
                this.fillCompanyData({
                    companyId: pf.buyer.organization_number,
                    companyName: pf.buyer.company_name
                });
                this.showPopupMessage(false);
            } else if (pf.ready) {
                // Resolved with no matching buyer → signup. Opening here keeps
                // the gesture intact; if the browser blocks it, show the link.
                const win = this.openIframe();
                this.showPopupMessage(!win);
            } else {
                // Prefetch not ready (payment selected before email entered):
                // kick it off and offer the link as the fallback.
                this.showPopupMessage(true);
                this.prefetchSoleTrader();
            }
        },

        // Mint tokens + read the buyer on the Two cookie for the entered email.
        // Deduped per email so re-renders don't re-mint. A matching buyer
        // auto-selects Sole trader and prefills via applyPrefetch().
        prefetchSoleTrader() {
            if (!this.showModeTab()) {
                return;
            }
            const email = (this.getEmail() || '').trim();
            if (!email || email === this.prefetchedEmail) {
                return;
            }
            this.prefetchedEmail = email;
            this.prefetched = { ready: false, buyer: null, matches: false };
            this.getTokens()
                .then((json) => {
                    this.delegationToken = json.delegation_token;
                    this.autofillToken = json.autofill_token;
                    return this.fetchBuyer();
                })
                .then((buyer) => {
                    const entered = (this.getEmail() || '').trim().toLowerCase();
                    const matches = !!(
                        buyer &&
                        buyer.email &&
                        String(buyer.email).toLowerCase() === entered
                    );
                    this.prefetched = { ready: true, buyer: buyer, matches: matches };
                    this.applyPrefetch();
                })
                .catch(() => {
                    this.prefetched = { ready: true, buyer: null, matches: false };
                });
        },

        // Read the buyer on the Two cookie; resolves to the buyer or null. No
        // UI side effects — the caller decides what to do with the result.
        fetchBuyer() {
            const URL = `${this._brandConfig.checkoutApiUrl}/autofill/v1/buyer/current`;
            return fetch(URL, {
                credentials: 'include',
                headers: { 'two-delegated-authority-token': this.autofillToken }
            })
                .then((response) => {
                    if (response.ok) return response.json();
                    if (response.status == 404) return null;
                    throw new Error(`Error response from ${URL}.`);
                })
                .catch(() => null);
        },

        // React to a resolved prefetch: a matching buyer auto-selects Sole
        // trader and prefills; a non-match reverts an active Sole-trader
        // selection to Registered organisation.
        applyPrefetch() {
            const pf = this.prefetched;
            if (pf.matches && pf.buyer) {
                this.enterSoleTraderUi();
                this.fillCompanyData({
                    companyId: pf.buyer.organization_number,
                    companyName: pf.buyer.company_name
                });
                this.showPopupMessage(false);
            } else if (this.showSoleTrader()) {
                this.registeredOrganisationMode();
            }
        },

        popupMessageListener() {
            window.addEventListener('message', (event) => {
                if (this.showSoleTrader() && event.origin == this._brandConfig.checkoutPageUrl) {
                    if (event.data == 'ACCEPTED') {
                        // Signup complete: the new sole trader now owns the
                        // entered email — re-read and autofill, staying in
                        // sole-trader mode.
                        this.fetchBuyer().then((buyer) => {
                            const entered = (this.getEmail() || '').trim().toLowerCase();
                            const matches = !!(
                                buyer &&
                                buyer.email &&
                                String(buyer.email).toLowerCase() === entered
                            );
                            this.prefetched = { ready: true, buyer: buyer, matches: matches };
                            if (matches) {
                                this.fillCompanyData({
                                    companyId: buyer.organization_number,
                                    companyName: buyer.company_name
                                });
                                this.showPopupMessage(false);
                            }
                        });
                    } else {
                        this.showErrorMessage(this.soleTraderErrorMessage);
                    }
                }
            });
        }
    });
});
