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
    'uiRegistry',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/company-search'
], function (
    $,
    $t,
    _,
    Component,
    customerData,
    stepNavigator,
    uiRegistry,
    brandConfig,
    companySearch
) {
    'use strict';

    // Resolve the active Two-family brand subtree so overlays
    // (acme_payment, …) get their own checkoutApiUrl /
    // isCompanySearchEnabled / companySearchLimit instead of falling
    // through to an empty object when the vanilla `two_payment` key
    // isn't present.
    const config = brandConfig.getActiveTwoBrandConfig();

    return Component.extend({
        countrySelector: '#shipping-new-address-form select[name="country_id"]',
        companyNameSelector: '#shipping-new-address-form input[name="company"]',
        companyNameLabel: 'div[name="shippingAddress.company"] label',
        companyIdSelector: '#shipping-new-address-form input[name="custom_attributes[company_id]"]',
        shippingTelephoneSelector: '#shipping-new-address-form input[name="telephone"]',
        companyNamePlaceholder: $t('Enter company name to search'),
        enterDetailsManuallyText: $t('Enter details manually'),
        enterDetailsManuallyButton: '#shipping_enter_details_manually',
        searchForCompanyText: $t('Search for company'),
        searchForCompanyButton: '#shipping_search_for_company',
        initialize: function () {
            let self = this;
            this._super();

            $.async(this.countrySelector, function (countrySelector) {
                self.toggleCompanyVisibility();
                $(countrySelector).on('change', function () {
                    self.toggleCompanyVisibility();
                });
            });
            this.enableCompanySearch();
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
            companySearch.applyAddress(address);
        },
        addressLookup: function (selectedCompany) {
            return companySearch.lookupCompanyAddress(config, selectedCompany);
        },
        enableCompanySearch: function () {
            if (!config.isCompanySearchEnabled) return;
            const self = this;
            require(['Two_Gateway/select2-4.1.0/js/select2.min'], function () {
                $.async(self.companyNameSelector, function (companyNameField) {
                    // Re-binding on every `$.async` fire is intentional:
                    // select2 4.1's constructor destroys any existing
                    // instance on the same node, so re-init re-points the
                    // widget and its handlers at the current component. An
                    // early-return guard here would both keep a stale widget
                    // alive and skip the placeholder / manual-entry
                    // housekeeping below.
                    const $companyNameField = $(companyNameField);
                    // Identity for this bind, so a previous widget's late
                    // response cannot paint chrome on its replacement.
                    const bindToken = {};
                    // select2's destroy() only clears its own `.select2`
                    // namespace, so our handlers would stack one copy per
                    // re-render and a single pick would fire N address
                    // lookups. Clear ours before re-binding.
                    $companyNameField.off(companySearch.EVENT_NS);
                    $companyNameField
                        .select2({
                            minimumInputLength: companySearch.MIN_INPUT_LENGTH,
                            // Displaces select2's own built-in English
                            // "input too short" text, which is baked into the
                            // vendored bundle and counts down the REMAINING
                            // characters. Ours is translatable and names the
                            // threshold outright.
                            language: {
                                inputTooShort: function () {
                                    return companySearch.minInputLengthMessage();
                                }
                            },
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
                            ajax: companySearch.buildSearchAjaxOptions({
                                config: config,
                                token: bindToken,
                                getCountryCode: function () {
                                    return $(self.countrySelector).val();
                                },
                                // Bound to THIS node, not to the selector, so
                                // a destroyed widget's late response cannot
                                // paint onto the live picker.
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
                            // Nothing else removes what we appended into the
                            // search box, so a reopened picker would show the
                            // previous search's "unavailable" notice.
                            companySearch.clearSearchChrome($companyNameField, bindToken);
                            if ($(self.enterDetailsManuallyButton).length == 0) {
                                $('.select2-results')
                                    .parent()
                                    .append(
                                        `<div id="shipping_enter_details_manually" class="enter_details_manually" title="${self.enterDetailsManuallyText}">` +
                                            `<span>${self.enterDetailsManuallyText}</span>` +
                                            '</div>'
                                    );
                            }
                            // Re-bound unconditionally, OUTSIDE the append
                            // guard: the div survives a re-render, so the
                            // guard was false and this handler kept closing
                            // over the first, stale component.
                            $(self.enterDetailsManuallyButton)
                                .off('click' + companySearch.EVENT_NS)
                                .on('click' + companySearch.EVENT_NS, function () {
                                    self.setCompanyData();
                                    // Scoped to the node this bind owns, not
                                    // the document-wide selector.
                                    $companyNameField.off(companySearch.EVENT_NS);
                                    $companyNameField.select2('destroy');
                                    $companyNameField.attr('type', 'text');
                                    $companyNameField.val('');
                                    $(self.searchForCompanyButton).show();
                                });
                            document.querySelector('.select2-search__field').focus();
                        })
                        .on('select2:select' + companySearch.EVENT_NS, function (e) {
                            const selectedItem = e.params.data;
                            $('.select2-selection__rendered').text(selectedItem.id);
                            self.setCompanyData(selectedItem.companyId, selectedItem.text);
                            // Gate lives in companySearch.lookupCompanyAddress
                            // (config.isAddressSearchEnabled = the single
                            // `enable_address_search` setting), shared with the
                            // payment-step picker.
                            self.addressLookup(selectedItem);
                        });
                    companySearch.markSearchBinding($companyNameField, bindToken);
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
                    }
                    // Re-bound unconditionally: the div survives a re-render,
                    // so the append guard above was false and this handler kept
                    // closing over the first, stale component.
                    $(self.searchForCompanyButton)
                        .off('click' + companySearch.EVENT_NS)
                        .on('click' + companySearch.EVENT_NS, function () {
                            self.enableCompanySearch();
                            $(self.searchForCompanyButton).hide();
                        });
                    $(self.searchForCompanyButton).hide();
                });
            });
        }
    });
});
