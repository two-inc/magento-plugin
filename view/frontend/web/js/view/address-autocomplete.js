/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
define([
    'jquery',
    'mage/translate',
    'underscore',
    'Magento_Ui/js/form/form',
    'Magento_Customer/js/customer-data',
    'Magento_Checkout/js/model/step-navigator',
    'uiRegistry'
], function ($, $t, _, Component, customerData, stepNavigator, uiRegistry) {
    'use strict';

    var config = window.checkoutConfig.payment.two_payment;

    return Component.extend({
        isCompanySearchEnabled: config.isCompanySearchEnabled,
        isAddressSearchEnabled: config.isAddressSearchEnabled,
        supportedCountryCodes: config.supportedCountryCodes,
        isInternationalTelephoneEnabled: config.isInternationalTelephoneEnabled,
        countrySelector: '#shipping-new-address-form select[name="country_id"]',
        companyNameSelector: '#shipping-new-address-form input[name="company"]',
        companyNameLabel: 'div[name="shippingAddress.company"] label',
        companyIdSelector: '#shipping-new-address-form input[name="custom_attributes[company_id]"]',
        companyNamePlaceholder: config.companyNamePlaceholder,
        shippingTelephoneSelector: '#shipping-new-address-form input[name="telephone"]',
        enterDetailsManuallyText: $t('Enter details manually'),
        enterDetailsManuallyButton: '#shipping_enter_details_manually',
        searchForCompanyText: $t('Search for company'),
        searchForCompanyButton: '#shipping_search_for_company',
        initialize: function () {
            let self = this;
            this._super();

            // Check if we're in the FireCheckout theme
            // Leaving here in case we wanto to do some conditional logic against this
            const isFireCheckout = $('body').hasClass('firecheckout');

            $.async(this.countrySelector, function (countrySelector) {
                self.toggleCompanyVisibility();
                $(countrySelector).on('change', function () {
                    self.toggleCompanyVisibility();
                });
            });
            if (this.isCompanySearchEnabled) {
                this.enableCompanySearch();
            }
            const setTwoTelephone = (e) => customerData.set('shippingTelephone', e.target.value);
            $.async(self.shippingTelephoneSelector, function (telephoneSelector) {
                $(telephoneSelector).on('change keyup', setTwoTelephone);
                const telephone = $(self.shippingTelephoneSelector).val();
                customerData.set('shippingTelephone', telephone);
            });
        },
        toggleCompanyVisibility: function () {
            const countryCode = $(this.countrySelector).val().toLowerCase();
            customerData.set('countryCode', countryCode);
            let field = $(this.companyNameSelector).closest('.field');
            field.show();
            field.attr('style', function (i, style) {
                return (style || '') + 'width: 100% !important;';
            });
        },
        setCompanyData: function (companyId = '', companyName = '') {
            console.debug({ logger: 'addressAutocomplete.setCompanyData', companyId, companyName });
            customerData.set('companyData', { companyId, companyName });
            $('.select2-selection__rendered').text(companyName);
            $(this.companyNameSelector).val(companyName);
            $(this.companyIdSelector).val(companyId);
        },
        setAddressData: function (address) {
            console.debug({ logger: 'addressAutocomplete.setAddressData', address });
            $('input[name="city"]').val(address.city);
            $('input[name="postcode"]').val(address.postal_code);
            $('input[name="street[0]"]').val(address.street_address);
            $('input[name="city"], input[name="postcode"], input[name="street[0]"]').trigger(
                'change'
            );
        },
        addressLookup: function (selectedCompany, countryCode) {
            const self = this;
            if (this.supportedCountryCodes.includes(countryCode.toLowerCase())) {
                // Use legacy address search for supported country codes
                const addressResponse = $.ajax({
                    dataType: 'json',
                    url: `${config.checkoutApiUrl}/v1/${countryCode}/company/${selectedCompany.companyId}/address`
                });
                addressResponse.done(function (response) {
                    if (response.address) {
                        self.setAddressData({
                            city: response.address.city,
                            postal_code: response.address.postalCode,
                            street_address: response.address.streetAddress
                        });
                    }
                });
            } else {
                // Use new address lookup for unsupported country codes
                const addressResponse = $.ajax({
                    dataType: 'json',
                    url: `${config.companySearchConfig.searchHost}/companies/v1/company/${selectedCompany.lookupId}`
                });
                addressResponse.done(function (response) {
                    // Use new address lookup by default
                    if (response.address) {
                        self.setAddressData(response.address);
                    }
                });
            }
        },
        enableCompanySearch: function () {
            const self = this;
            require(['Two_Gateway/select2-4.1.0/js/select2.min'], function () {
                $.async(self.companyNameSelector, function (companyNameField) {
                    var searchLimit = config.companySearchConfig.searchLimit;
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
                                return data.text || self.companyNamePlaceholder;
                            },
                            ajax: {
                                dataType: 'json',
                                delay: 400,
                                url: function (params) {
                                    const queryParams = new URLSearchParams({
                                        country: $(self.countrySelector).val()?.toUpperCase(),
                                        limit: searchLimit,
                                        offset: ((params.page || 1) - 1) * searchLimit,
                                        q: unescape(params.term)
                                    });
                                    return `${
                                        config.companySearchConfig.searchHost
                                    }/companies/v1/company?${queryParams.toString()}`;
                                },
                                processResults: function (response, params) {
                                    var items = [];
                                    for (var i = 0; i < response.items.length; i++) {
                                        var item = response.items[i];
                                        items.push({
                                            id: item.name,
                                            text: item.name,
                                            html: `${item.highlight} (${item.national_identifier.id})`,
                                            companyId: item.national_identifier.id,
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
                            }
                        })
                        .on('select2:open', function () {
                            if ($(self.enterDetailsManuallyButton).length == 0) {
                                $('.select2-results')
                                    .parent()
                                    .append(
                                        `<div id="shipping_enter_details_manually" class="enter_details_manually" title="${self.enterDetailsManuallyText}">` +
                                            `<span>${self.enterDetailsManuallyText}</span>` +
                                            '</div>'
                                    );
                                $(self.enterDetailsManuallyButton).on('click', function (e) {
                                    self.setCompanyData();
                                    $(self.companyNameSelector).select2('destroy');
                                    $(self.companyNameSelector).attr('type', 'text');
                                    $(self.companyNameSelector).val('');
                                    $(self.searchForCompanyButton).show();
                                });
                            }
                            document.querySelector('.select2-search__field').focus();
                        })
                        .on('select2:select', function (e) {
                            var selectedItem = e.params.data;
                            $('.select2-selection__rendered').text(selectedItem.id);
                            self.setCompanyData(selectedItem.companyId, selectedItem.text);
                            if (self.isAddressSearchEnabled) {
                                const countryCode = $(self.countrySelector).val().toLowerCase();
                                self.addressLookup(selectedItem, countryCode);
                            }
                        });
                    // Set initial placeholder text for the company search
                    if (!$(self.companyNameSelector).val()) {
                        $(self.companyNameSelector)
                            .closest('.field')
                            .find('.select2-selection__rendered')
                            .text(self.companyNamePlaceholder);
                    }
                    if ($(self.companyNameSelector).val()) {
                        // pre-fill on checkout render
                        $('.select2-selection__rendered').text($(self.companyNameSelector).val());
                    }
                    if ($(self.searchForCompanyButton).length == 0) {
                        $(self.companyNameSelector)
                            .closest('.field')
                            .append(
                                `<div id="shipping_search_for_company" class="search_for_company" title="${self.searchForCompanyText}">` +
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
        }
    });
});
