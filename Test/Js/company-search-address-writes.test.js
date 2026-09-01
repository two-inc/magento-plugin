/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §2 / TWO-25554 — each panel's address writes reach ITS OWN form.
 *
 * Magento renders a shipping address form (always present) and a billing form
 * (one per payment method, shown once the buyer unchecks "My billing and
 * shipping address are the same"). Each has its own capture panel, and NO field
 * of either address is ever written from the other panel.
 *
 * The jQuery double below is hand-rolled over the REAL jsdom document rather
 * than `require('jquery')`, because jQuery is not a devDependency of this
 * module's JS manifest — the same reason every neighbouring spec supplies its
 * own. It implements only what the module under test calls, but each call goes
 * to real markup, so what passes here is the production selector set evaluated
 * against real address forms rather than a recorder answering as it was told.
 */

'use strict';

const { loadAmdModule, tagged } = require('./amd-harness');

const MODEL = 'view/frontend/web/js/model/company-search.js';

/** The address fields these specs read, in the module's own vocabulary. */
const FIELD_SELECTORS = {
    company: 'input[name="company"]',
    organization: 'input[name="custom_attributes[company_id]"]',
    street0: 'input[name="street[0]"]',
    street1: 'input[name="street[1]"]',
    city: 'input[name="city"]',
    postcode: 'input[name="postcode"]',
    country: 'select[name="country_id"]',
    // Not one of the pin's fields — applyTelephone() is the one write that
    // lands outside them, and it has a form of its own to stay inside.
    telephone: 'input[name="telephone"]'
};

function makeDollar() {
    function wrap(nodes) {
        const set = {
            length: nodes.length,
            get: function (index) {
                return nodes[index];
            },
            first: function () {
                return wrap(nodes.slice(0, 1));
            },
            each: function (fn) {
                nodes.forEach(function (node, index) {
                    fn.call(node, index, node);
                });
                return set;
            },
            find: function (selector) {
                const found = [];
                nodes.forEach(function (node) {
                    Array.prototype.forEach.call(node.querySelectorAll(selector), function (m) {
                        found.push(m);
                    });
                });
                return wrap(found);
            },
            closest: function (selector) {
                const found = [];
                nodes.forEach(function (node) {
                    const match = node.closest(selector);
                    if (match) found.push(match);
                });
                return wrap(found);
            },
            val: function (value) {
                if (!arguments.length) return nodes.length ? nodes[0].value : undefined;
                nodes.forEach(function (node) {
                    node.value = value;
                });
                return set;
            },
            attr: function (name, value) {
                if (arguments.length < 2) {
                    if (!nodes.length || !nodes[0].hasAttribute(name)) return undefined;
                    return nodes[0].getAttribute(name);
                }
                nodes.forEach(function (node) {
                    node.setAttribute(name, value);
                });
                return set;
            },
            removeAttr: function (name) {
                nodes.forEach(function (node) {
                    node.removeAttribute(name);
                });
                return set;
            },
            trigger: function (event) {
                nodes.forEach(function (node) {
                    node.dispatchEvent(new window.Event(event, { bubbles: true }));
                });
                return set;
            }
        };
        return set;
    }

    function $(target) {
        if (!target) return wrap([]);
        if (typeof target === 'string') {
            return wrap(Array.prototype.slice.call(document.querySelectorAll(target)));
        }
        if (target.nodeType === 1) return wrap([target]);
        if (typeof target.each === 'function') return target;
        return wrap([]);
    }
    return $;
}

const $ = makeDollar();

/**
 * @param {object} [options]
 * @param {boolean} [options.regions] give the forms a populated `region_id`
 *        select (a country whose format has a state field) as well as the
 *        free-text `region` input core always renders beside it
 * @returns {string} markup
 */
function addressFields(options) {
    // The placeholder carries core's own LABEL, not an empty one: an empty
    // placeholder would make the "no region chosen" state indistinguishable
    // from a bug that reads the placeholder's text as an answer — which is
    // exactly the defect live verification found.
    const placeholder = '<option value="">Please select a region, state or province</option>';
    // NO abbreviation attribute on these options, deliberately. Checked in a
    // real browser: core's region options do carry a `data-title`, but it holds
    // the region's own NAME, the same string as the label. A fixture stamping a
    // two-letter code there would be testing markup no store ever renders. The
    // visible label is the only join available.
    const regionOptions = options.regions
        ? placeholder +
          '<option value="12">California</option>' +
          '<option value="43">Texas</option>'
        : placeholder;
    return (
        '<input name="company" value="">' +
        '<input name="custom_attributes[company_id]" value="">' +
        '<input name="street[0]" value="">' +
        '<input name="street[1]" value="">' +
        '<input name="city" value="">' +
        '<input name="postcode" value="">' +
        '<input name="telephone" value="">' +
        '<select name="region_id">' +
        regionOptions +
        '</select>' +
        '<input name="region" value="">' +
        '<select name="country_id">' +
        '<option value="GB">United Kingdom</option>' +
        '<option value="ES">Spain</option>' +
        '<option value="NO">Norway</option>' +
        '</select>'
    );
}

/**
 * Build a checkout with a shipping address form and a billing address form —
 * markup mirroring core's own `shipping-address/form.html` and
 * `billing-address.html`.
 *
 * @param {object} [options] plus addressFields()'s own
 * @param {boolean} [options.billing] false renders no billing form at all
 * @param {boolean} [options.savedAddressWrapper] wrap the shipping form in
 *        `#opc-new-shipping-address`, as core does for a buyer with saved
 *        addresses
 * @param {boolean} [options.hiddenPrimary] the same wrapper, hidden — a buyer
 *        checking out against a saved address, where the form core rendered is
 *        not the one in use
 * @returns {object} the real company-search module, closed over this document
 */
function renderCheckout(options) {
    const opts = options || {};
    const fields = addressFields(opts);
    const primary =
        '<div id="shipping-new-address-form" class="fieldset address">' + fields + '</div>';
    document.body.innerHTML =
        // Core wraps the form in `#opc-new-shipping-address` ONLY for a buyer
        // with saved addresses, and toggles that wrapper's own display. A buyer
        // with none gets the form rendered inline, with no wrapper at all.
        (opts.savedAddressWrapper || opts.hiddenPrimary
            ? '<div id="opc-new-shipping-address"' +
              (opts.hiddenPrimary ? ' style="display: none"' : '') +
              '>' +
              primary +
              '</div>'
            : primary) +
        (opts.billing === false
            ? ''
            : '<div class="checkout-billing-address">' +
              '<div class="billing-address-same-as-shipping-block">' +
              '<input type="checkbox" name="billing-address-same-as-shipping"' +
              ' id="billing-address-same-as-shipping-two_payment">' +
              '</div>' +
              '<fieldset class="fieldset address" data-form="billing-new-address">' +
              fields +
              '</fieldset>' +
              '</div>');

    // Real `window`/`document`, not the harness stubs: the module asks
    // `window.getComputedStyle` whether the shipping form is the one core is
    // actually using, and reads option lists off real `<select>` nodes.
    return loadAmdModule(MODEL, { jquery: $ }, { window: window, document: document });
}

const PRIMARY = '#shipping-new-address-form';
const SECONDARY = '[data-form="billing-new-address"]';

/** Read one field of one form, by the module's own field name. */
function read(rootSelector, name) {
    const root = document.querySelector(rootSelector);
    if (name === 'region') {
        const select = root.querySelector('select[name="region_id"]');
        const hasOptions = Array.prototype.some.call(select.options, function (option) {
            return option.value;
        });
        if (hasOptions) {
            const option = select.options[select.selectedIndex];
            return option && option.value ? option.text : '';
        }
        return root.querySelector('input[name="region"]').value;
    }
    return root.querySelector(FIELD_SELECTORS[name]).value;
}

/** Write one field of one form as the BUYER would — no marker, real `change`. */
function buyerTypes(rootSelector, name, value) {
    const root = document.querySelector(rootSelector);
    const node =
        name === 'region'
            ? root.querySelector('select[name="region_id"]')
            : root.querySelector(FIELD_SELECTORS[name]);
    node.value = value;
    node.dispatchEvent(new window.Event('change', { bubbles: true }));
}

const COMPANY_A = {
    city: 'London',
    postal_code: 'EC1A 1BB',
    street_address: '1 Example Street'
};
const COMPANY_B = {
    city: 'Stockholm',
    postal_code: '111 22',
    street_address: '2 Second Street'
};

afterEach(() => {
    document.body.innerHTML = '';
});

/** One panel writing an address into ITS OWN form. */
function writeInto(model, address, rootSelector) {
    return model.applyAddress(address, $(rootSelector));
}

describe('an external address payload is routed onto the two address lines', () => {
    /**
     * The routing rule: a building or apartment is the more specific locator
     * and takes line 1, pushing the street to line 2; with neither, the street
     * takes line 1 and line 2 is left alone. Both present join
     * most-specific-first. No de-duplication between the lines, ever.
     */
    const rows = [
        [
            'a building takes line 1 and pushes the street to line 2',
            { street_address: 'Mill Lane', building: 'Mill House' },
            'Mill House',
            'Mill Lane'
        ],
        [
            'an apartment does the same',
            { street_address: 'Mill Lane', apartment: 'Flat 9' },
            'Flat 9',
            'Mill Lane'
        ],
        [
            'both join most-specific-first',
            { street_address: 'Mill Lane', building: 'Mill House', apartment: 'Flat 9' },
            'Flat 9, Mill House',
            'Mill Lane'
        ],
        [
            'a street alone takes line 1 and leaves line 2 untouched',
            { street_address: 'Mill Lane' },
            'Mill Lane',
            ''
        ],
        [
            'identical text on both lines is kept — no de-duplication',
            { street_address: 'Mill Lane', building: 'Mill Lane' },
            'Mill Lane',
            'Mill Lane'
        ]
    ];

    test.each(rows)('%s', (description, address, line1, line2) => {
        const model = renderCheckout({ regions: true });

        writeInto(model, Object.assign({ city: 'Ashford', postal_code: 'TN23' }, address), PRIMARY);

        expect(tagged(description, read(PRIMARY, 'street0'))).toEqual(tagged(description, line1));
        expect(read(PRIMARY, 'street1')).toBe(line2);
        // The other panel's form is untouched, whatever the routing decided.
        expect(read(SECONDARY, 'street0')).toBe('');
        expect(read(SECONDARY, 'street1')).toBe('');
    });
});

describe("an external payload's region lands somewhere, always", () => {
    const rows = [
        [
            'a region matching an option by name selects it',
            true,
            { region: 'California', city: 'Ashford' },
            'California',
            'Ashford'
        ],
        [
            'a label match is case-folded and trimmed',
            true,
            { region: '  cALIFORNIA ', city: 'Ashford' },
            'California',
            'Ashford'
        ],
        [
            'a region given as a CODE cannot be resolved from the DOM and falls back to the city',
            true,
            { region: 'CA', city: 'Ashford' },
            '',
            'Ashford, CA'
        ],
        [
            'a region matching no option is appended to the city rather than guessed at',
            true,
            { region: 'Kent', city: 'Ashford' },
            '',
            'Ashford, Kent'
        ],
        [
            'a country with no state options takes the region in its free-text field',
            false,
            { region: 'Kent', city: 'Ashford' },
            'Kent',
            'Ashford'
        ],
        [
            'a region matching no option, with no city in the payload at all, still lands — in the city field alone',
            true,
            { region: 'Kent' },
            '',
            'Kent'
        ]
    ];

    test.each(rows)(
        '%s',
        (description, regions, address, expectedRegion, expectedCity) => {
            const model = renderCheckout({ regions: regions });

            writeInto(
                model,
                Object.assign({ postal_code: 'TN23', street_address: 'A' }, address),
                PRIMARY
            );

            expect(tagged(description, read(PRIMARY, 'region')))
                .toEqual(tagged(description, expectedRegion));
            expect(read(PRIMARY, 'city')).toBe(expectedCity);
        }
    );

    test('the city append does not grow on a repeated write', () => {
        const model = renderCheckout({ regions: true });
        const address = { region: 'Kent', city: 'Ashford, Kent', postal_code: 'TN23' };

        writeInto(model, address, PRIMARY);
        writeInto(model, address, PRIMARY);

        expect(read(PRIMARY, 'city')).toBe('Ashford, Kent');
    });
});

describe('a retraction stops at the form it was scoped to', () => {
    test.each([
        [PRIMARY, SECONDARY, 'the shipping form’s own retraction stops at the shipping form'],
        [SECONDARY, PRIMARY, 'the billing form’s own retraction stops at the billing form']
    ])('a retraction scoped to one form leaves the other alone (%#: %s)', (scoped, other) => {
        // The reported symptom: changing the BILLING country cleared the
        // SHIPPING address fields, because the retraction was page-wide
        // (TWO-25554).
        const model = renderCheckout({ regions: true });
        writeInto(model, COMPANY_A, PRIMARY);
        writeInto(model, COMPANY_B, SECONDARY);

        expect(model.revertAutofilledAddress($(scoped))).toBe(3);

        expect(read(scoped, 'city')).toBe('');
        expect(read(other, 'city')).toBe(other === PRIMARY ? 'London' : 'Stockholm');
    });

    test.each([
        [null, 'null — a panel with no form of its own'],
        [undefined, 'undefined — a caller that forgot to say']
    ])('a retraction with no form (%p — %s) reverts nothing', (root, description) => {
        const model = renderCheckout({ regions: true });
        writeInto(model, COMPANY_A, PRIMARY);
        writeInto(model, COMPANY_A, SECONDARY);

        expect([description, model.revertAutofilledAddress(root)]).toEqual([description, 0]);

        expect(read(PRIMARY, 'city')).toBe('London');
        expect(read(SECONDARY, 'city')).toBe('London');
    });

    test.each([
        [PRIMARY, SECONDARY, 'the shipping form’s phone stays in the shipping form'],
        [SECONDARY, PRIMARY, 'the billing form’s phone stays in the billing form']
    ])('a verified phone lands in the calling panel’s form alone (%#: %s)', (scoped, other) => {
        const model = renderCheckout({ regions: true });

        expect(model.applyTelephone('+47 123 45 678', $(scoped))).toBe(true);

        expect(read(scoped, 'telephone')).toBe('+47 123 45 678');
        expect(read(other, 'telephone')).toBe('');
    });

    test('a phone write with no form to scope to is refused', () => {
        const model = renderCheckout({ regions: true });

        expect(model.applyTelephone('+47 123 45 678')).toBe(false);

        expect(read(PRIMARY, 'telephone')).toBe('');
        expect(read(SECONDARY, 'telephone')).toBe('');
    });
});

describe('a retraction survives the checkout rebuilding its own fieldset', () => {
    /**
     * A re-render replaces an address fieldset's inputs — destroying every
     * attribute on them, the autofill marker included — while keeping the
     * fieldset itself, and repaints the values from the quote. Fire Checkout
     * does it on every totals change.
     *
     * @param {string} rootSelector
     * @param {object} values field name → what the re-render paints back
     */
    function rebuildFieldset(rootSelector, values) {
        const root = document.querySelector(rootSelector);
        root.innerHTML = addressFields({ regions: true });
        Object.keys(values).forEach(function (name) {
            root.querySelector(FIELD_SELECTORS[name]).value = values[name];
        });
    }

    test('a country switch after a rebuild still retracts the previous country\'s address', () => {
        const model = renderCheckout({ regions: true });
        writeInto(model, COMPANY_A, SECONDARY);

        rebuildFieldset(SECONDARY, {
            city: 'London',
            postcode: 'EC1A 1BB',
            street0: '1 Example Street'
        });

        expect(model.revertAutofilledAddress($(SECONDARY))).toBe(3);
        expect(read(SECONDARY, 'city')).toBe('');
        expect(read(SECONDARY, 'street0')).toBe('');
    });

    test('and still leaves what the buyer typed after that rebuild alone', () => {
        const model = renderCheckout({ regions: true });
        writeInto(model, COMPANY_A, SECONDARY);

        rebuildFieldset(SECONDARY, {
            city: 'Ashford',
            postcode: 'EC1A 1BB',
            street0: '1 Example Street'
        });

        expect(model.revertAutofilledAddress($(SECONDARY))).toBe(2);
        expect(read(SECONDARY, 'city')).toBe('Ashford');
    });

    test('the other panel\'s form is not reachable through the record either', () => {
        const model = renderCheckout({ regions: true });
        writeInto(model, COMPANY_A, PRIMARY);
        writeInto(model, COMPANY_B, SECONDARY);

        rebuildFieldset(SECONDARY, {
            city: 'Stockholm',
            postcode: '111 22',
            street0: '2 Second Street'
        });
        model.revertAutofilledAddress($(SECONDARY));

        expect(read(PRIMARY, 'city')).toBe('London');
        expect(read(PRIMARY, 'street0')).toBe('1 Example Street');
    });
});

describe('a replacement pick does not strand the previous line 2', () => {
    test('a payload with no locator retracts a line 2 the plugin wrote', () => {
        const model = renderCheckout({ regions: true });
        writeInto(model, Object.assign({ building: 'Mill House' }, COMPANY_A), PRIMARY);
        expect(read(PRIMARY, 'street1')).toBe('1 Example Street');

        writeInto(model, COMPANY_B, PRIMARY);

        expect(read(PRIMARY, 'street0')).toBe('2 Second Street');
        expect(read(PRIMARY, 'street1')).toBe('');
    });

    test('a line 2 the BUYER wrote is never retracted', () => {
        // Same code path, opposite outcome: the buyer's line 2 has no recording,
        // so it is not the plugin's to clear.
        const model = renderCheckout({ regions: true });
        buyerTypes(PRIMARY, 'street1', 'Buyer Annexe');

        writeInto(model, COMPANY_B, PRIMARY);

        expect(read(PRIMARY, 'street1')).toBe('Buyer Annexe');
    });
});

describe('a shipping form core is not using is not the buyer\'s own form', () => {
    test.each([
        [{ hiddenPrimary: true }, false, 'hidden inside the saved-addresses wrapper'],
        [{ savedAddressWrapper: true }, true, 'the same form inside a VISIBLE wrapper'],
        [{}, true, 'rendered inline, with no wrapper at all']
    ])('%#: it is in play = %p (%s)', (fixture, inPlay, description) => {
        // For a buyer with saved addresses core still RENDERS the new-address
        // form and only hides it, so it sits there holding store defaults for
        // the whole checkout. Luma also collapses the shipping step once the
        // buyer reaches payment, which hides an ANCESTOR of that wrapper while
        // leaving the form the buyer filled in authoritative.
        const model = renderCheckout(Object.assign({ regions: true }, fixture));

        expect([description, model.hasPrimaryAddressForm()]).toEqual([description, inPlay]);
    });
});
