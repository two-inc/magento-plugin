/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-522. The deprecated custom-term row hides itself only on the server-emitted fold-in
 * marker. It must never re-derive that from the value: the gate, the renderer and the save all
 * read one normalisation server-side, and a second reading in the browser is what previously
 * showed a value the save was deleting.
 *
 * The row is hidden, NOT removed — it still posts, which is what lets the fold-in save happen.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const SECTION = 'two_payment';
const PREFIX = SECTION + '_payment_terms_';
const CUSTOM_ROW = '#row_' + PREFIX + 'payment_terms_duration_days';

function buildForm(storedValue, foldsIn) {
    document.body.innerHTML =
        '<table><tbody>' +
        '<tr><td><div class="two-term-checkboxes" id="' + PREFIX + 'payment_terms_checkboxes">' +
        '<input class="two-term-checkboxes__input" type="checkbox" value="14" checked />' +
        '<input class="two-term-checkboxes__input" type="checkbox" value="30" />' +
        '</div></td></tr>' +
        '<tr id="row_' + PREFIX + 'payment_terms_duration_days"><td>' +
        '<select id="' + PREFIX + 'payment_terms_duration_days">' +
        '<option value="' + storedValue + '" selected="selected">keep</option>' +
        '<option value="">Remove</option>' +
        '</select>' +
        (foldsIn ? '<span class="two-legacy-term-folds-in" hidden="hidden"></span>' : '') +
        '</td></tr>' +
        '<tr><td><select id="' + PREFIX + 'default_payment_term"></select></td></tr>' +
        '<tr><td><select id="' + PREFIX + 'surcharge_type"><option value="none" selected>none</option></select></td></tr>' +
        '<tr><td><select id="' + PREFIX + 'surcharge_differential"><option value="0" selected>0</option></select></td></tr>' +
        '</tbody></table>';
}

function initWith(storedValue, foldsIn) {
    buildForm(storedValue, foldsIn);
    const mocks = defaultMocks();
    mocks.jquery = $;
    loadAmdModule('view/adminhtml/web/js/payment-terms-config.js', mocks).init();

    return $(CUSTOM_ROW);
}

describe('deprecated custom-term row visibility', () => {
    it.each([
        ['30', true, true, 'the marker hides the row the save will fold in'],
        ['37', false, false, 'no marker leaves a genuinely custom term visible'],
        ['abc', false, false, 'an unusable value stays visible so it can be removed'],
        ['30', false, false, 'a value that looks foldable is still shown without the marker']
    ])('value %s, marker %s -> hidden=%s — %s', (storedValue, foldsIn, expectedHidden) => {
        expect(initWith(storedValue, foldsIn).css('display') === 'none').toBe(expectedHidden);
    });

    it('keeps the hidden row in the form so its value still posts', () => {
        const $row = initWith('30', true);

        expect($row.find('select#' + PREFIX + 'payment_terms_duration_days').length).toBe(1);
        expect($row.find('select').val()).toBe('30');
    });
});
