/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §5: a completed sole-trader signup must write the buyer's
 * registered ADDRESS, not just their identity.
 *
 * `fetchBuyer()` (`/autofill/v1/buyer/current`) has always answered with the
 * buyer's address beside their organisation number and company name, and all
 * three call sites that consumed it read the two identity fields and threw the
 * address away — so a buyer who had just enrolled was asked to retype an
 * address the plugin was holding. The three sites are the passive prefetch, the
 * chip click resolving against an already-prefetched buyer, and the popup's
 * post-signup `ACCEPTED` message; they now share one write-back,
 * `adoptSoleTraderBuyer()`.
 *
 * The GATE is the part most likely to be "tidied" back into a bug, and it has
 * its own case below: the write must NOT be gated on the address-lookup switch
 * that gates an ordinary company-search selection's address write. That switch
 * is legitimately off wherever company search is not mounted in the address
 * area — which is exactly where the sole-trader entry point lives — so gating
 * here leaves the write permanently dead on the shops the feature runs on.
 *
 * Field routing itself is pinned against the real shared model in
 * company-search-address-field-routing.test.js; the double here records the
 * payload handed to it, which is what this surface is responsible for.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';
const CHECKOUT_PAGE_URL = 'https://checkout.example';
const ENTERED_EMAIL = 'entered@example.com';

const BILLING = {
    street: 'Mill Lane',
    building: 'Mill House',
    postal_code: 'TN23 1AA',
    city: 'Ashford',
    region: 'Kent',
    country_code: 'GB'
};

const BUYER = {
    email: ENTERED_EMAIL,
    organization_number: '999888777',
    company_name: 'Example Trader',
    billing_address: BILLING
};

/**
 * Load the renderer with a recording company-search double.
 *
 * @param {object|null} buyer what the stubbed `fetchBuyer()` resolves to
 * @param {object} [options] `addressAreaSearchEnabled` / `addressSearchEnabled`
 *        — the two switches the write must ignore
 * @returns {object} { renderer, applied, listeners }
 */
function loadRenderer(buyer, options) {
    const opts = options || {};
    const applied = [];
    const listeners = [];
    const companySearch = Object.assign(
        {},
        loadAmdModule(SEARCH, { jquery: require('./amd-harness').defaultMocks().jquery }),
        {
            applyAddress: function (address) {
                applied.push(address);
                return { city: address.city };
            }
        }
    );

    const renderer = loadAmdModule(
        RENDERER,
        { 'Two_Gateway/js/model/company-search': companySearch },
        {
            window: {
                checkoutConfig: { payment: {} },
                addEventListener: function (name, fn) {
                    listeners.push({ name: name, fn: fn });
                }
            }
        }
    );

    renderer._brandConfig = {
        checkoutPageUrl: CHECKOUT_PAGE_URL,
        isAddressSearchEnabled: !!opts.addressSearchEnabled
    };
    renderer.isAddressAreaCompanySearchEnabled = !!opts.addressAreaSearchEnabled;
    renderer.isOrderIntentEnabled = false;
    renderer.prefetched = { ready: false, buyer: null, matches: false };
    renderer.prefetchedEmail = null;
    renderer.getEmail = () => ENTERED_EMAIL;
    renderer.getTokens = () => Promise.resolve({ delegation_token: 'dt', autofill_token: 'at' });
    renderer.fetchBuyer = () => Promise.resolve(buyer);

    return { renderer: renderer, applied: applied, listeners: listeners };
}

/**
 * The `message` handler `popupMessageListener()` binds on window.
 *
 * @param {object} renderer loaded renderer
 * @param {Array} listeners captured window listeners
 * @returns {Function} the handler
 */
function messageHandler(renderer, listeners) {
    renderer.popupMessageListener();
    const bound = listeners.filter((l) => l.name === 'message');
    expect(bound).toHaveLength(1);
    return bound[0].fn;
}

/** Two microtask turns, which is what the ACCEPTED branch needs to settle. */
function settle() {
    return Promise.resolve().then(() => Promise.resolve());
}

describe('every path that adopts a sole trader writes their address', () => {
    test('the passive prefetch writes it', () => {
        const { renderer, applied } = loadRenderer(BUYER);
        renderer.showModeTab(true);

        return renderer.prefetchSoleTrader().then(() => {
            expect(applied).toEqual([BILLING]);
            expect(renderer.companyId()).toBe(BUYER.organization_number);
            expect(renderer.showSoleTrader()).toBe(true);
        });
    });

    test('the chip click against an already-prefetched buyer writes it', () => {
        const { renderer, applied } = loadRenderer(BUYER);
        renderer.prefetched = { ready: true, buyer: BUYER, matches: true };

        renderer.soleTraderMode();

        expect(applied).toEqual([BILLING]);
        expect(renderer.companyName()).toBe(BUYER.company_name);
        expect(renderer.showPopupMessage()).toBe(false);
    });

    test('a completed signup writes it', () => {
        const { renderer, applied, listeners } = loadRenderer(BUYER);
        const handler = messageHandler(renderer, listeners);
        renderer.showSoleTrader(true);

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED' });

        return settle().then(() => {
            expect(applied).toEqual([BILLING]);
            expect(renderer.companyId()).toBe(BUYER.organization_number);
        });
    });

    test('a prefetch that resolves NO matching buyer writes nothing', () => {
        const { renderer, applied } = loadRenderer({
            email: 'someone.else@example.com',
            organization_number: BUYER.organization_number,
            company_name: BUYER.company_name,
            billing_address: BILLING
        });
        renderer.showModeTab(true);

        return renderer.prefetchSoleTrader().then(() => {
            expect(applied).toEqual([]);
        });
    });
});

describe('the write-back ignores the address-lookup switches (§5)', () => {
    test.each([
        [false, false, 'both switches off — the shape every current shop runs'],
        [false, true, 'address search on, address-area company search off'],
        [true, false, 'address-area company search on, address search off'],
        [true, true, 'both on']
    ])(
        'addressArea=%p addressSearch=%p writes the address anyway (%s)',
        (addressAreaSearchEnabled, addressSearchEnabled) => {
            const { renderer, applied } = loadRenderer(BUYER, {
                addressAreaSearchEnabled,
                addressSearchEnabled
            });

            renderer.adoptSoleTraderBuyer(BUYER);

            expect(applied).toEqual([BILLING]);
        }
    );

    test('neither switch is even read on the write path', () => {
        // Stated against the source because the gate's absence is the
        // behaviour: a spec that only asserted the write landed would keep
        // passing if someone added a gate whose flag happened to be on.
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');
        const write = src.match(/writeSoleTraderAddress\(buyer\)\s*\{[\s\S]*?\n {8}\},/);
        expect(write).not.toBeNull();
        expect(write[0]).not.toMatch(/isAddress\w*Enabled/);
        const adopt = src.match(/adoptSoleTraderBuyer\(buyer\)\s*\{[\s\S]*?\n {8}\},/);
        expect(adopt).not.toBeNull();
        expect(adopt[0]).not.toMatch(/isAddress\w*Enabled/);
    });
});

describe('what the write-back does with an awkward buyer record', () => {
    test.each([
        [
            { billing_address: BILLING, shipping_address: { city: 'Elsewhere' } },
            BILLING,
            'the registered billing address wins over a shipping one'
        ],
        [
            { shipping_address: BILLING },
            BILLING,
            'a shipping address is the fallback when there is no billing one'
        ],
        [{ billing_address: null, shipping_address: BILLING }, BILLING, 'a null billing address falls back'],
        [{}, null, 'no address at all: nothing is written'],
        [{ billing_address: 'Mill House' }, null, 'a non-object address is not an address'],
        [{ billing_address: null }, null, 'a null address never blanks the form']
    ])('%p -> %p (%s)', (record, expected) => {
        const { renderer, applied } = loadRenderer(null);

        const wrote = renderer.adoptSoleTraderBuyer(
            Object.assign({ organization_number: '1', company_name: 'Example' }, record)
        );

        expect(applied).toEqual(expected === null ? [] : [expected]);
        expect(wrote).toBe(expected !== null);
    });

    test('a sole trader with no trading name still gets their address', () => {
        // fillCompanyData() refuses a nameless pair — correctly, a name/number
        // mismatch is its own defect class — and the address write must not be
        // collateral damage of that refusal.
        const { renderer, applied } = loadRenderer(null);

        renderer.adoptSoleTraderBuyer({
            organization_number: 'TWO:123',
            company_name: '',
            billing_address: BILLING
        });

        expect(applied).toEqual([BILLING]);
        expect(renderer.companyName()).toBe('');
    });

    test('adoption is safe against a non-buyer', () => {
        const { renderer, applied } = loadRenderer(null);

        expect(renderer.adoptSoleTraderBuyer(null)).toBe(false);
        expect(renderer.adoptSoleTraderBuyer('buyer')).toBe(false);
        expect(applied).toEqual([]);
    });
});

describe('re-entrancy', () => {
    test('a repeated adoption re-writes the same address and starts no second intent', () => {
        // The popup can post ACCEPTED more than once (double submit, a reopened
        // window) and a re-render can replay the prefetch. The address write is
        // idempotent by construction — same payload, same fields — and the
        // order intent stays behind fillCompanyData()'s in-flight guard rather
        // than gaining a trigger of its own here.
        const { renderer, applied } = loadRenderer(BUYER);
        renderer.isOrderIntentEnabled = true;
        let intents = 0;
        renderer.placeOrderIntent = function () {
            intents += 1;
            return {
                always: function () {
                    return this;
                },
                done: function () {
                    return this;
                },
                fail: function () {
                    return this;
                }
            };
        };
        renderer.clearOrderIntentNotices = function () {};

        renderer.adoptSoleTraderBuyer(BUYER);
        renderer.adoptSoleTraderBuyer(BUYER);

        expect(applied).toEqual([BILLING, BILLING]);
        expect(intents).toBe(1);
    });
});
