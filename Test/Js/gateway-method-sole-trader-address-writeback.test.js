/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §5 — a completed sole-trader signup writes the buyer's registered
 * ADDRESS, not just their identity.
 *
 * `/autofill/v1/buyer/current` has always answered with the address beside the
 * organisation number and company name, and the call sites that consumed it
 * read the two identity fields and threw the address away — so a buyer who had
 * just enrolled was asked to retype an address the plugin was holding.
 *
 * Two rules are the ones most likely to be "tidied" back into a bug, and each
 * has its own case below:
 *
 *  - the write is NOT gated on the address-lookup switch that gates an ordinary
 *    company-search selection. That switch is legitimately off wherever company
 *    search is not mounted in the address area, which is exactly where this
 *    flow lives, so gating here leaves the write permanently dead;
 *  - the write happens ONCE PER IDENTITY, so a replayed ACCEPTED cannot
 *    overwrite a correction the buyer made after the first write — and
 *    `forgetAdoptions()` is what re-arms it.
 *
 * Field routing itself is pinned against the real shared model in
 * company-search-address-field-routing.test.js; the double here records the
 * payload and the scope handed to it, which is what this surface owns.
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule, loadCompanyCapture, brandConfigMock, defaultMocks } = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';

const CHECKOUT_PAGE_URL = 'https://checkout.example.two.inc';

const BILLING = {
    street: 'Mill Lane',
    building: 'Mill House',
    postal_code: 'TN23 1AA',
    city: 'Ashford',
    region: 'Kent',
    country_code: 'GB'
};

const SHIPPING = { street: 'Other Lane', city: 'Elsewhere', country_code: 'GB' };

/** Sentinel for the scope the shared model resolves; see the scope case. */
const BILLING_ROLE_ROOT = { billingRoleFormRoot: true };

const BUYER = {
    email: 'trader@example.com',
    organization_number: '999888777',
    company_name: 'Example Trader',
    billing_address: BILLING
};

/**
 * The real flow, reached through Luma's wired capture component, over a
 * recording company-search double.
 *
 * Loaded fresh per test: the once-per-identity guard lives on the flow
 * instance, and the identity is a page-level singleton.
 *
 * @returns {object} `{ flow, rec, identity, component }`
 */
function loadFlow() {
    /** `failAddressWrite` is flipped mid-test to model a DOM failure. */
    const rec = { applied: [], roots: [], phones: [], adopted: [], failAddressWrite: false };

    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });

    const companySearch = Object.assign(
        {},
        defaultMocks()['Two_Gateway/js/model/company-search'],
        {
            apiClientParams: function () { return {}; },
            billingRoleFormRoot: function () { return BILLING_ROLE_ROOT; },
            applyAddress: function (address, root) {
                if (rec.failAddressWrite) throw new Error('DOM write failed');
                rec.applied.push(address);
                rec.roots.push(root);
            },
            applyTelephone: function (phone) { rec.phones.push(phone); return true; }
        }
    );

    const component = loadCompanyCapture(
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search': companySearch,
            'Two_Gateway/js/model/brand-config': brandConfigMock({
                checkoutPageUrl: CHECKOUT_PAGE_URL,
                checkoutApiUrl: 'https://api.example',
                isCompanySearchEnabled: true
            })
        },
        {
            document: document,
            window: { open: function () { return null; }, addEventListener: function () {}, removeEventListener: function () {} },
            btoa: global.btoa,
            setInterval: function () { return 1; },
            clearInterval: function () {},
            fetch: function () { return Promise.resolve({ ok: false, status: 404 }); }
        }
    );
    component.start();
    identity.captureMode('soletrader');

    const adopt = component.adoptSoleTrader.bind(component);
    component.adoptSoleTrader = function (buyer) {
        rec.adopted.push(buyer);
        return adopt(buyer);
    };

    return { flow: component.soleTrader(), rec: rec, identity: identity, component: component };
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('which address on the buyer record is written', () => {
    test.each([
        [
            { billing_address: BILLING, shipping_address: SHIPPING },
            BILLING,
            'the registered billing address wins over a shipping one'
        ],
        [{ shipping_address: BILLING }, BILLING, 'a shipping address is the fallback when there is no billing one'],
        [{ billing_address: null, shipping_address: BILLING }, BILLING, 'a null billing address falls back'],
        [{}, null, 'no address at all: nothing is written'],
        [{ billing_address: 'Mill House' }, null, 'a non-object address is not an address'],
        [{ billing_address: null }, null, 'a null address never blanks the form']
    ])('%p -> %p (%s)', (record, expected) => {
        const { flow, rec } = loadFlow();

        flow.adoptBuyer(Object.assign({ organization_number: '1', company_name: 'Example' }, record));

        expect(rec.applied).toEqual(expected === null ? [] : [expected]);
    });

    test('the write is scoped to the billing-role form, not document-wide', () => {
        // §1(a.3): this flow captures as the invoice role, and the payment step
        // has more than one address form in the DOM to get that wrong in.
        const { flow, rec } = loadFlow();

        flow.adoptBuyer(BUYER);

        expect(rec.roots).toEqual([BILLING_ROLE_ROOT]);
    });

    test.each([
        [null, 'a null buyer'],
        ['buyer', 'a string'],
        [undefined, 'nothing at all']
    ])('%p adopts nothing (%s)', (buyer) => {
        const { flow, rec } = loadFlow();

        flow.adoptBuyer(buyer);

        expect(rec.adopted).toEqual([]);
        expect(rec.applied).toEqual([]);
    });
});

describe('the write ignores the address-lookup switches (§5)', () => {
    test('no brand config is read on the write path at all', () => {
        // Stronger than asserting the write landed with the switches off: a gate
        // added in a helper, or read through the brand config, throws here. A
        // case that only checked the outcome would keep passing if the flag it
        // consulted happened to be on.
        const { flow, rec, component } = loadFlow();
        component.config = function () {
            throw new Error('the address-lookup switch must not be read here');
        };

        flow.adoptBuyer(BUYER);

        expect(rec.applied).toEqual([BILLING]);
    });

    test('a sole trader with no trading name still gets their address', () => {
        // A name/number mismatch is its own defect class; the address write must
        // not be collateral damage of the identity half being incomplete.
        const { flow, rec, identity } = loadFlow();

        flow.adoptBuyer({ organization_number: 'TWO:123', company_name: '', billing_address: BILLING });

        expect(rec.applied).toEqual([BILLING]);
        expect(identity.companyName()).toBe('');
    });
});

describe('the write happens once per identity', () => {
    test.each([
        [
            { organization_number: '999888777', email: 'a@example.com' },
            { organization_number: '999888777', email: 'b@example.com' },
            1,
            'the organisation number is the identity; the email is not'
        ],
        [
            { organization_number: '999888777' },
            { organization_number: '111222333' },
            2,
            'a different organisation number is a different sole trader'
        ],
        [
            { email: 'a@example.com' },
            { email: 'a@example.com' },
            1,
            'with no number, the email identifies the buyer'
        ],
        [
            { email: 'a@example.com' },
            { email: 'b@example.com' },
            2,
            'two numberless buyers are not collapsed onto one key'
        ],
        [
            {},
            {},
            2,
            'nothing tells one numberless, emailless buyer from another, so the write repeats'
        ]
    ])('%p then %p -> %p writes (%s)', (first, second, expectedWrites) => {
        const { flow, rec } = loadFlow();

        flow.adoptBuyer(Object.assign({ company_name: 'Example', billing_address: BILLING }, first));
        flow.adoptBuyer(Object.assign({ company_name: 'Example', billing_address: BILLING }, second));

        expect(rec.applied).toHaveLength(expectedWrites);
    });

    test('the identity itself is still written on every replay, only the address is guarded', () => {
        const { flow, rec } = loadFlow();

        flow.adoptBuyer(BUYER);
        flow.adoptBuyer(BUYER);

        expect(rec.adopted).toEqual([BUYER, BUYER]);
        expect(rec.applied).toEqual([BILLING]);
    });

    test('forgetAdoptions() re-arms the write', () => {
        // Leaving sole-trader mode reverts the address; without the re-arm the
        // buyer could never get it back by re-adopting the same identity.
        const { flow, rec } = loadFlow();

        flow.adoptBuyer(BUYER);
        flow.forgetAdoptions();
        flow.adoptBuyer(BUYER);

        expect(rec.applied).toEqual([BILLING, BILLING]);
    });

    test('a throw in the address write leaves the identity filled and the write retryable', () => {
        const { flow, rec, identity } = loadFlow();
        rec.failAddressWrite = true;

        flow.adoptBuyer(BUYER);

        expect(identity.companyId()).toBe(BUYER.organization_number);
        expect(rec.applied).toEqual([]);

        // The failure did not consume the one chance: the same identity writes
        // on the next attempt.
        rec.failAddressWrite = false;
        flow.adoptBuyer(BUYER);
        expect(rec.applied).toEqual([BILLING]);
    });
});
