define(['jquery', 'mage/translate', 'domReady!'], function ($, $t) {
    'use strict';

    // Mirrors Controller\Adminhtml\Config\VerifyApiKey::MIN_KEY_LENGTH — a
    // floor to avoid a round trip per keystroke of a half-typed key.
    var MIN_KEY_LENGTH = 20;
    var DEBOUNCE_MS = 500;

    function initApiKeyVerify() {
        var $panel = $('.two-api-key-verify').first();
        if (!$panel.length) {
            return;
        }
        var url = String($panel.data('verify-url') || '');
        var fieldId = String($panel.data('field-id') || '');
        var $input = fieldId ? $('#' + fieldId) : $();
        if (!url || !$input.length) {
            return;
        }

        var $message = $panel.find('.two-api-key-verify__message');
        var $icon = $panel.find('.two-api-key-verify__icon');
        // The server-rendered verdict for the SAVED key — restored whenever
        // the field reverts to that value (or to its untouched placeholder),
        // rather than left showing a stale "checking"/live verdict.
        var savedMessage = $message.text();
        var savedIconClass = $icon.attr('class');
        var timer = null;
        var latest = 0;
        var pending = null;

        function render(status, message) {
            if (!status) {
                $message.text(savedMessage);
                $icon.attr('class', savedIconClass);
                return;
            }
            $message.text(message);
            $icon.attr('class', 'two-api-key-verify__icon api-key-status-icon api-key-' + status);
        }

        function verify() {
            var key = $.trim(String($input.val() || ''));
            // The obscure field renders the stored key as asterisks; an
            // untouched placeholder is not a candidate.
            if (key.length < MIN_KEY_LENGTH || /^\*+$/.test(key)) {
                latest++;
                render('', '');
                return;
            }

            var sequence = ++latest;
            render('checking', $t('Checking API key…'));
            if (pending) {
                pending.abort();
            }
            pending = $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: $('input[name="form_key"]').first().val() || (window.FORM_KEY || ''),
                    api_key: key,
                    scope: String($panel.data('scope') || 'default'),
                    scopeId: parseInt($panel.data('scope-id'), 10) || 0
                }
            }).done(function (response) {
                // A slow answer for an earlier keystroke must not paint over
                // the verdict for what is in the field now.
                if (sequence !== latest) {
                    return;
                }
                if (!response || response.skipped || !response.status) {
                    render('', '');
                    return;
                }
                render(String(response.status), String(response.message || ''));
            }).fail(function () {
                if (sequence === latest) {
                    render('', '');
                }
            });
        }

        $input.on('input', function () {
            clearTimeout(timer);
            timer = setTimeout(verify, DEBOUNCE_MS);
        });
        // Blur fires immediately (no debounce) — an admin who tabs away
        // right after pasting a key should not wait out DEBOUNCE_MS for
        // the verdict that "input" alone would already be about to show.
        $input.on('blur', function () {
            clearTimeout(timer);
            verify();
        });
    }

    $(document).ready(function () {
        initApiKeyVerify();
    });

    return {
        init: initApiKeyVerify
    };
});
