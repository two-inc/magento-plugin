/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Tests for the client-side minimum-order visibility test used by the Two
 * payment renderer (view/frontend/web/js/model/minimum-order-visibility.js).
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(
    path.resolve(__dirname, '../../view/frontend/web/js/model/minimum-order-visibility.js'),
    'utf8'
);

function load() {
    let factory;
    vm.runInNewContext(SRC, { define: (deps, fn) => { factory = fn; } });

    return factory();
}

const isAboveMinimums = load();

describe('Two_Gateway/js/model/minimum-order-visibility', () => {
    it('is visible when there are no minimums', () => {
        expect(isAboveMinimums({ grand_total: '10' }, [])).toBe(true);
        expect(isAboveMinimums({ grand_total: '10' }, null)).toBe(true);
    });

    it('is visible when totals are absent (never hide on missing data)', () => {
        expect(isAboveMinimums(null, [{ amount: 250, basis: 'gross' }])).toBe(true);
    });

    it('gross basis compares the grand total', () => {
        const min = [{ amount: 250, basis: 'gross' }];
        expect(isAboveMinimums({ grand_total: '273.00', tax_amount: '45' }, min)).toBe(true);
        expect(isAboveMinimums({ grand_total: '238.00', tax_amount: '39' }, min)).toBe(false);
    });

    it('net basis compares grand total minus tax', () => {
        const min = [{ amount: 250, basis: 'net' }];
        // 302.50 gross − 52.50 tax = 250.00 net → satisfied
        expect(isAboveMinimums({ grand_total: '302.50', tax_amount: '52.50' }, min)).toBe(true);
        // 273 gross − 45 tax = 228 net → below
        expect(isAboveMinimums({ grand_total: '273.00', tax_amount: '45.00' }, min)).toBe(false);
    });

    it('requires EVERY minimum to be satisfied', () => {
        const mins = [{ amount: 250, basis: 'gross' }, { amount: 300, basis: 'gross' }];
        expect(isAboveMinimums({ grand_total: '320' }, mins)).toBe(true);
        expect(isAboveMinimums({ grand_total: '273' }, mins)).toBe(false); // clears 250, fails 300
    });

    it('treats the boundary as satisfied (>=, currency-precision epsilon)', () => {
        const min = [{ amount: 250, basis: 'gross' }];
        expect(isAboveMinimums({ grand_total: '250.00' }, min)).toBe(true);
        expect(isAboveMinimums({ grand_total: '249.99' }, min)).toBe(false);
    });

    it('handles missing tax on a net basis as zero tax', () => {
        const min = [{ amount: 250, basis: 'net' }];
        expect(isAboveMinimums({ grand_total: '250.00' }, min)).toBe(true);
    });
});
