/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-26231. The live API key check shows the merchant a candidate key resolves
 * to as soon as the check completes, without a save. A key Two rejected hides
 * the merchant; a check that judged nothing about the key leaves it alone.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const KEY = 'candidate-key-of-ample-length';

// Magento's admin ships jQuery 3, which still has $.trim; the test copy is jQuery 4.
$.trim = $.trim || ((value) => String(value).trim());

function setUp(response) {
    document.body.innerHTML =
        '<input name="form_key" value="fk" />' +
        '<div class="two-api-key-verify" data-verify-url="/verify" data-field-id="api_key">' +
        '<input id="api_key" value="" />' +
        '<span class="two-api-key-verify__icon api-key-success"></span>' +
        '<span class="two-api-key-verify__message">API key is valid</span>' +
        '<div class="two-api-key-verify__merchant">' +
        '<span class="two-api-key-verify__merchant-id">saved-id</span>' +
        '<span class="two-api-key-verify__merchant-short-name"> · saved</span>' +
        '</div></div>';
    $.ajax = jest.fn(() => {
        const promise = $.Deferred().resolve(response).promise();
        promise.abort = () => {};
        return promise;
    });
    const mocks = defaultMocks();
    mocks.jquery = $;
    loadAmdModule('view/adminhtml/web/js/api-key-verify.js', mocks).init();
}

function displayed() {
    const $merchant = $('.two-api-key-verify__merchant');
    return $merchant.prop('hidden') ? null : $merchant.text();
}

describe('merchant details follow the live API key check', () => {
    test.each([
        { response: { verified: true, status: 'success', merchant_id: 'new-id', merchant_short_name: 'fresh' },
            expected: 'new-id · fresh', description: 'a verified key shows its merchant before save' },
        { response: { verified: true, status: 'success', merchant_id: 'new-id', merchant_short_name: '' },
            expected: 'new-id', description: 'a verified key without a short name shows the id alone' },
        { response: { verified: false, status: 'error', definitive: true },
            expected: null, description: 'a rejected key hides the merchant' },
        { response: { verified: false, status: 'error', definitive: false },
            expected: 'saved-id · saved', description: 'an inconclusive check leaves the merchant as it was' },
    ])('$description', ({ response, expected, description }) => {
        setUp(response);

        $('#api_key').val(KEY).trigger('blur');

        expect({ description, shown: displayed() }).toEqual({ description, shown: expected });
    });

    test('clearing the field restores the saved merchant', () => {
        setUp({ verified: true, status: 'success', merchant_id: 'new-id', merchant_short_name: 'fresh' });
        $('#api_key').val(KEY).trigger('blur');

        $('#api_key').val('').trigger('blur');

        expect(displayed()).toBe('saved-id · saved');
    });
});
