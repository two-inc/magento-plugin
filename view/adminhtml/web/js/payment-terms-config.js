define(['jquery', 'mage/translate', 'domReady!'], function ($, $t) {
    'use strict';

    function initPaymentTermsConfig() {
        // Discover the section-id prefix from the page. The phtml
        // template ships the checkboxes container with id
        // `{section}_payment_terms_payment_terms_checkboxes` — strip
        // the suffix to get the section id, then build every other
        // selector against it. This keeps the JS brand-agnostic:
        // `two_payment` on vanilla, `abn_payment` on ABN, ...
        var $termsContainer = $('.two-term-checkboxes').first();
        if (!$termsContainer.length) {
            return;
        }
        var containerSuffix = '_payment_terms_payment_terms_checkboxes';
        var containerId = $termsContainer.attr('id') || '';
        if (containerId.slice(-containerSuffix.length) !== containerSuffix) {
            return;
        }
        var section = containerId.slice(0, -containerSuffix.length);
        var prefix = section + '_payment_terms_';

        var $customDays     = $('#' + prefix + 'payment_terms_duration_days');
        var $defaultTerm    = $('#' + prefix + 'default_payment_term');
        var $surchargeType  = $('#' + prefix + 'surcharge_type');
        var $differential   = $('#' + prefix + 'surcharge_differential');

        // ── Helpers ──────────────────────────────────────────────────────

        function getSelectedTerms() {
            var terms = [];
            $termsContainer.find('.two-term-checkboxes__input:checked').each(function () {
                terms.push(Number($(this).val()));
            });
            terms = terms.filter(function (n) { return n > 0; });
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
            var $inherit = $('#' + prefix + 'surcharge_type_inherit');
            if ($inherit.length && $inherit.is(':checked')) {
                return 'none';
            }
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
                    $('<option></option>').attr('value', days).text($t('%1 days').replace('%1', days))
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
            return $('#row_' + prefix + fieldId);
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
                'surcharge_tax_rate'
            ];
            $.each(surchargeFields, function (_, id) {
                hasSurcharge ? showField(id) : hideField(id);
            });
        }

        // ── Differential option label ────────────────────────────────────

        function updateDifferentialOptionLabel() {
            var defaultDays = parseInt($defaultTerm.val(), 10) || 0;
            var $option = $differential.find('option[value="1"]');
            if ($option.length && defaultDays > 0) {
                $option.text(
                    $t('Fee difference vs default payment term') +
                    ' (' + $t('%1 days').replace('%1', defaultDays) + ')'
                );
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
            updateDifferentialOptionLabel();
            updateSurchargeVisibility();
        }

        $termsContainer.on('change', '.two-term-checkboxes__input', onTermsChanged);
        $customDays.on('change keyup', onTermsChanged);
        $surchargeType.on('change', onSurchargeChanged);
        $differential.on('change', onSurchargeChanged);
        $defaultTerm.on('change', onDefaultTermChanged);
        $('#' + prefix + 'surcharge_type_inherit').on('change', onSurchargeChanged);

        // ── "Use System Value" reset ────────────────────────────────────

        function initInheritResetBehavior() {
            $('input[id^="' + prefix + '"][id$="_inherit"]').each(function () {
                var $inherit = $(this);
                var fieldId = $inherit.attr('id').replace(/_inherit$/, '');
                var $field = $('#' + fieldId);
                if (!$field.length) {
                    return;
                }

                var systemValue = null;

                // If inherit is checked on load, current value IS the system value
                if ($inherit.is(':checked')) {
                    systemValue = $field.val();
                }

                $inherit.on('change', function () {
                    if ($inherit.is(':checked')) {
                        // Re-checking: restore system value if we have it
                        if (systemValue !== null) {
                            $field.val(systemValue);
                            $field.trigger('change');
                        }
                    } else {
                        // Unchecking: field still shows system value — snapshot it
                        systemValue = $field.val();
                    }
                });
            });
        }

        // ── "Use System Value" for term checkboxes ────────────────────────

        function initTermCheckboxInherit() {
            var $inherit = $('#' + prefix + 'payment_terms_inherit');
            if (!$inherit.length) {
                return;
            }

            var $checkboxes = $termsContainer.find('.two-term-checkboxes__input');
            var systemSnapshot = null;

            function snapshotState() {
                var state = {};
                $checkboxes.each(function () {
                    state[$(this).val()] = $(this).is(':checked');
                });
                return state;
            }

            function restoreState(state) {
                $checkboxes.each(function () {
                    var val = $(this).val();
                    $(this).prop('checked', !!state[val]);
                });
                onTermsChanged();
            }

            // If inherit is checked on load, current state IS the system value
            if ($inherit.is(':checked')) {
                systemSnapshot = snapshotState();
            }

            function toggle() {
                var disabled = $inherit.is(':checked');
                if (disabled) {
                    // Re-checking: restore system value if we have it
                    if (systemSnapshot !== null) {
                        restoreState(systemSnapshot);
                    }
                } else {
                    // Unchecking: snapshot current state before user edits
                    systemSnapshot = snapshotState();
                }
                $checkboxes.prop('disabled', disabled);
            }

            $inherit.on('change', toggle);
            // Apply disabled state on load (don't fire the snapshot/restore logic)
            $checkboxes.prop('disabled', $inherit.is(':checked'));
        }

        // ── Inline merchant fees beside each checkbox ────────────────────

        // Fetched async from admin proxy `two/config/fees` (same endpoint
        // the old surcharge-grid Fee column used). Each `.two-term-
        // checkboxes__fee` span is populated with text like " (1.50% + 0.50)"
        // when the response arrives. On failure the span stays empty.
        var lastFeesKey = null;

        function loadFees() {
            var url = $termsContainer.data('fees-url');
            if (!url) {
                return; // brand has inline_term_fees disabled (no data-fees-url emitted)
            }
            // Fees render beside EVERY checkbox regardless of selection state,
            // so the API call asks for all available term values, plus the
            // custom-days entry if the merchant has one set. Differs from
            // getSelectedTerms (which feeds the default-term dropdown and
            // surcharge-visibility logic and intentionally tracks the
            // checked state).
            var terms = $termsContainer.find('.two-term-checkboxes__input').map(function () {
                return Number(this.value);
            }).get().filter(function (n) { return n > 0; });
            var custom = parseInt($customDays.val(), 10);
            if (custom > 0 && terms.indexOf(custom) === -1) {
                terms.push(custom);
            }
            terms.sort(function (a, b) { return a - b; });
            if (!terms.length) {
                return;
            }
            var key = terms.join(',');
            if (key === lastFeesKey) {
                return;
            }
            lastFeesKey = key;
            var $formKey = $('input[name="form_key"]').first();
            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: $formKey.val() || (window.FORM_KEY || ''),
                    terms: JSON.stringify(terms),
                    scope: String($termsContainer.data('scope') || 'default'),
                    scopeId: parseInt($termsContainer.data('scope-id'), 10) || 0
                }
            }).done(function (response) {
                if (!response || !response.success || !response.fees) {
                    return; // leave spans empty
                }
                // Currency MUST come from the API response — the fee
                // values do too, and we don't get to guess what currency
                // they're in. If the API omits it, we cannot safely
                // render any fixed amount.
                var currency = String(response.currency || '').toUpperCase().trim();
                var suffix = currency !== '' ? ' ' + currency : '';
                $termsContainer.find('.two-term-checkboxes__fee').each(function () {
                    var $span = $(this);
                    var term = String($span.data('term'));
                    var fee = response.fees[term];
                    if (!fee) {
                        $span.text('');
                        return;
                    }
                    var pctStr = Number(fee.percentage || 0).toFixed(2);
                    var fixedStr = Number(fee.fixed || 0).toFixed(2);
                    var pctZero = pctStr === '0.00';
                    var fixedZero = fixedStr === '0.00';
                    // Without an API-supplied currency, any fixed
                    // component would be ambiguous. Drop the fixed
                    // portion entirely in that case; percentage can
                    // stand alone since it carries its own unit (%).
                    if (currency === '') {
                        if (pctZero) {
                            $span.text('');
                            return;
                        }
                        $span.text(' (' + pctStr + '%)');
                        return;
                    }
                    var inner;
                    if (pctZero && fixedZero) {
                        inner = '0.00' + suffix;
                    } else if (pctZero) {
                        inner = fixedStr + suffix;
                    } else if (fixedZero) {
                        inner = pctStr + '%';
                    } else {
                        inner = pctStr + '% + ' + fixedStr + suffix;
                    }
                    $span.text(' (' + inner + ')');
                });
            }).fail(function () {
                // Allow a retry on the same term-set after a transient error,
                // and clear any half-populated spans.
                lastFeesKey = null;
                $termsContainer.find('.two-term-checkboxes__fee').text('');
            });
        }

        // Additional handlers for fee refresh — fire alongside the term-set
        // change handlers without disturbing their existing wiring.
        $termsContainer.on('change', '.two-term-checkboxes__input', loadFees);
        $customDays.on('change keyup', loadFees);

        // ── Initialize ───────────────────────────────────────────────────

        updateDefaultTermOptions();
        updateDifferentialOptionLabel();
        updateSurchargeVisibility();
        initInheritResetBehavior();
        initTermCheckboxInherit();
        loadFees();
    }

    $(document).ready(function () {
        initPaymentTermsConfig();
    });

    return {
        init: initPaymentTermsConfig
    };
});
