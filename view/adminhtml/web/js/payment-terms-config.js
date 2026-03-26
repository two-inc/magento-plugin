define(['jquery', 'domReady!'], function ($) {
    'use strict';

    var TERM_DAYS = [14, 30, 60, 90];

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
            var hasPercentage = type === 'percentage' || type === 'fixed_and_percentage';
            var hasFixed = type === 'fixed' || type === 'fixed_and_percentage';
            var selectedTerms = getSelectedTerms();
            var differential = isDifferential();
            var defaultDays = getDefaultTermValue();

            // Global surcharge fields
            var surchargeFields = [
                'surcharge_differential',
                'surcharge_line_description',
                'surcharge_show_tax_rate'
            ];
            $.each(surchargeFields, function (_, id) {
                hasSurcharge ? showField(id) : hideField(id);
            });

            // Per-term fields
            $.each(TERM_DAYS, function (_, days) {
                var termSelected = selectedTerms.indexOf(days) !== -1;
                var isDefaultTerm = days === defaultDays;
                var disableForDifferential = differential && isDefaultTerm;

                var pctRow = getFieldRow('surcharge_' + days + '_percentage');
                var limitRow = getFieldRow('surcharge_' + days + '_limit');
                var fixedRow = getFieldRow('surcharge_' + days + '_fixed');

                // Show/hide based on term selection and surcharge type
                var showPct = hasSurcharge && hasPercentage && termSelected;
                var showLimit = hasSurcharge && hasPercentage && termSelected;
                var showFixed = hasSurcharge && hasFixed && termSelected;

                showPct ? pctRow.show() : pctRow.hide();
                showLimit ? limitRow.show() : limitRow.hide();
                showFixed ? fixedRow.show() : fixedRow.hide();

                // Disable fields for default term in differential mode
                var pctInput = pctRow.find('input');
                var limitInput = limitRow.find('input');
                var fixedInput = fixedRow.find('input');

                pctInput.prop('disabled', disableForDifferential && showPct);
                limitInput.prop('disabled', disableForDifferential && showLimit);
                fixedInput.prop('disabled', disableForDifferential && showFixed);
            });

            // Also handle custom term surcharge fields (if custom day matches a standard term,
            // it's already covered; otherwise there's no XML field for arbitrary custom values)
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
