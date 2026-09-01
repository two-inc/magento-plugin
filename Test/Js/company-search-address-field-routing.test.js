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
        // jsdom has no layout, so jQuery's own `:visible` (offsetWidth/Height)
        // would answer false for everything. Only the root-form choice uses it,
        // and inline `display` is enough to express that here.
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
                    if ($.onChange) $.onChange(n, event);
                });
                return set;
            },
            is: function (selector) {
                if (selector !== ':visible') throw new Error(`unsupported: ${selector}`);
                return nodes.some(isVisible);
            },
            first: function () {
                return wrap(nodes.slice(0, 1));
            },
            eq: function (index) {
                return wrap(nodes.slice(index, index + 1));
            },
            prop: function (name) {
                return nodes.length ? !!nodes[0][name] : undefined;
            },
            find: function (selector) {
                const found = [];
                nodes.forEach(function (n) {
                    found.push.apply(found, Array.prototype.slice.call(
                        n.querySelectorAll(selector)
                    ));
                });
                return wrap(found);
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
 * country-without-regions shape, where the select is present but carries only
 * its placeholder. `both` is what the checkout actually renders for a country
 * WITH regions — the free-text input is still in the DOM beside the populated
 * select — which is why having real options, not CSS visibility, is what
 * decides which control is in play.
 */
const REGION_MARKUP = {
    none: '',
    select:
        '<select name="region_id">' +
        '  <option value=""></option>' +
        '  <option value="12" title="CA">California</option>' +
        '  <option value="43" title="NY">New York</option>' +
        '</select>',
    text: '<select name="region_id"><option value=""></option></select>' +
        '<input name="region" value="" />',
    both:
        '<select name="region_id">' +
        '  <option value=""></option>' +
        '  <option value="12" title="CA">California</option>' +
        '</select>' +
        '<input name="region" value="" />'
};

/**
 * Build the form and load the REAL shared model against it.
 *
 * @param {string} [regionShape] key of REGION_MARKUP, default 'none'
 * @returns {object} { model, $, field }
 */
function load(regionShape) {
    document.body.innerHTML =
        '<div id="shipping-new-address-form">' +
        ADDRESS_FIELDS +
        REGION_MARKUP[regionShape || 'none'] +
        '</div>';
    const $ = makeDollar();
    const model = loadAmdModule(SEARCH, { jquery: $ });
    // Every write and every revert is scoped to the calling panel's OWN form;
    // there is no page-wide path any more (TWO-25554).
    const root = $('#shipping-new-address-form');
    return {
        model: model,
        $: $,
        root: root,
        apply: function (address) { return model.applyAddress(address, root); },
        revert: function () { return model.revertAutofilledAddress(root); },
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
        const { apply, revert, field } = load();

        apply(address);

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
            'a populated select wins over the free-text input beside it'
        ],
        [
            'text',
            'Kent',
            { region: 'Kent', city: 'Los Angeles' },
            'a placeholder-only select means the free-text field is in play'
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
        const { apply, revert, field } = load(shape);

        apply({ city: 'Los Angeles', postal_code: '90001', street: 'Mill Lane', region });

        Object.keys(expected).forEach(function (name) {
            expect(`${name}=${field(name).value}`).toBe(`${name}=${expected[name]}`);
        });
    });

    test.each([
        [false, { region: 'Kent', city: 'Los Angeles' }, 'a visible free-text region takes it'],
        [
            true,
            { region: '', city: 'Los Angeles, Kent' },
            'a HIDDEN one is not the control in play: core hides the region field '
                + 'the country does not use'
        ]
    ])('free-text region hidden=%p -> %p (%s)', (hidden, expected) => {
        const { apply, revert, field } = load('text');
        if (hidden) field('region').style.display = 'none';

        apply({ city: 'Los Angeles', street: 'Mill Lane', region: 'Kent' });

        Object.keys(expected).forEach(function (name) {
            expect(`${name}=${field(name).value}`).toBe(`${name}=${expected[name]}`);
        });
    });

    test('the city append happens AFTER the city write, not against the old value', () => {
        // Ordering, stated as its own case because the failure is silent: run
        // the other way round the append lands on the PREVIOUS city and the
        // fill then overwrites it, losing the region with no error anywhere.
        const { apply, revert, field } = load();
        field('city').value = 'Ashford';

        apply({ city: 'Maidstone', region: 'Kent', street: 'Mill Lane' });

        expect(field('city').value).toBe('Maidstone, Kent');
        expect(field('city').getAttribute(MARKER)).toBe('Maidstone, Kent');
    });

    test('a repeated write does not grow the city on every pass', () => {
        // The sole-trader write-back is genuinely re-entrant: a second lookup
        // and a repeated popup `ACCEPTED` both replay it.
        const { apply, revert, field } = load();
        const address = { city: 'Ashford', region: 'Kent', street: 'Mill Lane' };

        apply(address);
        apply(address);
        apply(address);

        expect(field('city').value).toBe('Ashford, Kent');
    });

    test('an unmatched region does not disturb a region the buyer already picked', () => {
        // The select keeps its value: guessing at an id from an unmatched name
        // is worse than leaving the buyer's own answer, and the region text is
        // still visible on the city.
        const { apply, revert, field } = load('select');
        field('region_id').value = '43';

        apply({ city: 'Ashford', region: 'Kent', street: 'Mill Lane' });

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
        const { apply, revert, field } = load('select');

        apply({
            city: 'Los Angeles',
            postal_code: '90001',
            street: 'Mill Lane',
            building: 'Mill House',
            region: 'California'
        });
        expect(field('region_id').value).toBe('12');

        expect(revert()).toBe(5);

        ['city', 'postcode', 'street[0]', 'street[1]', 'region_id'].forEach(function (name) {
            expect(`${name}=${field(name).value}`).toBe(`${name}=`);
            expect(field(name).hasAttribute(MARKER)).toBe(false);
        });
    });

    test('a free-text region the buyer edited survives the revert', () => {
        const { apply, revert, field } = load('text');

        apply({ city: 'Ashford', street: 'Mill Lane', region: 'Kent' });
        field('region').value = 'Surrey';

        // city and line 1 revert; the edited region does not, and the payload
        // said nothing about a postcode so none was ever written.
        expect(revert()).toBe(2);
        expect(field('region').value).toBe('Surrey');
    });

    test('the form is already fully written when the first change fires', () => {
        // The invariant, not the event order: a handler on any of these fields
        // serialises the WHOLE form, so one firing mid-write posts an address
        // that is half the previous company's. Asserting the sequence alone
        // cannot tell "after all writes" from "interleaved with them".
        const seen = [];
        document.body.innerHTML =
            '<div id="shipping-new-address-form">' + ADDRESS_FIELDS + REGION_MARKUP.select +
            '</div>';
        const $ = makeDollar();
        $.onChange = function () {
            seen.push(
                ['city', 'postcode', 'street[0]', 'street[1]', 'region_id']
                    .map((name) => `${name}=${document.querySelector(`[name="${name}"]`).value}`)
                    .join(' ')
            );
        };
        const model = loadAmdModule(SEARCH, { jquery: $ });

        model.applyAddress({
            city: 'Los Angeles',
            postal_code: '90001',
            street: 'Mill Lane',
            building: 'Mill House',
            region: 'California'
        }, $('#shipping-new-address-form'));

        const complete =
            'city=Los Angeles postcode=90001 street[0]=Mill House street[1]=Mill Lane region_id=12';
        expect(seen).toEqual([complete, complete, complete, complete, complete]);
    });

    test('every write fires change, so the quote sees it', () => {
        const { apply, $ } = load('select');

        apply({
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
            '<div id="shipping-new-address-form">' +
            ADDRESS_FIELDS +
            '<select name="country_id"><option value="GB" selected>GB</option></select>' +
            '</div>';
        const $ = makeDollar();
        const model = loadAmdModule(SEARCH, { jquery: $ });

        model.applyAddress(
            { city: 'Los Angeles', street: 'Mill Lane', country_code: 'US' },
            $('#shipping-new-address-form')
        );

        expect(document.querySelector('[name="country_id"]').value).toBe('GB');
    });
});

describe('an omission is not a blank, but a stale value is not kept either', () => {
    test.each([
        [
            { city: 'Ashford', street: 'Mill Lane' },
            { postcode: 'TN23 1AA' },
            'a payload with no postcode leaves the buyer\'s own postcode alone'
        ],
        [
            { city: 'Ashford', postal_code: '', street: 'Mill Lane' },
            { postcode: '' },
            'an explicit empty postcode IS an instruction, and blanks it'
        ]
    ])('%p -> %p (%s)', (address, expected) => {
        const { apply, revert, field } = load();
        field('postcode').value = 'TN23 1AA';

        apply(address);

        Object.keys(expected).forEach(function (name) {
            expect(`${name}=${field(name).value}`).toBe(`${name}=${expected[name]}`);
        });
    });

    test('a second selection takes back what the first wrote and the second does not mention', () => {
        // Otherwise the form ends up holding one address assembled from two
        // companies: line 2 and the region from the first, everything else from
        // the second, with nothing on screen saying so.
        const { apply, revert, field } = load('select');

        apply({
            city: 'Los Angeles',
            postal_code: '90001',
            street: 'Mill Lane',
            building: 'Mill House',
            region: 'California'
        });
        expect(field('street[1]').value).toBe('Mill Lane');
        expect(field('region_id').value).toBe('12');

        apply({ city: 'Ashford', postal_code: 'TN23 1AA', street: 'Mill Lane' });

        expect(field('street[0]').value).toBe('Mill Lane');
        expect(field('street[1]').value).toBe('');
        expect(field('region_id').value).toBe('');
    });

    test('what the buyer edited between the two selections survives the retraction', () => {
        const { apply, revert, field } = load('select');

        apply({ street: 'Mill Lane', building: 'Mill House', city: 'Ashford' });
        field('street[1]').value = 'Second Line By Hand';

        apply({ street: 'Other Lane', city: 'Ashford' });

        expect(field('street[1]').value).toBe('Second Line By Hand');
    });

    test('a region with no city of its own does not annex the city the buyer typed', () => {
        // Appending would mark a buyer-authored city as this module's own, and
        // the next revert would then delete their text along with the region.
        const { apply, revert, field } = load();
        field('city').value = 'Ashford';

        apply({ street: 'Mill Lane', region: 'Kent' });

        expect(field('city').value).toBe('Ashford');
        expect(field('city').hasAttribute(MARKER)).toBe(false);
    });
});

describe('a shop configured for a single street line', () => {
    test('joins the locator and the street rather than losing the street', () => {
        // `input[name="street[1]"]` simply does not exist when
        // `customer/address/street_lines` is 1, and jQuery no-ops on an empty
        // set — so routing the street there would discard it silently.
        document.body.innerHTML =
            '<div id="shipping-new-address-form">' +
            '<input name="city" value="" /><input name="postcode" value="" />' +
            '<input name="street[0]" value="" />' +
            '</div>';
        const $ = makeDollar();
        const model = loadAmdModule(SEARCH, { jquery: $ });

        model.applyAddress(
            { street: 'Mill Lane', building: 'Mill House', city: 'Ashford' },
            $('#shipping-new-address-form')
        );

        expect(document.querySelector('[name="street[0]"]').value).toBe('Mill House, Mill Lane');
    });
});

describe('the write can be scoped to one address form', () => {
    /** Two forms, as the payment step actually renders them. */
    function loadTwoForms() {
        // Core's own markup: the shipping form carries an id, the billing
        // fieldset carries `data-form="billing-new-address"` and NO id (checked
        // against Magento_Checkout's billing-address/form.html and
        // shipping-address/form.html, 2.4.6).
        document.body.innerHTML =
            '<div id="shipping-new-address-form">' +
            '<input name="city" value="Shipping City" /><input name="postcode" value="" />' +
            '<input name="street[0]" value="" /><input name="street[1]" value="" />' +
            '</div>' +
            '<fieldset data-form="billing-new-address">' +
            '<input name="city" value="Billing City" /><input name="postcode" value="" />' +
            '<input name="street[0]" value="" /><input name="street[1]" value="" />' +
            '</fieldset>';
        const $ = makeDollar();
        return { model: loadAmdModule(SEARCH, { jquery: $ }), $: $ };
    }

    /**
     * @param {string} form form selector to read from
     * @param {string} name field name
     * @returns {string}
     */
    function valueIn(form, name) {
        return document.querySelector(`${form} [name="${name}"]`).value;
    }

    test.each([
        ['[data-form="billing-new-address"]', '#shipping-new-address-form', 'the billing panel writes its own form'],
        ['#shipping-new-address-form', '[data-form="billing-new-address"]', 'the shipping panel writes its own form']
    ])('a write scoped to %s leaves %s alone (%s)', (written, untouched, description) => {
        // The payment step is not a one-form page: Luma leaves the shipping
        // form in the DOM and core renders a billing form per payment method,
        // all with the same field names. A page-wide write reached every one.
        const { model, $ } = loadTwoForms();
        const before = valueIn(untouched, 'city');

        model.applyAddress({ city: 'Ashford', street: 'Mill Lane' }, $(written));

        expect(valueIn(written, 'city')).toBe('Ashford', description);
        expect(valueIn(untouched, 'city')).toBe(before);
    });

    test.each([
        [null, 'null — a panel with no form of its own'],
        [undefined, 'undefined — a caller that forgot to say'],
        ['empty', 'an empty set — a form this checkout does not render']
    ])('a write with no form (%p — %s) reaches nothing', (rootKind, description) => {
        // Falling through to a page-wide write put one panel's pick into every
        // address form on the page, the other panel's included (TWO-25554).
        const { model, $ } = loadTwoForms();
        const root = rootKind === 'empty' ? $('#nothing-here') : rootKind;

        expect(model.applyAddress({ city: 'Ashford' }, root)).toBe(0, description);
        expect(valueIn('#shipping-new-address-form', 'city')).toBe('Shipping City');
        expect(valueIn('[data-form="billing-new-address"]', 'city')).toBe('Billing City');
    });
});
