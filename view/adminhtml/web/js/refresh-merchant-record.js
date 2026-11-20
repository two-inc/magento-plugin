define(['jquery', 'mage/translate', 'domReady!'], function ($, $t) {
    'use strict';

    var STAMP = 'two_refreshed';
    var STORED_STATUS = 'twoRefreshedStatus';

    // window.twoAdminNavigate, when set, stands in (the JS tests set it: jsdom cannot navigate).
    function navigateTo(href) {
        window.location.assign(href);
    }

    function initRefreshMerchantRecord() {
        var $panel = $('.two-refresh-merchant-record').first();
        if (!$panel.length) {
            return;
        }
        // TWO-26153: the reload after a refresh carries a stamp the page confirms
        // once; drop it, so a later reload or save does not confirm it again.
        var landed = new window.URL(window.location.href);
        var stored = null;
        try {
            stored = window.sessionStorage.getItem(STORED_STATUS);
            window.sessionStorage.removeItem(STORED_STATUS);
        } catch (e) {
            stored = null;
        }
        if (landed.searchParams.has(STAMP)) {
            landed.searchParams.delete(STAMP);
            window.history.replaceState(window.history.state, '', landed.toString());
            if (stored) {
                $panel.find('.two-refresh-merchant-record__status')
                    .attr('class', 'two-refresh-merchant-record__status success')
                    .text(stored);
            }
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
                var text = String(response.message || '') + (merchant ? ' ' + merchant : '');
                render('success', text);
                // TWO-26153: reload onto this panel, so every section renders afresh,
                // the tax codes section included. The stamp makes every reload a new
                // URL, so it is never only a hash change.
                try {
                    window.sessionStorage.setItem(STORED_STATUS, text);
                } catch (e) {
                    // The page confirms the refresh without the merchant names.
                }
                var url = new window.URL(window.location.href);
                url.searchParams.set(STAMP, String(Date.now()));
                url.hash = 'two-refresh-merchant-record';
                (window.twoAdminNavigate || navigateTo)(url.toString());
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
