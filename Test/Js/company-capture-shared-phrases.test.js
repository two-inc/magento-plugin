/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-27214: the shared company panel keeps its original "unavailable" wording,
 * and the Luma host maps it, through the `translate()` it hands every panel, to
 * the standard sentence every checkout shows. Every other phrase maps to its own
 * literal. The translator double tags what it was asked for, so each assertion
 * names the catalogue key the buyer's language is looked up by.
 */

'use strict';

const { loadCompanyCapture } = require('./amd-harness');

/** The options each capture component was constructed with. */
function hostTranslate() {
    const constructed = [];
    // Inert apart from handing back the identity it was given: the host wires
    // its panels up at load, and only the options matter here.
    function ComponentDouble(options) {
        constructed.push(options);
        return new Proxy({}, {
            get: function (_target, name) {
                return name === 'identity' ? function () { return options.identity; } : function () {};
            }
        });
    }

    loadCompanyCapture({
        'mage/translate': function (s) { return '[' + s + ']'; },
        'Two_Gateway/js/model/company-capture-component': ComponentDouble
    });

    expect(constructed.length).toBeGreaterThan(0);
    return constructed[0].translate;
}

describe('the phrases the Luma host hands the shared panel (TWO-27214)', () => {
    test.each([
        [
            'Company search is unavailable right now. Please try again shortly.',
            '[Company search is temporarily unavailable. Please try again.]',
            'the unavailable line is the standard sentence'
        ],
        ['No matches found', '[No matches found]', 'a phrase outside the shared map is its own literal'],
        ['Registered company', '[Registered company]', 'a phrase in the shared map is its own literal']
    ])('%s', (phrase, expected, description) => {
        expect({ description, text: hostTranslate()(phrase) }).toEqual({ description, text: expected });
    });
});
