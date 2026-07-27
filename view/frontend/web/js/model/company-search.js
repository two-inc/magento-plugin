/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Shared company-search primitives for the two select2 company pickers
 * in checkout: the shipping-step one (`view/address-autocomplete.js`,
 * a Magento_Ui form Component) and the payment-step one
 * (`view/payment/method-renderer/gateway_method.js`, a payment
 * renderer).
 *
 * Only the genuinely identical parts live here — the search request,
 * the result mapping (including `lookup_id`, whose omission on the
 * payment step was TWO-25193), the company-detail fetch and the
 * address write-back. Everything else about the two pickers differs
 * (selectors, placeholder/manual-entry chrome, KO observables vs
 * customerData, order-intent side effects) and deliberately stays in
 * the two call sites.
 */
define(['jquery'], function ($) {
    'use strict';

    return {
        /**
         * Build the select2 `ajax` option block for the company search.
         *
         * @param {object} options
         * @param {object} options.config brand config subtree; needs
         *        `checkoutApiUrl` and `companySearchLimit`
         * @param {function(): (string|undefined)} options.getCountryCode
         *        returns the current ISO country code (any case)
         * @returns {object} select2 `ajax` options
         */
        buildSearchAjaxOptions: function (options) {
            const config = options.config;
            const getCountryCode = options.getCountryCode;

            return {
                dataType: 'json',
                delay: 400,
                url: function (params) {
                    const queryParams = new URLSearchParams({
                        country: getCountryCode()?.toUpperCase(),
                        limit: config.companySearchLimit,
                        offset: ((params.page || 1) - 1) * config.companySearchLimit,
                        q: unescape(params.term)
                    });
                    return `${config.checkoutApiUrl}/companies/v2/company?${queryParams.toString()}`;
                },
                processResults: function (response) {
                    const items = [];
                    for (let i = 0; i < response.items.length; i++) {
                        const item = response.items[i];
                        items.push({
                            id: item.name,
                            text: item.name,
                            html: `${item.highlight} (${item.national_identifier.id})`,
                            companyId: item.national_identifier.id,
                            // Required by lookupCompanyAddress(); dropping it
                            // silently disables address autofill.
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
            };
        },

        /**
         * Fetch the full company record for a search result and write its
         * first address into the checkout address form.
         *
         * No-op unless `config.isAddressSearchEnabled` is true. That flag is
         * server-side the AND of the company-search and address-search admin
         * settings (Model\Config\Repository::isAddressSearchEnabled), so both
         * pickers honour exactly one gate.
         *
         * @param {object} config brand config subtree
         * @param {object} selectedCompany select2 result item (needs lookupId)
         * @returns {object|null} the jqXHR, or null when gated off / no id
         */
        lookupCompanyAddress: function (config, selectedCompany) {
            if (!config.isAddressSearchEnabled) return null;
            if (!selectedCompany || !selectedCompany.lookupId) return null;

            const self = this;
            const addressResponse = $.ajax({
                dataType: 'json',
                url: `${config.checkoutApiUrl}/companies/v2/company/${selectedCompany.lookupId}`
            });
            addressResponse.done(function (response) {
                if (response && response.addresses && response.addresses.length) {
                    self.applyAddress(response.addresses[0]);
                }
            });
            return addressResponse;
        },

        /**
         * Write a company address into whichever checkout address form is on
         * screen. The selectors are intentionally unscoped: the shipping step
         * renders `#shipping-new-address-form` and the payment step renders
         * `#billing-new-address-form`, and only one of them is present when a
         * company is picked from that step's search box.
         *
         * @param {object} address company address record from the API
         */
        applyAddress: function (address) {
            console.debug({ logger: 'companySearch.applyAddress', address });
            $('input[name="city"]').val(address.city);
            $('input[name="postcode"]').val(address.postal_code);
            $('input[name="street[0]"]').val(address.street_address);
            $('input[name="city"], input[name="postcode"], input[name="street[0]"]').trigger(
                'change'
            );
        }
    };
});
