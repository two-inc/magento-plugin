define(['jquery', 'domReady!'], function ($) {
    'use strict';

    function initPaymentTermsConfig() {
        var $terms = $('#two_payment_payment_terms_payment_terms');
        var $customDays = $('#two_payment_payment_terms_payment_terms_duration_days');
        var $defaultTerm = $('#two_payment_payment_terms_default_payment_term');
        var $surchargeType = $('#two_payment_payment_terms_surcharge_type');
        var $differential = $('#two_payment_payment_terms_surcharge_differential');
        var $differentialLabel = $('label[for="two_payment_payment_terms_surcharge_differential"]');

        if (!$terms.length) {
            return;
        }

        // ── Helpers ──────────────────────────────────────────────────────

        function getSelectedTerms() {
            var selected = $terms.val() || [];
            if (!Array.isArray(selected)) {
                selected = [selected];
            }
            var terms = selected.map(Number).filter(function (n) { return n > 0; });
            var custom = parseInt($customDays.val(), 10);
            if (custom > 0) {
                terms.push(custom);
            }
            // Deduplicate and sort
            terms = terms.filter(function (v, i, a) { return a.indexOf(v) === i; });
            terms.sort(function (a, b) { return a - b; });
            return terms;
        }

        function getSurchargeType() {
            return $surchargeType.val() || 'none';
        }

        function isDifferential() {
            return $differential.val() === '1';
        }

        function getDefaultTermValue() {
            return parseInt($defaultTerm.val(), 10) || 0;
        }

        // ── Default Payment Term dropdown ────────────────────────────────

        function updateDefaultTermOptions() {
            var terms = getSelectedTerms();
            var currentDefault = getDefaultTermValue();

            $defaultTerm.empty();
            $.each(terms, function (_, days) {
                $defaultTerm.append(
                    $('<option></option>').attr('value', days).text(days + ' days')
                );
            });

            // Keep current selection if still valid, otherwise pick lowest
            if (terms.indexOf(currentDefault) !== -1) {
                $defaultTerm.val(currentDefault);
            } else if (terms.length) {
                $defaultTerm.val(terms[0]);
            }

            $defaultTerm.trigger('change');
        }

        // ── Surcharge field visibility ───────────────────────────────────

        function getFieldRow(fieldId) {
            return $('#row_two_payment_payment_terms_' + fieldId);
        }

        function showField(fieldId) {
            getFieldRow(fieldId).show();
        }

        function hideField(fieldId) {
            getFieldRow(fieldId).hide();
        }

        function updateSurchargeVisibility() {
            var type = getSurchargeType();
            var hasSurcharge = type !== 'none';

            // Global surcharge fields
            var surchargeFields = [
                'surcharge_differential',
                'surcharge_line_description',
                'surcharge_tax_rate'
            ];
            $.each(surchargeFields, function (_, id) {
                hasSurcharge ? showField(id) : hideField(id);
            });
        }

        // ── Differential label ───────────────────────────────────────────

        function updateDifferentialLabel() {
            var defaultDays = getDefaultTermValue();
            var baseLabel = 'Only surcharge term extensions';
            if (defaultDays > 0) {
                $differentialLabel.text(baseLabel + ' beyond ' + defaultDays + ' days');
            } else {
                $differentialLabel.text(baseLabel);
            }
        }

        // ── Event bindings ───────────────────────────────────────────────

        function onTermsChanged() {
            updateDefaultTermOptions();
            updateSurchargeVisibility();
        }

        function onSurchargeChanged() {
            updateSurchargeVisibility();
        }

        function onDefaultTermChanged() {
            updateDifferentialLabel();
            updateSurchargeVisibility();
        }

        $terms.on('change', onTermsChanged);
        $customDays.on('change keyup', onTermsChanged);
        $surchargeType.on('change', onSurchargeChanged);
        $differential.on('change', onSurchargeChanged);
        $defaultTerm.on('change', onDefaultTermChanged);

        // ── Initialize ───────────────────────────────────────────────────

        updateDefaultTermOptions();
        updateDifferentialLabel();
        updateSurchargeVisibility();
    }

    $(document).ready(function () {
        initPaymentTermsConfig();
    });

    return {
        init: initPaymentTermsConfig
    };
});
