/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-558. Magento's admin validator does not ignore `:hidden`, so a grid cell
 * hidden as irrelevant still refuses the save. The refusal itself is correct
 * and must survive wherever the cell is on screen.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const PREFIX = 'two_payment_payment_terms_';

/** Verbatim from Magento's admin validation widget (mage/backend/validation.js). */
const ADMIN_IGNORE = ':disabled, .ignore-validate, .no-display.template, '
    + ':disabled input, .ignore-validate input, .no-display.template input, '
    + ':disabled select, .ignore-validate select, .no-display.template select, '
    + ':disabled textarea, .ignore-validate textarea, .no-display.template textarea';

const TERMS = [30, 60];

/** The rules view/adminhtml/templates/system/config/field/surcharge-grid.phtml emits per column. */
const COLUMN_RULES = {
    fixed: '{"validate-zero-or-greater":true,"validate-number-range":"0-100"}',
    percentage: '{"validate-zero-or-greater":true,"validate-number-range":"0-10"}',
    limit: '{"validate-zero-or-greater":true,"validate-two-nonzero-limit":true}'
};

function cell(days, col) {
    return '<td class="surcharge-grid__' + col + '">'
        + '<input type="text" class="input-text surcharge-grid__input"'
        + ' data-column="' + col + '" data-term="' + days + '"'
        + ' id="fld_' + days + '_' + col + '"'
        + " data-validate='" + COLUMN_RULES[col] + "'"
        + ' name="groups[payment_terms][fields][surcharge_' + days + '_' + col + '][value]"'
        + ' value="' + (col === 'limit' && days === 60 ? '0' : '5') + '"/>'
        + '</td>';
}

/** Residue of a save the merchant already watched fail while the cell was visible. */
function markPreviouslyRefused() {
    $('#fld_60_limit')
        .attr('aria-invalid', 'true')
        .attr('aria-describedby', 'fld_60_limit-error')
        .after('<label class="mage-error" id="fld_60_limit-error">not zero</label>');
}

function boot(surchargeType) {
    const options = ['none', 'fixed', 'percentage', 'fixed_and_percentage'].map(function (t) {
        return '<option value="' + t + '"' + (t === surchargeType ? ' selected="selected"' : '') + '>'
            + t + '</option>';
    }).join('');

    document.body.innerHTML =
        '<form id="config-edit-form">'
        + '<input name="form_key" value="k"/>'
        + '<select id="' + PREFIX + 'payment_terms_duration_days">'
        + '  <option value="" data-two-term="0" selected="selected">Remove</option>'
        + '</select>'
        + '<select id="' + PREFIX + 'surcharge_type">' + options + '</select>'
        + '<select id="' + PREFIX + 'surcharge_differential">'
        + '  <option value="0" selected="selected">0</option><option value="1">1</option>'
        + '</select>'
        + '<select id="' + PREFIX + 'default_payment_term">'
        + '  <option value="" selected="selected">Automatic</option>'
        + TERMS.map(function (d) { return '<option value="' + d + '">' + d + '</option>'; }).join('')
        + '</select>'
        + '<div id="' + PREFIX + 'payment_terms_checkboxes" class="two-term-checkboxes"'
        + ' data-merchant-default-term="0">'
        + TERMS.map(function (d) {
            return '<input class="two-term-checkboxes__input" type="checkbox" value="' + d
                + '" checked="checked"/>';
        }).join('')
        + '</div>'
        // The grid field sits in its own admin form row, hidden wholesale once
        // no surcharge applies.
        + '<table><tbody><tr id="row_' + PREFIX + 'surcharge_grid"><td>'
        + '<div id="surcharge-grid-container" data-max-fixed="100" data-max-percentage="10">'
        + '  <p class="surcharge-grid__no-terms"><span></span></p>'
        + '  <table class="admin__table-secondary surcharge-grid"><tbody>'
        + TERMS.map(function (d) {
            return '<tr class="surcharge-grid__row" data-term="' + d + '">'
                + '<td class="surcharge-grid__term"><strong>' + d + '</strong></td>'
                + ['fixed', 'percentage', 'limit'].map(function (c) { return cell(d, c); }).join('')
                + '</tr>';
        }).join('')
        + '  </tbody></table>'
        + '  <p class="surcharge-grid__currency-note note"><span></span></p>'
        + '</div></td></tr></tbody></table>'
        + '</form>';

    markPreviouslyRefused();

    const mocks = defaultMocks();
    $.validator = mocks.jquery.validator;
    $.mage = mocks.jquery.mage;
    mocks.jquery = $;
    loadAmdModule('view/adminhtml/web/js/surcharge-grid.js', mocks)(
        {},
        document.getElementById('surcharge-grid-container')
    );
}

/**
 * The fields Magento's admin validator would validate on submit: the ignore
 * filter from mage/backend/validation.js `elements()`, then the rule-bearing
 * forms this screen uses — `data-validate` and the `validate-*` classes.
 */
function validatedFieldIds() {
    return $('#config-edit-form')
        .find('input, select, textarea')
        .not(':submit, :reset, :image, [disabled]')
        .not(ADMIN_IGNORE)
        .filter('[data-validate], [class*="validate-"], .required-entry')
        .map(function () { return this.id; })
        .get();
}

function selectType(type) {
    $('#' + PREFIX + 'surcharge_type').val(type).trigger('change');
}

describe('a surcharge cap the grid hides does not gate the save', () => {
    it.each([
        ['none', false, false, false, 'no surcharge applies, so no cell can refuse the save'],
        ['fixed', true, false, false, 'only the fixed column is on screen'],
        ['percentage', false, true, true, 'the percentage and its cap are on screen'],
        ['fixed_and_percentage', true, true, true, 'every column is on screen']
    ])(
        'surcharge type %s -> fixed=%s percentage=%s cap=%s — %s',
        (type, fixedIn, percentageIn, capIn) => {
            boot(type);
            const scoped = validatedFieldIds();

            expect(scoped.indexOf('fld_60_fixed') !== -1).toBe(fixedIn);
            expect(scoped.indexOf('fld_60_percentage') !== -1).toBe(percentageIn);
            expect(scoped.indexOf('fld_60_limit') !== -1).toBe(capIn);
        }
    );

    it.each([
        ['none', 'switching to no surcharge'],
        ['fixed', 'switching to a fixed fee']
    ])('%s clears the refusal the merchant can no longer reach — %s', (type) => {
        boot('percentage');
        expect($('#fld_60_limit').attr('aria-invalid')).toBe('true');

        selectType(type);

        expect($('#fld_60_limit').attr('aria-invalid')).toBeUndefined();
        expect($('#fld_60_limit').attr('aria-describedby')).toBeUndefined();
        expect($('#config-edit-form').find('.mage-error').length).toBe(0);
    });

    it('keeps hidden caps posting, so their stored values survive the save', () => {
        boot('none');

        expect($('#fld_60_limit').is(':disabled')).toBe(false);
        expect($('#fld_60_limit').val()).toBe('0');
    });

    it('takes a deselected term out of scope, cap and all', () => {
        boot('percentage');
        expect(validatedFieldIds()).toContain('fld_60_limit');

        $('.two-term-checkboxes__input[value="60"]').prop('checked', false).trigger('change');

        expect(validatedFieldIds()).not.toContain('fld_60_limit');
        expect(validatedFieldIds()).toContain('fld_30_limit');
    });

    it('puts the cap back in scope when the merchant returns to percentage', () => {
        boot('none');
        expect(validatedFieldIds()).not.toContain('fld_60_limit');

        selectType('percentage');

        expect(validatedFieldIds()).toContain('fld_60_limit');
    });
});
