/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-26153: "Tax codes for 0% lines" posts as one hidden JSON field, rebuilt
 * from the dropdowns, so a large rate table cannot exceed max_input_vars.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

function render(carried) {
    document.body.innerHTML = `
        <div id="map_rows"><table>
            <tr><td><select data-key="5|exempt"><option value="">(none)</option>
                <option value="ES_IVA_INTRA_COMMUNITY" selected>x</option></select></td></tr>
            <tr><td><select data-key="5|rate:ES CANARIAS [0]"><option value="" selected>(none)</option>
                <option value="ES_IVA_EXPORT">x</option></select></td></tr>
        </table></div>
        <input type="hidden" id="map" value="{}" data-carried='${carried}'/>`;
    loadAmdModule('view/adminhtml/web/js/tax-code-map.js')({ input: 'map' }, document.getElementById('map_rows'));

    return document.getElementById('map');
}

describe('tax-code-map', () => {
    it.each([
        ['{}', { '5|exempt': 'ES_IVA_INTRA_COMMUNITY' }, 'the dropdowns are written on load; (none) is left out'],
        ['{"7|rate:GONE":"ES_IVA_EXPORT"}', { '7|rate:GONE': 'ES_IVA_EXPORT', '5|exempt': 'ES_IVA_INTRA_COMMUNITY' }, 'rows the form does not show are kept'],
    ])('carried %s', (carried, expected, description) => {
        expect(JSON.parse(render(carried).value)).toEqual(expected);
    });

    it('rewrites the field when a dropdown changes, whatever characters the rate code holds', () => {
        const input = render('{}');
        const select = document.querySelector('select[data-key="5|rate:ES CANARIAS [0]"]');
        select.value = 'ES_IVA_EXPORT';
        select.dispatchEvent(new Event('change', { bubbles: true }));

        expect(JSON.parse(input.value)).toEqual({
            '5|exempt': 'ES_IVA_INTRA_COMMUNITY',
            '5|rate:ES CANARIAS [0]': 'ES_IVA_EXPORT'
        });
    });
});
