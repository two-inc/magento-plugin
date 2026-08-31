/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25554: which of the shipping/billing panels' captures is the buyer's
 * actual paying-as company.
 *
 * Rule (confirmed ruling, verbatim): "where billing address is secondary, pick from
 * shipping unless user specifies billing is different in which case pick
 * from billing first, falling back to shipping only if billing doesn't
 * present a company number."
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const RESOLVER = 'view/frontend/web/js/model/company-source-resolver.js';

function loadIdentityFactory() {
    return loadAmdModule(IDENTITY, {}, { document: document, window: window });
}

/**
 * @param {object} [options] `{ billingIsDistinct }` — defaults to a fixed
 *        answer the test flips via the returned `setBillingDistinct()`
 * @returns {object} `{ Resolver, shipping, billing, resolved, resolver,
 *          setBillingDistinct }`
 */
function build() {
    const createIdentity = loadIdentityFactory();
    const Resolver = loadAmdModule(RESOLVER, {}, { document: document, window: window });
    const shipping = createIdentity();
    const billing = createIdentity();
    const resolved = createIdentity();
    let distinct = false;
    const resolver = new Resolver({
        shipping: shipping,
        billing: billing,
        resolved: resolved,
        billingIsDistinct: function () { return distinct; }
    });
    return {
        shipping: shipping,
        billing: billing,
        resolved: resolved,
        resolver: resolver,
        setBillingDistinct: function (value) { distinct = value; }
    };
}

describe('billing not distinct — shipping always wins, wholesale', () => {
    test('an empty shipping identity resolves to empty, not undefined', () => {
        const { resolver, resolved } = build();
        resolver.connect();
        expect(resolved.companyName()).toBe('');
        expect(resolved.companyId()).toBe('');
        expect(resolved.isCaptured()).toBe(false);
    });

    test('a registered shipping pick resolves verbatim, even with billing holding a DIFFERENT captured company', () => {
        const { shipping, billing, resolver, resolved } = build();
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        billing.write({ companyName: 'Billing Co', companyId: '222' }, { authoritative: true });
        resolver.connect();

        expect(resolved.companyName()).toBe('Shipping Co');
        expect(resolved.companyId()).toBe('111');
    });

    test('shipping in manual mode resolves the typed name with no number, matching today\'s single-mount behaviour', () => {
        const { shipping, resolver, resolved } = build();
        shipping.captureMode('manual');
        shipping.companyName('Acme');
        resolver.connect();

        expect(resolved.captureMode()).toBe('manual');
        expect(resolved.companyName()).toBe('Acme');
        expect(resolved.companyId()).toBe('');
    });
});

describe('billing distinct — billing wins if it has a number, else shipping', () => {
    test('billing distinct with a registered pick wins over a shipping pick', () => {
        const { shipping, billing, resolver, resolved, setBillingDistinct } = build();
        setBillingDistinct(true);
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        billing.write({ companyName: 'Billing Co', companyId: '222' }, { authoritative: true });
        resolver.connect();

        expect(resolved.companyName()).toBe('Billing Co');
        expect(resolved.companyId()).toBe('222');
    });

    test.each([
        ['manual', false, 'billing in manual entry never carries a vouched number'],
        ['registered', false, 'billing search left with no id at all'],
        ['soletrader', false, 'sole-trader mode entered but not yet adopted']
    ])('billing distinct, mode=%s adopted=%p falls back to shipping (%s)', (mode, adopted) => {
        const { shipping, billing, resolver, resolved, setBillingDistinct } = build();
        setBillingDistinct(true);
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        billing.captureMode(mode);
        billing.soleTraderAdopted(adopted);
        if (mode === 'manual') billing.companyName('Billing Typed Name');
        resolver.connect();

        expect(resolved.companyName()).toBe('Shipping Co');
        expect(resolved.companyId()).toBe('111');
    });

    test('billing distinct, sole trader ADOPTED wins over a shipping pick', () => {
        const { shipping, billing, resolver, resolved, setBillingDistinct } = build();
        setBillingDistinct(true);
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        billing.captureMode('soletrader');
        billing.soleTraderAdopted(true);
        billing.write({ companyName: 'Sole Trader Name', companyId: 'ST-1' }, { authoritative: true });
        resolver.connect();

        expect(resolved.companyName()).toBe('Sole Trader Name');
        expect(resolved.companyId()).toBe('ST-1');
    });

    test('billing distinct but empty AND shipping empty resolves to empty on both halves', () => {
        const { resolver, resolved, setBillingDistinct } = build();
        setBillingDistinct(true);
        resolver.connect();

        expect(resolved.companyName()).toBe('');
        expect(resolved.companyId()).toBe('');
    });
});

describe('re-resolves live, on every input that could change the answer', () => {
    test('a billing pick arriving AFTER connect() overrides shipping once billing is distinct', () => {
        const { shipping, billing, resolver, resolved, setBillingDistinct } = build();
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        setBillingDistinct(true);
        resolver.connect();
        expect(resolved.companyId()).toBe('111');

        billing.write({ companyName: 'Billing Co', companyId: '222' }, { authoritative: true });

        expect(resolved.companyName()).toBe('Billing Co');
        expect(resolved.companyId()).toBe('222');
    });

    test('shipping\'s own country-change invalidation (clear()) propagates through, even while billing is winning', () => {
        // Corner case: billing is authoritative, then the buyer changes
        // SHIPPING's country — shipping's own invalidation must not leak into
        // the resolved answer, since billing is still the winner.
        const { shipping, billing, resolver, resolved, setBillingDistinct } = build();
        setBillingDistinct(true);
        billing.write({ companyName: 'Billing Co', companyId: '222' }, { authoritative: true });
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        resolver.connect();
        expect(resolved.companyId()).toBe('222');

        shipping.clear();

        expect(resolved.companyName()).toBe('Billing Co');
        expect(resolved.companyId()).toBe('222');
    });

    test('billing losing its number hands resolution back to shipping without a fresh connect()', () => {
        const { shipping, billing, resolver, resolved, setBillingDistinct } = build();
        setBillingDistinct(true);
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        billing.write({ companyName: 'Billing Co', companyId: '222' }, { authoritative: true });
        resolver.connect();
        expect(resolved.companyId()).toBe('222');

        // The buyer abandons billing's pick — clearNumber() is what
        // manualEntryMode()/a country change route through in production.
        billing.clearNumber();

        expect(resolved.companyName()).toBe('Shipping Co');
        expect(resolved.companyId()).toBe('111');
    });

    test('billing toggling from distinct to not (checkbox re-checked) hands resolution back to shipping', () => {
        const { shipping, billing, resolver, resolved, setBillingDistinct } = build();
        setBillingDistinct(true);
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        billing.write({ companyName: 'Billing Co', companyId: '222' }, { authoritative: true });
        resolver.connect();
        expect(resolved.companyId()).toBe('222');

        setBillingDistinct(false);
        resolver.recompute();

        expect(resolved.companyName()).toBe('Shipping Co');
        expect(resolved.companyId()).toBe('111');
    });
});

describe('the mirror copies every field, not just the name/number pair', () => {
    test('soleTraderAvailable, soleTraderBusy and addressNotice all mirror the winning identity', () => {
        const { shipping, resolver, resolved } = build();
        shipping.soleTraderAvailable(true);
        shipping.soleTraderBusy(true);
        shipping.addressNotice('We could not fetch this company\'s address. Please enter it below.');
        resolver.connect();

        expect(resolved.soleTraderAvailable()).toBe(true);
        expect(resolved.soleTraderBusy()).toBe(true);
        expect(resolved.addressNotice()).toBe(
            'We could not fetch this company\'s address. Please enter it below.'
        );
    });

    test('a torn read is impossible: a subscriber sees BOTH halves of the pair already updated', () => {
        // Mutation-proof for CompanySourceResolver.recompute() using
        // applySnapshot() rather than the per-accessor loop it replaced — see
        // that method's own doc. A subscriber firing mid-mirror would observe
        // one field already changed and the other still stale.
        const { shipping, billing, resolver, resolved } = build();
        shipping.write({ companyName: 'First', companyId: '111' }, { authoritative: true });
        resolver.connect();

        const seenPairs = [];
        resolved.subscribe(function () {
            seenPairs.push([resolved.companyName(), resolved.companyId()]);
        });

        shipping.write({ companyName: 'Second', companyId: '222' }, { authoritative: true });

        expect(seenPairs).toEqual([['Second', '222']]);
    });
});

describe('watchBillingToggle() is opt-in, separate from connect()', () => {
    test('connect() alone never touches the host\'s watchBillingToggle hook', () => {
        const createIdentity = loadIdentityFactory();
        const Resolver = loadAmdModule(RESOLVER, {}, { document: document, window: window });
        let watchCalls = 0;
        const resolver = new Resolver({
            shipping: createIdentity(),
            billing: createIdentity(),
            resolved: createIdentity(),
            billingIsDistinct: function () { return false; },
            watchBillingToggle: function () { watchCalls += 1; }
        });

        resolver.connect();

        expect(watchCalls).toBe(0);
    });

    test('watchBillingToggle() registers the host hook, which recomputes on its own trigger', () => {
        const createIdentity = loadIdentityFactory();
        const Resolver = loadAmdModule(RESOLVER, {}, { document: document, window: window });
        const shipping = createIdentity();
        const billing = createIdentity();
        const resolved = createIdentity();
        let distinct = false;
        let trigger = null;
        const resolver = new Resolver({
            shipping: shipping,
            billing: billing,
            resolved: resolved,
            billingIsDistinct: function () { return distinct; },
            watchBillingToggle: function (onChange) { trigger = onChange; }
        });
        shipping.write({ companyName: 'Shipping Co', companyId: '111' }, { authoritative: true });
        billing.write({ companyName: 'Billing Co', companyId: '222' }, { authoritative: true });
        resolver.connect();
        resolver.watchBillingToggle();
        expect(resolved.companyId()).toBe('111');

        distinct = true;
        trigger();

        expect(resolved.companyId()).toBe('222');
    });
});
