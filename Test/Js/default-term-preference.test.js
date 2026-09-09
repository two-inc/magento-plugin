/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-548. The default-term select carries an Automatic option, so a selection
 * dropped by a term being unticked lands there and the checkout resolves the
 * term. The select posts on save, so synthesising a day count here would pin
 * the stored default to whatever the browser happened to show.
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
    const options = ['<option value=""' + (selected === '' ? ' selected' : '') + '>Automatic</option>'].concat(
        ticked.map(function (days) {
            return '<option value="' + days + '"' + (days === selected ? ' selected' : '') + '>' + days + '</option>';
        })
    ).join('');

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

function selection() {
    return $('#' + PREFIX + 'default_payment_term').val();
}

function untick(days) {
    $('.two-term-checkboxes__input[value="' + days + '"]').prop('checked', false).trigger('change');

    return selection();
}

describe('the default-term select on load', () => {
    it.each([
        [[7, 30, 60], 60, '60', 'a stored term that is still ticked is kept'],
        [[7, 30, 60], '', '', 'Automatic is kept'],
        [[7, 30], 45, '', 'a stored term that is no longer ticked reads as Automatic, not as a day count'],
        [[7, 14], 45, '', 'the same with no 30 ticked — no day count is synthesised']
    ])('ticked %s selected %s -> %s — %s', (ticked, selected, expected) => {
        initWith(ticked, selected);
        expect(selection()).toBe(expected);
    });
});

describe('the default-term select after a rebuild', () => {
    it.each([
        [[7, 30, 60], 60, 7, '60', 'unticking another term leaves the selection alone'],
        [[7, 14, 30], 14, 14, '', 'losing the selection falls to Automatic'],
        [[7, 30], 30, 30, '', 'losing 30 itself falls to Automatic'],
        [[7, 30, 60], '', 7, '', 'Automatic survives a rebuild']
    ])('ticked %s selected %s, untick %s -> %s — %s', (ticked, selected, unticked, expected) => {
        initWith(ticked, selected);
        expect(untick(unticked)).toBe(expected);
    });
});

describe('the Automatic option itself', () => {
    it('is offered first, ahead of every ticked term', () => {
        initWith([7, 30], '');
        const values = $('#' + PREFIX + 'default_payment_term option').map(function () {
            return this.value;
        }).get();

        expect(values).toEqual(['', '7', '30']);
    });
});
