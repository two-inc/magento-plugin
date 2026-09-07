/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Hidden unless configured or foldable; invalid must stay visible so validate-digits fires.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

const OFFERED = [7, 14, 15, 20, 21, 30, 45, 60, 90];

function loadPredicate() {
    const mocks = defaultMocks();
    return loadAmdModule('view/adminhtml/web/js/payment-terms-config.js', mocks).shouldHideCustomDays;
}

describe('shouldHideCustomDays', () => {
    const shouldHideCustomDays = loadPredicate();

    it.each([
        ['', true, 'nothing stored, so there is no legacy value to show'],
        ['   ', true, 'whitespace is nothing stored'],
        [null, true, 'an absent value is nothing stored'],
        [undefined, true, 'an absent value is nothing stored'],
        ['30', true, 'folds into an offered term the save will tick'],
        ['7', true, 'the shortest offered term folds in too'],
        ['45', true, 'offered but unticked still folds in (TWO-25498)'],
        ['37', false, 'a genuine custom term the account does not offer'],
        ['abc', false, 'unparseable, so validate-digits must be able to fire'],
        ['30abc', false, 'parses to an offered term but is not one'],
        ['3.5', false, 'not a whole number'],
        ['-5', false, 'negative'],
        ['0', false, 'zero is not a usable term']
    ])('%s hides=%s — %s', (value, expected, description) => {
        expect(shouldHideCustomDays(value, OFFERED)).toBe(expected);
    });

    it('shows a value when the account offers no terms at all', () => {
        expect(shouldHideCustomDays('30', [])).toBe(false);
    });
});
