/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25547: the checkout's delegation/autofill token pair, its refresh, and
 * the buyer lookup those tokens authorise.
 *
 * ONE per checkout page. Separate from `sole-trader.js` because none of this
 * depends on what that flow is built around: not a mount, not a country, not
 * the registry's company types, not a payment method being selected. It needs
 * the REST entry point and the quote alone, both of which exist the moment
 * checkout is reached — which is when `start()` is called.
 *
 * FRAMEWORK-FREE, with a UMD tail, for the reason `sole-trader.js` has one:
 * Luma and Hyvä run this same file.
 */
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.TwoSoleTraderSession = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // WooCommerce's `scheduleTokenRefresh` and PrestaShop's
    // `_TOKEN_REFRESH_INTERVAL_MS` both use this. A buyer who sits on checkout
    // past expiry would otherwise find the signup URL rejected.
    const TOKEN_REFRESH_INTERVAL_MS = 30 * 60 * 1000;

    /**
     * Whether an autofill record carries enough to adopt without the popup.
     *
     * Keyed on the name because that is the identity `adoptSoleTrader()` writes
     * authoritatively: adopting a nameless record blanks the company field,
     * which is worse than the popup.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     * @returns {boolean}
     */
    function isUsableSoleTrader(buyer) {
        if (!buyer || typeof buyer !== 'object') return false;
        return !!String(buyer.company_name || '').trim();
    }

    /**
     * The page's delegation/autofill token pair and the buyer lookup they
     * authorise — ONE per checkout, not one per capture component.
     *
     * Separate from `SoleTrader` because minting depends on none of what that
     * flow is built around: not the mount, not the country, not the registry's
     * company types, not a payment method being selected. It needs the REST
     * entry point and the quote alone, both of which exist the moment checkout
     * is reached, which is when `start()` is called (TWO-25547).
     *
     * @param {object} host `config`, `tokensUrl`, `quoteId`, `apiClientParams`,
     *        and optionally `isBusy`
     */
    function SoleTraderSession(host) {
        this._host = host || {};
        this.delegationToken = '';
        this.autofillToken = '';
        this._mintChain = null;
        this._tokenRefreshId = null;
        this._prefetch = null;
        this._autofillBuyer = null;
        this._autofillGeneration = 0;
    }

    /** Mint and look the buyer up. Idempotent — every caller shares one answer. */
    SoleTraderSession.prototype.start = function () {
        // No Two-family method on this checkout: no API to reach, no signup to
        // prepare for.
        if (!this._host.config) return Promise.resolve(null);
        return this.prefetchBuyer();
    };

    SoleTraderSession.prototype.getTokens = function () {
        const URL = this._host.tokensUrl();
        return fetch(URL, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ cartId: this._host.quoteId() })
        })
            .then((response) => {
                if (!response.ok) throw new Error(`Error response from ${URL}.`);
                return response.json();
            })
            // The REST controller answers with a single-element list.
            .then((json) => (Array.isArray(json) ? json[0] : json))
            .catch((error) => {
                console.error({ logger: 'twoPayment.getTokens', error });
                throw error;
            });
    };

    // Both tokens are minted together and neither is optional in the signup
    // URL — an empty one produces a link the hosted flow rejects.
    SoleTraderSession.prototype.hasSignupTokens = function () {
        return !!(this.delegationToken && this.autofillToken);
    };

    /**
     * Mint a fresh pair, replacing whatever is held.
     *
     * @returns {Promise<boolean>} whether the mint produced usable tokens
     */
    SoleTraderSession.prototype.mintTokens = function () {
        return this.getTokens()
            .then((json) => {
                this.delegationToken = (json && json.delegation_token) || '';
                this.autofillToken = (json && json.autofill_token) || '';
                return this.hasSignupTokens();
            })
            .catch(() => false);
    };

    /**
     * Have tokens ready BEFORE the buyer clicks anything, so the click handler's
     * `window.open()` runs inside the gesture that triggered it.
     *
     * @returns {Promise<boolean>}
     */
    SoleTraderSession.prototype.ensureTokens = function () {
        if (this.hasSignupTokens()) {
            this.startTokenRefresh();
            return Promise.resolve(true);
        }
        if (this._mintChain) return this._mintChain;
        this._mintChain = this.mintTokens()
            .then((minted) => {
                if (minted) this.startTokenRefresh();
                return minted;
            })
            .finally(() => {
                this._mintChain = null;
            });
        return this._mintChain;
    };

    SoleTraderSession.prototype.startTokenRefresh = function () {
        if (this._tokenRefreshId) return;
        this._tokenRefreshId = setInterval(() => this.refreshTokens(), TOKEN_REFRESH_INTERVAL_MS);
    };

    SoleTraderSession.prototype.stopTokenRefresh = function () {
        if (!this._tokenRefreshId) return;
        clearInterval(this._tokenRefreshId);
        this._tokenRefreshId = null;
    };

    /**
     * One refresh tick. Skipped while any round trip is outstanding — the
     * tokens a popup was launched with must stay valid for the flow it is
     * running, and that flight's own completion leaves them fresh anyway.
     */
    SoleTraderSession.prototype.refreshTokens = function () {
        if (typeof this._host.isBusy === 'function' && this._host.isBusy()) return;
        return this.mintTokens();
    };

    /**
     * Look the buyer's Two session up ahead of any click, so a buyer Two
     * already knows never sees the signup popup (TWO-40).
     *
     * A mint that failed is NOT memoised: a network blip at load would
     * otherwise leave the page unable to mint for the rest of its life.
     *
     * @returns {Promise<?object>} the usable record, or null for nobody
     */
    SoleTraderSession.prototype.prefetchBuyer = function () {
        if (this._prefetch) return this._prefetch;
        const generation = this._autofillGeneration;
        const attempt = this.ensureTokens()
            .then((minted) => {
                if (minted) return this.fetchBuyer();
                if (this._prefetch === attempt) this._prefetch = null;
                return null;
            })
            .then((buyer) => {
                // A lookup superseded while it was out is not an answer: a
                // signup or a country change since has already decided who
                // the checkout holds.
                if (generation !== this._autofillGeneration) return null;
                this._autofillBuyer = isUsableSoleTrader(buyer) ? buyer : null;
                return this._autofillBuyer;
            });
        this._prefetch = attempt;
        return attempt;
    };

    /**
     * The sole trader this session already identifies, if the lookup has landed
     * and nothing has superseded it.
     *
     * @returns {?object} `/autofill/v1/buyer/current` record
     */
    SoleTraderSession.prototype.autofilledSoleTrader = function () {
        return this._autofillBuyer || null;
    };

    /**
     * Retire the held answer, in flight or already in hand. The caller owns
     * re-arming the lookup.
     */
    SoleTraderSession.prototype.forgetAutofilledBuyer = function () {
        this._autofillGeneration += 1;
        this._prefetch = null;
        this._autofillBuyer = null;
    };

    /** Retire the held answer without freeing the lookup to run again. */
    SoleTraderSession.prototype.supersedeAutofilledBuyer = function () {
        this._autofillGeneration += 1;
        this._autofillBuyer = null;
    };

    /**
     * Read the buyer the Two session identifies.
     *
     * That session's email IS the identity — the order's contact field has no
     * say in it. Re-gating on a match there discarded an authenticated buyer
     * and left the company field permanently blank with no route forward
     * (TWO-25461).
     *
     * @returns {Promise<object|null>} null for no buyer and for any failure
     */
    SoleTraderSession.prototype.fetchBuyer = function () {
        const config = this._host.config;
        const params = new URLSearchParams(this._host.apiClientParams(config)).toString();
        const URL = `${config.checkoutApiUrl}/autofill/v1/buyer/current${params ? `?${params}` : ''}`;
        // The one call that cannot be proxied: it is authenticated by the
        // buyer's own session cookie on the API's domain, which a server-side
        // call has no way to present.
        const headers = {};
        const customHeaders = config.customHeaders || {};
        Object.keys(customHeaders).forEach((name) => {
            headers[name] = customHeaders[name];
        });
        headers['two-delegated-authority-token'] = this.autofillToken;
        return fetch(URL, {
            credentials: 'include',
            headers: headers
        })
            .then((response) => {
                if (response.ok) return response.json();
                if (response.status === 404) return null;
                throw new Error(`Error response from ${URL}.`);
            })
            .catch(() => null);
    };

    return SoleTraderSession;
}));
