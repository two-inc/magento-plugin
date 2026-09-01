/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25554: which of the two address panels' captured companies is the
 * buyer's actual paying-as company.
 *
 * Rule (confirmed ruling, verbatim): "where billing address is secondary, pick from
 * shipping unless user specifies billing is different in which case pick
 * from billing first, falling back to shipping only if billing doesn't
 * present a company number."
 *
 * Never a hybrid of the two: `resolved` is always a live mirror of exactly ONE
 * of `shipping`/`billing`, so a downstream reader (order-intent, the tile's own
 * display) sees one coherent company. What travels is `snapshot()`'s business —
 * the company fields alone; each panel's own UI state stays with the panel that
 * renders it.
 *
 * FRAMEWORK-FREE, for the same reason its two inputs are: both checkouts
 * load this file, and Hyvä ships no Knockout.
 */
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.TwoCompanySourceResolver = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * @param {object} options
     * @param {object} options.shipping the shipping panel's identity
     * @param {object} options.billing the billing panel's identity
     * @param {object} options.resolved the identity downstream consumers read
     * @param {function(): boolean} options.billingIsDistinct whether billing
     *        is currently a distinct address from shipping (core's "my
     *        billing address is the same as shipping" unchecked and a
     *        billing form rendered)
     * @param {function(function())} [options.watchBillingToggle] report every
     *        time billingIsDistinct()'s answer could have changed
     */
    function CompanySourceResolver(options) {
        this._options = options || {};
        this._subs = [];
    }

    /**
     * A captured company with nothing to bill against is not a fallback
     * candidate. Sole trader counts only once adopted — an opened-then-
     * abandoned signup left the mode set but nothing captured (TWO-25554).
     * Manual entry never carries a vouched number by construction.
     *
     * @param {object} identity
     * @returns {boolean}
     */
    function hasCompanyNumber(identity) {
        if (identity.captureMode() === 'soletrader') return identity.soleTraderAdopted();
        return identity.captureMode() === 'registered' && !!identity.companyId();
    }

    /**
     * The single identity `resolved` should mirror right now.
     *
     * @returns {object}
     */
    CompanySourceResolver.prototype.chosenIdentity = function () {
        const options = this._options;
        if (!options.billingIsDistinct()) return options.shipping;
        return hasCompanyNumber(options.billing) ? options.billing : options.shipping;
    };

    /**
     * Copy every field of the chosen identity into `resolved`, in one notify
     * — see `applySnapshot()`'s own doc for why per-field copying is unsafe
     * here specifically (a torn read written back into the source).
     */
    CompanySourceResolver.prototype.recompute = function () {
        this._options.resolved.applySnapshot(this.chosenIdentity().snapshot());
    };

    /**
     * Subscribe to both source identities and mirror once immediately.
     *
     * Deliberately separate from watchBillingToggle() below: a write into
     * either identity must resolve whether or not the page has booted yet —
     * applyCompanyData() et al. are usable before any start() call, same as
     * the single identity this replaced always was — so the host wires this
     * eagerly, at construction, never gated behind an explicit start().
     */
    CompanySourceResolver.prototype.connect = function () {
        const self = this;
        const options = this._options;
        this._subs.push(options.shipping.subscribe(function () { self.recompute(); }));
        this._subs.push(options.billing.subscribe(function () { self.recompute(); }));
        this.recompute();
    };

    /**
     * The one piece that DOES need an explicit boot: a live DOM listener for
     * the "same as shipping" checkbox toggling. Unlike connect() this has a
     * real host dependency (a document to delegate off), so it stays opt-in.
     */
    CompanySourceResolver.prototype.watchBillingToggle = function () {
        const self = this;
        if (this._options.watchBillingToggle) {
            this._options.watchBillingToggle(function () { self.recompute(); });
        }
    };

    return CompanySourceResolver;
}));
