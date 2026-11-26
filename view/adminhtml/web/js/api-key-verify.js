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
        // The Environment dropdown sits in the key's own group in every
        // section that renders this panel, so its id differs from the key's
        // only in the field id. The key is checked against the environment
        // chosen there, saved or not (TWO-27303).
        var $mode = /api_key$/.test(fieldId) ? $('#' + fieldId.replace(/api_key$/, 'mode')) : $();
        // The "Use Default"/"Use Website" boxes. Ticking one only disables the
        // field, keeping whatever it holds, and the save then uses the parent
        // scope's value instead, so the server is told and judges that value.
        var $modeInherit = $mode.length ? $('#' + $mode.attr('id') + '_inherit') : $();
        var $keyInherit = $('#' + fieldId + '_inherit');
        // What the server-rendered verdict below was given for.
        var savedMode = $mode.length ? String($mode.val() || '') : '';
        var savedModeInherited = ticked($modeInherit);
        var savedKeyInherited = ticked($keyInherit);

        var $message = $panel.find('.two-api-key-verify__message');
        var $icon = $panel.find('.two-api-key-verify__icon');
        // The server-rendered verdict for the SAVED key — restored whenever
        // the field reverts to that value (or to its untouched placeholder),
        // rather than left showing a stale "checking"/live verdict.
        var savedMessage = $message.text();
        var savedIconClass = $icon.attr('class');
        var $merchant = $panel.find('.two-api-key-verify__merchant');
        var $merchantId = $merchant.find('.two-api-key-verify__merchant-id');
        var $shortName = $merchant.find('.two-api-key-verify__merchant-short-name');
        var savedMerchantId = $merchantId.text();
        var savedShortName = $shortName.text();
        var savedMerchantHidden = $merchant.prop('hidden');
        var timer = null;
        var latest = 0;
        var pending = null;

        function render(status, message) {
            if (!status) {
                $message.text(savedMessage);
                $icon.attr('class', savedIconClass);
                $merchantId.text(savedMerchantId);
                $shortName.text(savedShortName);
                $merchant.prop('hidden', savedMerchantHidden);
                return;
            }
            $message.text(message);
            $icon.attr('class', 'two-api-key-verify__icon api-key-status-icon api-key-' + status);
        }

        function ticked($box) {
            return $box.length > 0 && $box.prop('checked') === true;
        }

        function verify() {
            var keyInherited = ticked($keyInherit);
            var modeInherited = ticked($modeInherit);
            // An inherited key is the parent's, whatever the field holds.
            var key = keyInherited ? '' : $.trim(String($input.val() || ''));
            var mode = $mode.length && !modeInherited ? String($mode.val() || '') : null;
            // The obscure field renders the stored key as asterisks; an
            // untouched placeholder is not a candidate.
            var typed = key.length >= MIN_KEY_LENGTH && !/^\*+$/.test(key);
            // With no key typed, the saved key is judged again whenever the
            // choices differ from those the page's own verdict was given for.
            var stored = !typed && (keyInherited !== savedKeyInherited
                || modeInherited !== savedModeInherited
                || (mode !== null && mode !== savedMode));
            if (!typed && !stored) {
                latest++;
                render('', '');
                return;
            }

            var sequence = ++latest;
            render('checking', $t('Checking API key…'));
            if (pending) {
                pending.abort();
            }
            var data = {
                form_key: $('input[name="form_key"]').first().val() || (window.FORM_KEY || ''),
                api_key: typed ? key : '',
                scope: String($panel.data('scope') || 'default'),
                scopeId: parseInt($panel.data('scope-id'), 10) || 0
            };
            if (stored) {
                data.stored = 1;
            }
            if (keyInherited) {
                data.key_inherit = 1;
            }
            if (modeInherited) {
                data.mode_inherit = 1;
            } else if (mode !== null) {
                data.mode = mode;
            }
            pending = $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: data
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
                // Show the merchant the candidate resolves to straight away
                // (nothing is saved until the section save). A key Two
                // rejected clears it; a check that judged nothing leaves it.
                if (response.verified && response.merchant_id) {
                    $merchantId.text(String(response.merchant_id));
                    $shortName.text(response.merchant_short_name ? ' \u00b7 ' + response.merchant_short_name : '');
                    $merchant.prop('hidden', false);
                } else if (response.definitive) {
                    $merchant.prop('hidden', true);
                }
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
        // A verdict is for one key in one environment, so switching the
        // environment or either box checks the typed key, or else the saved
        // one, again.
        $mode.add($modeInherit).add($keyInherit).on('change', function () {
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
