/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-522. The deprecated custom-term row hides on the server-emitted fold-in marker alone, and
 * the term it contributes comes from the server-emitted data-two-term, never from the raw value.
 *
 * The row is hidden, NOT removed: it still posts, which is what lets the fold-in save happen.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const SECTION = 'two_payment';
const PREFIX = SECTION + '_payment_terms_';
const CUSTOM_ROW = '#row_' + PREFIX + 'payment_terms_duration_days';

function buildForm(storedValue, foldsIn, term) {
    document.body.innerHTML =
        '<table><tbody>' +
        '<tr><td><div class="two-term-checkboxes" id="' + PREFIX + 'payment_terms_checkboxes">' +
        '<input class="two-term-checkboxes__input" type="checkbox" value="14" checked />' +
        '<input class="two-term-checkboxes__input" type="checkbox" value="30" />' +
        '</div></td></tr>' +
        '<tr id="row_' + PREFIX + 'payment_terms_duration_days"><td>' +
        '<select id="' + PREFIX + 'payment_terms_duration_days">' +
        '<option value="' + storedValue + '" data-two-term="' + (term === undefined ? 0 : term) +
        '" selected="selected">keep</option>' +
        '<option value="" data-two-term="0">Remove</option>' +
        '</select>' +
        (foldsIn ? '<span class="two-legacy-term-folds-in" hidden="hidden"></span>' : '') +
        '</td></tr>' +
        '<tr><td><select id="' + PREFIX + 'default_payment_term"></select></td></tr>' +
        '<tr><td><select id="' + PREFIX + 'surcharge_type"><option value="none" selected>none</option></select></td></tr>' +
        '<tr><td><select id="' + PREFIX + 'surcharge_differential"><option value="0" selected>0</option></select></td></tr>' +
        '</tbody></table>';
}

function initWith(storedValue, foldsIn, term) {
    buildForm(storedValue, foldsIn, term);
    const mocks = defaultMocks();
    mocks.jquery = $;
    loadAmdModule('view/adminhtml/web/js/payment-terms-config.js', mocks).init();

    return $(CUSTOM_ROW);
}

/** Terms the default-payment-term dropdown was rebuilt from, i.e. what the module read. */
function offeredTermsInDropdown() {
    return $('#' + PREFIX + 'default_payment_term option').map(function () {
        return Number(this.value);
    }).get();
}

describe('deprecated custom-term row visibility', () => {
    it.each([
        ['30', true, 30, true, 'the marker hides the row the save will fold in'],
        ['37', false, 37, false, 'no marker leaves a genuinely custom term visible'],
        ['abc', false, 0, false, 'an unusable value stays visible so it can be removed'],
        ['30', false, 30, false, 'a value that looks foldable is still shown without the marker']
    ])('value %s, marker %s -> hidden=%s — %s', (storedValue, foldsIn, term, expectedHidden) => {
        expect(initWith(storedValue, foldsIn, term).css('display') === 'none').toBe(expectedHidden);
    });

    it('keeps the hidden row in the form so its value still posts', () => {
        const $row = initWith('30', true, 30);

        expect($row.find('select#' + PREFIX + 'payment_terms_duration_days').length).toBe(1);
        expect($row.find('select').val()).toBe('30');
    });
});

describe('the term the custom-days row contributes', () => {
    it.each([
        ['30', 30, [14, 30], 'a plain term joins the ticked 14'],
        ['030', 30, [14, 30], 'a leading-zero value contributes the normalised term'],
        ['1e2', 0, [14], 'an unusable value contributes nothing, where parseInt would read 1'],
        ['30.0', 0, [14], 'a decimal contributes nothing, where parseInt would read 30'],
        ['abc', 0, [14], 'junk contributes nothing']
    ])('value %s, data-two-term %s -> %s — %s', (storedValue, term, expected) => {
        initWith(storedValue, false, term);

        expect(offeredTermsInDropdown()).toEqual(expected);
    });

    it('drops the term when the merchant selects Remove', () => {
        initWith('30', false, 30);
        expect(offeredTermsInDropdown()).toEqual([14, 30]);

        $('#' + PREFIX + 'payment_terms_duration_days').val('').trigger('change');

        expect(offeredTermsInDropdown()).toEqual([14]);
    });
});
