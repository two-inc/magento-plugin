/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-26775: a sole trader's autofill record is written with PrestaShop's
 * rules (`adoptSoleTraderBuyer`), through the real company-search model over
 * real address-form markup:
 *
 *  - a non-blank value overwrites;
 *  - a blank, or a field the record leaves out, clears only a value the plugin
 *    wrote and the buyer has not changed since;
 *  - the region is filled only where it is empty or the plugin's own.
 *
 * A registry pick keeps its own rules, and has a case here so the two cannot
 * drift into one.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const COMPANY_SEARCH = 'view/frontend/web/js/model/company-search.js';
const MARKER = 'data-two-autofilled-value';
const FORM = '#shipping-new-address-form';

/** Kent is option 1, Essex option 2; the placeholder is the empty state. */
function mountForm() {
    document.body.innerHTML =
        '<form id="shipping-new-address-form">' +
        '<input name="street[0]" /><input name="street[1]" />' +
        '<input name="city" /><input name="postcode" />' +
        '<select name="region_id"><option value="">Please select</option>' +
        '<option value="1">Kent</option><option value="2">Essex</option></select>' +
        '<input name="region" />' +
        '</form>';
}

function field(name) {
    return document.querySelector(`${FORM} [name="${name}"]`);
}

/**
 * @param {string} name
 * @param {string} value
 * @param {?string} marker what the plugin recorded writing, or null for a
 *        value the buyer typed
 */
function prime(name, value, marker) {
    field(name).value = value;
    if (marker !== null) field(name).setAttribute(MARKER, marker);
}

function loadModel() {
    return loadAmdModule(
        COMPANY_SEARCH,
        { jquery: $, 'Magento_Checkout/js/model/quote': defaultMocks()['Magento_Checkout/js/model/quote'] },
        { document: document, window: window }
    );
}

beforeEach(() => {
    mountForm();
});

describe('a sole trader\'s record follows PrestaShop\'s rules', () => {
    test.each([
        ['city', 'Leeds', null, { city: 'London' }, 'London', 'a value overwrites one the buyer typed'],
        ['city', 'Leeds', null, { city: '' }, 'Leeds', 'a blank keeps one the buyer typed'],
        ['city', 'Leeds', 'Leeds', { city: '' }, '', 'a blank clears the plugin\'s own unchanged write'],
        ['city', 'Leeds', null, { postal_code: 'X1' }, 'Leeds', 'an omission keeps one the buyer typed'],
        ['city', 'Leeds', 'Leeds', { postal_code: 'X1' }, '', 'an omission clears the plugin\'s own unchanged write'],
        ['city', 'Leeds 2', 'Leeds', { postal_code: 'X1' }, 'Leeds 2', 'an omission keeps a write the buyer has since edited'],
        ['postcode', 'AB1', null, { postal_code: '  ' }, 'AB1', 'whitespace is a blank'],
        ['region_id', '', null, { region: 'Kent' }, '1', 'an empty region is filled'],
        ['region_id', '2', 'Essex', { region: 'Kent' }, '1', 'the plugin\'s own region is replaced'],
        ['region_id', '2', null, { region: 'Kent' }, '2', 'a region the buyer chose stays'],
        ['region_id', '2', 'Kent', { region: 'Kent' }, '2', 'a region the buyer changed from the plugin\'s stays']
    ])('%s %p (marker %p) + %p -> %p (%s)', (name, prior, marker, address, expected, why) => {
        prime(name, prior, marker);

        loadModel().applyAddress(address, $(FORM), {}, { buyerRules: true });

        expect([why, field(name).value]).toEqual([why, expected]);
    });
});

describe('the next sole trader\'s record never keeps the previous one\'s region', () => {
    test.each([
        ['region_id', '1', { region: '' }, 'a blank region'],
        ['region_id', '1', { region: 'Atlantis' }, 'a region that matches no option'],
        ['region_id', '1', { city: 'Leeds' }, 'no region at all'],
        ['region', 'Kent', { region: '' }, 'a blank region, on a country with a free-text region'],
        ['region', 'Kent', { city: 'Leeds' }, 'no region at all, on a country with a free-text region']
    ])('%s holding %p, then %p: cleared (%s)', (name, written, next, why) => {
        // A country with no region list has a select of the placeholder alone,
        // and shows the free-text field, which jsdom lays out as hidden.
        if (name === 'region') {
            field('region_id').innerHTML = '<option value="">Please select</option>';
            field('region').getClientRects = function () { return [{}]; };
        }
        const model = loadModel();
        const identity = {};
        model.applyAddress({ region: 'Kent' }, $(FORM), identity, { buyerRules: true });
        expect(field(name).value).toBe(written);

        model.applyAddress(next, $(FORM), identity, { buyerRules: true });

        expect([why, field('region_id').value, field('region').value]).toEqual([why, '', '']);
    });
});

describe('a registry pick keeps its own rules', () => {
    test.each([
        ['city', 'Leeds', null, { city: '' }, '', 'a blank the registry sends is written'],
        ['region_id', '2', null, { region: 'Kent' }, '1', 'the region is written over the buyer\'s']
    ])('%s %p (marker %p) + %p -> %p (%s)', (name, prior, marker, address, expected, why) => {
        prime(name, prior, marker);

        loadModel().applyAddress(address, $(FORM), {});

        expect([why, field(name).value]).toEqual([why, expected]);
    });
});
