/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: the company the buyer is paying as — name, registry number, and
 * which of the three capture options produced them.
 *
 * Magento's counterpart to WooCommerce's `twoincCompanyCapture`, which owns the
 * same subject there: the name/number pair and which input surface is active.
 *
 * A PAGE-LEVEL singleton, not a member of the payment tile. The buyer picks one
 * company per checkout however many Two-family brands render a tile, and the
 * identity has to outlive a payment-method list rebuild — Luma, Amasty and Fire
 * Checkout all re-create payment renderers on every totals change.
 *
 * Owns the observables and nothing else. Who may write them, and the DOM they
 * are captured through, belong to company-search.js's mount and to
 * sole-trader.js respectively.
 */
define(['ko'], function (ko) {
    'use strict';

    /**
     * Which of the three peer capture options is active. Drives the chips'
     * selected state; each option's own behaviour hangs off the live mount, so
     * every route into a mode writes this, including the in-dropdown "Search
     * for company" link.
     *
     * @type {'registered'|'manual'|'soletrader'}
     */
    const captureMode = ko.observable('registered');

    const companyName = ko.observable('');
    const companyId = ko.observable('');

    /** The billing country's registry offers sole traders. */
    const soleTraderAvailable = ko.observable(false);

    /** A sole-trader identity is adopted and on screen. */
    const soleTraderAdopted = ko.observable(false);

    /**
     * A sole-trader round trip is outstanding — the signup popup being open, or
     * the post-signup buyer lookup. Counted rather than flagged because both
     * can be live at once.
     */
    let flightDepth = 0;
    const soleTraderBusy = ko.observable(false);

    /** Why the picked company's address could not be filled in, or ''. */
    const addressNotice = ko.observable('');

    return {
        captureMode: captureMode,
        companyName: companyName,
        companyId: companyId,
        addressNotice: addressNotice,
        soleTraderAvailable: soleTraderAvailable,
        soleTraderAdopted: soleTraderAdopted,
        soleTraderBusy: soleTraderBusy,

        /** The buyer is in sole-trader mode. */
        isSoleTrader: function () {
            return captureMode() === 'soletrader';
        },

        /**
         * Both halves of an identity are captured. Two.inc requires a registry
         * number, so a name alone is not a company.
         *
         * @returns {boolean}
         */
        isCaptured: function () {
            return !!(companyName() && companyId());
        },

        /**
         * Write an identity. `authoritative` overwrites a previous number even
         * when the new company has none of its own — a pick is a decision,
         * where an autofill is only ever an offer.
         *
         * @param {object} identity `{ companyName, companyId }`
         * @param {object} [options] `{ authoritative: boolean }`
         */
        write: function (identity, options) {
            const authoritative = !!(options && options.authoritative);
            // An authoritative write replaces BOTH halves, empty ones included:
            // a company that supplies only one of them must not keep the other
            // from whoever was captured before it.
            if (identity.companyName || authoritative) companyName(identity.companyName || '');
            if (identity.companyId || authoritative) companyId(identity.companyId || '');
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
            companyId('');
        },

        /** Abandon both halves — a country change invalidates the registry. */
        clear: function () {
            companyName('');
            companyId('');
        },

        beginFlight: function () {
            flightDepth += 1;
            soleTraderBusy(true);
        },

        settleFlight: function () {
            flightDepth = Math.max(0, flightDepth - 1);
            if (flightDepth === 0) soleTraderBusy(false);
        },

        /** Whether any sole-trader round trip is outstanding. */
        isBusy: function () {
            return flightDepth > 0;
        }
    };
});
