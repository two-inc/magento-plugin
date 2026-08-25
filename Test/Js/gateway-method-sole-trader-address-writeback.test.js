/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25461 §5: a completed sole-trader signup must write the buyer's
 * registered ADDRESS, not just their identity.
 *
 * `fetchBuyer()` (`/autofill/v1/buyer/current`) has always answered with the
 * buyer's address beside their organisation number and company name, and the
 * call sites that consumed it read the two identity fields and threw the
 * address away — so a buyer who had just enrolled was asked to retype an
 * address the plugin was holding. The two sites are the chip click's own lookup
 * and the popup's post-signup `ACCEPTED` message; they share one write-back,
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

/** Sentinel for the scope the shared model resolves; see the scope spec. */
const BILLING_ROLE_ROOT = { billingRoleFormRoot: true };

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
    const roots = [];
    const reverts = [];
    const listeners = [];
    const removed = [];
    const companySearch = Object.assign(
        {},
        loadAmdModule(SEARCH, { jquery: require('./amd-harness').defaultMocks().jquery }),
        {
            applyAddress: function (address, root) {
                applied.push(address);
                roots.push(root);
            },
            billingRoleFormRoot: function () {
                return BILLING_ROLE_ROOT;
            },
            revertAutofilledAddress: function () {
                reverts.push(true);
                return 0;
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
                },
                removeEventListener: function (name, fn) {
                    removed.push({ name: name, fn: fn });
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
    renderer.soleTraderLookup = { ready: false, buyer: null, matches: false };
    renderer.soleTraderLookupEmail = null;
    renderer.getEmail = () => ENTERED_EMAIL;
    renderer.getTokens = () => Promise.resolve({ delegation_token: 'dt', autofill_token: 'at' });
    renderer.fetchBuyer = () => Promise.resolve(buyer);

    return {
        renderer: renderer,
        applied: applied,
        roots: roots,
        reverts: reverts,
        listeners: listeners,
        removed: removed
    };
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
    test('the chip click resolves the buyer and writes it', () => {
        const { renderer, applied } = loadRenderer(BUYER);
        renderer.showModeTab(true);

        return renderer.soleTraderMode().then(() => {
            expect(applied).toEqual([BILLING]);
            expect(renderer.companyId()).toBe(BUYER.organization_number);
            expect(renderer.showSoleTrader()).toBe(true);
        });
    });

    test('a repeated chip click against the already-resolved buyer writes it', () => {
        const { renderer, applied } = loadRenderer(BUYER);
        renderer.soleTraderLookup = { ready: true, buyer: BUYER, matches: true };
        renderer.soleTraderLookupEmail = renderer.getEmail();

        return renderer.soleTraderMode().then(() => {
            expect(applied).toEqual([BILLING]);
            expect(renderer.companyName()).toBe(BUYER.company_name);
            expect(renderer.showPopupMessage()).toBe(false);
        });
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

    test('a lookup that resolves NO matching buyer writes nothing', () => {
        const { renderer, applied } = loadRenderer({
            email: 'someone.else@example.com',
            organization_number: BUYER.organization_number,
            company_name: BUYER.company_name,
            billing_address: BILLING
        });
        renderer.showModeTab(true);

        return renderer.lookupSoleTrader().then(() => {
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

    test('neither switch is even READ on the write path', () => {
        // Stronger than asserting the write landed with both off: a gate added
        // in a helper, or read through the brand config, throws here. A spec
        // that only checked the outcome would keep passing if the flag it
        // consulted happened to be on.
        const { renderer, applied } = loadRenderer(BUYER);
        const refuse = {
            get: function () {
                throw new Error('the address-lookup switch must not be read here');
            }
        };
        Object.defineProperty(renderer, 'isAddressAreaCompanySearchEnabled', refuse);
        Object.defineProperty(renderer._brandConfig, 'isAddressSearchEnabled', refuse);

        renderer.adoptSoleTraderBuyer(BUYER);

        expect(applied).toEqual([BILLING]);
    });

    test('the write is scoped to the billing-role form, not document-wide', () => {
        // §1(a.3): the tile writes as the billing/invoice role, and the payment
        // step has more than one address form in the DOM to get that wrong in —
        // Luma leaves the shipping form there and core renders a billing form
        // per payment method.
        const { renderer, roots } = loadRenderer(BUYER);

        renderer.adoptSoleTraderBuyer(BUYER);

        expect(roots).toEqual([BILLING_ROLE_ROOT]);
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

        renderer.adoptSoleTraderBuyer(
            Object.assign({ organization_number: '1', company_name: 'Example' }, record)
        );

        expect(applied).toEqual(expected === null ? [] : [expected]);
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

        renderer.adoptSoleTraderBuyer(null);
        renderer.adoptSoleTraderBuyer('buyer');
        expect(applied).toEqual([]);
    });
});

describe('re-entrancy', () => {
    test('a repeated adoption writes the address once and starts no second intent', () => {
        // The popup can post ACCEPTED more than once (double submit, a reopened
        // window) and a re-render can replay the lookup. A replay must not
        // overwrite a correction the buyer made to the address after the first
        // write, and the order intent stays behind fillCompanyData()'s
        // in-flight guard rather than gaining a trigger of its own here.
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

        expect(applied).toEqual([BILLING]);
        expect(intents).toBe(1);
    });
});

describe('the state the write-back has to survive', () => {
    test('a different identity writes again; the same one does not', () => {
        const { renderer, applied } = loadRenderer(null);
        const other = Object.assign({}, BUYER, {
            organization_number: '111222333',
            billing_address: { city: 'Elsewhere', street: 'Other Lane' }
        });

        renderer.adoptSoleTraderBuyer(BUYER);
        renderer.adoptSoleTraderBuyer(BUYER);
        renderer.adoptSoleTraderBuyer(other);

        expect(applied).toEqual([BILLING, other.billing_address]);
    });

    test('leaving sole-trader mode takes the address back and re-arms the write', () => {
        // The identity half is already discarded on the way out; without the
        // address half the sole trader's address goes out under whatever
        // registered company the buyer searches for next.
        const { renderer, applied, reverts } = loadRenderer(null);
        renderer.enableCompanySearch = function () {};
        renderer.fillCustomerData = function () {};
        renderer.clearCompany = function () {};

        renderer.adoptSoleTraderBuyer(BUYER);
        renderer.showSoleTrader(true);
        renderer.registeredOrganisationMode();
        renderer.adoptSoleTraderBuyer(BUYER);

        expect(reverts).toHaveLength(1);
        expect(applied).toEqual([BILLING, BILLING]);
    });

    test.each([
        [false, 'the buyer is not in sole-trader mode'],
        [true, 'the buyer IS in sole-trader mode and must stay there']
    ])('a superseded lookup records nothing and adopts nothing (%p: %s)', (soleTrader) => {
        // The buyer corrects their email mid-flight. The first chain resolves
        // LAST, against an email that is no longer in the form. Superseded after
        // its tokens are minted, so every guard downstream is genuinely
        // exercised — bumping the generation before the first await would
        // short-circuit the chain at its first check and prove only that one.
        const first = Object.assign({}, BUYER, { email: 'first@example.com' });
        const { renderer, applied } = loadRenderer(first);
        renderer.showModeTab(true);
        renderer.showSoleTrader(soleTrader);
        renderer.enableCompanySearch = function () {};
        renderer.fillCustomerData = function () {};
        renderer.clearCompany = function () {};
        let releaseTokens;
        renderer.getTokens = () =>
            new Promise((resolve) => {
                releaseTokens = () => resolve({ delegation_token: 'dt', autofill_token: 'at' });
            });

        const chain = renderer.lookupSoleTrader();
        renderer._soleTraderLookupGeneration += 1;
        releaseTokens();

        return chain.then(() => {
            // Not recorded: a chip click reads `soleTraderLookup` and would
            // otherwise adopt the buyer belonging to the replaced email.
            expect(renderer.soleTraderLookup).toEqual({ ready: false, buyer: null, matches: false });
            expect(applied).toEqual([]);
            // The mode is left exactly as it was: a superseded chain never
            // moves the buyer between modes.
            expect(renderer.showSoleTrader()).toBe(soleTrader);
            // And a chip click still finds nothing to adopt.
            renderer.soleTraderMode();
            expect(applied).toEqual([]);
        });
    });

    test('a superseded chain that FAILS does not clobber the live result', () => {
        const { renderer } = loadRenderer(BUYER);
        renderer.showModeTab(true);
        let failTokens;
        renderer.getTokens = () =>
            new Promise((resolve, reject) => {
                failTokens = () => reject(new Error('token mint failed'));
            });

        const chain = renderer.lookupSoleTrader();
        renderer._soleTraderLookupGeneration += 1;
        renderer.soleTraderLookup = { ready: true, buyer: BUYER, matches: true };
        failTokens();

        return chain.then(() => {
            expect(renderer.soleTraderLookup.buyer).toBe(BUYER);
        });
    });

    test('the buyer lookup goes out under the token its own chain minted', () => {
        // The real fetchBuyer(), not the stub every other case here uses: the
        // token parameter exists precisely so a superseded chain cannot read the
        // cookie under a newer chain's token, and a stubbed fetchBuyer never
        // executes the header expression that carries it.
        const requests = [];
        const renderer = loadAmdModule(
            RENDERER,
            {},
            {
                window: { checkoutConfig: { payment: {} }, addEventListener: function () {} },
                fetch: function (url, options) {
                    requests.push(options.headers['two-delegated-authority-token']);
                    return Promise.resolve({ ok: false, status: 404 });
                }
            }
        );
        renderer._brandConfig = { checkoutApiUrl: 'https://api.example' };
        renderer.autofillToken = 'newer-chain-token';

        return renderer.fetchBuyer('own-chain-token').then(() => {
            expect(requests).toEqual(['own-chain-token']);
            // No token passed → the instance's current one, which is what the
            // post-signup lookup relies on.
            return renderer.fetchBuyer().then(() => {
                expect(requests).toEqual(['own-chain-token', 'newer-chain-token']);
            });
        });
    });

    test('a completed signup supersedes a lookup still in flight', () => {
        // Otherwise the pre-auth chain lands after the adoption, disagrees with
        // the authenticated identity, and reverts both the mode and the address
        // that adoption just wrote.
        const { renderer, listeners } = loadRenderer(BUYER);
        const handler = messageHandler(renderer, listeners);
        renderer.showSoleTrader(true);
        renderer._soleTraderLookupGeneration = 7;

        handler({ origin: CHECKOUT_PAGE_URL, data: 'ACCEPTED' });

        expect(renderer._soleTraderLookupGeneration).toBe(8);
    });

    test('a throw in the address write leaves the identity filled and the write retryable', () => {
        const { renderer, applied } = loadRenderer(BUYER);
        let fail = true;
        renderer.writeSoleTraderAddress = function (buyer) {
            if (fail) throw new Error('DOM write failed');
            applied.push(buyer.billing_address);
            return true;
        };

        renderer.showPopupMessage(true);
        renderer.adoptSoleTraderBuyer(BUYER);

        expect(renderer.companyId()).toBe(BUYER.organization_number);
        expect(renderer.showPopupMessage()).toBe(false);

        // The failure did not consume the one chance: the next adoption of the
        // same identity tries again.
        fail = false;
        renderer.adoptSoleTraderBuyer(BUYER);
        expect(applied).toEqual([BILLING]);
    });

    test('dispose detaches the popup listener, so one message adopts once', () => {
        // A re-rendering checkout (Amasty, Fire Checkout) rebuilds the method
        // list; a listener left behind by each render would write to the live
        // address form once per stacked renderer.
        const { renderer, listeners, removed } = loadRenderer(BUYER);
        renderer.destroyCompanySearchWidget = function () {};
        renderer._super = function () {};

        renderer.popupMessageListener();
        const bound = listeners.filter((l) => l.name === 'message');
        expect(bound).toHaveLength(1);

        renderer.dispose();

        expect(removed).toEqual([{ name: 'message', fn: bound[0].fn }]);
        expect(renderer._popupMessageHandler).toBeNull();
    });
});
