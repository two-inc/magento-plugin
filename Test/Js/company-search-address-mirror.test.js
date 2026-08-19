/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §2 — the two-address sync model.
 *
 * Magento renders a DEFAULT address form (the shipping step's, always present)
 * and a NON-DEFAULT one (the billing form, one per payment method, shown once
 * the buyer unchecks "My billing and shipping address are the same"). The
 * default one propagates into the non-default one, the non-default one stays
 * fully editable, and the propagation stops the moment the buyer authors
 * anything in it — ADDRESS-WIDE, not field by field.
 *
 * The sync-stop is a content match, not a flag: there is no "resume sync"
 * control anywhere, because the match check is the resumption path.
 *
 * The jQuery double below is hand-rolled over the REAL jsdom document rather
 * than `require('jquery')`, because jQuery is not a devDependency of this
 * module's JS manifest — the same reason every neighbouring spec supplies its
 * own. It implements only what the module under test calls, but each call goes
 * to real markup, so what passes here is the production selector set evaluated
 * against real address forms rather than a recorder answering as it was told.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const MODEL = 'view/frontend/web/js/model/company-search.js';
const MARKER = 'data-two-autofilled-value';

/** Every field the pin judges, in the module's own vocabulary. */
const FIELD_SELECTORS = {
    company: 'input[name="company"]',
    organization: 'input[name="custom_attributes[company_id]"]',
    street0: 'input[name="street[0]"]',
    street1: 'input[name="street[1]"]',
    city: 'input[name="city"]',
    postcode: 'input[name="postcode"]',
    country: 'select[name="country_id"]'
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
 * Build a checkout with a shipping address form and zero or more billing
 * address forms — markup mirroring core's own `shipping-address/form.html` and
 * `billing-address.html`, including the per-payment-method
 * `billing-address-same-as-shipping-<code>` checkbox id core stamps.
 *
 * @param {object} [options] plus addressFields()'s own
 * @param {boolean} [options.billing] false renders no billing form at all
 * @param {Array<string>} [options.billingCodes] payment codes to render a
 *        billing form for — several exercises the per-form record keying
 * @param {boolean} [options.noCheckboxId] omit the checkbox id, so the key has
 *        to come from the module's own fallback
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
    const codes = opts.billing === false ? [] : opts.billingCodes || ['two_payment'];
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
        codes
            .map(function (code) {
                return (
                    '<div class="checkout-billing-address">' +
                    '<div class="billing-address-same-as-shipping-block">' +
                    '<input type="checkbox" name="billing-address-same-as-shipping"' +
                    (opts.noCheckboxId
                        ? ''
                        : ' id="billing-address-same-as-shipping-' + code + '"') +
                    '>' +
                    '</div>' +
                    '<fieldset class="fieldset address" data-form="billing-new-address"' +
                    ' data-test-code="' +
                    code +
                    '">' +
                    fields +
                    '</fieldset>' +
                    '</div>'
                );
            })
            .join('');

    // Real `window`/`document`, not the harness stubs: the module asks
    // `window.getComputedStyle` whether the shipping form is the one core is
    // actually using, and reads option lists off real `<select>` nodes.
    const model = loadAmdModule(MODEL, { jquery: $ }, { window: window, document: document });
    model.resetMirrorState();
    return model;
}

/** Capture the rendered baseline for every billing form, as production does. */
function captureAllBaselines(model) {
    Array.prototype.forEach.call(document.querySelectorAll(SECONDARY), function (root) {
        model.captureSecondaryAddressBaseline(root);
    });
}

/** One billing form, by the payment code the fixture rendered it for. */
function billing(code) {
    return document.querySelector(SECONDARY + '[data-test-code="' + code + '"]');
}

/** Strip every marker attribute, as core rebuilding the form would. */
function rebuildForms() {
    Array.prototype.forEach.call(document.querySelectorAll('[' + MARKER + ']'), function (node) {
        node.removeAttribute(MARKER);
    });
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

describe('the sync pin is whole-address, and one buyer-edited field freezes all of it', () => {
    /**
     * Each row edits exactly ONE field of the billing address and then asks the
     * mirror to write a second company's address. The expectation is the same
     * for every row: nothing lands, including in the fields the buyer never
     * touched. That is the address-wide ruling — the alternative reading, where
     * only the edited field is protected, would pass a per-field pin.
     */
    const rows = [
        ['the company name', 'company', 'Buyer Owned Ltd'],
        ['the organisation number', 'organization', '99999999'],
        ['address line 1', 'street0', 'Buyer House'],
        ['address line 2 — the field a first attempt skipped', 'street1', 'Buyer Annexe'],
        ['the city', 'city', 'Bristol'],
        ['the postcode', 'postcode', 'BS1 4DJ'],
        ['the country', 'country', 'NO'],
        ['the state/region — the other field a first attempt skipped', 'region', '43']
    ];

    test.each(rows)(
        'an edit to %s pins the WHOLE billing address',
        (description, field, buyerValue) => {
            const model = renderCheckout({ regions: true });
            model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
            model.applyAddress(COMPANY_A);
            expect(read(SECONDARY, 'city')).toBe('London');

            buyerTypes(SECONDARY, field, buyerValue);
            const before = Object.keys(FIELD_SELECTORS)
                .concat('region')
                .map(function (name) {
                    return read(SECONDARY, name);
                });

            expect(model.applyAddress(COMPANY_B)).toBe(0);

            // The default address always takes the write — it is the form the
            // buyer is working in, and it is never pinned.
            expect(read(PRIMARY, 'city')).toBe('Stockholm');
            expect(model.secondaryAddressIsPinned(document.querySelector(SECONDARY))).toBe(true);
            expect(
                Object.keys(FIELD_SELECTORS)
                    .concat('region')
                    .map(function (name) {
                        return read(SECONDARY, name);
                    })
            ).toEqual(before);
        }
    );

    test('an untouched billing address syncs every write', () => {
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));

        expect(model.applyAddress(COMPANY_A)).toBe(1);
        expect(read(SECONDARY, 'city')).toBe('London');

        expect(model.applyAddress(COMPANY_B)).toBe(1);
        expect(read(SECONDARY, 'city')).toBe('Stockholm');
        expect(read(SECONDARY, 'postcode')).toBe('111 22');
        expect(read(SECONDARY, 'street0')).toBe('2 Second Street');
        expect(model.secondaryAddressIsPinned(document.querySelector(SECONDARY))).toBe(false);
    });

    test('a buyer edit that restores the mirrored value resumes syncing', () => {
        // The whole of the "no resume control" design: the pin is a content
        // match, so undoing the edit is what lifts it. Nothing else does.
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        model.applyAddress(COMPANY_A);

        buyerTypes(SECONDARY, 'street1', 'Buyer Annexe');
        expect(model.applyAddress(COMPANY_B)).toBe(0);

        buyerTypes(SECONDARY, 'street1', '');
        expect(model.applyAddress(COMPANY_B)).toBe(1);
        expect(read(SECONDARY, 'city')).toBe('Stockholm');
    });

    test('a country default the buyer never chose does not pin the address', () => {
        // Core renders the billing country pre-selected to the store default,
        // and a select has no reachable empty state. Without the rendered-value
        // baseline, a store default differing from the buyer's shipping country
        // would read as a buyer edit and pin an address they never opened.
        const model = renderCheckout({ regions: true });
        document.querySelector(SECONDARY).querySelector('select[name="country_id"]').value = 'NO';
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));

        expect(model.secondaryAddressIsPinned(document.querySelector(SECONDARY))).toBe(false);
        expect(model.applyAddress(COMPANY_A)).toBe(1);
        expect(read(SECONDARY, 'city')).toBe('London');
    });

    test('a checkout with no billing address form syncs nothing and still fills the default', () => {
        const model = renderCheckout({ regions: true, billing: false });

        expect(model.applyAddress(COMPANY_A)).toBe(0);
        expect(read(PRIMARY, 'city')).toBe('London');
    });
});

describe('country and company propagate from the default address', () => {
    test('a country change reaches an in-sync billing address', () => {
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));

        buyerTypes(PRIMARY, 'country', 'ES');
        expect(model.mirrorFieldsToSecondaryAddresses(['country'])).toBe(1);

        expect(read(SECONDARY, 'country')).toBe('ES');
    });

    test('a country change does not reach a pinned billing address', () => {
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        buyerTypes(SECONDARY, 'street1', 'Buyer Annexe');

        buyerTypes(PRIMARY, 'country', 'ES');
        expect(model.mirrorFieldsToSecondaryAddresses(['country'])).toBe(0);

        expect(read(SECONDARY, 'country')).toBe('GB');
    });

    test('a company change reaches an in-sync billing address', () => {
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));

        buyerTypes(PRIMARY, 'company', 'Example Trading Ltd');
        expect(model.mirrorFieldsToSecondaryAddresses(['company'])).toBe(1);

        expect(read(SECONDARY, 'company')).toBe('Example Trading Ltd');
    });

    test('a mirrored country retracts the region record it invalidates', () => {
        // Core rebuilds the region option list from the new country's directory
        // data and resets the control while doing it. A region record written
        // under the previous country would then stand against an empty field —
        // the exact mismatch the pin reads as a buyer edit — and would freeze an
        // address the buyer never touched.
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        model.applyAddress(Object.assign({ region: 'California' }, COMPANY_A));
        expect(read(SECONDARY, 'region')).toBe('California');

        buyerTypes(PRIMARY, 'country', 'ES');
        model.mirrorFieldsToSecondaryAddresses(['country']);
        // Core's own reset, reproduced: the control is emptied by the country
        // change, not by anything this module did.
        document.querySelector(SECONDARY).querySelector('select[name="region_id"]').value = '';

        expect(model.secondaryAddressIsPinned(document.querySelector(SECONDARY))).toBe(false);
    });

    test('a country change that repopulates the region select does not pin the address', () => {
        // Live-found regression. Core rebuilds the region option list from the
        // new country's directory data and lands on its own placeholder option,
        // whose LABEL is not empty. Reading that label as the buyer's answer
        // pinned a billing address nobody had touched, permanently.
        const model = renderCheckout({ regions: false });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        expect(read(SECONDARY, 'region')).toBe('');

        buyerTypes(PRIMARY, 'country', 'ES');
        model.mirrorFieldsToSecondaryAddresses(['country']);
        // What core does next: the country now has regions, so the select is
        // repopulated and sits on its placeholder.
        const select = document.querySelector(SECONDARY).querySelector('select[name="region_id"]');
        select.innerHTML =
            '<option value="">Please select a region, state or province</option>' +
            '<option value="61">Madrid</option>';
        select.selectedIndex = 0;

        expect(model.secondaryAddressIsPinned(document.querySelector(SECONDARY))).toBe(false);
        expect(model.applyAddress(COMPANY_A)).toBe(1);
    });

    test('propagation does nothing when there is no default address form', () => {
        const model = renderCheckout({ regions: true, billing: false });
        document.querySelector(PRIMARY).setAttribute('id', 'not-the-shipping-form');

        expect(model.mirrorFieldsToSecondaryAddresses(['country'])).toBe(0);
    });
});

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
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));

        model.applyAddress(Object.assign({ city: 'Ashford', postal_code: 'TN23' }, address));

        [PRIMARY, SECONDARY].forEach(function (root) {
            expect(read(root, 'street0')).toBe(line1);
            expect(read(root, 'street1')).toBe(line2);
        });
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
            model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));

            model.applyAddress(Object.assign({ postal_code: 'TN23', street_address: 'A' }, address));

            [PRIMARY, SECONDARY].forEach(function (root) {
                expect(read(root, 'region')).toBe(expectedRegion);
                expect(read(root, 'city')).toBe(expectedCity);
            });
        }
    );

    test('the city append does not grow on a repeated write', () => {
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        const address = { region: 'Kent', city: 'Ashford, Kent', postal_code: 'TN23' };

        model.applyAddress(address);
        model.applyAddress(address);

        expect(read(SECONDARY, 'city')).toBe('Ashford, Kent');
    });
});

describe('collapsing the billing address back into the default one', () => {
    /**
     * The reference platform gets its "reopening re-matches" property from a
     * server round trip that rebuilds the second address form from the first.
     * Magento does not do that: re-checking "My billing and shipping address
     * are the same" leaves the billing form's own field values exactly as the
     * buyer left them (core's `useShippingAddress()` retargets the QUOTE's
     * billing address and hides the form; it never rewrites the form's data).
     *
     * So the two halves of the rule hold here for a different reason than they
     * do there, and both are pinned below: the collapse/reopen cycle itself
     * neither pins a pristine address nor unpins an edited one, and the
     * resumption path is the content match alone. There is deliberately no
     * resume control.
     */
    function collapseAndReopen() {
        const checkbox = document.querySelector(
            'input[name="billing-address-same-as-shipping"]'
        );
        checkbox.checked = true;
        checkbox.dispatchEvent(new window.Event('click', { bubbles: true }));
        checkbox.checked = false;
        checkbox.dispatchEvent(new window.Event('click', { bubbles: true }));
    }

    test('a pristine billing address still syncs after a collapse and reopen', () => {
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        model.applyAddress(COMPANY_A);

        collapseAndReopen();

        expect(model.secondaryAddressIsPinned(document.querySelector(SECONDARY))).toBe(false);
        expect(model.applyAddress(COMPANY_B)).toBe(1);
        expect(read(SECONDARY, 'city')).toBe('Stockholm');
    });

    test('a buyer-edited billing address stays pinned across a collapse and reopen', () => {
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        model.applyAddress(COMPANY_A);
        buyerTypes(SECONDARY, 'city', 'Bristol');

        collapseAndReopen();

        expect(model.secondaryAddressIsPinned(document.querySelector(SECONDARY))).toBe(true);
        expect(model.applyAddress(COMPANY_B)).toBe(0);
        expect(read(SECONDARY, 'city')).toBe('Bristol');
    });
});

describe('the mirror record survives core rebuilding the form', () => {
    test('a re-rendered billing address is still attributed to the mirror', () => {
        // Fire Checkout rebuilds these forms on every totals change, which
        // destroys every marker attribute. Without the module-scoped record the
        // rebuilt form would read as buyer-authored — non-empty fields with
        // nothing on record — and pin itself.
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        model.applyAddress(COMPANY_A);

        const secondary = document.querySelector(SECONDARY);
        Array.prototype.forEach.call(secondary.querySelectorAll('[' + MARKER + ']'), function (n) {
            n.removeAttribute(MARKER);
        });

        expect(model.secondaryAddressIsPinned(secondary)).toBe(false);
        expect(model.applyAddress(COMPANY_B)).toBe(1);
    });
});

describe('a country switch retracts the autofill from both addresses', () => {
    test('an in-sync billing address is reverted alongside the default one', () => {
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        model.applyAddress(COMPANY_A);

        expect(model.revertAutofilledAddress()).toBe(6);

        expect(read(PRIMARY, 'city')).toBe('');
        expect(read(SECONDARY, 'city')).toBe('');
        expect(read(SECONDARY, 'street0')).toBe('');
    });

    test('a pinned billing address is skipped whole, not field by field', () => {
        // The address-wide rule applies to the retraction too: clearing the
        // fields of a pinned address that happen to still match would be the
        // plugin deleting part of an address the buyer has taken ownership of.
        const model = renderCheckout({ regions: true });
        model.captureSecondaryAddressBaseline(document.querySelector(SECONDARY));
        model.applyAddress(COMPANY_A);
        buyerTypes(SECONDARY, 'postcode', 'BS1 4DJ');

        expect(model.revertAutofilledAddress()).toBe(3);

        expect(read(PRIMARY, 'city')).toBe('');
        expect(read(SECONDARY, 'city')).toBe('London');
        expect(read(SECONDARY, 'street0')).toBe('1 Example Street');
    });
});

describe('the content match is trimmed and case-folded', () => {
    /**
     * A buyer who retypes what is already there in different case, or who leaves
     * a trailing space behind, has not authored a different answer and must not
     * freeze the address. Stated as its own table because the rule is a product
     * ruling, not an implementation detail of the comparison.
     */
    const rows = [
        ['identical', 'London', true],
        ['the same text in another case', 'LONDON', true],
        ['the same text with surrounding space', '  London  ', true],
        ['genuinely different text', 'Bristol', false]
    ];

    test.each(rows)('%s -> still syncing = %s', (description, retyped, stillSyncing) => {
        const model = renderCheckout({ regions: true });
        captureAllBaselines(model);
        model.applyAddress(COMPANY_A);
        expect(read(SECONDARY, 'city')).toBe('London');

        buyerTypes(SECONDARY, 'city', retyped);

        expect(model.secondaryAddressIsPinned(billing('two_payment'))).toBe(!stillSyncing);
        expect(model.applyAddress(COMPANY_B)).toBe(stillSyncing ? 1 : 0);
    });
});

describe('each billing form is judged and recorded on its own', () => {
    // Core renders one billing form PER PAYMENT METHOD, so the record cannot be
    // shared: a value written into one form is not evidence about another.
    test('an edit to one payment method’s billing form does not freeze another’s', () => {
        const model = renderCheckout({ regions: true, billingCodes: ['two_payment', 'checkmo'] });
        captureAllBaselines(model);
        expect(model.applyAddress(COMPANY_A)).toBe(2);

        buyerTypes(SECONDARY + '[data-test-code="two_payment"]', 'city', 'Bristol');

        expect(model.applyAddress(COMPANY_B)).toBe(1);
        expect(model.secondaryAddressIsPinned(billing('two_payment'))).toBe(true);
        expect(model.secondaryAddressIsPinned(billing('checkmo'))).toBe(false);
        expect(billing('two_payment').querySelector('input[name="city"]').value).toBe('Bristol');
        expect(billing('checkmo').querySelector('input[name="city"]').value).toBe('Stockholm');
    });

    test('two forms with no checkbox id to key on still get separate records', () => {
        // The fallback must not collapse to one shared key. If it did, the second
        // form would be judged against writes made into the first — empty fields
        // against a non-empty record, which pins a pristine address.
        const model = renderCheckout({
            regions: true,
            billingCodes: ['a', 'b'],
            noCheckboxId: true
        });
        captureAllBaselines(model);

        expect(model.applyAddress(COMPANY_A)).toBe(2);
        expect(billing('a').querySelector('input[name="city"]').value).toBe('London');
        expect(billing('b').querySelector('input[name="city"]').value).toBe('London');
        expect(model.secondaryAddressIsPinned(billing('b'))).toBe(false);
    });
});

describe('the rendered baseline refuses to be captured from a half-built form', () => {
    test('a form whose fields have not rendered yet defers, and is captured on the next pass', () => {
        // Core inserts the billing fieldset BEFORE its child field components
        // resolve their templates, and `$.async` fires on the insertion. Sealing
        // a baseline of nothing would make every field of the real render read
        // as buyer-authored and pin the address before the buyer saw it.
        const model = renderCheckout({ regions: true });
        const root = billing('two_payment');
        const fields = root.innerHTML;
        root.innerHTML = '';

        model.captureSecondaryAddressBaseline(root);

        root.innerHTML = fields;
        root.querySelector('select[name="country_id"]').value = 'NO';
        model.captureSecondaryAddressBaseline(root);

        expect(model.secondaryAddressIsPinned(root)).toBe(false);
        expect(model.applyAddress(COMPANY_A)).toBe(1);
    });

    test('a re-captured baseline never adopts what the buyer has typed', () => {
        const model = renderCheckout({ regions: true });
        captureAllBaselines(model);
        buyerTypes(SECONDARY, 'city', 'Bristol');

        model.captureSecondaryAddressBaseline(billing('two_payment'));

        expect(model.secondaryAddressIsPinned(billing('two_payment'))).toBe(true);
    });

    test('a form appearing after the mirror has written is judged on emptiness alone', () => {
        // The payment-method switch: every method's billing form renders from the
        // same quote billing address, so a form appearing now can be carrying an
        // edit the buyer made in a sibling. Its render is no longer evidence of
        // anything, so only a genuinely empty field counts as unanswered.
        const model = renderCheckout({ regions: true, billingCodes: ['two_payment', 'checkmo'] });
        model.captureSecondaryAddressBaseline(billing('two_payment'));
        model.applyAddress(COMPANY_A);
        buyerTypes(SECONDARY + '[data-test-code="two_payment"]', 'city', 'Bristol');
        // The sibling form renders carrying the buyer's own edit.
        billing('checkmo').querySelector('input[name="city"]').value = 'Bristol';

        model.captureSecondaryAddressBaseline(billing('checkmo'));

        expect(model.secondaryAddressIsPinned(billing('checkmo'))).toBe(true);
        expect(billing('checkmo').querySelector('input[name="city"]').value).toBe('Bristol');
    });
});

describe('a shipping form core is not using is not the default address', () => {
    test('a display:none shipping form neither sources nor receives a write', () => {
        // For a logged-in buyer with saved addresses core still RENDERS the
        // new-address form and only hides it, so it sits there holding store
        // defaults for the whole checkout. Reading country/company from it would
        // propagate values nobody chose.
        const model = renderCheckout({ regions: true, hiddenPrimary: true });
        captureAllBaselines(model);

        expect(model.mirrorFieldsToSecondaryAddresses(['country'])).toBe(0);
        // With no default form in use the billing form is the buyer's own
        // working form: it is written directly, and reports no sync.
        expect(model.applyAddress(COMPANY_A)).toBe(0);
        expect(read(SECONDARY, 'city')).toBe('London');
        expect(read(PRIMARY, 'city')).toBe('');
    });

    test('the same form inside a VISIBLE wrapper is the default address', () => {
        // Live-found regression, twice over. The step collapse that hides the
        // shipping step on the payment step must not read as "core is not using
        // this form", and neither must the form being empty — a country switch
        // retracts the autofill and empties it, and the very next thing that
        // switch does is ask for the country to be propagated.
        const model = renderCheckout({ regions: true, savedAddressWrapper: true });
        captureAllBaselines(model);

        buyerTypes(PRIMARY, 'country', 'ES');
        expect(model.mirrorFieldsToSecondaryAddresses(['country'])).toBe(1);
        expect(read(SECONDARY, 'country')).toBe('ES');
    });

    test('an EMPTY form in use is still the default address', () => {
        const model = renderCheckout({ regions: true, savedAddressWrapper: true });
        captureAllBaselines(model);

        expect(model.applyAddress(COMPANY_A)).toBe(1);
        expect(read(PRIMARY, 'city')).toBe('London');
    });

    test('the direct write is recorded, so a later evaluation attributes it', () => {
        const model = renderCheckout({ regions: true, hiddenPrimary: true });
        captureAllBaselines(model);
        model.applyAddress(COMPANY_A);

        rebuildForms();

        expect(model.secondaryAddressIsPinned(billing('two_payment'))).toBe(false);
    });
});

describe('a replacement pick does not strand the previous line 2', () => {
    test('a payload with no locator retracts a line 2 the plugin wrote', () => {
        const model = renderCheckout({ regions: true });
        captureAllBaselines(model);
        model.applyAddress(Object.assign({ building: 'Mill House' }, COMPANY_A));
        expect(read(SECONDARY, 'street1')).toBe('1 Example Street');

        model.applyAddress(COMPANY_B);

        [PRIMARY, SECONDARY].forEach(function (root) {
            expect(read(root, 'street0')).toBe('2 Second Street');
            expect(read(root, 'street1')).toBe('');
        });
    });

    test("a line 2 the BUYER wrote is never retracted", () => {
        // Same code path, opposite outcome: the buyer's line 2 has no recording,
        // so it is not the plugin's to clear — and it pins the address anyway.
        const model = renderCheckout({ regions: true });
        captureAllBaselines(model);
        buyerTypes(PRIMARY, 'street1', 'Buyer Annexe');

        model.applyAddress(COMPANY_B);

        expect(read(PRIMARY, 'street1')).toBe('Buyer Annexe');
    });
});

describe('the country is mirrored before the address it belongs to', () => {
    test('both forms route the same region the same way', () => {
        // The store default is frequently not the buyer's country, so the billing
        // form can be sitting on a different country from the address about to be
        // written into it — and the region routing reads that country's own
        // option list. Without the country going first, one form selected a state
        // while the other appended a state name into its city.
        const model = renderCheckout({ regions: true });
        billing('two_payment').querySelector('select[name="country_id"]').value = 'NO';
        captureAllBaselines(model);

        model.applyAddress(Object.assign({ region: 'California' }, COMPANY_A));

        expect(read(PRIMARY, 'region')).toBe('California');
        expect(read(SECONDARY, 'region')).toBe('California');
        expect(read(SECONDARY, 'city')).toBe('London');
        expect(read(SECONDARY, 'country')).toBe('GB');
    });
});

describe('a country switch after core has rebuilt the form', () => {
    test('the mirror’s own values are still retracted, and the address is not pinned', () => {
        // The markers are gone with the nodes that carried them; the module
        // record is the only surviving evidence those values are the plugin's.
        // Retracting the RECORD without clearing the field left the previous
        // country's address in the billing form attributed to nobody, which the
        // pin reads as a buyer edit and freezes permanently.
        const model = renderCheckout({ regions: true });
        captureAllBaselines(model);
        model.applyAddress(COMPANY_A);

        rebuildForms();
        expect(model.revertAutofilledAddress()).toBeGreaterThan(0);

        expect(read(SECONDARY, 'city')).toBe('');
        expect(model.secondaryAddressIsPinned(billing('two_payment'))).toBe(false);
        expect(model.applyAddress(COMPANY_B)).toBe(1);
        expect(read(SECONDARY, 'city')).toBe('Stockholm');
    });

    test('an in-sync billing address resumes syncing after a revert', () => {
        const model = renderCheckout({ regions: true });
        captureAllBaselines(model);
        model.applyAddress(COMPANY_A);

        model.revertAutofilledAddress();

        expect(model.secondaryAddressIsPinned(billing('two_payment'))).toBe(false);
        expect(model.applyAddress(COMPANY_B)).toBe(1);
        expect(read(SECONDARY, 'city')).toBe('Stockholm');
    });
});

describe('the mirror record belongs to billing addresses only', () => {
    test('the default address neither consults a record nor gets stamped with a key', () => {
        // `secondaryAddressKey()` is reached for the DEFAULT form too — the
        // retraction asks every form what it is on record as having written. It
        // must answer "nothing" there rather than minting an identity: a minted
        // key stamps an attribute onto a form that is not a billing address, and
        // burns a key a real billing form would otherwise have been given.
        const model = renderCheckout({ regions: true, noCheckboxId: true });
        captureAllBaselines(model);
        model.applyAddress(COMPANY_A);
        rebuildForms();

        model.revertAutofilledAddress();

        const primary = document.querySelector(PRIMARY);
        expect(primary.hasAttribute('data-two-mirror-key')).toBe(false);
        // And the billing form still got its own, so the guard did not disable
        // the fallback it is guarding.
        expect(billing('two_payment').hasAttribute('data-two-mirror-key')).toBe(true);
    });
});
