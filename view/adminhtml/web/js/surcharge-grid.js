define(['jquery', 'mage/translate', 'domReady!'], function ($, $t) {
    'use strict';

    return function (config, element) {
        var $container = $(element);

        // Derive the section-id prefix from the DOM (same approach as
        // payment-terms-config.js). The phtml template renders the term
        // checkboxes container with id `{section}_payment_terms_payment_terms_checkboxes`
        // — strip the suffix to recover the section id and build every
        // other selector against it. Keeps the file brand-agnostic:
        // `two_payment` on vanilla, `abn_payment` on a brand overlay
        // install, etc. The previous hardcoded `two_payment_*` selectors
        // matched nothing on overlay installs, so $surchargeType.val()
        // returned undefined → getSurchargeType() defaulted to 'none' →
        // updateContainerVisibility() hid the grid's row immediately on
        // load.
        var $termsContainer = $('.two-term-checkboxes').first();
        if (!$termsContainer.length) {
            return;
        }
        var containerSuffix = '_payment_terms_payment_terms_checkboxes';
        var termsContainerId = $termsContainer.attr('id') || '';
        if (termsContainerId.slice(-containerSuffix.length) !== containerSuffix) {
            return;
        }
        var section = termsContainerId.slice(0, -containerSuffix.length);
        var prefix = section + '_payment_terms_';

        var $customDays = $('#' + prefix + 'payment_terms_duration_days');
        var $surchargeType = $('#' + prefix + 'surcharge_type');
        var $differential = $('#' + prefix + 'surcharge_differential');
        var $defaultTerm = $('#' + prefix + 'default_payment_term');
        var $table = $container.find('.surcharge-grid');
        var $noTermsMsg = $container.find('.surcharge-grid__no-terms');
        var $currencyNote = $container.find('.surcharge-grid__currency-note');
        // Grid-level inherit ("Use Website/Default"). Present only at a
        // non-default scope; absent at default scope.
        var $inheritToggle = $container.find('.surcharge-grid__inherit-toggle');
        var $inheritSentinel = $container.find('.surcharge-grid__inherit-sentinel');
        // maxFixed === null when the brand has no upper bound on fixed-fee
        // surcharges. Template emits `data-max-fixed=""` in that case;
        // parseInt('', 10) is NaN, so we explicitly track "no bound" and
        // skip the validate-number-range rule below.
        var rawMaxFixed = $container.data('max-fixed');
        var maxFixed = (rawMaxFixed === '' || rawMaxFixed === null || rawMaxFixed === undefined)
            ? null
            : parseInt(rawMaxFixed, 10);
        if (maxFixed !== null && (isNaN(maxFixed) || maxFixed <= 0)) {
            maxFixed = null;
        }
        var maxPercentage = parseInt($container.data('max-percentage'), 10) || 100;

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
            terms = terms.filter(function (v, i, a) { return a.indexOf(v) === i; });
            terms.sort(function (a, b) { return a - b; });
            return terms;
        }

        function getSurchargeType() {
            // Effective (resolved) type, scope-aware. When the type field's
            // "Use Website/Default" is ticked the <select> is disabled but
            // still carries the inherited value, so read it directly. An
            // inherited Percentage type must still render the grid; returning
            // 'none' on inherit (the old behaviour) hid the grid at store
            // scope and stranded any store-scope override out of sight
            // (ABN-440).
            return $surchargeType.val() || 'none';
        }

        function isDifferential() {
            return $differential.val() === '1';
        }

        function getDefaultTerm() {
            return parseInt($defaultTerm.val(), 10) || 0;
        }

        // ── Row management ───────────────────────────────────────────────

        function createRow(days) {
            var columns = ['fixed', 'percentage', 'limit'];
            var html = '<tr class="surcharge-grid__row" data-term="' + days + '">';
            html += '<td class="surcharge-grid__term"><strong>' + $t('%1 days').replace('%1', days) + '</strong></td>';

            $.each(columns, function (_, col) {
                var name = 'groups[payment_terms][fields][surcharge_grid][value][' + days + '][' + col + ']';
                // validate-number intentionally omitted — locale-blind regex
                // would reject Dutch admins typing "10,50". The other two
                // rules route through $.mage.parseNumber, which IS locale-
                // aware (mirrors the surcharge-grid.phtml template).
                var validateRules = ['"validate-zero-or-greater":true'];
                if (col === 'fixed' && maxFixed !== null) {
                    validateRules.push('"validate-number-range":"0-' + maxFixed + '"');
                } else if (col === 'percentage') {
                    validateRules.push('"validate-number-range":"0-' + maxPercentage + '"');
                }
                var dataValidate = validateRules.join(',');

                html += '<td class="surcharge-grid__' + col + '">';
                html += '<input type="text" name="' + name + '" value=""';
                html += ' class="input-text admin__control-text surcharge-grid__input"';
                html += ' data-column="' + col + '" data-term="' + days + '"';
                html += ' data-validate=\'{' + dataValidate + '}\'';
                html += '/></td>';
            });

            html += '</tr>';
            return $(html);
        }

        function updateTermRows() {
            var activeTerms = getSelectedTerms();
            var defaultDays = getDefaultTerm();
            var $tbody = $table.find('tbody');

            // Show/hide existing rows, track which terms have rows
            var existingTerms = {};
            $tbody.find('.surcharge-grid__row').each(function () {
                var $row = $(this);
                var term = parseInt($row.data('term'), 10);
                existingTerms[term] = $row;

                if (activeTerms.indexOf(term) === -1) {
                    $row.hide();
                } else {
                    $row.show();
                }
            });

            // Create rows for new terms (e.g. custom term just entered)
            $.each(activeTerms, function (_, days) {
                if (!existingTerms[days]) {
                    var $newRow = createRow(days);
                    // Insert in sorted position
                    var inserted = false;
                    $tbody.find('.surcharge-grid__row:visible').each(function () {
                        if (parseInt($(this).data('term'), 10) > days) {
                            $newRow.insertBefore($(this));
                            inserted = true;
                            return false;
                        }
                    });
                    if (!inserted) {
                        $tbody.append($newRow);
                    }
                }
            });

            // Update default badge
            $tbody.find('.surcharge-grid__default-badge').remove();
            $tbody.find('.surcharge-grid__row[data-term="' + defaultDays + '"] .surcharge-grid__term strong')
                .after(' <span class="surcharge-grid__default-badge">(default)</span>');

            // Show table or "no terms" message
            if (activeTerms.length > 0) {
                $table.show();
                $currencyNote.show();
                $noTermsMsg.hide();
            } else {
                $table.hide();
                $currencyNote.hide();
                $noTermsMsg.show();
            }
        }

        // ── Column visibility ────────────────────────────────────────────

        function updateColumnVisibility() {
            var type = getSurchargeType();
            var showFixed = type === 'fixed' || type === 'fixed_and_percentage';
            var showPct = type === 'percentage' || type === 'fixed_and_percentage';

            $container.find('.surcharge-grid__fixed').toggle(showFixed);
            $container.find('.surcharge-grid__percentage').toggle(showPct);
            $container.find('.surcharge-grid__limit').toggle(showPct);
        }

        // ── Differential mode ────────────────────────────────────────────

        function updateDifferentialState() {
            var differential = isDifferential();
            var defaultDays = getDefaultTerm();

            $container.find('.surcharge-grid__row').each(function () {
                var $row = $(this);
                var term = parseInt($row.data('term'), 10);
                var disabled = differential && term === defaultDays;

                $row.attr('data-differential-disabled', disabled ? '1' : '0');
                $row.find('.surcharge-grid__input').each(function () {
                    var $input = $(this);
                    if (!$input.data('inherit-disabled')) {
                        $input.prop('disabled', disabled);
                    }
                    // Differential mode: default term never surcharges. Zero
                    // the UI values, snapshotting whatever was there so we
                    // can restore if the merchant toggles differential off
                    // (or picks a different default term) before saving.
                    if (disabled) {
                        if ($input.data('differential-snapshot') === undefined) {
                            $input.data('differential-snapshot', $input.val());
                        }
                        $input.val('0');
                    } else if ($input.data('differential-snapshot') !== undefined) {
                        $input.val($input.data('differential-snapshot'));
                        $input.removeData('differential-snapshot');
                    }
                });
            });
        }

        // ── Helper text ──────────────────────────────────────────────────

        function updateHelperText() {
            var type = getSurchargeType();
            $container.find('.surcharge-grid__helper-text').hide();
            $container.find('.surcharge-grid__helper-text--' + type).show();
        }

        // ── Container visibility ─────────────────────────────────────────

        function updateContainerVisibility() {
            var type = getSurchargeType();
            var hasSurcharge = type !== 'none';
            $container.closest('tr').toggle(hasSurcharge);
        }

        // ── Grid-level inherit ("Use Website/Default") ─────────────────────

        // One checkbox inherits or overrides the whole grid. Checked →
        // the grid inherits the parent scope: all inputs disabled and the
        // hidden sentinel posts '1' so the backend purges every per-term
        // override row at this scope. Unchecked → editable override, and
        // the differential rule reapplies (it owns the default-term row).
        function applyGridInherit() {
            if (!$inheritToggle.length) {
                return; // default scope — no inherit control rendered
            }
            var inherit = $inheritToggle.is(':checked');
            $inheritSentinel.val(inherit ? '1' : '0');
            $container.find('.surcharge-grid__input').prop('disabled', inherit);
            $table.toggleClass('surcharge-grid--inherited', inherit);
            if (!inherit) {
                updateDifferentialState();
            }
        }

        // ── Master update ────────────────────────────────────────────────

        function update() {
            updateContainerVisibility();
            updateTermRows();
            updateColumnVisibility();
            updateDifferentialState();
            updateHelperText();
            // Last: grid-level inherit has final say on input disabled
            // state, overriding column/differential toggles when the whole
            // grid is inheriting.
            applyGridInherit();
            // Fee-preview column removed (ABN-356 / ABN-401-F12); skip the
            // loadFees() AJAX whose response would have no cells to populate.
        }

        // ── Event bindings ───────────────────────────────────────────────

        $termsContainer.on('change', '.two-term-checkboxes__input', update);
        $customDays.on('change keyup', update);
        $surchargeType.on('change', update);
        $differential.on('change', update);
        $defaultTerm.on('change', update);
        $('#' + prefix + 'surcharge_type_inherit').on('change', update);
        $inheritToggle.on('change', applyGridInherit);

        // ── Fee column (read-only, fetched from Two API via admin proxy) ─

        // Memoise the term-set we last fetched so rapid re-fires (keystrokes
        // in the custom-days input, unrelated update() calls) collapse into
        // one network round-trip per genuine change. Declared before the
        // init update() call below so the assignment doesn't shadow what
        // loadFees() writes during init.
        var lastFeesKey = null;

        update();

        function loadFees() {
            var url = $container.data('fees-url');
            if (!url) {
                return;
            }
            var terms = getSelectedTerms();
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
                    scope: String($container.data('scope') || 'default'),
                    scopeId: parseInt($container.data('scope-id'), 10) || 0
                }
            }).done(function (response) {
                if (!response || !response.success || !response.fees) {
                    return; // leave "—" in cells
                }
                var gridCurrency = String($container.data('base-currency') || '').toUpperCase();
                var responseCurrency = String(response.currency || '').toUpperCase();
                var degraded = responseCurrency !== '' && responseCurrency !== gridCurrency;
                var suffix = degraded ? ' ' + responseCurrency : '';
                var decimalSep = String($container.data('decimal-separator') || '.');
                function formatAmount(n) {
                    var s = Number(n).toFixed(2);
                    return decimalSep === '.' ? s : s.replace('.', decimalSep);
                }
                var zero = formatAmount(0);
                $container.find('td.surcharge-grid__fee').each(function () {
                    var $cell = $(this);
                    var term = String($cell.data('term'));
                    var fee = response.fees[term];
                    if (!fee) {
                        return;
                    }
                    var pctStr = formatAmount(fee.percentage || 0);
                    var fixedStr = formatAmount(fee.fixed || 0);
                    var pctZero = pctStr === zero;
                    var fixedZero = fixedStr === zero;
                    var text;
                    if (pctZero && fixedZero) {
                        text = zero + suffix;
                    } else if (pctZero) {
                        text = fixedStr + suffix;
                    } else if (fixedZero) {
                        text = pctStr + '%';
                    } else {
                        text = pctStr + '% + ' + fixedStr + suffix;
                    }
                    $cell.text(text);
                });
                var $note = $currencyNote.find('span');
                var noteText = degraded
                    ? $currencyNote.attr('data-text-degraded')
                    : $currencyNote.attr('data-text-default');
                if (noteText) {
                    $note.text(noteText);
                }
            }).fail(function () {
                // Allow a retry on the same term-set after a transient error,
                // and fall back to "—" on any cell still showing the loading
                // animation so the user isn't watching dots forever.
                lastFeesKey = null;
                $container.find('td.surcharge-grid__fee').each(function () {
                    var $cell = $(this);
                    if ($cell.find('.surcharge-grid__loading').length) {
                        $cell.text('—');
                    }
                });
            });
        }
    };
});
