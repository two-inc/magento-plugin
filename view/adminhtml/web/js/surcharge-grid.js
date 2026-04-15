define(['jquery', 'domReady!'], function ($) {
    'use strict';

    return function (config, element) {
        var $container = $(element);
        var $terms = $('#two_payment_payment_terms_payment_terms');
        var $customDays = $('#two_payment_payment_terms_payment_terms_duration_days');
        var $surchargeType = $('#two_payment_payment_terms_surcharge_type');
        var $differential = $('#two_payment_payment_terms_surcharge_differential');
        var $defaultTerm = $('#two_payment_payment_terms_default_payment_term');
        var $table = $container.find('.surcharge-grid');
        var $noTermsMsg = $container.find('.surcharge-grid__no-terms');
        var maxFixed = parseInt($container.data('max-fixed'), 10) || 100;
        var maxPercentage = parseInt($container.data('max-percentage'), 10) || 100;

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
            terms = terms.filter(function (v, i, a) { return a.indexOf(v) === i; });
            terms.sort(function (a, b) { return a - b; });
            return terms;
        }

        function getSurchargeType() {
            var $inherit = $('#two_payment_payment_terms_surcharge_type_inherit');
            if ($inherit.length && $inherit.is(':checked')) {
                return 'none';
            }
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
            html += '<td class="surcharge-grid__term"><strong>' + days + ' days</strong></td>';

            $.each(columns, function (_, col) {
                var name = 'groups[payment_terms][fields][surcharge_grid][value][' + days + '][' + col + ']';
                var validateRules = ['"validate-number":true', '"validate-zero-or-greater":true'];
                if (col === 'fixed') {
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
                $noTermsMsg.hide();
            } else {
                $table.hide();
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
                });
            });
        }

        // ── Container visibility ─────────────────────────────────────────

        function updateContainerVisibility() {
            var type = getSurchargeType();
            var hasSurcharge = type !== 'none';
            $container.closest('tr').toggle(hasSurcharge);
        }

        // ── Inherit checkboxes ───────────────────────────────────────────

        function initInheritCheckboxes() {
            $container.on('change', '.surcharge-grid__inherit-checkbox', function () {
                var $cb = $(this);
                var col = $cb.data('column');
                var term = $cb.data('term');
                var inherited = $cb.is(':checked');
                var $row = $container.find('.surcharge-grid__row[data-term="' + term + '"]');

                var $input = $row.find('.surcharge-grid__input[data-column="' + col + '"]');
                $input.prop('disabled', inherited).data('inherit-disabled', inherited);

                var $flag = $row.find('.surcharge-grid__inherit-flag[data-column="' + col + '"]');
                $flag.val(inherited ? '1' : '0');
            });
        }

        // ── Master update ────────────────────────────────────────────────

        function update() {
            updateContainerVisibility();
            updateTermRows();
            updateColumnVisibility();
            updateDifferentialState();
        }

        // ── Event bindings ───────────────────────────────────────────────

        $terms.on('change', update);
        $customDays.on('change keyup', update);
        $surchargeType.on('change', update);
        $differential.on('change', update);
        $defaultTerm.on('change', update);
        $('#two_payment_payment_terms_surcharge_type_inherit').on('change', update);

        initInheritCheckboxes();
        update();
    };
});
