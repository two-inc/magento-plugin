/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-548. Differential mode disables and zeroes the row it prices against.
 * With the default-term field on Automatic the select carries no day count, so
 * the row is the one the checkout resolves — reading the select alone left the
 * buyer's baseline term editable and its fee posted.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const SECTION = 'two_payment';
const PREFIX = SECTION + '_payment_terms_';

function rows(terms) {
    return terms.map(function (days) {
        return '<tr class="surcharge-grid__row" data-term="' + days + '">'
            + '<td class="surcharge-grid__term"><strong>' + days + '</strong></td>'
            + ['fixed', 'percentage', 'limit'].map(function (col) {
                return '<td class="surcharge-grid__' + col + '">'
                    + '<input type="text" class="input-text surcharge-grid__input" value="5"'
                    + ' name="groups[payment_terms][fields][surcharge_' + days + '_' + col + '][value]"/>'
                    + '</td>';
            }).join('')
            + '</tr>';
    }).join('');
}

function boot(ticked, selectedDefault, merchantOffered, merchantDefaultTerm) {
    const checkboxes = merchantOffered.map(function (days) {
        return '<input class="two-term-checkboxes__input" type="checkbox" value="' + days + '"'
            + (ticked.indexOf(days) === -1 ? '' : ' checked="checked"') + '/>';
    }).join('');
    const options = ['<option value=""' + (selectedDefault === '' ? ' selected="selected"' : '') + '>Automatic</option>']
        .concat(ticked.map(function (days) {
            return '<option value="' + days + '"'
                + (String(days) === selectedDefault ? ' selected="selected"' : '') + '>' + days + '</option>';
        }))
        .join('');

    document.body.innerHTML =
        '<input name="form_key" value="k"/>'
        + '<select id="' + PREFIX + 'payment_terms_duration_days">'
        + '  <option value="" data-two-term="0" selected="selected">Remove</option>'
        + '</select>'
        + '<select id="' + PREFIX + 'surcharge_type">'
        + '  <option value="percentage" selected="selected">percentage</option>'
        + '</select>'
        + '<select id="' + PREFIX + 'surcharge_differential">'
        + '  <option value="1" selected="selected">1</option><option value="0">0</option>'
        + '</select>'
        + '<select id="' + PREFIX + 'default_payment_term">' + options + '</select>'
        + '<div id="' + PREFIX + 'payment_terms_checkboxes" class="two-term-checkboxes"'
        + ' data-merchant-default-term="' + merchantDefaultTerm + '">' + checkboxes + '</div>'
        + '<div id="surcharge-grid-container" data-max-fixed="100" data-max-percentage="10">'
        + '  <p class="surcharge-grid__no-terms"><span></span></p>'
        + '  <table class="admin__table-secondary surcharge-grid"><tbody>'
        + rows(ticked)
        + '  </tbody></table>'
        + '  <p class="surcharge-grid__currency-note note"><span></span></p>'
        + '</div>';

    const mocks = defaultMocks();
    $.validator = mocks.jquery.validator;
    $.mage = mocks.jquery.mage;
    mocks.jquery = $;
    loadAmdModule('view/adminhtml/web/js/surcharge-grid.js', mocks)(
        {},
        document.getElementById('surcharge-grid-container')
    );
}

/** The term whose row differential mode took out of the merchant's hands. */
function disabledTerm() {
    const $row = $('.surcharge-grid__row[data-differential-disabled="1"]');

    return $row.length ? Number($row.data('term')) : 0;
}

describe('the row differential mode disables', () => {
    it.each([
        [[7, 30, 60], '60', [7, 30, 60], 0, 60, 'the term the admin pinned'],
        [[7, 30, 60], '', [7, 30, 60], 0, 30, '30 when the admin pinned nothing'],
        [[7, 14, 60], '', [7, 14, 60], 0, 7, 'the shortest when 30 is not offered'],
        [[7, 30, 60], '', [7, 30, 60], 60, 60, "the merchant's own default term ahead of 30"],
        [[7, 30, 60], '', [7, 30, 60], 45, 30, 'and not a merchant default term nobody offers']
    ])('ticked %s pinned %s merchant %s/%s -> %s — %s', (ticked, pinned, offered, merchantDefault, expected) => {
        boot(ticked, pinned, offered, merchantDefault);

        expect(disabledTerm()).toBe(expected);
    });

    it('zeroes that row rather than leaving a fee that will not apply', () => {
        boot([7, 30, 60], '', [7, 30, 60], 0);

        const values = $('.surcharge-grid__row[data-term="30"] .surcharge-grid__input')
            .map(function () { return this.value; })
            .get();

        expect(values).toEqual(['0', '0', '0']);
    });
});
