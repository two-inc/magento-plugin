define(['jquery', 'domReady!'], function ($) {
    'use strict';

    // Payment Terms Configuration Handler
    function initPaymentTermsConfig() {
        var $termsType = $('#two_payment_payment_method_payment_terms_type');
        var $durationDays = $('#two_payment_payment_method_payment_terms_duration_days');

        if ($termsType.length && $durationDays.length) {
            // Options for different payment terms types
            var standardOptions = [
                { value: 7, label: '7 days' },
                { value: 15, label: '15 days' },
                { value: 30, label: '30 days' },
                { value: 45, label: '45 days' },
                { value: 60, label: '60 days' },
                { value: 90, label: '90 days' }
            ];

            var endOfMonthOptions = [
                { value: 30, label: '30 days' },
                { value: 45, label: '45 days' },
                { value: 60, label: '60 days' }
            ];

            function updateDurationOptions() {
                var termsType = $termsType.val();
                var currentValue = $durationDays.val();
                var options = termsType === 'end_of_month' ? endOfMonthOptions : standardOptions;

                // Clear current options
                $durationDays.empty();

                // Add new options
                $.each(options, function (index, option) {
                    $durationDays.append(
                        $('<option></option>').attr('value', option.value).text(option.label)
                    );
                });

                // Try to keep current value if it's valid for the new type
                var validValues = options.map(function (opt) {
                    return opt.value.toString();
                });
                if (validValues.indexOf(currentValue) !== -1) {
                    $durationDays.val(currentValue);
                } else {
                    // Set default value based on type
                    $durationDays.val(termsType === 'end_of_month' ? '30' : '30');
                }

                // Trigger change event
                $durationDays.trigger('change');
            }

            // Initialize on page load
            updateDurationOptions();

            // Update when terms type changes
            $termsType.on('change', updateDurationOptions);
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function () {
        initPaymentTermsConfig();
    });

    return {
        init: initPaymentTermsConfig
    };
});
