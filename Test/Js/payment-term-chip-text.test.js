/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-554: what a payment-term chip says the term is, and what its accessible
 * name states. An end-of-month term falls due that many days after the end of
 * the month, so a chip reading "30 days" under that setting states the wrong
 * due date; and an `aria-label` replaces the whole accessible name, so the
 * chip's own surcharge stops being announced unless the name carries it.
 *
 * jsdom has no accessibility layer, so the accessible name is asserted as the
 * `aria-label` the template binds; what a screen reader utters is a browser
 * check.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule, defaultMocks, makeObservable } = require('./amd-harness');

const ROOT = path.join(__dirname, '..', '..');
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';

const EOM_30 = 'EOM+30: pay 30 days after the end of the month';
const EOM_30_FEE = 'EOM+30: pay 30 days after the end of the month, plus a €7.25 surcharge';
const TERMS = [14, 30, 60];

/** A `this` carrying only what the label accessors read. */
function ctx(isEndOfMonthTerms) {
    return Object.assign({}, loadAmdModule(RENDERER), {
        isEndOfMonthTerms: isEndOfMonthTerms
    });
}

/** The renderer over a surcharge model whose fee map a spec can write. */
function loadWithFees(isEndOfMonthTerms) {
    // The harness's own observable: the ko double tracks a dependency only on
    // one of its own, so a local stub would make the specs vacuous.
    const fees = makeObservable({});
    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        getPriceFormat: function () {
            return { pattern: '%s' };
        }
    });
    const component = Object.assign(
        loadAmdModule(RENDERER, {
            'Magento_Checkout/js/model/quote': quote,
            'Two_Gateway/js/model/surcharge': {
                selectedTerm: makeObservable(null),
                taxDisplay: makeObservable('excl'),
                currencySymbol: '€',
                selectTerm: function () {},
                isTermReconciled: function () {
                    return true;
                },
                displayedTermSurcharges: function () {
                    return fees();
                }
            }
        }),
        { isEndOfMonthTerms: isEndOfMonthTerms }
    );

    return {
        options: component.buildTermOptions.call(component, TERMS.slice()),
        publish: fees
    };
}

/**
 * A renderer taken through its own `initialize`, which is where the lone chip's
 * visible text is decided. The heavy collaborators are stubbed; the chip label
 * path is real.
 */
function initialised(isEndOfMonthTerms, terms) {
    const quote = Object.assign({}, defaultMocks()['Magento_Checkout/js/model/quote'], {
        getPriceFormat: function () {
            return { pattern: '%s' };
        }
    });
    // A callable carrying getActiveTwoBrandCode: company-capture.js loads for
    // real through the renderer's dep list and reads that member off it.
    const brandConfig = function () {
        return { availableBuyerTerms: terms, isEndOfMonthTerms: isEndOfMonthTerms };
    };
    brandConfig.getActiveTwoBrandCode = function () {
        return null;
    };
    const module = loadAmdModule(RENDERER, {
        'Magento_Checkout/js/model/quote': quote,
        'Two_Gateway/js/model/brand-config': brandConfig
    });
    const component = Object.assign(Object.create(module), {
        _super: function () {},
        getCode: function () {
            return 'two_payment';
        },
        initOrderIntentApprovedNotice: function () {},
        fillCustomerData: function () {},
        configureFormValidation: function () {},
        showWhatIsTwo: makeObservable(false)
    });
    module.initialize.call(component);

    return component;
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
        {
            eom: true,
            fee: '€7.25',
            explanation: EOM_30_FEE,
            case: 'a priced term states the fee, which the label would otherwise silence'
        },
        {
            eom: true,
            fee: '',
            explanation: EOM_30,
            case: 'a term carrying no fee states no amount'
        },
        {
            eom: true,
            fee: null,
            explanation: EOM_30,
            case: 'a quote still in flight states no amount either'
        },
        {
            eom: false,
            fee: '€7.25',
            explanation: '',
            case: 'a standard term is left unnamed whatever it costs'
        }
    ])('the accessible name folds in the surcharge: $case', ({ eom, fee, explanation }) => {
        expect(ctx(eom).termChipExplanation(30, fee)).toBe(explanation);
    });

    test.each([
        { days: 30, fee: undefined, case: 'unpriced' },
        { days: 30, fee: '€7.25', case: 'priced' },
        { days: 1, fee: '€0.10', case: 'the shortest term' },
        { days: 120, fee: '€1,000.00', case: 'a three-digit term at a four-figure fee' }
    ])('the accessible name contains the visible text: $case', ({ days, fee }) => {
        // WCAG 2.5.3 Label in Name.
        expect(ctx(true).termChipExplanation(days, fee)).toContain(ctx(true).termChipText(days));
        if (fee) {
            expect(ctx(true).termChipExplanation(days, fee)).toContain(fee);
        }
    });

    test.each([
        {
            eom: false,
            days: 30,
            text: '30 days',
            explanation: '',
            case: 'a standard term states the days and nothing else'
        },
        {
            eom: false,
            days: 1,
            text: '1 days',
            explanation: '',
            case: 'so does the shortest one'
        },
        {
            eom: true,
            days: 30,
            text: 'EOM+30',
            explanation: EOM_30_FEE,
            case: 'an end-of-month term carries the explanation and the fee'
        },
        {
            eom: true,
            days: 120,
            text: 'EOM+120',
            explanation: EOM_30_FEE,
            case: 'and a three-digit one'
        }
    ])(
        'the sole offered term chip reads exactly as the same term does in a row: $case',
        ({ eom, days, text, explanation }) => {
            // The prefix this replaces ("Payment Terms 30 days") was the chip
            // naming its own group; the group heading now does that.
            expect(initialised(eom, [days]).singleTermLabel).toBe(text);
            expect(initialised(eom, [days, 999]).singleTermLabel).toBe('');
            expect(initialised(eom, [days]).singleTermLabel).toBe(ctx(eom).termChipText(days));
            expect(ctx(eom).termChipExplanation(30, '€7.25')).toBe(explanation);
        }
    );

    test.each([
        { terms: [], chips: false, selector: false, single: false, case: 'no offered term' },
        { terms: [30], chips: true, selector: false, single: true, case: 'one' },
        { terms: [14, 30], chips: true, selector: true, single: false, case: 'several' }
    ])(
        'the heading wrapper is on whenever any chip is: $case',
        ({ terms, chips, selector, single }) => {
            const component = initialised(false, terms);

            expect(component.showTermChips).toBe(chips);
            expect(component.showTermSelector).toBe(selector);
            expect(component.showSingleTerm).toBe(single);
        }
    );

    test('every chip gets its own text and its own explanation', () => {
        const standard = loadWithFees(false);
        const endOfMonth = loadWithFees(true);
        endOfMonth.publish({ 14: 1, 30: 7.25, 60: 0 });

        expect(standard.options.map((option) => option.daysLabel)).toEqual([
            '14 days',
            '30 days',
            '60 days'
        ]);
        expect(standard.options.map((option) => option.explanation())).toEqual(['', '', '']);
        expect(endOfMonth.options.map((option) => option.daysLabel)).toEqual([
            'EOM+14',
            'EOM+30',
            'EOM+60'
        ]);
        expect(endOfMonth.options.map((option) => option.explanation())).toEqual([
            'EOM+14: pay 14 days after the end of the month, plus a 1 surcharge',
            'EOM+30: pay 30 days after the end of the month, plus a 7.25 surcharge',
            'EOM+60: pay 60 days after the end of the month, plus a 0 surcharge'
        ]);
    });

    test('the accessible name picks the fee up when the quote lands', () => {
        const { options, publish } = loadWithFees(true);

        expect(options.map((option) => option.explanation())).toEqual([
            'EOM+14: pay 14 days after the end of the month',
            EOM_30,
            'EOM+60: pay 60 days after the end of the month'
        ]);

        publish({ 14: 1, 30: 7.25, 60: 3 });

        expect(options[1].explanation()).toBe(
            'EOM+30: pay 30 days after the end of the month, plus a 7.25 surcharge'
        );
        expect(options[1].surchargeLabel()).toBe('+7.25');

        // Every term ~zero shows no fee on any chip, so none is claimed either.
        publish({ 14: 0, 30: 0, 60: 0 });

        expect(options[1].explanation()).toBe(EOM_30);
        expect(options[1].surchargeLabel()).toBe('');
    });

    test.each([
        {
            pattern:
                /this\.singleTermLabel = terms\.length === 1 \? this\.termChipText\(terms\[0\]\)/,
            case: 'its text, from the accessor every chip reads'
        },
        {
            pattern: /self\.termChipExplanation\(terms\[0\], self\.singleTermSurchargeAmount\(\)\)/,
            case: 'its explanation, over the same fee the chip displays'
        }
    ])('the sole-term chip reads the shared accessors: $case', ({ pattern }) => {
        expect(fs.readFileSync(path.join(ROOT, RENDERER), 'utf8')).toMatch(pattern);
    });

    test.each([
        { pattern: "'aria-label': explanation() || false", case: 'the chip name' },
        { pattern: 'title: explanation() || false', case: 'the chip tooltip' },
        { pattern: "'aria-label': singleTermExplanation() || false", case: 'the sole chip name' },
        { pattern: 'title: singleTermExplanation() || false', case: 'the sole chip tooltip' }
    ])(
        'an empty explanation reaches the binding as false, not as a blank string: $case',
        ({ pattern }) => {
            // knockout removes an attribute bound to false and renders one bound
            // to ''; an unwrapped computed is always truthy, so the binding calls it.
            expect(fs.readFileSync(path.join(ROOT, TEMPLATE), 'utf8')).toContain(pattern);
        }
    );

    test.each([
        {
            pattern: /<!-- ko if: showTermChips -->/,
            case: 'one wrapper over both branches, so the heading cannot be in only one'
        },
        {
            pattern:
                /<span class="label" data-bind="attr: \{id: termGroupLabelId\(\)\}">[\s\S]*?<!-- ko if: showTermSelector -->/,
            case: 'the heading ahead of the multi-term branch, not inside it'
        },
        {
            pattern:
                /<!-- ko if: showSingleTerm -->\s*<div\s+class="two-term-chips__container"\s+role="group"\s+data-bind="attr: \{'aria-labelledby': termGroupLabelId\(\)\}"/,
            case: 'the lone chip in a group named by that same heading'
        }
    ])('the chip group is named whether one term is offered or several: $case', ({ pattern }) => {
        // The chip text states only the term, so nothing else names the group.
        expect(fs.readFileSync(path.join(ROOT, TEMPLATE), 'utf8')).toMatch(pattern);
    });

    test.each([
        {
            pattern:
                /<button\s+type="button"\s+class="two-term-chip two-term-chip--single"\s+disabled/,
            case: 'a disabled button, so its name is exposed and Tab skips it'
        },
        {
            pattern: /class="two-term-chip two-term-chip--single"/,
            case: 'still carrying the sole-chip styling hook'
        }
    ])('the sole offered term renders as: $case', ({ pattern }) => {
        // ARIA prohibits naming role=generic, which is what a bare span is.
        expect(fs.readFileSync(path.join(ROOT, TEMPLATE), 'utf8')).toMatch(pattern);
    });
});
