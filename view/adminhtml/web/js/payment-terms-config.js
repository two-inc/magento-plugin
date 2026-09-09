define(['jquery', 'mage/translate', 'domReady!'], function ($, $t) {
    'use strict';

    function initPaymentTermsConfig() {
        // Discover the section-id prefix from the page. The phtml
        // template ships the checkboxes container with id
        // `{section}_payment_terms_payment_terms_checkboxes` — strip
        // the suffix to get the section id, then build every other
        // selector against it. This keeps the JS brand-agnostic:
        // `two_payment` on vanilla, `acme_payment` on a brand overlay, ...
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
        var $termsInherit   = $('#' + prefix + 'payment_terms_inherit');

        // ── Helpers ──────────────────────────────────────────────────────

        // Server-normalised term for the current selection; parsing the raw value here would
        // disagree with the save on shapes like '1e2' (ABN-522).
        function getCustomTerm() {
            return Number($customDays.find('option:selected').attr('data-two-term')) || 0;
        }

        function getSelectedTerms() {
            var terms = [];
            $termsContainer.find('.two-term-checkboxes__input:checked').each(function () {
                terms.push(Number($(this).val()));
            });
            terms = terms.filter(function (n) { return n > 0; });
            var custom = getCustomTerm();
            if (custom > 0) {
                terms.push(custom);
            }
            // Deduplicate and sort
            terms = terms.filter(function (v, i, a) { return a.indexOf(v) === i; });
            terms.sort(function (a, b) { return a - b; });
            return terms;
        }

        function getSurchargeType() {
            // Effective (resolved) type, scope-aware. When the type field's
            // "Use Website/Default" is ticked the <select> is disabled but
            // still carries the inherited value, so read it directly. An
            // inherited Percentage type must still surface the surcharge
            // fields; returning 'none' on inherit (the old behaviour) hid
            // them at store scope (the store-scope orphaned-override bug).
            return $surchargeType.val() || 'none';
        }

        function isDifferential() {
            return $differential.val() === '1';
        }

        function getDefaultTermValue() {
            return parseInt($defaultTerm.val(), 10) || 0;
        }

        // ── Default payment term dropdown ────────────────────────────────

        function updateDefaultTermOptions() {
            var terms = getSelectedTerms();
            var currentDefault = getDefaultTermValue();

            $defaultTerm.empty();
            // First, so a selection that is no longer offered lands here rather
            // than on a day count nobody chose (ABN-548).
            $defaultTerm.append($('<option></option>').attr('value', '').text($t('Automatic')));
            $.each(terms, function (_, days) {
                $defaultTerm.append(
                    $('<option></option>').attr('value', days).text($t('%1 days').replace('%1', days))
                );
            });

            if (terms.indexOf(currentDefault) !== -1) {
                $defaultTerm.val(currentDefault);
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

            // Global surcharge fields. The deprecated
            // custom_surcharge_tax_rate row is NOT managed here — its
            // visibility is owned by the system.xml <depends> on the
            // surcharge tax treatment ("custom" only), and a jQuery
            // show() would fight Magento's dependence controller.
            var surchargeFields = [
                'surcharge_differential',
                'surcharge_line_description',
                'surcharge_tax_class'
            ];
            $.each(surchargeFields, function (_, id) {
                hasSurcharge ? showField(id) : hideField(id);
            });
        }

        // ── Custom payment terms visibility ──────────────────────────────

        // The marker carries what the server settles before the post; the sibling's inherit box is
        // the rest of it, and an inheriting sibling makes the save keep the value (ABN-522).
        function customDaysFoldsIn() {
            return $customDays.closest('tr').find('.two-legacy-term-folds-in').length > 0
                && !$termsInherit.is(':checked');
        }

        function updateCustomDaysVisibility() {
            // Hidden, not removed: the row must still post for the fold-in save to happen.
            if (customDaysFoldsIn()) {
                hideField('payment_terms_duration_days');
            } else {
                showField('payment_terms_duration_days');
            }
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
        $customDays.on('change', onTermsChanged);
        $surchargeType.on('change', onSurchargeChanged);
        $differential.on('change', onSurchargeChanged);
        $defaultTerm.on('change', onDefaultTermChanged);
        $('#' + prefix + 'surcharge_type_inherit').on('change', onSurchargeChanged);
        $termsInherit.on('change', updateCustomDaysVisibility);

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
        // when the response arrives.
        //
        // An empty span means that term carries no fee, so a failed fetch says
        // so in the notice rather than leaving the spans empty (ABN-512).
        var lastFeesKey = null;

        function setFeeNotice(text) {
            var $notice = $termsContainer.find('.two-term-checkboxes__fee-notice');
            if (!$notice.length) {
                if (!text) {
                    return;
                }
                $notice = $('<div class="two-term-checkboxes__fee-notice admin__field-note"></div>')
                    .appendTo($termsContainer);
            }
            $notice.text(text || '');
        }

        function showFeesUnavailable(reason) {
            $termsContainer.find('.two-term-checkboxes__fee').text('');
            setFeeNotice(
                reason === 'not_configured'
                    ? $t('Fees cannot be shown until an API key is saved for this scope.')
                    : $t('Fees could not be loaded because the pricing service could not be reached. The figures beside each term are missing, not zero.')
            );
        }

        // Only the newest request may paint: a slow answer landing after a
        // later one would otherwise re-state figures that are already replaced.
        var feesRequestId = 0;

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
            var custom = getCustomTerm();
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
            feesRequestId += 1;
            var requestId = feesRequestId;
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
                if (requestId !== feesRequestId) {
                    return;
                }
                var terminal = response && response.error === 'not_configured';
                // Anything but a fresh, renderable set may be asked again for
                // the same terms — the server's own cooldown, not this key, is
                // what stops an outage becoming a call per render. An unsaved
                // key is the exception: nothing changes until it is saved.
                if (!terminal && (!response || !response.success || !response.fees || response.stale)) {
                    lastFeesKey = null;
                }
                if (!response || !response.success || !response.fees) {
                    showFeesUnavailable(response && response.error);
                    return;
                }
                if (response.stale) {
                    var retrieved = String(response.fetched_at_display || '');
                    setFeeNotice(
                        retrieved === ''
                            ? $t('Fees could not be refreshed, so the figures last retrieved are shown.')
                            : $t('Fees could not be refreshed, so the figures retrieved on %1 are shown.')
                                .replace('%1', retrieved)
                    );
                } else {
                    setFeeNotice('');
                }
                // Currency comes from the API response, never guessed: the fee
                // values are its too. A set without one is refused server-side
                // rather than drawn.
                var currency = String(response.currency || '').toUpperCase().trim();
                var suffix = currency !== '' ? ' ' + currency : '';
                // Admin locale's decimal separator, sourced server-side
                // from ICU (matches what the surcharge-grid display path
                // uses). Falls back to '.' on legacy renders that didn't
                // emit the attribute.
                var decimalSep = String($termsContainer.data('decimal-separator') || '.');
                function formatAmount(n) {
                    var s = Number(n).toFixed(2);
                    return decimalSep === '.' ? s : s.replace('.', decimalSep);
                }
                $termsContainer.find('.two-term-checkboxes__fee').each(function () {
                    var $span = $(this);
                    var term = String($span.data('term'));
                    var fee = response.fees[term];
                    if (!fee) {
                        // An empty span reads as "no fee for this term", so a
                        // term the answer did not price says so instead.
                        $span.text(' (' + $t('no figure') + ')');
                        return;
                    }
                    var pctStr = formatAmount(fee.percentage || 0);
                    var fixedStr = formatAmount(fee.fixed || 0);
                    var zero = formatAmount(0);
                    var pctZero = pctStr === zero;
                    var fixedZero = fixedStr === zero;
                    var inner;
                    if (pctZero && fixedZero) {
                        inner = zero + suffix;
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
                if (requestId !== feesRequestId) {
                    return;
                }
                lastFeesKey = null;
                showFeesUnavailable();
            });
        }

        // Additional handlers for fee refresh — fire alongside the term-set
        // change handlers without disturbing their existing wiring.
        $termsContainer.on('change', '.two-term-checkboxes__input', loadFees);
        $customDays.on('change', loadFees);

        // ── Initialize ───────────────────────────────────────────────────

        updateDefaultTermOptions();
        updateDifferentialOptionLabel();
        updateSurchargeVisibility();
        updateCustomDaysVisibility();
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
