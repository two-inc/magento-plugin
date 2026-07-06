/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Behavioural tests for the checkout payment-availability refresher
 * (view/frontend/web/js/view/payment-availability.js).
 *
 * It subscribes to quote.getTotals() and, on a genuine grand-total change,
 * re-fetches payment-information and applies ONLY the method list. The
 * load-bearing guarantees:
 *  - it NEVER calls quote.setTotals() (the clobber that got the first attempt
 *    reverted),
 *  - it does not fetch on the bootstrap total nor on no-op re-emits,
 *  - a mid-flight change is parked and run on completion (trailing edge).
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
        deps.$,
        deps.quote,
        deps.urlBuilder,
        deps.storage,
        deps.customer,
        deps.methodConverter,
        deps.paymentService,
        deps.errorProcessor,
        deps.globalMessageList
    );
}

/** Minimal KO-style observable. */
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
    obs.subscribe = (fn) => subs.push(fn);

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
    const response = opts.response || { totals: { grand_total: '999' }, payment_methods: [{ method: 'two_payment' }] };
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

function setup(initialTotals, storageOpts) {
    const totals = makeObservable(initialTotals);
    const setTotals = jest.fn();
    const quote = {
        getTotals: () => totals,
        getQuoteId: () => 'cart1',
        setTotals
    };
    const storage = makeStorage(storageOpts);
    const setPaymentMethods = jest.fn();
    const process = jest.fn();
    const Widget = loadComponent({
        Component: ComponentMock,
        $: {},
        quote,
        urlBuilder: { createUrl: (t) => t },
        storage,
        customer: { isLoggedIn: () => false },
        methodConverter: (m) => m,
        paymentService: { setPaymentMethods },
        errorProcessor: { process },
        globalMessageList: {}
    });
    const instance = new Widget();
    instance.initialize();

    return { instance, totals, storage, setPaymentMethods, setTotals, process };
}

describe('Two_Gateway/js/view/payment-availability', () => {
    it('does not fetch on the bootstrap total at mount', () => {
        const { storage } = setup({ grand_total: '224.00' });
        expect(storage.get).not.toHaveBeenCalled();
    });

    it('re-fetches and applies ONLY the method list on a grand-total change', () => {
        const { totals, storage, setPaymentMethods, setTotals } = setup({ grand_total: '224.00' });
        totals({ grand_total: '264.00' });

        expect(storage.get).toHaveBeenCalledTimes(1);
        expect(setPaymentMethods).toHaveBeenCalledTimes(1);
        // The whole point of the rewrite: totals are never clobbered.
        expect(setTotals).not.toHaveBeenCalled();
    });

    it('does not fetch on a no-op re-emit with an unchanged grand total', () => {
        const { totals, storage } = setup({ grand_total: '224.00' });
        totals({ grand_total: '224.00' });
        expect(storage.get).not.toHaveBeenCalled();
    });

    it('seeds the baseline from the first emit when totals are absent at mount', () => {
        const { totals, storage } = setup(null);
        totals({ grand_total: '224.00' });
        expect(storage.get).not.toHaveBeenCalled();
        totals({ grand_total: '264.00' });
        expect(storage.get).toHaveBeenCalledTimes(1);
    });

    it('parks a mid-flight change and runs it once the refresh resolves', () => {
        const { totals, storage } = setup({ grand_total: '224.00' }, { defer: true });
        totals({ grand_total: '264.00' });
        expect(storage.get).toHaveBeenCalledTimes(1);

        // Change before the in-flight fetch resolves: parked, not fired.
        totals({ grand_total: '300.00' });
        expect(storage.get).toHaveBeenCalledTimes(1);

        // Resolve → the parked change drives a second fetch.
        storage.get._last._resolve();
        expect(storage.get).toHaveBeenCalledTimes(2);
    });

    it('routes a failed fetch through the error processor and clears the in-flight flag', () => {
        const { totals, storage, process } = setup({ grand_total: '224.00' }, { defer: true });
        totals({ grand_total: '264.00' });
        storage.get._last._reject();
        expect(process).toHaveBeenCalledTimes(1);

        // Flag cleared → a later change fetches again.
        totals({ grand_total: '300.00' });
        expect(storage.get).toHaveBeenCalledTimes(2);
    });
});
