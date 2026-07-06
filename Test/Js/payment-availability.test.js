/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Behavioural tests for the checkout payment-availability refresher
 * (view/frontend/web/js/view/payment-availability.js).
 *
 * The component subscribes to quote.getTotals() and re-asks the server
 * for payment availability (get-payment-information) whenever the grand
 * total actually moves. The delicate parts are the dedup and loop guards:
 *  - it must NOT refresh on the bootstrap total core already fetched for,
 *  - it must NOT refresh on a no-op re-emit (same grand total),
 *  - and crucially it must NOT loop, because get-payment-information itself
 *    re-emits the totals observable via quote.setTotals().
 * These are exactly what this test pins down.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(
    path.resolve(__dirname, '../../view/frontend/web/js/view/payment-availability.js'),
    'utf8'
);

/**
 * Load the AMD module by shimming `define`, capturing the factory, and
 * invoking it with the supplied dependency mocks. Returns the module's
 * export (the extended uiComponent constructor).
 */
function loadComponent(deps) {
    let factory;
    const sandbox = {
        define: function (depList, fn) {
            factory = fn;
        }
    };
    vm.runInNewContext(SRC, sandbox);

    return factory(
        deps.Component,
        deps.$,
        deps.quote,
        deps.getPaymentInformation,
        deps.globalMessageList || { addErrorMessage: function () {} }
    );
}

/** Minimal KO-style observable: callable getter/setter with subscribe. */
function makeObservable(initial) {
    let value = initial;
    const subscribers = [];
    const obs = function () {
        if (arguments.length) {
            value = arguments[0];
            subscribers.slice().forEach(function (fn) {
                fn(value);
            });
        }

        return value;
    };
    obs.subscribe = function (fn) {
        subscribers.push(fn);
    };

    return obs;
}

/** Minimal uiComponent stand-in: extend() returns a constructor. */
const ComponentMock = {
    extend: function (proto) {
        function Ctor() {}
        Ctor.prototype = Object.assign({ _super: function () {} }, proto);

        return Ctor;
    }
};

/** jQuery Deferred/when stubs that fire always() regardless of order. */
const $mock = {
    Deferred: function () {
        let resolved = false;
        const callbacks = [];
        const d = {
            resolve: function () {
                resolved = true;
                callbacks.splice(0).forEach(function (fn) {
                    fn();
                });

                return d;
            },
            always: function (fn) {
                if (resolved) {
                    fn();
                } else {
                    callbacks.push(fn);
                }

                return d;
            }
        };

        return d;
    },
    when: function (d) {
        return d;
    }
};

function setup(initialTotals, opts) {
    opts = opts || {};
    const totals = makeObservable(initialTotals);
    const quote = { getTotals: function () { return totals; } };
    // The action resolves synchronously unless the test asks it to defer,
    // and (like the real one) re-emits the totals observable with the same
    // grand total to model quote.setTotals().
    const getPaymentInformation = jest.fn(function (deferred) {
        if (opts.reEmitSameTotal) {
            totals(Object.assign({}, totals()));
        }
        if (!opts.defer) {
            deferred.resolve();
        } else {
            getPaymentInformation.lastDeferred = deferred;
        }
    });

    const Widget = loadComponent({
        Component: ComponentMock,
        $: $mock,
        quote: quote,
        getPaymentInformation: getPaymentInformation
    });
    const instance = new Widget();
    instance.initialize();

    return { instance, totals, getPaymentInformation };
}

describe('Two_Gateway/js/view/payment-availability', () => {
    it('does not refresh on the bootstrap total the component mounts with', () => {
        const { getPaymentInformation } = setup({ grand_total: '224.00' });
        expect(getPaymentInformation).not.toHaveBeenCalled();
    });

    it('refreshes when the grand total changes (crossing the threshold)', () => {
        const { totals, getPaymentInformation } = setup({ grand_total: '224.00' });
        totals({ grand_total: '264.00' });
        expect(getPaymentInformation).toHaveBeenCalledTimes(1);
    });

    it('does not refresh on a no-op re-emit with an unchanged grand total', () => {
        const { totals, getPaymentInformation } = setup({ grand_total: '224.00' });
        totals({ grand_total: '224.00' });
        expect(getPaymentInformation).not.toHaveBeenCalled();
    });

    it('does not loop when get-payment-information re-emits the same total', () => {
        // The action calls quote.setTotals() on success, re-firing the
        // subscriber. With an unchanged grand total the guard must swallow it.
        const { totals, getPaymentInformation } = setup(
            { grand_total: '224.00' },
            { reEmitSameTotal: true }
        );
        totals({ grand_total: '264.00' });
        expect(getPaymentInformation).toHaveBeenCalledTimes(1);
    });

    it('seeds the baseline from the first emit when totals are absent at mount', () => {
        const { totals, getPaymentInformation } = setup(null);
        // First emit is the bootstrap load core already fetched for: baseline only.
        totals({ grand_total: '224.00' });
        expect(getPaymentInformation).not.toHaveBeenCalled();
        // A genuine subsequent change refreshes.
        totals({ grand_total: '264.00' });
        expect(getPaymentInformation).toHaveBeenCalledTimes(1);
    });

    it('ignores a totals change while a refresh is still in flight', () => {
        const { totals, getPaymentInformation } = setup(
            { grand_total: '224.00' },
            { defer: true }
        );
        totals({ grand_total: '264.00' });
        expect(getPaymentInformation).toHaveBeenCalledTimes(1);

        // Re-entrant change before the in-flight call resolves: swallowed.
        totals({ grand_total: '300.00' });
        expect(getPaymentInformation).toHaveBeenCalledTimes(1);

        // Once the in-flight call resolves, later changes refresh again.
        getPaymentInformation.lastDeferred.resolve();
        totals({ grand_total: '320.00' });
        expect(getPaymentInformation).toHaveBeenCalledTimes(2);
    });

    it('ignores emits with an absent or unparseable grand total', () => {
        const { totals, getPaymentInformation } = setup({ grand_total: '224.00' });
        totals(null);
        totals({ grand_total: 'not-a-number' });
        expect(getPaymentInformation).not.toHaveBeenCalled();
    });
});
