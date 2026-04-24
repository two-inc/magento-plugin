/**
 * Shared surcharge state for checkout.
 *
 * Manages the selected term observable (shared between chips and summary)
 * and handles the AJAX call to recalculate totals when the term changes.
 * Also watches for external totals changes (coupon, shipping) and
 * refreshes chip labels to stay in sync.
 */
define([
    'ko',
    'jquery',
    'Magento_Checkout/js/model/quote',
    'mage/url'
], function (ko, $, quote, url) {
    'use strict';

    var config = (window.checkoutConfig.payment || {}).two_payment || {};

    var selectedTerm = ko.observable(config.selectedPaymentTerm || config.defaultPaymentTerm || 0);
    var termSurcharges = ko.observable(config.termSurcharges || {});
    var isUpdating = ko.observable(false);
    var lastKnownGrandTotal = null;

    /**
     * Sync the selected term's chip value from the authoritative two_surcharge
     * segment in quote totals. Prevents chip/summary mismatch when the
     * server-side ConfigProvider computed chip values from stale session data.
     */
    function syncSelectedChipFromSegment(totals) {
        if (!totals || !Array.isArray(totals.total_segments)) {
            return;
        }
        var segment = totals.total_segments.find(function (s) {
            return s && s.code === 'two_surcharge';
        });
        if (!segment) {
            return;
        }
        var value = parseFloat(segment.value);
        if (isNaN(value)) {
            return;
        }
        var current = Object.assign({}, termSurcharges());
        current[selectedTerm()] = value;
        termSurcharges(current);
    }

    // Watch for external totals changes (coupon apply/remove, shipping change).
    // When the grand total changes from a source other than our own AJAX call,
    // re-fire select-term to refresh chip labels and surcharge amounts.
    quote.getTotals().subscribe(function (totals) {
        if (!totals || isUpdating()) {
            return;
        }
        // Always keep the selected chip in sync with the authoritative segment
        syncSelectedChipFromSegment(totals);

        var newGrandTotal = parseFloat(totals.grand_total);
        if (lastKnownGrandTotal !== null && lastKnownGrandTotal !== newGrandTotal) {
            // Grand total changed externally — refresh surcharges
            var model = surchargeModel;
            model.recalculateTotals(selectedTerm());
        }
        lastKnownGrandTotal = newGrandTotal;
    });

    var surchargeModel = {
        selectedTerm: selectedTerm,
        isUpdating: isUpdating,
        termSurcharges: termSurcharges,
        currencySymbol: config.currencySymbol || '',

        /**
         * Get the surcharge for the current term (for chip labels).
         */
        getAmount: function () {
            var surcharges = termSurcharges();
            return parseFloat(surcharges[selectedTerm()] || 0);
        },

        /**
         * Set the selected term and recalculate totals server-side.
         */
        selectTerm: function (days) {
            if (days === selectedTerm()) {
                return;
            }
            selectedTerm(days);
            this.recalculateTotals(days);
        },

        /**
         * Call the REST endpoint to update totals with the new surcharge.
         */
        recalculateTotals: function (days) {
            var restUrl = url.build('rest/V1/two/select-term');

            isUpdating(true);

            $.ajax({
                url: restUrl,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    cartId: quote.getQuoteId(),
                    termDays: days
                })
            }).done(function (response) {
                // Webapi wraps response in outer array — unwrap
                var data = Array.isArray(response) ? response[0] : response;
                if (data && data.total_segments) {
                    var currentTotals = quote.getTotals()();
                    if (currentTotals) {
                        currentTotals.grand_total = data.grand_total;
                        currentTotals.base_grand_total = data.base_grand_total;
                        currentTotals.tax_amount = data.tax_amount;
                        currentTotals.total_segments = data.total_segments;
                        quote.setTotals(currentTotals);
                        lastKnownGrandTotal = parseFloat(data.grand_total);
                    }
                }

                // Update chip labels with recalculated surcharges
                if (data && data.term_surcharges) {
                    var updated = {};
                    data.term_surcharges.forEach(function (item) {
                        updated[item.days] = item.net;
                    });
                    termSurcharges(updated);
                }
            }).always(function () {
                isUpdating(false);
            });
        }
    };

    return surchargeModel;
});
