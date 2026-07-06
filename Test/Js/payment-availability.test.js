/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Behavioural tests for the checkout payment-availability refresher
 * (view/frontend/web/js/view/payment-availability.js).
 *
 * Load-bearing guarantees:
 *  - NEVER calls quote.setTotals() (the clobber that got the first attempt
 *    reverted);
 *  - only calls paymentService.setPaymentMethods() when the available-method
 *    SET changed (re-applying an unchanged list rebuilds every Luma renderer
 *    and wipes in-progress payment forms);
 *  - no fetch on the bootstrap total nor on no-op re-emits; keys on
 *    grand_total AND tax (net-basis gate);
 *  - a mid-flight change is parked and run on completion (trailing edge);
 *  - a failed probe is silent (no error banner) and rolls back so the next
 *    emit retries.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(
    path.resolve(__dirname, '../../view/frontend/web/js/view/payment-availability.js'),
    'utf8'
);

function loadComponent(deps) {
    let factory;
    const sandbox = { define: (depList, fn) => { factory = fn; } };
    vm.runInNewContext(SRC, sandbox);

    return factory(
        deps.Component,
        deps.quote,
        deps.urlBuilder,
        deps.storage,
        deps.customer,
        deps.methodConverter,
        deps.paymentService
    );
}

/** Minimal KO-style observable with a disposable subscription. */
function makeObservable(initial) {
    let value = initial;
    const subs = [];
    const obs = function () {
        if (arguments.length) {
            value = arguments[0];
            subs.slice().forEach((fn) => fn(value));
        }

        return value;
    };
    obs.subscribe = (fn) => {
        subs.push(fn);

        return { dispose: () => { const i = subs.indexOf(fn); if (i > -1) { subs.splice(i, 1); } } };
    };

    return obs;
}

const ComponentMock = {
    extend: function (proto) {
        function Ctor() {}
        Ctor.prototype = Object.assign({ _super: function () {} }, proto);

        return Ctor;
    }
};

/** mage/storage.get() stub returning a jQuery-style promise. */
function makeStorage(opts) {
    opts = opts || {};
    const response = opts.response || { payment_methods: [{ method: 'two_payment' }] };
    const get = jest.fn(function () {
        let settled = null;
        const done = [];
        const fail = [];
        const always = [];
        const p = {
            done(cb) { settled === 'done' ? cb(response) : done.push(cb); return p; },
            fail(cb) { settled === 'fail' ? cb({}) : fail.push(cb); return p; },
            always(cb) { settled ? cb() : always.push(cb); return p; }
        };
        p._resolve = () => { settled = 'done'; done.forEach((c) => c(response)); always.forEach((c) => c()); };
        p._reject = () => { settled = 'fail'; fail.forEach((c) => c({})); always.forEach((c) => c()); };
        get._last = p;
        if (!opts.defer) { p._resolve(); }

        return p;
    });

    return { get };
}

function setup(cfg) {
    cfg = cfg || {};
    const totals = makeObservable(cfg.initialTotals);
    const setTotals = jest.fn();
    const quote = {
        getTotals: () => totals,
        getQuoteId: () => 'cart1',
        setTotals
    };
    const storage = makeStorage(cfg.storage);
    const setPaymentMethods = jest.fn();
    // Current server-available methods the checkout already shows.
    const available = cfg.available || [];
    const paymentService = {
        setPaymentMethods,
        getAvailablePaymentMethods: () => available
    };
    const createUrl = jest.fn((t) => t);
    const Widget = loadComponent({
        Component: ComponentMock,
        quote,
        urlBuilder: { createUrl },
        storage,
        customer: { isLoggedIn: () => !!cfg.loggedIn },
        methodConverter: (m) => m,
        paymentService
    });
    const instance = new Widget();
    instance.initialize();

    return { instance, totals, storage, setPaymentMethods, setTotals, createUrl };
}

describe('Two_Gateway/js/view/payment-availability', () => {
    it('does not fetch on the bootstrap total at mount', () => {
        const { storage } = setup({ initialTotals: { grand_total: '224.00' } });
        expect(storage.get).not.toHaveBeenCalled();
    });

    it('re-fetches on a grand-total change and never touches totals', () => {
        const { totals, storage, setTotals } = setup({ initialTotals: { grand_total: '224.00' } });
        totals({ grand_total: '264.00' });

        expect(storage.get).toHaveBeenCalledTimes(1);
        // The whole point of the rewrite: totals are never clobbered.
        expect(setTotals).not.toHaveBeenCalled();
    });

    it('applies the method list only when the available-method set changed', () => {
        // Two currently absent; server now returns it → set changed → apply.
        const { totals, setPaymentMethods } = setup({
            initialTotals: { grand_total: '224.00' },
            available: [],
            storage: { response: { payment_methods: [{ method: 'two_payment' }] } }
        });
        totals({ grand_total: '264.00' });
        expect(setPaymentMethods).toHaveBeenCalledTimes(1);
    });

    it('does NOT re-apply when the method set is unchanged (no renderer churn)', () => {
        // Two already shown and still returned → set unchanged → skip, so the
        // buyer's in-progress form/selection is never rebuilt.
        const { totals, setPaymentMethods } = setup({
            initialTotals: { grand_total: '264.00' },
            available: [{ method: 'two_payment' }],
            storage: { response: { payment_methods: [{ method: 'two_payment' }] } }
        });
        totals({ grand_total: '300.00' });
        expect(setPaymentMethods).not.toHaveBeenCalled();
    });

    it('does not fetch on a no-op re-emit with an unchanged key', () => {
        const { totals, storage } = setup({ initialTotals: { grand_total: '224.00', tax_amount: '0' } });
        totals({ grand_total: '224.00', tax_amount: '0' });
        expect(storage.get).not.toHaveBeenCalled();
    });

    it('keys on tax too — a tax-only change (net basis) triggers a re-fetch', () => {
        const { totals, storage } = setup({ initialTotals: { grand_total: '264.00', tax_amount: '44.00' } });
        totals({ grand_total: '264.00', tax_amount: '20.00' });
        expect(storage.get).toHaveBeenCalledTimes(1);
    });

    it('seeds the baseline from the first emit when totals are absent at mount', () => {
        const { totals, storage } = setup({ initialTotals: null });
        totals({ grand_total: '224.00' });
        expect(storage.get).not.toHaveBeenCalled();
        totals({ grand_total: '264.00' });
        expect(storage.get).toHaveBeenCalledTimes(1);
    });

    it('parks a mid-flight change and runs it once the refresh resolves', () => {
        const { totals, storage } = setup({ initialTotals: { grand_total: '224.00' }, storage: { defer: true } });
        totals({ grand_total: '264.00' });
        expect(storage.get).toHaveBeenCalledTimes(1);

        totals({ grand_total: '300.00' });
        expect(storage.get).toHaveBeenCalledTimes(1);

        storage.get._last._resolve();
        expect(storage.get).toHaveBeenCalledTimes(2);
    });

    it('fails silently and rolls back so the next emit retries', () => {
        const { totals, storage, setPaymentMethods } = setup({
            initialTotals: { grand_total: '224.00' },
            storage: { defer: true }
        });
        totals({ grand_total: '264.00' });
        // No error surfaced (no errorProcessor dependency at all) and no list applied.
        expect(() => storage.get._last._reject()).not.toThrow();
        expect(setPaymentMethods).not.toHaveBeenCalled();

        // Key rolled back → a later change still fetches (retry not stranded).
        totals({ grand_total: '300.00' });
        expect(storage.get).toHaveBeenCalledTimes(2);
    });

    it('uses the registered-customer URL when logged in', () => {
        const { totals, createUrl } = setup({ initialTotals: { grand_total: '224.00' }, loggedIn: true });
        totals({ grand_total: '264.00' });
        expect(createUrl).toHaveBeenCalledWith('/carts/mine/payment-information', {});
    });

    it('uses the guest URL with the cart id when not logged in', () => {
        const { totals, createUrl } = setup({ initialTotals: { grand_total: '224.00' }, loggedIn: false });
        totals({ grand_total: '264.00' });
        expect(createUrl).toHaveBeenCalledWith(
            '/guest-carts/:cartId/payment-information',
            { cartId: 'cart1' }
        );
    });

    it('disposes the totals subscription on destroy', () => {
        const { instance, totals, storage } = setup({ initialTotals: { grand_total: '224.00' } });
        instance.destroy();
        totals({ grand_total: '264.00' });
        expect(storage.get).not.toHaveBeenCalled();
    });
});
