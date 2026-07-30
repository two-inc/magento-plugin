/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The browser-side refusal of a zero surcharge limit (TWO-25289).
 *
 * The backend is the authority; this rule only saves the admin a round trip.
 * It is registered rather than reusing Magento's own
 * validate-greater-than-zero because that rule is locale-blind — the comma
 * cases below are exactly why.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

function loadRule() {
    const mocks = defaultMocks();
    loadAmdModule('view/adminhtml/web/js/surcharge-grid.js', mocks);

    return {
        rule: mocks.jquery.validator.methods['validate-two-nonzero-limit'],
        message: mocks.jquery.validator.messages['validate-two-nonzero-limit']
    };
}

describe('validate-two-nonzero-limit', () => {
    const { rule, message } = loadRule();

    it('is registered by loading the module', () => {
        expect(typeof rule).toBe('function');
    });

    it('refuses a zero limit however it is typed', () => {
        ['0', '0.0', '0.00', '00', '0,00'].forEach((v) => {
            expect(rule(v)).toBe(false);
        });
    });

    it('refuses a sub-cent limit, which is sent as 0.00 and suppresses the whole fee', () => {
        ['0.001', '0.004', '0,004'].forEach((v) => {
            expect(rule(v)).toBe(false);
        });
    });

    it('accepts an empty limit, which means "no limit"', () => {
        ['', '   ', undefined, null].forEach((v) => {
            expect(rule(v)).toBe(true);
        });
    });

    it('accepts a comma decimal, which a locale-blind rule would reject', () => {
        // parseFloat('0,5') is 0 — the whole reason this rule exists rather
        // than Magento's validate-greater-than-zero.
        expect(rule('0,5')).toBe(true);
        expect(rule('0.5')).toBe(true);
        expect(rule('0,005')).toBe(true);
    });

    it('leaves non-numeric input to validate-zero-or-greater', () => {
        expect(rule('abc')).toBe(true);
    });

    it('resolves its message lazily, so it is not baked in before translation loads', () => {
        expect(typeof message).toBe('function');
        expect(message()).toContain('A limit of 0 is not allowed');
    });
});
