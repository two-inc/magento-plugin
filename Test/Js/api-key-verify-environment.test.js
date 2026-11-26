/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-27303. The live API key check posts the Environment dropdown's current
 * value with the key, so a key for an environment chosen but not yet saved is
 * checked against that environment. The dropdown is found beside the key in
 * any section, Two's own or a brand's.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const KEY = 'candidate-key-of-ample-length';

// Magento's admin ships jQuery 3, which still has $.trim; the test copy is jQuery 4.
$.trim = $.trim || ((value) => String(value).trim());

function setUp(keyFieldId, modeFieldId, modeValue) {
    const options = ['production', 'sandbox', 'staging']
        .map((mode) => `<option value="${mode}"${mode === modeValue ? ' selected' : ''}>${mode}</option>`)
        .join('');
    document.body.innerHTML =
        '<input name="form_key" value="fk" />' +
        (modeFieldId
            ? `<select id="${modeFieldId}">${options}</select>` +
              `<input type="checkbox" id="${modeFieldId}_inherit" class="config-inherit" />`
            : '') +
        `<div class="two-api-key-verify" data-verify-url="/verify" data-field-id="${keyFieldId}">` +
        `<input id="${keyFieldId}" value="" />` +
        '<span class="two-api-key-verify__icon api-key-success"></span>' +
        '<span class="two-api-key-verify__message">API key is valid</span>' +
        '<div class="two-api-key-verify__merchant" hidden>' +
        '<span class="two-api-key-verify__merchant-id"></span>' +
        '<span class="two-api-key-verify__merchant-short-name"></span>' +
        '</div></div>';
    $.ajax = jest.fn(() => {
        const promise = $.Deferred().resolve({ verified: true, status: 'success' }).promise();
        promise.abort = () => {};
        return promise;
    });
    const mocks = defaultMocks();
    mocks.jquery = $;
    loadAmdModule('view/adminhtml/web/js/api-key-verify.js', mocks).init();
}

function postedModes() {
    return $.ajax.mock.calls.map(([request]) => request.data.mode);
}

describe('the live API key check uses the environment chosen on the form', () => {
    test.each([
        { keyFieldId: 'two_general_general_api_key', modeFieldId: 'two_general_general_mode', mode: 'production',
            expected: ['production'], description: 'env switched, unsaved: the dropdown value is posted' },
        { keyFieldId: 'two_general_general_api_key', modeFieldId: 'two_general_general_mode', mode: 'sandbox',
            expected: ['sandbox'], description: 'env unchanged: the dropdown value is posted' },
        { keyFieldId: 'acme_general_general_api_key', modeFieldId: 'acme_general_general_mode', mode: 'production',
            expected: ['production'], description: "a brand's section: its own dropdown is found" },
        { keyFieldId: 'two_general_general_api_key', modeFieldId: 'other_general_general_mode', mode: 'production',
            expected: [undefined], description: "mode missing: another section's dropdown is not used" },
        { keyFieldId: 'two_general_general_api_key', modeFieldId: null, mode: null,
            expected: [undefined], description: 'mode missing: no dropdown posts no mode' },
    ])('$description', ({ keyFieldId, modeFieldId, mode, expected, description }) => {
        setUp(keyFieldId, modeFieldId, mode);

        $('#' + keyFieldId).val(KEY).trigger('blur');

        expect({ description, posted: postedModes() }).toEqual({ description, posted: expected });
    });

    test('switching the environment checks the typed key again against the new one', () => {
        setUp('two_general_general_api_key', 'two_general_general_mode', 'sandbox');
        $('#two_general_general_api_key').val(KEY).trigger('blur');

        $('#two_general_general_mode').val('production').trigger('change');

        expect(postedModes()).toEqual(['sandbox', 'production']);
    });

    test.each([
        { field: '', description: 'an empty field' },
        { field: '********', description: 'the saved-key placeholder' },
    ])('switching the environment with $description re-checks the saved key there', ({ field, description }) => {
        setUp('two_general_general_api_key', 'two_general_general_mode', 'sandbox');
        $('#two_general_general_api_key').val(field);

        $('#two_general_general_mode').val('production').trigger('change');

        const posted = $.ajax.mock.calls.map(([request]) => ({
            api_key: request.data.api_key, stored: request.data.stored, mode: request.data.mode,
        }));
        expect({ description, posted }).toEqual(
            { description, posted: [{ api_key: '', stored: 1, mode: 'production' }] }
        );
    });

    test('switching back to the saved environment shows the page verdict without a request', () => {
        setUp('two_general_general_api_key', 'two_general_general_mode', 'sandbox');
        $('#two_general_general_mode').val('production').trigger('change');

        $('#two_general_general_mode').val('sandbox').trigger('change');

        expect(postedModes()).toEqual(['production']);
        expect($('.two-api-key-verify__message').text()).toBe('API key is valid');
    });

    // Core's toggleValueElements() only disables the select when "Use Website"
    // is ticked: no change event, and the select keeps the value chosen.
    function tickInherit() {
        $('#two_general_general_mode').prop('disabled', true);
        $('#two_general_general_mode_inherit').prop('checked', true).trigger('change');
    }

    test('a typed key is checked against the saved environment once the dropdown is inherited', () => {
        setUp('two_general_general_api_key', 'two_general_general_mode', 'sandbox');
        $('#two_general_general_api_key').val(KEY);
        $('#two_general_general_mode').val('production').trigger('change');

        tickInherit();

        expect(postedModes()).toEqual(['production', undefined]);
    });

    test('ticking inherit with no key typed restores the page verdict without a request', () => {
        setUp('two_general_general_api_key', 'two_general_general_mode', 'sandbox');
        $('#two_general_general_mode').val('production').trigger('change');

        tickInherit();

        expect(postedModes()).toEqual(['production']);
        expect($('.two-api-key-verify__message').text()).toBe('API key is valid');
    });
});
