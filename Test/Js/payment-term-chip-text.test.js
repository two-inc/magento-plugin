/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-554: what a payment-term chip says the term is. An end-of-month term
 * falls due that many days after the end of the month, so a chip reading
 * "30 days" under that setting states the wrong due date.
 *
 * jsdom has no accessibility layer, so the accessible name is asserted as the
 * `aria-label` the template binds; what a screen reader utters is a browser
 * check.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const ROOT = path.join(__dirname, '..', '..');
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';

const EOM_30 = 'EOM+30: pay 30 days after the end of the month';

/** A `this` carrying only what the label accessors read. */
function ctx(isEndOfMonthTerms) {
    return Object.assign({}, loadAmdModule(RENDERER), {
        isEndOfMonthTerms: isEndOfMonthTerms
    });
}

describe('payment-term chip text', () => {
    test.each([
        {
            eom: false,
            days: 30,
            text: '30 days',
            explanation: '',
            case: 'a standard term states the days from invoice'
        },
        {
            eom: false,
            days: 90,
            text: '90 days',
            explanation: '',
            case: 'a long standard term needs no explanation either'
        },
        {
            eom: true,
            days: 30,
            text: 'EOM+30',
            explanation: EOM_30,
            case: 'an end-of-month term names the month end'
        },
        {
            eom: true,
            days: 1,
            text: 'EOM+1',
            explanation: 'EOM+1: pay 1 days after the end of the month',
            case: 'so does the shortest one'
        },
        {
            eom: true,
            days: 120,
            text: 'EOM+120',
            explanation: 'EOM+120: pay 120 days after the end of the month',
            case: 'and a three-digit one'
        }
    ])('the chip states the term: $case', ({ eom, days, text, explanation }) => {
        expect(ctx(eom).termChipText(days)).toBe(text);
        expect(ctx(eom).termChipExplanation(days)).toBe(explanation);
    });

    test.each([
        { days: 30, case: 'the term the default configuration offers' },
        { days: 1, case: 'the shortest term' },
        { days: 120, case: 'a three-digit term' }
    ])('the accessible name contains the visible text: $case', ({ days }) => {
        // WCAG 2.5.3 Label in Name.
        expect(ctx(true).termChipExplanation(days)).toContain(ctx(true).termChipText(days));
    });

    test.each([
        {
            eom: false,
            text: 'Payment Terms 30 days',
            explanation: '',
            case: 'the sole standard term names itself, with nothing to explain'
        },
        {
            eom: true,
            text: 'Payment Terms EOM+30',
            explanation: EOM_30,
            case: 'the sole end-of-month term carries the explanation too'
        }
    ])('a single offered term: $case', ({ eom, text, explanation }) => {
        expect(ctx(eom).singleTermText(30)).toBe(text);
        expect(ctx(eom).termChipExplanation(30)).toBe(explanation);
    });

    test('every chip gets its own text and its own explanation', () => {
        const standard = loadAmdModule(RENDERER).buildTermOptions.call(ctx(false), [14, 30]);
        const endOfMonth = loadAmdModule(RENDERER).buildTermOptions.call(ctx(true), [14, 30]);

        expect(standard.map((option) => option.daysLabel)).toEqual(['14 days', '30 days']);
        expect(standard.map((option) => option.explanation)).toEqual(['', '']);
        expect(endOfMonth.map((option) => option.daysLabel)).toEqual(['EOM+14', 'EOM+30']);
        expect(endOfMonth.map((option) => option.explanation)).toEqual([
            'EOM+14: pay 14 days after the end of the month',
            EOM_30
        ]);
    });

    test.each([
        {
            pattern:
                /this\.singleTermLabel = terms\.length === 1 \? this\.singleTermText\(terms\[0\]\)/,
            case: 'its text'
        },
        {
            pattern:
                /this\.singleTermExplanation =\s+terms\.length === 1 \? this\.termChipExplanation\(terms\[0\]\)/,
            case: 'its explanation'
        }
    ])('the sole-term chip reads the shared accessors: $case', ({ pattern }) => {
        expect(fs.readFileSync(path.join(ROOT, RENDERER), 'utf8')).toMatch(pattern);
    });

    test.each([
        { pattern: "'aria-label': explanation || false", case: 'the chip name' },
        { pattern: 'title: explanation || false', case: 'the chip tooltip' },
        { pattern: "'aria-label': singleTermExplanation || false", case: 'the sole chip name' },
        { pattern: 'title: singleTermExplanation || false', case: 'the sole chip tooltip' }
    ])(
        'an empty explanation reaches the binding as false, not as a blank string: $case',
        ({ pattern }) => {
            // knockout removes an attribute bound to false and renders one bound to ''.
            expect(fs.readFileSync(path.join(ROOT, TEMPLATE), 'utf8')).toContain(pattern);
        }
    );
});
