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

    // Our own event namespace, separate from company-search's. The picker
    // teardown paths clear `companySearch.EVENT_NS` off the company-name input
    // and select2's own destroy() clears `.select2`; the company-number
    // handlers below have to survive both.
    const EVENT_NS = '.twoAddressCompanyId';

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
            this.enableManualCompanyId();
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
        /**
         * THE single writer of the `companyData` customer-data section.
         *
         * Its own method so that every path which has to publish — a registry
         * pick, "Enter details manually", and the company-number field the
         * buyer types into — shares one call site. The payment step treats a
         * change NOTIFICATION on this section as an act of selection, and that
         * reading is only sound while the section has exactly one writer.
         */
        publishCompanyData: function (companyId, companyName) {
            customerData.set('companyData', { companyId: companyId, companyName: companyName });
        },
        setCompanyData: function (companyId = '', companyName = '') {
            console.debug({ logger: 'addressAutocomplete.setCompanyData', companyId, companyName });
            this.publishCompanyData(companyId, companyName);
            $('.select2-selection__rendered').text(companyName);
            $(this.companyNameSelector).val(companyName);
            $(this.companyIdSelector).val(companyId);
            this.syncCompanyIdEditable();
        },
        /**
         * True while select2 owns the company-name input. The widget replaces
         * the input's own value with its internal item id, so the company NAME
         * can only be read off the input once it is a plain text field again.
         */
        isCompanySearchActive: function () {
            const $field = $(this.companyNameSelector);
            return !!($field.length && $field.data('select2'));
        },
        /**
         * The company name currently in play. Prefers the published section
         * while the picker owns the input (see isCompanySearchActive), and the
         * input's own value once the buyer has switched to manual entry — the
         * one state in which the buyer's typing is the only record of it.
         */
        currentCompanyName: function () {
            const published = (customerData.get('companyData')() || {}).companyName || '';
            if (this.isCompanySearchActive()) {
                return published;
            }
            return $(this.companyNameSelector).val() || published;
        },
        /**
         * The buyer has to supply the company number by hand exactly when a
         * company is in play but no registry identifier came with it. Same
         * derivation the payment tile applies to its own company-number field.
         */
        needsManualCompanyId: function () {
            return !!this.currentCompanyName() && !$(this.companyIdSelector).val();
        },
        /**
         * Company search owns `company_id` while it can fill it: the number
         * arrives with the picked company, so an editable field would only let
         * the buyer contradict the registry. Being enabled is also the state in
         * which a `required` rule would be enforced at all — jQuery
         * Validation's `elements()` skips `:disabled`.
         *
         * Deliberately NOT called from the company-number field's own change
         * handler. The derivation reads that field's value, so re-deriving on
         * the buyer's own input would disable the field the instant they
         * finished typing into it.
         */
        syncCompanyIdEditable: function () {
            $(this.companyIdSelector).prop('disabled', !this.needsManualCompanyId());
        },
        /**
         * Make the company-number field usable: publish what the buyer types
         * into it, and re-derive its editable state when a manually-typed
         * company name appears.
         *
         * `change`, never `keyup`: the payment step fires an order intent as
         * soon as it holds both a company name and a number, so publishing per
         * keystroke would fire one credit-check request per character. The
         * telephone handler above can afford `keyup` because nothing downstream
         * of it calls out.
         */
        enableManualCompanyId: function () {
            const self = this;
            $.async(this.companyIdSelector, function (companyIdField) {
                $(companyIdField)
                    .off('change' + EVENT_NS)
                    .on('change' + EVENT_NS, function () {
                        self.publishCompanyData(
                            $(self.companyIdSelector).val() || '',
                            self.currentCompanyName()
                        );
                    });
                // A form rendered with an address already on it (returning
                // customer, or a reload mid-checkout) never passes through
                // setCompanyData(), so derive once on resolve.
                self.syncCompanyIdEditable();
            });
            $.async(this.companyNameSelector, function (companyNameField) {
                // Only meaningful after "Enter details manually" has destroyed
                // the widget: until then the input's value is select2's own
                // item id, and picks arrive through setCompanyData(). Re-derives
                // editability only — the manually typed NAME reaches the payment
                // step through the quote's billing address, and publishing it
                // from here on every keystroke would fire order intents.
                $(companyNameField)
                    .off('input' + EVENT_NS)
                    .on('input' + EVENT_NS, function () {
                        if (self.isCompanySearchActive()) return;
                        self.syncCompanyIdEditable();
                    });
            });
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
