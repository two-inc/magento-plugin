/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: LUMA'S HALF of the shared company-capture controller.
 *
 * The controller is `company-capture-component.js` — framework-free, UMD, and
 * loaded unchanged by Hyvä. This module is the Magento-shaped adapter it is
 * constructed with: RequireJS, the quote model, `$.async`, `mage/url` and the
 * message list, each reduced to the one function the controller asks for.
 *
 * Nothing here decides anything about capture. Every branch that does lives in
 * the shared file.
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'mage/translate',
    'Magento_Ui/js/model/messageList',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/company-identity',
    'Two_Gateway/js/model/company-search',
    'Two_Gateway/js/model/company-search-panel',
    'Two_Gateway/js/model/company-capture-component',
    'Two_Gateway/js/model/sole-trader'
], function (
    $,
    quote,
    url,
    $t,
    messageList,
    brandConfig,
    identity,
    companySearch,
    CompanySearchPanel,
    CompanyCaptureComponent,
    SoleTrader
) {
    'use strict';

    /** The address step's form — core's own markup. */
    const ADDRESS_FORM_ROOT = '#shipping-new-address-form';

    /** The address step's company field. */
    const ADDRESS_FIELD_SELECTOR = `${ADDRESS_FORM_ROOT} input[name="company"]`;

    /** The country select sitting alongside that company field. */
    const ADDRESS_COUNTRY_SELECTOR = `${ADDRESS_FORM_ROOT} select[name="country_id"]`;

    /** The payment tile's company field. One per Two-family brand tile. */
    const TILE_FIELD_SELECTOR = '#two_gateway_form input#company_name';

    /** Any address form's country select, shipping or billing. */
    const COUNTRY_SELECT_SELECTOR = 'select[name="country_id"]';

    const brandCode = brandConfig.getActiveTwoBrandCode();

    /**
     * The country select the mounted control answers to.
     *
     * Mounted in the address form, that is the selector beside it. Mounted on
     * the payment tile, which has no address fields of its own, it is the one in
     * the form holding the buyer's invoice address — the billing form where the
     * buyer unchecked "same as shipping", the shipping form where they did not
     * (TWO-25461 §1(a.3)).
     *
     * @param {string} mountSelector
     * @returns {?object} jQuery set, or `null` when no form can answer yet
     */
    function adjacentCountrySelect(mountSelector) {
        // For a buyer with saved addresses core renders the shipping form —
        // company field, country select and all — inside the hidden new-address
        // modal, holding store defaults nobody chose.
        const hasShippingForm = companySearch.hasPrimaryAddressForm();
        if (mountSelector === ADDRESS_FIELD_SELECTOR) {
            return hasShippingForm ? $(ADDRESS_COUNTRY_SELECTOR) : null;
        }
        // The same form the tile's own address write-back targets, so the
        // country searched and the address written can never disagree.
        const $root = companySearch.billingRoleFormRoot();
        if (!$root) return null;
        if (!hasShippingForm && $root.is && $root.is(ADDRESS_FORM_ROOT)) return null;
        return $root.find(COUNTRY_SELECT_SELECTOR);
    }

    const component = new CompanyCaptureComponent({
        config: brandCode ? brandConfig(brandCode) : null,
        Panel: CompanySearchPanel,
        SoleTraderFlow: SoleTrader,
        identity: identity,
        search: companySearch,
        translate: $t,
        observe: function (selector, onNode) {
            $.async(selector, onNode);
        },

        addressFieldSelector: ADDRESS_FIELD_SELECTOR,
        tileFieldSelector: TILE_FIELD_SELECTOR,
        fieldExists: function (selector) {
            return !!$(selector).length;
        },
        isVirtualCart: function () {
            return !!quote.isVirtual();
        },

        getAdjacentCountry: function (mountSelector) {
            const $select = adjacentCountrySelect(mountSelector);
            if (!$select || !$select.length) return null;
            const selected = $select.val();
            return typeof selected === 'string' ? selected : '';
        },
        getQuoteCountry: function () {
            const billing = quote.billingAddress();
            const fromQuote = billing && billing.countryId;
            return typeof fromQuote === 'string' ? fromQuote.toLowerCase() : '';
        },
        getFallbackCountry: function () {
            return companySearch.currentAddressFormCountry() || '';
        },
        watchCountryChanges: function (onChange) {
            // Delegated off the document rather than bound to the node: every
            // checkout re-renders its address form freely, and delegation
            // survives that with no re-resolution.
            $(document).on('change.twoCompanyCapture', COUNTRY_SELECT_SELECTOR, function (event) {
                // The select the buyer actually touched, not a document scan:
                // core saves asynchronously so the quote still holds the country
                // they just left, and a shipping-first scan answers for the
                // wrong form when it is the billing country that changed.
                onChange(String($(event.target).val() || '').toLowerCase());
            });
            // A buyer who accepts the default country never fires `change`, and
            // sole-trader availability would stay unresolved.
            $.async(COUNTRY_SELECT_SELECTOR, function () {
                onChange();
            });
        },

        supportedCompanyTypesUrl: function (country) {
            return url.build(`rest/V1/two/supported-company-types/${encodeURIComponent(country)}`);
        },
        applyCompanyAddress: function (selectedItem) {
            companySearch.lookupCompanyAddress(
                component.config(),
                selectedItem,
                companySearch.billingRoleFormRoot()
            );
        },
        revertAutofilledAddress: function () {
            companySearch.revertAutofilledAddress();
        },
        clearField: function (selector) {
            // `change`, not just `val('')`: Knockout's `value:` binding reads the
            // DOM on change only, so without it the buyer sees an empty box while
            // the quote still carries the company they searched for.
            $(selector).val('').trigger('change').trigger('focus');
        },

        tokensUrl: function () {
            return url.build('rest/V1/two/get-tokens');
        },
        quoteId: function () {
            return quote.getQuoteId();
        },
        apiClientParams: function (config) {
            return companySearch.apiClientParams(config);
        },
        signupPrefill: function () {
            const billingAddress = quote.billingAddress() || {};
            const street = (billingAddress.street || []).filter((s) => s).join(', ').split(' ');
            return {
                email: (billingAddress.email || quote.guestEmail || ''),
                first_name: billingAddress.firstname,
                last_name: billingAddress.lastname,
                company_name: identity.companyName(),
                phone_number: billingAddress.telephone,
                billing_address: {
                    building: (street[0] || '').replace(',', ''),
                    street: street.slice(1).join(' '),
                    postal_code: billingAddress.postcode,
                    city: billingAddress.city,
                    region: billingAddress.region,
                    country_code: billingAddress.countryId
                }
            };
        },
        signupCountry: function () {
            return ((quote.billingAddress() || {}).countryId || '').toUpperCase();
        },
        applyBuyerAddress: function (source) {
            companySearch.applyAddress(source, companySearch.billingRoleFormRoot());
        },
        applyTelephone: function (phoneNumber) {
            companySearch.applyTelephone(phoneNumber);
        },
        showError: function (message) {
            messageList.addErrorMessage({ message: message });
        },

        /**
         * Anchored OUTSIDE the search popover, after the field's wrapper:
         * entering sole-trader mode closes the popover, so a prompt inside it is
         * a route forward the buyer cannot see.
         */
        renderSignupPrompt: function (show, onRetry) {
            let $prompt = $('.two-sole-trader-note');
            if (!show) {
                $prompt.addClass('two-hidden');
                return;
            }
            if (!$prompt.length) {
                $prompt = $('<div></div>').addClass('two-sole-trader-note');
                $('<a href="#"></a>')
                    .addClass('two-sole-trader-note__link')
                    .text($t('Click here to log in or sign up as a Sole Trader.'))
                    .on('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        onRetry();
                    })
                    .appendTo($prompt);
                const $anchor = $('.two-company-field-wrap');
                if (!$anchor.length) return;
                $prompt.insertAfter($anchor);
            }
            $prompt.removeClass('two-hidden');
        }
    });

    return component;
});
