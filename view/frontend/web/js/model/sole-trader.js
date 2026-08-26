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
 * component that Amasty and Fire Checkout destroy on every totals change was
 * orphaned mid-signup — the buyer completed enrolment and the `ACCEPTED`
 * handshake landed on a listener that no longer recognised the window.
 *
 * Owns the delegation/autofill token pair and its refresh, the hosted signup
 * popup and the watcher that notices the buyer closing it, and the
 * `postMessage` handshake enrolment finishes on.
 *
 * Does NOT own the identity it produces. Adoption calls back into the
 * component, which routes every write through one path — the same division
 * WooCommerce (`setCompany` → `twoincCompanyCapture.write`) and PrestaShop
 * (`adoptEnrolledIdentity` → `TwoCompanySearch.adoptSoleTraderBuyer`) both
 * settled on after their sole-trader modules hand-rolled it and drifted.
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'mage/translate',
    'Magento_Ui/js/model/messageList',
    'Two_Gateway/js/model/company-identity',
    'Two_Gateway/js/model/company-search'
], function ($, quote, url, $t, messageList, identity, companySearch) {
    'use strict';

    // WooCommerce's `scheduleTokenRefresh` and PrestaShop's
    // `_TOKEN_REFRESH_INTERVAL_MS` both use this. A buyer who sits on checkout
    // past expiry would otherwise find the signup URL rejected.
    const TOKEN_REFRESH_INTERVAL_MS = 30 * 60 * 1000;

    // There is no event for "the popup went away", so the opener polls.
    const POPUP_CLOSE_POLL_MS = 300;

    /**
     * Sole-trader identities whose registered address has already been written
     * into this page's checkout, so a replay does not overwrite a correction
     * the buyer made afterwards (TWO-25461 §5).
     */
    const adoptedSoleTraderIds = new Set();

    /**
     * What "the same sole trader" means for that guard. The organisation number
     * where there is one; the email otherwise, so two buyers who both arrive
     * without a number are not treated as one.
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
     *        Supplies `config()`, `countryCode()`, `adoptSoleTrader()`,
     *        `abandonSoleTrader()`.
     */
    function SoleTrader(component) {
        this._component = component;
        this.delegationToken = '';
        this.autofillToken = '';
        this._mintChain = null;
        this._tokenRefreshId = null;
        this._popupWindow = null;
        this._popupCloseWatcherId = null;
        this._messageHandler = null;
        // The handshake's own buyer lookup is still out. The popup can close
        // the instant it posts, and that lookup is the authority from then on.
        this._signupConfirming = false;
        this._blockedSignupOptions = null;
    }

    SoleTrader.prototype.getTokens = function () {
        const URL = url.build('rest/V1/two/get-tokens');
        return fetch(URL, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ cartId: quote.getQuoteId() })
        })
            .then((response) => {
                if (!response.ok) throw new Error(`Error response from ${URL}.`);
                return response.json();
            })
            .then((json) => json[0])
            .catch((error) => {
                console.error({ logger: 'twoPayment.getTokens', error });
                throw error;
            });
    };

    // Both tokens are minted together and neither is optional in the signup
    // URL — an empty one produces a link the hosted flow rejects.
    SoleTrader.prototype.hasSignupTokens = function () {
        return !!(this.delegationToken && this.autofillToken);
    };

    /**
     * Mint a fresh pair, replacing whatever is held.
     *
     * @returns {Promise<boolean>} whether the mint produced usable tokens
     */
    SoleTrader.prototype.mintTokens = function () {
        return this.getTokens()
            .then((json) => {
                this.delegationToken = json.delegation_token;
                this.autofillToken = json.autofill_token;
                return this.hasSignupTokens();
            })
            .catch(() => false);
    };

    /**
     * Have tokens ready BEFORE the buyer clicks anything, so the click
     * handler's `window.open()` runs inside the gesture that triggered it.
     * Called the moment the billing country is known to support sole traders —
     * WooCommerce mints at the same point, for the same reason.
     *
     * @returns {Promise<boolean>}
     */
    SoleTrader.prototype.ensureTokens = function () {
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

    SoleTrader.prototype.startTokenRefresh = function () {
        if (this._tokenRefreshId) return;
        this._tokenRefreshId = setInterval(() => this.refreshTokens(), TOKEN_REFRESH_INTERVAL_MS);
    };

    SoleTrader.prototype.stopTokenRefresh = function () {
        if (!this._tokenRefreshId) return;
        clearInterval(this._tokenRefreshId);
        this._tokenRefreshId = null;
    };

    /**
     * One refresh tick. Skipped while any round trip is outstanding — the
     * tokens a popup was launched with must stay valid for the flow it is
     * running, and that flight's own completion leaves them fresh anyway.
     */
    SoleTrader.prototype.refreshTokens = function () {
        if (identity.isBusy()) return;
        return this.mintTokens();
    };

    /**
     * The buyer data the hosted signup prefills its form from.
     *
     * @returns {string} base64 of the JSON payload
     */
    SoleTrader.prototype.getAutofillData = function () {
        const billingAddress = quote.billingAddress() || {};
        const street = (billingAddress.street || []).filter((s) => s).join(', ').split(' ');
        const data = {
            email: this.buyerEmail(),
            first_name: billingAddress.firstname,
            last_name: billingAddress.lastname,
            company_name: identity.companyName(),
            phone_number: billingAddress.telephone,
            billing_address: {
                building: (street[0] || '').replace(',', ''),
                street: street.slice(1).join(' '),
                postal_code: billingAddress.postcode,
                city: billingAddress.city,
                region: billingAddress.region,
                country_code: billingAddress.countryId
            }
        };
        // Bare btoa() only accepts Latin1; this is UTF-8 data (e.g. names with diacritics).
        return btoa(unescape(encodeURIComponent(JSON.stringify(data))));
    };

    /** @returns {string} the email the order is being placed under */
    SoleTrader.prototype.buyerEmail = function () {
        const billingAddress = quote.billingAddress();
        return (billingAddress && billingAddress.email) || quote.guestEmail || '';
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
        const country = ((quote.billingAddress() || {}).countryId || '').toUpperCase();
        if (country) params += `&country=${encodeURIComponent(country)}`;

        this._popupWindow = window.open(
            `${config.checkoutPageUrl}/soletrader/signup?${params}`,
            '_blank',
            'location=yes,resizable=yes,scrollbars=yes,status=yes,height=805,width=700'
        );
        if (this._popupWindow) this.watchPopupClose(this._popupWindow);
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
     * Hold the busy state while the popup is open, and hand the checkout back
     * to company search if the buyer closes it having captured nothing.
     *
     * @param {Window} win
     */
    SoleTrader.prototype.watchPopupClose = function (win) {
        identity.beginFlight();
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
        identity.settleFlight();
    };

    /** Re-arm the once-per-identity address guard. */
    SoleTrader.prototype.forgetAdoptions = function () {
        adoptedSoleTraderIds.clear();
    };

    /**
     * Read the buyer the popup has just authenticated.
     *
     * Reached only from the ACCEPTED handshake, so the buyer has proved this
     * identity server-side and the email it authenticated with IS the identity
     * — the order's contact field has no say in it. Re-gating on a match there
     * discarded an authenticated buyer and left the company field permanently
     * blank with no route forward (TWO-25461).
     *
     * @returns {Promise<object|null>}
     */
    SoleTrader.prototype.fetchBuyer = function () {
        const config = this._component.config();
        const params = new URLSearchParams(companySearch.apiClientParams(config));
        const URL = `${config.checkoutApiUrl}/autofill/v1/buyer/current?${params.toString()}`;
        return fetch(URL, {
            credentials: 'include',
            headers: { 'two-delegated-authority-token': this.autofillToken }
        })
            .then((response) => {
                if (response.ok) return response.json();
                if (response.status === 404) return null;
                throw new Error(`Error response from ${URL}.`);
            })
            .catch(() => null);
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
        this._component.adoptSoleTrader(buyer);
        const key = soleTraderIdentityKey(buyer);
        if (key && adoptedSoleTraderIds.has(key)) {
            this.showSignupPrompt(false);
            return;
        }
        // Isolated: a DOM failure in the address write must not take the
        // identity fill with it. Recorded only once the write has happened, so
        // a failure leaves the next attempt free to try again.
        try {
            const source = buyer.billing_address || buyer.shipping_address || null;
            if (source && typeof source === 'object') {
                companySearch.applyAddress(source, companySearch.billingRoleFormRoot());
                if (key) adoptedSoleTraderIds.add(key);
            }
            // Not routed through applyAddress(), which deliberately never
            // touches telephone — correct for a registry number that is not the
            // buyer's own, but this record IS the buyer's own verified data.
            companySearch.applyTelephone(buyer.phone_number);
        } catch (error) {
            console.error({ logger: 'twoPayment.adoptSoleTraderBuyer', error });
        }
        this.showSignupPrompt(false);
    };

    /**
     * Show or withdraw the on-page signup prompt — the fallback route when the
     * popup was blocked. Rendered beside the chips, which is the only surface
     * guaranteed to be on screen whichever mount is live.
     *
     * @param {boolean} show
     */
    SoleTrader.prototype.showSignupPrompt = function (show) {
        let $prompt = $('.two-sole-trader-note');
        if (!show) {
            $prompt.addClass('two-hidden');
            return;
        }
        if (!$prompt.length) {
            $prompt = $('<div></div>').addClass('two-sole-trader-note');
            $('<a href="#"></a>')
                .addClass('two-sole-trader-note__link')
                .text($t('Click here to log in or sign up as a Sole Trader.'))
                .on('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    this.retrySignup();
                })
                .appendTo($prompt);
            const $chips = $('.two-company-mode-chips');
            if (!$chips.length) return;
            $prompt.insertAfter($chips);
        }
        $prompt.removeClass('two-hidden');
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
            if (!identity.isSoleTrader()) return;

            if (event.data !== 'ACCEPTED') {
                this.showSignupError();
                return;
            }
            // Held across the lookup: the popup can close the instant it posts,
            // well before the identity has been written, and the close watcher
            // must not read that as an abandoned signup.
            this._signupConfirming = true;
            identity.beginFlight();
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
                    identity.settleFlight();
                });
        };
        window.addEventListener('message', this._messageHandler);
    };

    /** A signup that did not complete. Silence would leave an open flow and no explanation. */
    SoleTrader.prototype.showSignupError = function () {
        messageList.addErrorMessage({
            message: $t('Could not complete sole trader signup. Please try again.')
        });
    };

    /** Release everything this flow armed on the page. */
    SoleTrader.prototype.dispose = function () {
        if (this._messageHandler) {
            window.removeEventListener('message', this._messageHandler);
            this._messageHandler = null;
        }
        this.stopTokenRefresh();
        this.stopPopupCloseWatcher();
    };

    return SoleTrader;
});
