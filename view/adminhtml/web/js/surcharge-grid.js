define(['jquery', 'domReady!'], function ($) {
    'use strict';

    return function (config, element) {
        var $container = $(element);
        var $surchargeType = $('#two_payment_payment_terms_surcharge_type');
        var $differential = $('#two_payment_payment_terms_surcharge_differential');
        var $defaultTerm = $('#two_payment_payment_terms_default_payment_term');

        function getSurchargeType() {
            return $surchargeType.val() || 'none';
        }

        function isDifferential() {
            return $differential.val() === '1';
        }

        function getDefaultTerm() {
            return parseInt($defaultTerm.val(), 10) || 0;
        }

        function updateColumnVisibility() {
            var type = getSurchargeType();
            var showFixed = type === 'fixed' || type === 'fixed_and_percentage';
            var showPct = type === 'percentage' || type === 'fixed_and_percentage';

            $container.find('.surcharge-grid__fixed').toggle(showFixed);
            $container.find('.surcharge-grid__percentage').toggle(showPct);
            // Limit only relevant when percentage is involved
            $container.find('.surcharge-grid__limit').toggle(showPct);
        }

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
                    // Don't override inherit-disabled state
                    if (!$input.data('inherit-disabled')) {
                        $input.prop('disabled', disabled);
                    }
                });
            });
        }

        function initInheritCheckboxes() {
            $container.find('.surcharge-grid__inherit-checkbox').on('change', function () {
                var $cb = $(this);
                var col = $cb.data('column');
                var term = $cb.data('term');
                var inherited = $cb.is(':checked');
                var $row = $container.find('.surcharge-grid__row[data-term="' + term + '"]');

                // Toggle the input
                var $input = $row.find('.surcharge-grid__input[data-column="' + col + '"]');
                $input.prop('disabled', inherited).data('inherit-disabled', inherited);

                // Toggle the hidden inherit flag
                var $flag = $row.find('.surcharge-grid__inherit-flag[data-column="' + col + '"]');
                $flag.val(inherited ? '1' : '0');
            });
        }

        function update() {
            updateColumnVisibility();
            updateDifferentialState();
        }

        // Bind to config changes
        $surchargeType.on('change', update);
        $differential.on('change', update);
        $defaultTerm.on('change', update);

        initInheritCheckboxes();
        update();
    };
});
