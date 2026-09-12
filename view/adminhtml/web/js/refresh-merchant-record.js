define(['jquery', 'mage/translate', 'domReady!'], function ($, $t) {
    'use strict';

    function initRefreshMerchantRecord() {
        var $panel = $('.two-refresh-merchant-record').first();
        if (!$panel.length) {
            return;
        }
        var url = String($panel.data('refresh-url') || '');
        var $button = $panel.find('.two-refresh-merchant-record__button');
        var $status = $panel.find('.two-refresh-merchant-record__status');
        if (!url || !$button.length) {
            return;
        }

        function render(state, message) {
            $status
                .attr('class', 'two-refresh-merchant-record__status' + (state ? ' ' + state : ''))
                .text(message);
        }

        // init() is exported, so a second call must not double-bind.
        $button.off('click.twoRefreshRecord').on('click.twoRefreshRecord', function () {
            $button.prop('disabled', true);
            render('', $t('Refreshing…'));

            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: $('input[name="form_key"]').first().val() || (window.FORM_KEY || ''),
                    scope: String($panel.data('scope') || 'default'),
                    scopeId: parseInt($panel.data('scope-id'), 10) || 0
                }
            }).done(function (response) {
                if (!response || !response.success) {
                    render(
                        'error',
                        String((response && response.message) || $t('Could not refresh the merchant profile.'))
                    );
                    return;
                }
                var merchant = String(response.merchant || '');
                render(
                    'success',
                    String(response.message || '') + (merchant ? ' ' + merchant : '')
                );
            }).fail(function () {
                render('error', $t('Could not refresh the merchant profile.'));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    }

    initRefreshMerchantRecord();

    return {
        init: initRefreshMerchantRecord
    };
});
