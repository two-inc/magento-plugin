/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-548. When a term is unticked the default-term select rebuilds, and where
 * that drops the current selection it lands on 30 if 30 is still ticked, else
 * the lowest. The select posts on save, so a synthesised lowest would pin the
 * stored default below 30 permanently.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const SECTION = 'two_payment';
const PREFIX = SECTION + '_payment_terms_';

function initWith(ticked, selected) {
    const checkboxes = ticked.map(function (days) {
        return '<input class="two-term-checkboxes__input" type="checkbox" value="' + days + '" checked />';
    }).join('');
    const options = ticked.map(function (days) {
        return '<option value="' + days + '"' + (days === selected ? ' selected' : '') + '>' + days + '</option>';
    }).join('');

    document.body.innerHTML =
        '<table><tbody>' +
        '<tr><td><div class="two-term-checkboxes" id="' + PREFIX + 'payment_terms_checkboxes">' +
        checkboxes +
        '</div></td></tr>' +
        '<tr id="row_' + PREFIX + 'payment_terms_duration_days"><td>' +
        '<select id="' + PREFIX + 'payment_terms_duration_days">' +
        '<option value="" data-two-term="0" selected="selected">Remove</option>' +
        '</select></td></tr>' +
        '<tr><td><select id="' + PREFIX + 'default_payment_term">' + options + '</select></td></tr>' +
        '<tr><td><select id="' + PREFIX + 'surcharge_type"><option value="none" selected>none</option></select></td></tr>' +
        '<tr><td><select id="' + PREFIX + 'surcharge_differential"><option value="0" selected>0</option></select></td></tr>' +
        '</tbody></table>';

    const mocks = defaultMocks();
    mocks.jquery = $;
    loadAmdModule('view/adminhtml/web/js/payment-terms-config.js', mocks).init();
}

function untick(days) {
    $('.two-term-checkboxes__input[value="' + days + '"]').prop('checked', false).trigger('change');

    return $('#' + PREFIX + 'default_payment_term').val();
}

describe('the term the default-term select lands on after a rebuild', () => {
    it.each([
        [[7, 30, 60], 60, 7, '60', 'unticking another term leaves the selection alone'],
        [[7, 14, 30], 14, 14, '30', 'losing the selection lands on 30 rather than the lowest'],
        [[7, 14, 60], 14, 14, '7', 'losing the selection lands on the lowest when 30 is not ticked'],
        [[14, 30], 14, 14, '30', 'the only remaining term is selected'],
        [[7, 30], 30, 30, '7', 'losing 30 itself lands on the lowest']
    ])('ticked %s selected %s, untick %s -> %s — %s', (ticked, selected, unticked, expected) => {
        initWith(ticked, selected);
        expect(untick(unticked)).toBe(expected);
    });
});
