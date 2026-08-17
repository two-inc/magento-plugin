/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §2.6: where each part of an external address payload lands in the
 * checkout address form.
 *
 * `applyAddress()` used to write three fields — city, postcode and the FIRST
 * street line, taking the street line from `street_address` alone. Two payload
 * shapes reach it: a registered-company detail response, and (since the
 * sole-trader write-back) an autofill buyer record, which spells the street
 * `street` and can carry a `building`/`apartment` and a `region` beside it.
 * Everything but the street was silently discarded.
 *
 * The rules, and the reasoning is in the guide rather than invented here:
 *
 *  - a building/apartment is the more specific locator and takes LINE 1, with
 *    the street moving to line 2; with neither, the street takes line 1 and
 *    line 2 is left ALONE rather than blanked;
 *  - no de-duplication between the lines even when identical — real addresses
 *    repeat, and suppressing the second line discards registry data;
 *  - a region goes to the region control when the form has a usable one
 *    (best-effort text match, inherently lossy), and otherwise onto the city
 *    with a comma, which is lossy but visible and correctable.
 *
 * The DOM here is REAL jsdom markup, not a recorder: the field routing is
 * expressed as selectors and as an option-label match, so a double that
 * answered whatever it was told would pin nothing. The jQuery shim implements
 * only what the module under test calls.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const SEARCH = 'view/frontend/web/js/model/company-search.js';
const MARKER = 'data-two-autofilled-value';

/**
 * jQuery-shaped selector function over the jsdom document.
 *
 * Hand-rolled for the same reason every sibling spec does it: jQuery is not a
 * devDependency of this module's JS test manifest.
 *
 * @returns {Function} jQuery double, with `.triggered` recording events
 */
function makeDollar() {
    const triggered = [];

    function isVisible(el) {
        let node = el;
        while (node && node.style) {
            if (node.hidden || node.style.display === 'none') return false;
            node = node.parentElement;
        }
        return true;
    }

    function wrap(nodes) {
        const set = {
            length: nodes.length,
            0: nodes[0],
            val: function (next) {
                if (!arguments.length) return nodes.length ? nodes[0].value : undefined;
                nodes.forEach(function (n) {
                    n.value = next;
                });
                return set;
            },
            attr: function (name, next) {
                if (arguments.length < 2) {
                    if (!nodes.length || !nodes[0].hasAttribute(name)) return undefined;
                    return nodes[0].getAttribute(name);
                }
                nodes.forEach(function (n) {
                    n.setAttribute(name, next);
                });
                return set;
            },
            removeAttr: function (name) {
                nodes.forEach(function (n) {
                    n.removeAttribute(name);
                });
                return set;
            },
            trigger: function (event) {
                nodes.forEach(function (n) {
                    triggered.push([n.getAttribute('name'), event]);
                });
                return set;
            },
            is: function (selector) {
                if (selector !== ':visible') throw new Error(`unsupported: ${selector}`);
                return nodes.some(isVisible);
            },
            first: function () {
                return wrap(nodes.slice(0, 1));
            }
        };
        return set;
    }

    function $(selector) {
        if (typeof selector !== 'string') return wrap([]);
        return wrap(Array.prototype.slice.call(document.querySelectorAll(selector)));
    }
    $.triggered = triggered;
    $.ajax = function () {
        return {
            done: function () {
                return this;
            },
            fail: function () {
                return this;
            },
            always: function () {
                return this;
            }
        };
    };
    return $;
}

const ADDRESS_FIELDS =
    '<input name="city" value="" />' +
    '<input name="postcode" value="" />' +
    '<input name="street[0]" value="" />' +
    '<input name="street[1]" value="" />';

/**
 * Region controls, in the three shapes a Magento address form presents.
 *
 * `select` is the country-with-regions shape (Magento renders the region code
 * in each option's `title` and the name as its text). `text` is the
 * country-without-regions shape. `both` is what the checkout actually renders
 * for a country WITH regions — the free-text input is still in the DOM,
 * hidden — which is why the select is tried first.
 */
const REGION_MARKUP = {
    none: '',
    select:
        '<select name="region_id">' +
        '  <option value=""></option>' +
        '  <option value="12" title="CA">California</option>' +
        '  <option value="43" title="NY">New York</option>' +
        '</select>',
    text: '<input name="region" value="" />',
    both:
        '<select name="region_id">' +
        '  <option value=""></option>' +
        '  <option value="12" title="CA">California</option>' +
        '</select>' +
        '<input name="region" value="" style="display:none" />'
};

/**
 * Build the form and load the REAL shared model against it.
 *
 * @param {string} [regionShape] key of REGION_MARKUP, default 'none'
 * @returns {object} { model, $, field }
 */
function load(regionShape) {
    document.body.innerHTML =
        '<form id="shipping-new-address-form">' +
        ADDRESS_FIELDS +
        REGION_MARKUP[regionShape || 'none'] +
        '</form>';
    const $ = makeDollar();
    return {
        model: loadAmdModule(SEARCH, { jquery: $ }),
        $: $,
        /**
         * @param {string} name field name
         * @returns {?Element}
         */
        field: function (name) {
            return document.querySelector(`[name="${name}"]`);
        }
    };
}

describe('street parts route onto the two address lines', () => {
    test.each([
        [
            { street: 'Mill Lane', building: 'Mill House' },
            'Mill House',
            'Mill Lane',
            'a building takes line 1 and moves the street to line 2'
        ],
        [
            { street: 'Mill Lane', building: 'Mill House', apartment: 'Apartment 4' },
            'Apartment 4, Mill House',
            'Mill Lane',
            'apartment and building join most-specific-first'
        ],
        [
            { street: 'Mill Lane', apartment: 'Apartment 4' },
            'Apartment 4',
            'Mill Lane',
            'an apartment alone is still the locator'
        ],
        [
            { street: 'Mill Lane' },
            'Mill Lane',
            null,
            'no locator: the street takes line 1 and line 2 is untouched'
        ],
        [
            { street_address: '1 Example Street' },
            '1 Example Street',
            null,
            "the company-detail response's own street spelling still routes"
        ],
        [
            { street: 'Mill House', building: 'Mill House' },
            'Mill House',
            'Mill House',
            'identical lines are BOTH written — no de-duplication'
        ],
        [
            { street: '  Mill Lane  ', building: '  Mill House  ' },
            'Mill House',
            'Mill Lane',
            'both parts are trimmed'
        ],
        [
            { street: '', building: 'Mill House' },
            'Mill House',
            '',
            'an empty street beside a building still records line 2 as empty'
        ],
        [
            { building: '', apartment: '', street: 'Mill Lane' },
            'Mill Lane',
            null,
            'empty locator parts are not a locator'
        ]
    ])('%p -> line1=%p line2=%p (%s)', (address, line1, line2) => {
        const { model, field } = load();

        model.applyAddress(address);

        expect(field('street[0]').value).toBe(line1);
        expect(field('street[0]').getAttribute(MARKER)).toBe(line1);
        if (line2 === null) {
            // UNTOUCHED, not blanked: a payload saying nothing about a second
            // line must not delete one the buyer typed — and with no marker on
            // it, the revert leaves it alone too.
            expect(field('street[1]').value).toBe('');
            expect(field('street[1]').hasAttribute(MARKER)).toBe(false);
        } else {
            expect(field('street[1]').value).toBe(line2);
            expect(field('street[1]').getAttribute(MARKER)).toBe(line2);
        }
    });
});

describe('a region lands wherever the address format can hold it', () => {
    test.each([
        [
            'select',
            'California',
            { region_id: '12', city: 'Los Angeles' },
            'a matching option name writes the region id'
        ],
        [
            'select',
            'california',
            { region_id: '12', city: 'Los Angeles' },
            'the name match is case-insensitive'
        ],
        [
            'select',
            'CA',
            { region_id: '12', city: 'Los Angeles' },
            "a registry's region CODE matches the option title"
        ],
        [
            'select',
            'Kent',
            { region_id: '', city: 'Los Angeles, Kent' },
            'no matching option: appended to the city rather than dropped'
        ],
        [
            'both',
            'Kent',
            { region_id: '', region: '', city: 'Los Angeles, Kent' },
            'a HIDDEN free-text region is not a usable field either'
        ],
        [
            'text',
            'Kent',
            { region: 'Kent', city: 'Los Angeles' },
            'a visible free-text region field takes it verbatim'
        ],
        [
            'none',
            'Kent',
            { city: 'Los Angeles, Kent' },
            'no region control at all: appended to the city'
        ],
        [
            'none',
            '  Kent  ',
            { city: 'Los Angeles, Kent' },
            'the region is trimmed before it is used'
        ],
        [
            'none',
            '',
            { city: 'Los Angeles' },
            'an empty region writes nothing anywhere'
        ]
    ])('%s form, region=%p -> %p (%s)', (shape, region, expected) => {
        const { model, field } = load(shape);

        model.applyAddress({ city: 'Los Angeles', postal_code: '90001', street: 'Mill Lane', region });

        Object.keys(expected).forEach(function (name) {
            expect(`${name}=${field(name).value}`).toBe(`${name}=${expected[name]}`);
        });
    });

    test('the city append happens AFTER the city write, not against the old value', () => {
        // Ordering, stated as its own case because the failure is silent: run
        // the other way round the append lands on the PREVIOUS city and the
        // fill then overwrites it, losing the region with no error anywhere.
        const { model, field } = load();
        field('city').value = 'Ashford';

        model.applyAddress({ city: 'Maidstone', region: 'Kent', street: 'Mill Lane' });

        expect(field('city').value).toBe('Maidstone, Kent');
        expect(field('city').getAttribute(MARKER)).toBe('Maidstone, Kent');
    });

    test('a repeated write does not grow the city on every pass', () => {
        // The sole-trader write-back is genuinely re-entrant: a second prefetch
        // and a repeated popup `ACCEPTED` both replay it.
        const { model, field } = load();
        const address = { city: 'Ashford', region: 'Kent', street: 'Mill Lane' };

        model.applyAddress(address);
        model.applyAddress(address);
        model.applyAddress(address);

        expect(field('city').value).toBe('Ashford, Kent');
    });

    test('an unmatched region does not disturb a region the buyer already picked', () => {
        // The select keeps its value: guessing at an id from an unmatched name
        // is worse than leaving the buyer's own answer, and the region text is
        // still visible on the city.
        const { model, field } = load('select');
        field('region_id').value = '43';

        model.applyAddress({ city: 'Ashford', region: 'Kent', street: 'Mill Lane' });

        expect(field('region_id').value).toBe('43');
        expect(field('region_id').hasAttribute(MARKER)).toBe(false);
        expect(field('city').value).toBe('Ashford, Kent');
    });
});

describe('every field the write can reach, the revert can take back', () => {
    test('line 2 and the region are reverted alongside the original three', () => {
        // The lists have to stay in step. A field the write reaches and the
        // revert does not keeps the PREVIOUS country's value after a country
        // switch, with a marker on it claiming the plugin owns it — the exact
        // state the marker exists to prevent.
        const { model, field } = load('select');

        model.applyAddress({
            city: 'Los Angeles',
            postal_code: '90001',
            street: 'Mill Lane',
            building: 'Mill House',
            region: 'California'
        });
        expect(field('region_id').value).toBe('12');

        expect(model.revertAutofilledAddress()).toBe(5);

        ['city', 'postcode', 'street[0]', 'street[1]', 'region_id'].forEach(function (name) {
            expect(`${name}=${field(name).value}`).toBe(`${name}=`);
            expect(field(name).hasAttribute(MARKER)).toBe(false);
        });
    });

    test('a free-text region the buyer edited survives the revert', () => {
        const { model, field } = load('text');

        model.applyAddress({ city: 'Ashford', street: 'Mill Lane', region: 'Kent' });
        field('region').value = 'Surrey';

        // city, postcode and line 1 revert; the edited region does not.
        expect(model.revertAutofilledAddress()).toBe(3);
        expect(field('region').value).toBe('Surrey');
    });

    test('every write fires change, so the quote sees it', () => {
        const { model, $ } = load('select');

        model.applyAddress({
            city: 'Los Angeles',
            postal_code: '90001',
            street: 'Mill Lane',
            building: 'Mill House',
            region: 'California'
        });

        expect($.triggered).toEqual([
            ['city', 'change'],
            ['postcode', 'change'],
            ['street[0]', 'change'],
            ['street[1]', 'change'],
            ['region_id', 'change']
        ]);
    });

    test('the country is never written, wherever the payload came from', () => {
        // Decision #12: the server discards a company whose country disagrees
        // with the checkout address's, so writing a registered country over the
        // one the buyer chose would destroy the selection this completes.
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            ADDRESS_FIELDS +
            '<select name="country_id"><option value="GB" selected>GB</option></select>' +
            '</form>';
        const $ = makeDollar();
        const model = loadAmdModule(SEARCH, { jquery: $ });

        model.applyAddress({ city: 'Los Angeles', street: 'Mill Lane', country_code: 'US' });

        expect(document.querySelector('[name="country_id"]').value).toBe('GB');
    });
});
