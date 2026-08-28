/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: the company the buyer is paying as — name, registry number, where
 * the number came from, and which of the three capture options produced them.
 *
 * Magento's counterpart to WooCommerce's `twoincCompanyCapture`, which owns the
 * same subject there: the name/number pair and which input surface is active.
 *
 * A PAGE-LEVEL singleton, not a member of the payment tile. The buyer picks one
 * company per checkout however many Two-family brands render a tile, and the
 * identity has to outlive a payment-method list rebuild — Luma, Amasty and Fire
 * Checkout all re-create payment renderers on every totals change, and Hyvä's
 * Magewire rebuilds the whole subtree.
 *
 * FRAMEWORK-FREE, for the same reason `company-search-panel.js` is: both
 * checkouts load this one file, and Hyvä ships no Knockout to hold observables
 * in. Reads are plain calls and every write notifies `subscribe()`, which is
 * what a host with its own reactivity bridges to — Luma mirrors it onto
 * `ko.observable`s in the renderer, Alpine reads it directly.
 *
 * Owns the state and nothing else. Who may write it, and the DOM it is captured
 * through, belong to the capture component and to each host's transport.
 */
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.TwoCompanyIdentity = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const subscribers = [];

    const state = {
        /**
         * Which of the three peer capture options is active. Drives the chips'
         * selected state; each option's own behaviour hangs off the live mount,
         * so every route into a mode writes this.
         *
         * @type {'registered'|'manual'|'soletrader'}
         */
        captureMode: 'registered',
        companyName: '',
        companyId: '',
        /** 'registry' where a lookup vouched for the number, '' where the buyer typed it. */
        companyIdSource: '',
        soleTraderAvailable: false,
        soleTraderAdopted: false,
        soleTraderBusy: false
    };

    /**
     * A sole-trader round trip is outstanding — the signup popup being open, or
     * the post-signup buyer lookup. Counted rather than flagged because both can
     * be live at once.
     */
    let flightDepth = 0;

    function notify() {
        subscribers.slice().forEach(function (subscriber) {
            subscriber(identity);
        });
    }

    /**
     * A read/write accessor over one field, notifying on a real change.
     *
     * Callable in both directions (`companyName()` / `companyName(value)`) so a
     * host that mirrors it onto its own reactive primitive can pass it around as
     * one function.
     */
    function accessor(key, coerce) {
        return function (value) {
            if (arguments.length === 0) return state[key];
            const next = coerce(value);
            if (state[key] === next) return next;
            state[key] = next;
            notify();
            return next;
        };
    }

    const asString = function (value) {
        return value === undefined || value === null ? '' : String(value);
    };
    const asBoolean = function (value) {
        return !!value;
    };

    const identity = {
        captureMode: accessor('captureMode', asString),
        companyName: accessor('companyName', asString),
        companyId: accessor('companyId', asString),
        companyIdSource: accessor('companyIdSource', asString),
        soleTraderAvailable: accessor('soleTraderAvailable', asBoolean),
        soleTraderAdopted: accessor('soleTraderAdopted', asBoolean),
        soleTraderBusy: accessor('soleTraderBusy', asBoolean),

        /**
         * @param {function(object)} subscriber called after every change
         * @returns {{dispose: function()}} Knockout's own subscription shape, so
         *          a host can hold this where it held a `ko.computed`
         */
        subscribe: function (subscriber) {
            subscribers.push(subscriber);
            return {
                dispose: function () {
                    const at = subscribers.indexOf(subscriber);
                    if (at !== -1) subscribers.splice(at, 1);
                }
            };
        },

        /** The buyer is in sole-trader mode. */
        isSoleTrader: function () {
            return state.captureMode === 'soletrader';
        },

        /**
         * Both halves of an identity are captured. Two.inc requires a registry
         * number, so a name alone is not a company.
         *
         * @returns {boolean}
         */
        isCaptured: function () {
            return !!(state.companyName && state.companyId);
        },

        /**
         * A number something other than the buyer vouched for. Only a lookup
         * can; a hand-typed number is the buyer's own and stays theirs to
         * correct.
         *
         * @returns {boolean}
         */
        hasVouchedNumber: function () {
            return !!state.companyId && state.companyIdSource === 'registry';
        },

        /**
         * Write an identity. `authoritative` overwrites a previous number even
         * when the new company has none of its own — a pick is a decision,
         * where an autofill is only ever an offer.
         *
         * @param {object} written `{ companyName, companyId, companyIdSource }`
         * @param {object} [options] `{ authoritative: boolean }`
         */
        write: function (written, options) {
            const authoritative = !!(options && options.authoritative);
            // An authoritative write replaces BOTH halves, empty ones included:
            // a company that supplies only one of them must not keep the other
            // from whoever was captured before it.
            if (written.companyName || authoritative) state.companyName = asString(written.companyName);
            if (written.companyId || authoritative) {
                state.companyId = asString(written.companyId);
                state.companyIdSource = written.companyIdSource === undefined
                    ? (state.companyId ? 'registry' : '')
                    : asString(written.companyIdSource);
            }
            notify();
        },

        /**
         * Abandon the number but keep the name.
         *
         * The name survives because the sole-trader signup popup prefills from
         * it and the intent-approved notice reads it. Without a number,
         * Model/Two.php::authorize() refuses the order server-side, so there is
         * no client-side gate to add here.
         */
        clearNumber: function () {
            if (!state.companyId && !state.companyIdSource) return;
            state.companyId = '';
            state.companyIdSource = '';
            notify();
        },

        /** Abandon both halves — a country change invalidates the registry. */
        clear: function () {
            if (!state.companyName && !state.companyId && !state.companyIdSource) return;
            state.companyName = '';
            state.companyId = '';
            state.companyIdSource = '';
            notify();
        },

        beginFlight: function () {
            flightDepth += 1;
            identity.soleTraderBusy(true);
        },

        settleFlight: function () {
            flightDepth = Math.max(0, flightDepth - 1);
            if (flightDepth === 0) identity.soleTraderBusy(false);
        },

        /** Whether any sole-trader round trip is outstanding. */
        isBusy: function () {
            return flightDepth > 0;
        }
    };

    return identity;
}));
