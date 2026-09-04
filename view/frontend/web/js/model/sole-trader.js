/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: the sole-trader flow — Magento's counterpart to PrestaShop's
 * `TwoSoleTrader.js` and WooCommerce's `twoincSoleTrader`.
 *
 * PAGE-LEVEL, like both of those: one flow per checkout, constructed by the
 * company-capture component and outliving every payment-tile render. The popup
 * it opens is a browser-level object, and a handle to it held inside a
 * component that Amasty, Fire Checkout and Magewire all destroy on every totals
 * change was orphaned mid-signup — the buyer completed enrolment and the
 * `ACCEPTED` handshake landed on a listener that no longer recognised the
 * window.
 *
 * FRAMEWORK-FREE, with a UMD tail, for the reason `company-search-panel.js` is:
 * Luma and Hyvä run this one file. Everything platform-shaped — the REST entry
 * point, the quote, the address write-back, the error surface, the fallback
 * prompt — arrives through `host`, which the capture component assembles from
 * its own options.
 *
 * Owns the hosted signup popup, the watcher that notices the buyer closing it,
 * and the `postMessage` handshake enrolment finishes on.
 *
 * Does NOT own the token pair or the buyer lookup. Those are
 * `sole-trader-session.js` — one per checkout page, started on reaching
 * checkout rather than on this flow being constructed (TWO-25547).
 *
 * Does NOT own the identity it produces. Adoption calls back into the
 * component, which routes every write through one path — the same division
 * WooCommerce (`setCompany` → `twoincCompanyCapture.write`) and PrestaShop
 * (`adoptEnrolledIdentity` → `TwoCompanySearch.adoptSoleTraderBuyer`) both
 * settled on after their sole-trader modules hand-rolled it and drifted.
 */
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define(['Two_Gateway/js/model/sole-trader-session'], factory);
    } else {
        root.TwoSoleTrader = factory(root.TwoSoleTraderSession);
    }
}(typeof self !== 'undefined' ? self : this, function (SoleTraderSession) {
    'use strict';

    // There is no event for "the popup went away", so the opener polls.
    const POPUP_CLOSE_POLL_MS = 300;

    /**
     * How long the page keeps focus before the popup is taken down — long
     * enough for the mousedown of a Sole trader chip click to cancel it, short
     * enough that a buyer who has genuinely come back does not watch it linger.
     */
    const RETURN_TO_CHECKOUT_GRACE_MS = 200;

    /**
     * What "the same sole trader" means for the once-per-identity address
     * guard. The organisation number where there is one; the email otherwise,
     * so two buyers who both arrive without a number are not treated as one.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     * @returns {string}
     */
    function soleTraderIdentityKey(buyer) {
        const number = String(buyer.organization_number || '').trim();
        if (number) return number;
        const email = String(buyer.email || '').trim().toLowerCase();
        // Nothing tells one such buyer from another, so record nothing and let
        // the write happen every time — repeating a write is recoverable, and
        // collapsing two buyers onto one key silently drops the second's
        // address.
        return email ? `email:${email}` : '';
    }

    /**
     * @param {object} component the company-capture component this flow serves.
     *        Supplies `config()`, `identity()`, `host()`, `adoptSoleTrader()`,
     *        `abandonSoleTrader()`.
     */
    function SoleTrader(component) {
        this._component = component;
        // Injected where the checkout owns one, so every capture component on
        // the page shares the one token pair and the one buyer answer.
        this._session = component.host().soleTraderSession
            || new SoleTraderSession(component.host());
        this._popupWindow = null;
        this._popupCloseWatcherId = null;
        this._messageHandler = null;
        this._returnHandler = null;
        this._returnCloseTimerId = null;
        // The handshake's own buyer lookup is still out. The popup can close
        // the instant it posts, and that lookup is the authority from then on.
        this._signupConfirming = false;
        this._blockedSignupOptions = null;
        /**
         * Sole-trader identities whose registered address has already been
         * written into this page's checkout, so a replay does not overwrite a
         * correction the buyer made afterwards (TWO-25461 §5).
         */
        this._adoptedIds = new Set();
    }

    /** @returns {object} the host adapter the component was built with */
    SoleTrader.prototype.host = function () {
        return this._component.host();
    };

    /** @returns {object} the page-level identity */
    SoleTrader.prototype.identity = function () {
        return this._component.identity();
    };

    /** @returns {object} the page's token/autofill session */
    SoleTrader.prototype.session = function () {
        return this._session;
    };

    // The token pair stays readable and writable on the flow: it is the flow
    // that spends it, in the signup URL.
    ['delegationToken', 'autofillToken'].forEach(function (token) {
        Object.defineProperty(SoleTrader.prototype, token, {
            get: function () { return this._session[token]; },
            set: function (value) { this._session[token] = value; },
            enumerable: true
        });
    });

    SoleTrader.prototype.hasSignupTokens = function () {
        return this._session.hasSignupTokens();
    };

    SoleTrader.prototype.mintTokens = function () {
        return this._session.mintTokens();
    };

    SoleTrader.prototype.ensureTokens = function () {
        return this._session.ensureTokens();
    };

    SoleTrader.prototype.startTokenRefresh = function () {
        this._session.startTokenRefresh();
    };

    SoleTrader.prototype.stopTokenRefresh = function () {
        this._session.stopTokenRefresh();
    };

    SoleTrader.prototype.refreshTokens = function () {
        return this._session.refreshTokens();
    };

    /**
     * The buyer data the hosted signup prefills its form from.
     *
     * @returns {string} base64 of the JSON payload
     */
    SoleTrader.prototype.getAutofillData = function () {
        const data = this.host().signupPrefill();
        // Bare btoa() only accepts Latin1; this is UTF-8 data (e.g. names with diacritics).
        return btoa(unescape(encodeURIComponent(JSON.stringify(data))));
    };

    SoleTrader.prototype.isPopupOpen = function () {
        return !!(this._popupWindow && !this._popupWindow.closed);
    };

    /**
     * Open the hosted signup.
     *
     * Synchronous from top to bottom, with no await anywhere between the click
     * and `window.open()` — that is what keeps the popup inside a user gesture
     * a blocker will allow.
     *
     * At most one popup is ever live: a prior one still open is CLOSED rather
     * than left running, so it cannot later post a stale ACCEPTED that would
     * win a race against whichever popup the buyer actually completed.
     *
     * @param {object} [options] `{ autoselect: false }` to stop the hosted flow
     *        silently re-picking the registration the buyer is replacing
     * @returns {Window|null}
     */
    SoleTrader.prototype.openPopup = function (options) {
        const config = this._component.config();
        if (!this.hasSignupTokens()) return null;
        if (this.isPopupOpen()) this._popupWindow.close();
        this.stopPopupCloseWatcher();

        let params = `businessToken=${this.delegationToken}`;
        params += `&autofillToken=${this.autofillToken}`;
        params += `&autofillData=${this.getAutofillData()}`;
        if (config.brand) params += `&brand=${config.brand}`;
        if (config.brandVersion) params += `&brandVersion=${config.brandVersion}`;
        if (options && options.autoselect === false) params += '&autoselect=false';
        // PDEV-4669: the popup only renders its country-specific identity step
        // (e.g. US biometric consent) when the URL carries `&country=`. Taken
        // from the quote, never a DOM read, so a buyer cannot pick their own
        // verification flow.
        const country = this.host().signupCountry();
        if (country) params += `&country=${encodeURIComponent(country)}`;

        this._popupWindow = window.open(
            `${config.checkoutPageUrl}/soletrader/signup?${params}`,
            '_blank',
            'location=yes,resizable=yes,scrollbars=yes,status=yes,height=805,width=700'
        );
        if (this._popupWindow) {
            this.watchPopupClose(this._popupWindow);
            this.watchForReturnToCheckout();
        }
        return this._popupWindow;
    };

    /**
     * Open the popup and, if it did not open, offer the on-page link that lets
     * the buyer ask again — the fallback for a blocked popup, and for the
     * narrow window before the up-front mint lands.
     *
     * The retry needs tokens, so a mint is kicked off when there were none; it
     * is deliberately not awaited, because the next open must stay inside its
     * own click.
     *
     * @param {object} [options] passed through to openPopup()
     * @returns {Window|null}
     */
    SoleTrader.prototype.launchSignup = function (options) {
        const win = this.openPopup(options);
        this.showSignupPrompt(!win);
        if (!win) {
            // Held for retrySignup(): a blocked replacement that retried
            // without them would hand back the identity being replaced.
            this._blockedSignupOptions = options || null;
            this.ensureTokens();
        }
        return win;
    };

    /** The fallback link's own launch, on the terms the blocked one had. */
    SoleTrader.prototype.retrySignup = function () {
        return this.launchSignup(this._blockedSignupOptions);
    };

    /** "Select a different sole trader" — offer a choice, not what is on screen. */
    SoleTrader.prototype.selectDifferentSoleTrader = function () {
        return this.launchSignup({ autoselect: false });
    };

    /**
     * Look the buyer's Two session up ahead of any click, so a buyer Two
     * already knows never sees the signup popup (TWO-40).
     *
     * Runs where the tokens are minted rather than inside the click: the
     * lookup needs the autofill token, and a click that had to wait for either
     * could not open a popup a blocker would allow. Idempotent, and the answer
     * is held until something supersedes it.
     *
     * The answer is never revalidated, so a buyer who signs out of Two in
     * another tab mid-checkout is still offered the trader it found. Accepted:
     * "Select a different sole trader" is the way off it, and the order is
     * authorised against the session, not against this record.
     *
     * @returns {Promise<?object>} the usable record, or null for nobody
     */
    SoleTrader.prototype.prefetchBuyer = function () {
        return this._session.prefetchBuyer();
    };

    /**
     * The sole trader this session already identifies, if the lookup has landed
     * and found one. Synchronous, so the click that reads it can still open a
     * popup inside its own gesture when the answer is nobody.
     *
     * @returns {?object} `/autofill/v1/buyer/current` record
     */
    SoleTrader.prototype.autofilledSoleTrader = function () {
        return this._session.autofilledSoleTrader();
    };

    /**
     * Hold the busy state while the popup is open, and hand the checkout back
     * to company search if the buyer closes it having captured nothing.
     *
     * @param {Window} win
     */
    SoleTrader.prototype.watchPopupClose = function (win) {
        this.identity().beginFlight();
        this._popupCloseWatcherId = setInterval(() => {
            if (!win.closed) return;
            this.stopPopupCloseWatcher();
            // The handshake's buyer lookup can still be out; it owns the
            // outcome from here and will write whatever identity it resolves.
            if (this._signupConfirming) return;
            this._component.abandonSoleTrader();
        }, POPUP_CLOSE_POLL_MS);
    };

    /**
     * Settles the flight the watcher held, so the busy state cannot outlive it
     * — a superseding launch stops a watcher whose popup will never be polled
     * closed.
     */
    SoleTrader.prototype.stopPopupCloseWatcher = function () {
        if (!this._popupCloseWatcherId) return;
        clearInterval(this._popupCloseWatcherId);
        this._popupCloseWatcherId = null;
        this.identity().settleFlight();
    };

    /**
     * Take the popup down when the buyer comes back to the checkout page.
     *
     * The rule: focus returning to CHECKOUT means the buyer is looking at
     * checkout rather than at the signup, so the popup goes. Focus leaving for
     * anywhere else — their mail client, to fetch the OTP the signup just sent
     * them — must leave it alone, which is why this is gated on the page
     * actually having focus rather than on a blur.
     *
     * Deferred, and gated on where focus SETTLES, because of the one exception:
     * the capture popover stays open behind the signup, so a click landing
     * inside it — the Sole trader chip above all — is the buyer reaching for
     * the signup, not away from it.
     */
    SoleTrader.prototype.watchForReturnToCheckout = function () {
        if (this._returnHandler) return;
        this._returnHandler = () => {
            if (!this.isPopupOpen()) return;
            clearTimeout(this._returnCloseTimerId);
            this._returnCloseTimerId = setTimeout(() => {
                this._returnCloseTimerId = null;
                if (typeof document.hasFocus === 'function' && !document.hasFocus()) return;
                // This flow's OWN popover, never a page-wide class query —
                // that returns the other panel's popover (TWO-25554).
                const own = this._component.panel();
                const panel = own && own.getPanelElement();
                if (panel && panel.contains(document.activeElement)) return;
                // The CLOSE half only: looking away from the signup is not a
                // decision about the enrolment, which stays live and resumable
                // with its tokens unspent.
                this.closeSignupPopup();
            }, RETURN_TO_CHECKOUT_GRACE_MS);
        };
        window.addEventListener('focus', this._returnHandler);
    };

    /** The Sole trader chip's route: keep the popup, raise it instead. */
    SoleTrader.prototype.cancelPendingReturnClose = function () {
        clearTimeout(this._returnCloseTimerId);
        this._returnCloseTimerId = null;
    };

    /** Close the popup this flow opened, if it is still up. */
    SoleTrader.prototype.closeSignupPopup = function () {
        if (!this.isPopupOpen()) return false;
        this._popupWindow.close();
        return true;
    };

    /**
     * Raise an already-open popup back to the front.
     *
     * @returns {boolean} false means there is nothing on screen to go back to,
     *          so the caller should start a signup instead
     */
    SoleTrader.prototype.focusSignupPopup = function () {
        if (!this.isPopupOpen()) return false;
        this.cancelPendingReturnClose();
        try {
            this._popupWindow.focus();
        } catch (error) {
            // `closed` can flip between the check and the call.
            return false;
        }
        return true;
    };

    /** Re-arm the once-per-identity address guard. */
    SoleTrader.prototype.forgetAdoptions = function () {
        this._adoptedIds.clear();
    };

    /**
     * Retire the held answer, in flight or already in hand. The caller owns
     * re-arming the lookup.
     */
    SoleTrader.prototype.forgetAutofilledBuyer = function () {
        this._session.forgetAutofilledBuyer();
    };

    SoleTrader.prototype.fetchBuyer = function () {
        return this._session.fetchBuyer();
    };

    /**
     * Adopt the sole trader an autofill record describes: the identity through
     * the component's single write path, the registered ADDRESS and the phone
     * into the checkout form (TWO-25461 §5).
     *
     * The address write is NOT gated on the merchant's address-autofill switch,
     * which gates an ordinary registry pick: that switch is legitimately off
     * wherever company search is not in the address area, which is exactly
     * where this flow lives. Both sibling platforms bypass it here too.
     *
     * Written ONCE PER IDENTITY, so a replayed ACCEPTED cannot overwrite a
     * correction the buyer made after the first write.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     */
    SoleTrader.prototype.adoptBuyer = function (buyer) {
        if (!buyer || typeof buyer !== 'object') return;
        // Any adoption supersedes the held answer, in flight or already in
        // hand, so a later click cannot re-adopt it over the identity that won.
        this._session.supersedeAutofilledBuyer();
        this._component.adoptSoleTrader(buyer);
        const key = soleTraderIdentityKey(buyer);
        if (key && this._adoptedIds.has(key)) {
            this.showSignupPrompt(false);
            return;
        }
        // Isolated: a DOM failure in the address write must not take the
        // identity fill with it. Recorded only once the write has happened, so
        // a failure leaves the next attempt free to try again.
        try {
            const source = buyer.billing_address || buyer.shipping_address || null;
            if (source && typeof source === 'object') {
                this.host().applyBuyerAddress(source);
                if (key) this._adoptedIds.add(key);
            }
            // Routed separately because the address writer deliberately never
            // touches telephone — correct for a registry number that is not the
            // buyer's own, where this record IS the buyer's own verified data.
            this.host().applyTelephone(buyer.phone_number);
        } catch (error) {
            console.error({ logger: 'twoPayment.adoptSoleTraderBuyer', error });
        }
        this.showSignupPrompt(false);
    };

    /**
     * Show or withdraw the on-page signup prompt — the fallback route when the
     * popup was blocked. The host renders it, because the two checkouts anchor
     * and style it differently.
     *
     * @param {boolean} show
     */
    SoleTrader.prototype.showSignupPrompt = function (show) {
        this.host().renderSignupPrompt(!!show, () => this.retrySignup());
    };

    /**
     * Listen for the hosted signup reporting its outcome.
     *
     * Bound once, for the page's life. A component that re-rendered would
     * otherwise stack one live listener per render, each writing to the live
     * address form on a single message.
     */
    SoleTrader.prototype.listenForSignupResult = function () {
        if (this._messageHandler) return;
        this._messageHandler = (event) => {
            if (event.origin !== this._component.config().checkoutPageUrl) return;
            // Correlate against the tracked popup: a message from any window
            // that is not the one currently open — stale, already superseded —
            // must never overwrite a later adoption. Truthiness first, because
            // `MessageEvent.source` is null for a non-window sender.
            if (!this._popupWindow || event.source !== this._popupWindow) return;
            if (!this.identity().isSoleTrader()) return;

            if (event.data !== 'ACCEPTED') {
                this.showSignupError();
                return;
            }
            // Held across the lookup: the popup can close the instant it posts,
            // well before the identity has been written, and the close watcher
            // must not read that as an abandoned signup.
            this._signupConfirming = true;
            this.identity().beginFlight();
            this.fetchBuyer()
                .then((buyer) => {
                    if (buyer) {
                        this.adoptBuyer(buyer);
                    } else {
                        this.showSignupError();
                    }
                })
                .finally(() => {
                    this._signupConfirming = false;
                    // Settled AFTER the write has landed: the flow is complete
                    // when the identity is in the form, not when the response
                    // arrived. Both sibling platforms order it this way.
                    this.identity().settleFlight();
                });
        };
        window.addEventListener('message', this._messageHandler);
    };

    /** A signup that did not complete. Silence would leave an open flow and no explanation. */
    SoleTrader.prototype.showSignupError = function () {
        this.host().showError(
            this._component.translate('Could not complete sole trader signup. Please try again.')
        );
    };

    /** Release everything this flow armed on the page. */
    SoleTrader.prototype.dispose = function () {
        if (this._messageHandler) {
            window.removeEventListener('message', this._messageHandler);
            this._messageHandler = null;
        }
        if (this._returnHandler) {
            window.removeEventListener('focus', this._returnHandler);
            this._returnHandler = null;
        }
        this.cancelPendingReturnClose();
        this.stopTokenRefresh();
        this.stopPopupCloseWatcher();
    };

    return SoleTrader;
}));
