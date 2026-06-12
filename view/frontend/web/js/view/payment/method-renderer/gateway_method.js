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
    getBrandConfig
) {
    'use strict';

    window.quote = quote;

    return Component.extend({
        defaults: {
            template: 'Two_Gateway/payment/gateway_method'
        },
        redirectAfterPlaceOrder: false,
        // Brand-supplied checkout subtitle; populated in initialize() from
        // the brand's checkoutConfig subtree. Empty ('') for the vanilla
        // Two brand → the template renders no subtitle text. Brand overlays
        // (ABN, …) supply the string + its translations.
        twoSubtitleHtml: '',
        isPaymentTermsAccepted: ko.observable(false),
        soleTraderCountryCodes: ['gb'],
        formSelector: 'form#two_gateway_form',
        companyNameSelector: 'input#company_name',
        companyIdSelector: 'input#company_id',
        enterDetailsManuallyText: $t('Enter details manually'),
        enterDetailsManuallyButton: '#billing_enter_details_manually',
        searchForCompanyText: $t('Search for company'),
        searchForCompanyButton: '#billing_search_for_company',
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

            // Brand-overlay config: read once at initialize time, keyed on
            // this.getCode() so abn_payment, two_payment, etc each pull
            // their own subtree from window.checkoutConfig.payment.
            this._brandConfig = getBrandConfig(this.getCode());
            var config = this._brandConfig;

            this.twoSubtitleHtml = config.subtitleHtml || '';
            this.paymentTermsMessage = config.paymentTermsMessage;
            this.termsNotAcceptedMessage = config.termsNotAcceptedMessage;
            this.isPaymentTermsEnabled = config.isPaymentTermsEnabled;
            this.orderIntentApprovedMessage = config.orderIntentApprovedMessage;
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

            var terms = config.availableBuyerTerms || [];
            this.availableBuyerTerms = terms;
            this.showTermSelector = terms.length > 1;
            this.showSingleTerm = terms.length === 1;
            this.singleTermLabel = terms.length === 1
                ? $t('Payment Terms %1 days').replace('%1', terms[0])
                : '';

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
                var allZero = !isLoading && amounts.every(function (a) { return a < 0.005; });
                return terms.map(function (days, i) {
                    return {
                        days: days,
                        daysLabel: days + ' ' + $t('days'),
                        isLoading: isLoading,
                        surchargeLabel: (isLoading || allZero) ? '' : '+' + priceUtils.formatPrice(amounts[i], quote.getPriceFormat())
                    };
                });
            });

            this.registeredOrganisationMode();
            this.configureFormValidation();
            this.popupMessageListener();
            return this;
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
                this.showErrorMessage(this.invalidEmailListMessage, 3);
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
            $(this.companyIdSelector).val(companyId);
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
            if (this.soleTraderCountryCodes.includes(countryCode.toLowerCase())) {
                this.showModeTab(true);
            } else {
                if (this.showSoleTrader()) {
                    this.registeredOrganisationMode();
                }
                this.showModeTab(false);
            }
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
        fillCustomerData: function () {
            const self = this;

            customerData
                .get('companyData')
                .subscribe((companyData) => self.fillCompanyData(companyData));
            this.fillCompanyData(customerData.get('companyData')());

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
            this.messageContainer.clear();
            if (this.isPaymentTermsEnabled && !this.isPaymentTermsAccepted()) {
                this.processTermsNotAcceptedErrorResponse();
                return;
            }

            // Validate emails on the forward list
            if (this.isInvoiceEmailsEnabled && !this.validateEmails()) {
                this.showErrorMessage(this.invalidEmailListMessage);
                return;
            }

            if (
                this.validate() &&
                additionalValidators.validate() &&
                this.isPaymentTermsAccepted() === true &&
                this.isPlaceOrderActionAllowed() === true
            )
                this.placeOrderBackend();
        },
        placeOrderBackend: function () {
            const self = this;
            this.isPlaceOrderActionAllowed(false);
            return this.getPlaceOrderDeferredObject()
                .done(function () {
                    self.afterPlaceOrder();
                    if (self.redirectAfterPlaceOrder) {
                        redirectOnSuccessAction.execute();
                    }
                })
                .always(function () {
                    self.isPlaceOrderActionAllowed(true);
                });
        },
        processOrderIntentSuccessResponse: function (response) {
            if (response) {
                if (response.approved) {
                    this.messageContainer.addSuccessMessage({
                        message: this.orderIntentApprovedMessage
                    });
                } else {
                    this.showErrorMessage(this.orderIntentDeclinedMessage);
                }
            }
        },
        processOrderIntentErrorResponse: function (response) {
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
                url: `${this._brandConfig.checkoutApiUrl}/v1/order_intent?${queryParams.toString()}`,
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
        enableCompanySearch: function () {
            let self = this;
            require(['Two_Gateway/select2-4.1.0/js/select2.min'], function () {
                $.async(self.companyIdSelector, function (companyIdField) {
                    $(companyIdField).prop('disabled', true);
                });
                $.async(self.companyNameSelector, function (companyNameField) {
                    $(companyNameField)
                        .select2({
                            minimumInputLength: 3,
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
                            ajax: {
                                dataType: 'json',
                                delay: 400,
                                url: function (params) {
                                    const queryParams = new URLSearchParams({
                                        country: self.countryCode()?.toUpperCase(),
                                        limit: self._brandConfig.companySearchLimit,
                                        offset:
                                            ((params.page || 1) - 1) * self._brandConfig.companySearchLimit,
                                        q: unescape(params.term)
                                    });
                                    return `${
                                        self._brandConfig.checkoutApiUrl
                                    }/companies/v2/company?${queryParams.toString()}`;
                                },
                                processResults: function (response, params) {
                                    const items = [];
                                    for (let i = 0; i < response.items.length; i++) {
                                        const item = response.items[i];
                                        items.push({
                                            id: item.name,
                                            text: item.name,
                                            html: `${item.highlight} (${item.national_identifier.id})`,
                                            companyId: item.national_identifier.id
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
                            }
                        })
                        .on('select2:open', function () {
                            if ($(self.enterDetailsManuallyButton).length == 0) {
                                $('.select2-results')
                                    .parent()
                                    .append(
                                        `<div id="billing_enter_details_manually" class="enter_details_manually" title="${self.enterDetailsManuallyText}">` +
                                            `<span>${self.enterDetailsManuallyText}</span>` +
                                            '</div>'
                                    );
                                $(self.enterDetailsManuallyButton).on('click', function (e) {
                                    self.clearCompany();
                                    $(self.searchForCompanyButton).show();
                                });
                            }
                            document.querySelector('.select2-search__field').focus();
                        })
                        .on('select2:select', function (e) {
                            const selectedItem = e.params.data;
                            const companyId = selectedItem.companyId;
                            const companyName = selectedItem.text;
                            self.fillCompanyData({ companyId, companyName });
                        });
                    $('#select2-company_name-container').text(self.companyName());
                    if ($(self.searchForCompanyButton).length == 0) {
                        $(self.companyNameSelector)
                            .closest('.field')
                            .append(
                                `<div id="billing_search_for_company" class="search_for_company" title="${self.searchForCompanyText}">` +
                                    `<span>${self.searchForCompanyText}</span>` +
                                    '</div>'
                            );
                        $(self.searchForCompanyButton).on('click', function (e) {
                            self.enableCompanySearch();
                            $(self.searchForCompanyButton).hide();
                        });
                    }
                    $(self.searchForCompanyButton).hide();
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
        clearCompany: function (disableCompanyId = false) {
            const companyIdSelector = $(this.companyIdSelector);
            companyIdSelector.val('');
            companyIdSelector.prop('disabled', disableCompanyId);
            $(this.companyNameSelector).val('');
            this.disableCompanySearch();
        },
        disableCompanySearch: function () {
            const companyNameSelector = $(this.companyNameSelector);
            if (companyNameSelector.data('select2')) {
                companyNameSelector.select2('destroy');
                companyNameSelector.attr('type', 'text');
            }
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
            window.open(URL, '_blank', windowFeatures);
        },

        showErrorMessage(message) {
            this.messageContainer.addErrorMessage({ message });
        },

        registeredOrganisationMode() {
            this.showSoleTrader(false);
            this.enableCompanySearch();
            this.fillCustomerData();
        },

        soleTraderMode() {
            this.showSoleTrader(true);
            this.clearCompany(true);
            this.getTokens()
                .then((json) => {
                    console.debug({ logger: 'twoPayment.soleTraderMode', json });
                    this.delegationToken = json.delegation_token;
                    this.autofillToken = json.autofill_token;
                    this.getCurrentBuyer();
                    $(this.searchForCompanyButton).hide();
                })
                .catch(() => this.showErrorMessage(this.soleTraderErrorMessage));
        },

        getCurrentBuyer() {
            const URL = `${this._brandConfig.checkoutApiUrl}/autofill/v1/buyer/current`;
            const OPTIONS = {
                credentials: 'include',
                headers: {
                    'two-delegated-authority-token': this.autofillToken
                }
            };

            fetch(URL, OPTIONS)
                .then((response) => {
                    if (response.ok) {
                        return response.json();
                    } else if (response.status == 404) {
                        return null;
                    } else {
                        throw new Error(`Error response from ${URL}.`);
                    }
                })
                .then((json) => {
                    if (json) {
                        const email = this.getEmail();
                        if (json.email == email) {
                            // Only autofill if email matches
                            this.fillCompanyData({
                                companyId: json.organization_number,
                                companyName: json.company_name
                            });
                            this.showPopupMessage(false);
                        } else {
                            this.showPopupMessage(true);
                        }
                    } else {
                        this.showPopupMessage(true);
                    }
                })
                .catch(() => this.showErrorMessage(this.soleTraderErrorMessage));
        },

        popupMessageListener() {
            window.addEventListener('message', (event) => {
                if (this.showSoleTrader() && event.origin == this._brandConfig.checkoutPageUrl) {
                    if (event.data == 'ACCEPTED') {
                        this.getCurrentBuyer();
                    } else {
                        this.showErrorMessage(this.soleTraderErrorMessage);
                    }
                }
            });
        }
    });
});
