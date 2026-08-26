/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: the sole-trader capture flow, as its own model — Magento's
 * counterpart to PrestaShop's `TwoSoleTrader.js` and WooCommerce's
 * `twoincSoleTrader`, both of which are siblings of their company-search
 * module rather than part of their payment-method code.
 *
 * Owns the whole flow end to end: minting the delegation/autofill token pair
 * and keeping it alive, the hosted signup popup (including the country
 * parameter the popup's identity step depends on), watching that popup for
 * abandonment, adopting the buyer it authenticates into the checkout
 * (identity, registered address, phone) and the `postMessage` handshake the
 * popup finishes on.
 *
 * The buyer's company is only ever filled in by their own trip through the
 * hosted signup flow. `/autofill/v1/buyer/current` is read from one place, the
 * popup's `ACCEPTED` handshake, and never before it: the record it returns is
 * whatever Two session cookie the browser happens to carry, which the person
 * checking out may never have authenticated against. That is also what leaves
 * the click-to-`window.open()` path synchronous, where popup blockers allow it
 * — a returning sole trader is recognised by the hosted flow's own autoselect.
 *
 * The HOST is whatever mounts this — today the payment tile's Knockout
 * renderer. Everything shared with the rest of the checkout is reached
 * through it and never duplicated here: the brand config, the buyer's email,
 * the company identity observables and their writers, the mode observables
 * the chips drive, and the error surface. Everything private to the flow —
 * the tokens and their refresh timer, the popup handle and its close watcher,
 * the `message` listener — is this object's own state.
 *
 * @see view/frontend/web/js/model/company-capture.js for the mode/chip
 *      selector that decides when this flow is entered.
 */
define([
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'Two_Gateway/js/model/company-search'
], function (quote, url, companySearch) {
    'use strict';

    // Matches WooCommerce's `scheduleTokenRefresh` and PrestaShop's
    // `_TOKEN_REFRESH_INTERVAL_MS`. A buyer who sits on checkout past the
    // tokens' expiry would otherwise find the signup popup broken on a stale
    // token the next time they reach for it.
    const TOKEN_REFRESH_INTERVAL_MS = 30 * 60 * 1000;

    // How often the opener can ask whether the popup went away. There is no
    // event for it, so polling `window.closed` is the only signal available.
    const POPUP_CLOSE_POLL_MS = 300;

    /**
     * Sole-trader identities whose registered address has already been written
     * into this page's checkout, so a replay does not overwrite a correction the
     * buyer made afterwards (TWO-25461 §5).
     *
     * Module scope, not per instance: Amasty and Fire Checkout rebuild the
     * payment method list on every totals change, and a per-instance record
     * would be re-armed by the very re-render the guard exists to survive.
     * Cleared when the buyer leaves sole-trader mode, the one event that means
     * "write it again next time".
     */
    const adoptedSoleTraderIds = new Set();

    /**
     * Outstanding sole-trader round trips, across every mount. Module scope
     * because the `soleTraderBusy` observable it drives is prototype-level and
     * therefore shared by every brand tile on the checkout: a per-instance
     * count would let one tile's settle drop another's spinner.
     */
    let flightDepth = 0;

    /**
     * What "the same sole trader" means for that guard. The organisation number
     * where there is one; the email otherwise, so two buyers who both arrive
     * without a number are not treated as one.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     * @returns {string}
     */
    function soleTraderIdentityKey(brandCode, buyer) {
        const number = String(buyer.organization_number || '').trim();
        // Per BRAND: a checkout offering two Two-family brands renders a tile —
        // and a billing form — for each, so an adoption in one says nothing
        // about whether the other's form has been written.
        if (number) return `${brandCode}:${number}`;
        const email = String(buyer.email || '').trim().toLowerCase();
        // Nothing to tell one such buyer from another, so record nothing and let
        // the write happen every time — repeating a write is recoverable, and
        // collapsing two buyers onto one key silently drops the second's address.
        return email ? `${brandCode}:email:${email}` : '';
    }

    /**
     * @param {object} host the mount this flow runs inside. Must supply
     *        `_brandConfig`, `getCode()`, `getEmail()`, `getTelephone()`, the
     *        `companyName` / `companyId` / `showSoleTrader` /
     *        `showPopupMessage` / `captureMode` / `showModeTab` /
     *        `soleTraderBusy` observables, and `fillCompanyData()`,
     *        `fillTelephone()`, `clearCompany()`, `searchForCompanyLink()`,
     *        `registeredOrganisationMode()`, `showErrorMessage()`.
     */
    function SoleTrader(host) {
        this._host = host;
        this.delegationToken = '';
        this.autofillToken = '';
        this._mintChain = null;
        this._tokenRefreshId = null;
        this._soleTraderPopupWindow = null;
        this._popupCloseWatcherId = null;
        this._popupMessageHandler = null;
        // The ACCEPTED handshake's own buyer lookup is still out. The popup can
        // close the instant it posts, and that handler is the sole authority on
        // the outcome once it has.
        this._signupConfirming = false;
        this._blockedSignupOptions = null;
        this._destroyed = false;
    }

    /** Detach everything this instance armed on the page. Safe to call twice. */
    SoleTrader.prototype.dispose = function () {
        this._destroyed = true;
        // The popup's `message` listener — see popupMessageListener().
        if (this._popupMessageHandler) {
            window.removeEventListener('message', this._popupMessageHandler);
            this._popupMessageHandler = null;
        }
        this.stopTokenRefresh();
        this.stopPopupCloseWatcher();
    };

    /**
     * Re-arm the once-per-identity address guard, for the events that mean
     * "there is an address to write again" — leaving sole-trader mode, and a
     * billing-country change (whose address revert the address step performs
     * on the same switch).
     */
    SoleTrader.prototype.forgetAdoptions = function () {
        adoptedSoleTraderIds.clear();
        // The identity itself is gone on both of those events — a latch left
        // standing would answer for an identity no longer on screen.
        this._host.soleTraderAdopted(false);
    };

    SoleTrader.prototype.getTokens = function () {
        const URL = url.build('rest/V1/two/get-tokens');
        const OPTIONS = {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ cartId: quote.getQuoteId() })
        };

        return fetch(URL, OPTIONS)
            .then((response) => {
                if (response.ok) {
                    return response.json();
                } else {
                    throw new Error(`Error response from ${URL}.`);
                }
            })
            .then((json) => {
                return json[0];
            })
            .catch((error) => {
                console.error({ logger: 'twoPayment.getTokens', error });
                throw error;
            });
    };

    // True once the signup URL can be built. Both tokens are minted
    // together, and neither is optional in the URL — an empty one produces a
    // signup link the hosted flow rejects.
    SoleTrader.prototype.hasSignupTokens = function () {
        return !!(this.delegationToken && this.autofillToken);
    };

    /**
     * Mint a fresh token pair, replacing whatever is held.
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
     * Have signup tokens ready BEFORE the buyer clicks anything, so the click
     * handler's `window.open()` runs inside the gesture that triggered it.
     * Called the moment the billing country's registry is known to support
     * sole traders — WooCommerce mints at the same point, for the same reason.
     *
     * Idempotent, and never two mints at once: a second caller joins the
     * outstanding one.
     *
     * @returns {Promise<boolean>} whether tokens are available
     */
    SoleTrader.prototype.ensureTokens = function () {
        if (this.hasSignupTokens()) {
            // Re-armed here rather than only on the first mint, so tokens that
            // survived a country round trip keep their refresh timer.
            this.startTokenRefresh();
            return Promise.resolve(true);
        }
        if (this._mintChain) return this._mintChain;
        this._mintChain = this.mintTokens()
            .then((minted) => {
                // A mint outstanding when the checkout re-renders resolves
                // against a disposed instance, whose timer nothing would ever
                // clear again.
                if (minted && !this._destroyed) this.startTokenRefresh();
                return minted;
            })
            .finally(() => {
                this._mintChain = null;
            });
        return this._mintChain;
    };

    SoleTrader.prototype.startTokenRefresh = function () {
        if (this._tokenRefreshId || this._destroyed) return;
        this._tokenRefreshId = setInterval(() => this.refreshTokens(), TOKEN_REFRESH_INTERVAL_MS);
    };

    SoleTrader.prototype.stopTokenRefresh = function () {
        if (!this._tokenRefreshId) return;
        clearInterval(this._tokenRefreshId);
        this._tokenRefreshId = null;
    };

    /**
     * One refresh tick. Skipped while any round trip is outstanding — the
     * tokens it was launched with must stay valid for the flow it is running,
     * and that flight's own completion leaves them fresh anyway. A failed
     * re-mint is left for the next tick.
     */
    SoleTrader.prototype.refreshTokens = function () {
        if (flightDepth > 0) return;
        return this.mintTokens();
    };

    SoleTrader.prototype.getAutofillData = function () {
        const host = this._host;
        const billingAddress = quote.billingAddress();
        const _street = billingAddress.street
            .filter((s) => s)
            .join(', ')
            .split(' ');
        const building = _street[0].replace(',', '');
        const street = _street.slice(1, _street.length).join(' ');
        const data = {
            email: host.getEmail(),
            first_name: billingAddress.firstname,
            last_name: billingAddress.lastname,
            company_name: host.companyName(),
            phone_number: host.getTelephone(),
            billing_address: {
                building: building,
                street: street,
                postal_code: billingAddress.postcode,
                city: billingAddress.city,
                region: billingAddress.region,
                country_code: billingAddress.countryId
            }
        };
        // Bare btoa() only accepts Latin1; this is UTF-8 data (e.g. names with diacritics).
        return btoa(unescape(encodeURIComponent(JSON.stringify(data))));
    };

    SoleTrader.prototype.isPopupOpen = function () {
        return !!(this._soleTraderPopupWindow && !this._soleTraderPopupWindow.closed);
    };

    // @param {Object} [options] `{ autoselect: false }` skips the hosted
    //   flow's silent autoselect of the buyer's existing registration —
    //   passed whenever the buyer is asking to REPLACE an identity already
    //   on screen. The flag is currently unread server-side (PS/WC
    //   precedent); wired through unconditionally, no client-side branching
    //   on its value.
    //
    // Re-entrancy (TWO-25461 review finding): at most one sole-trader
    // popup is ever live. A prior one still open (e.g. the first
    // adoption's popup, still open when "select a different sole
    // trader" is clicked) is CLOSED, not left running — closing it
    // rather than refocusing it also stops it from later posting a
    // stale ACCEPTED that would win a race against whichever popup the
    // buyer actually completed (popupMessageListener() additionally
    // checks event.source against the tracked handle as a second line
    // of defence). Same handle also makes a double-click on either
    // launcher a close-then-reopen instead of two concurrent tabs.
    //
    // Synchronous from top to bottom, with no await anywhere on the path
    // from the click to `window.open()`: that is what keeps the popup
    // inside the user gesture a blocker will allow.
    SoleTrader.prototype.openIframe = function (options) {
        const config = this._host._brandConfig;
        if (!this.hasSignupTokens()) {
            return null;
        }
        if (this.isPopupOpen()) {
            this._soleTraderPopupWindow.close();
        }
        this.stopPopupCloseWatcher();
        const data = this.getAutofillData();
        var brandParams = config.brand ? `&brand=${config.brand}` : '';
        if (config.brandVersion) {
            brandParams += `&brandVersion=${config.brandVersion}`;
        }
        if (options && options.autoselect === false) {
            brandParams += '&autoselect=false';
        }
        // PDEV-4669: the popup only renders its country-specific identity
        // step (e.g. US Onfido biometric consent) when the URL carries
        // `&country=`; without it the popup silently defaulted its whole
        // form to GB. Sourced from the quote's own billing address — the
        // same server-resolved value getAutofillData() already puts in
        // billing_address.country_code — not from countryCode(), which
        // is partly DOM-fed and would let a buyer dodge the extra step
        // by picking a different country client-side.
        const country = (quote.billingAddress().countryId || '').toUpperCase();
        const countryParam = country ? `&country=${encodeURIComponent(country)}` : '';
        const URL = `${config.checkoutPageUrl}/soletrader/signup?businessToken=${this.delegationToken}&autofillToken=${this.autofillToken}&autofillData=${data}${brandParams}${countryParam}`;
        const windowFeatures =
            'location=yes,resizable=yes,scrollbars=yes,status=yes, height=805, width=700';
        this._soleTraderPopupWindow = window.open(URL, '_blank', windowFeatures);
        if (this._soleTraderPopupWindow) {
            this.watchPopupClose(this._soleTraderPopupWindow);
        }
        return this._soleTraderPopupWindow;
    };

    /**
     * Hold the busy state for as long as the popup is open, and hand the
     * checkout back to an ordinary company search if the buyer closes it
     * having captured nothing (WooCommerce `watchPopupClose` parity).
     *
     * @param {Window} win the popup returned by `window.open`
     */
    SoleTrader.prototype.watchPopupClose = function (win) {
        this.beginFlight();
        this._popupCloseWatcherId = setInterval(() => {
            if (!win.closed) return;
            this.stopPopupCloseWatcher();
            // The ACCEPTED handshake's buyer lookup can still be out; it owns
            // the outcome from here and will write the identity it resolves.
            if (this._signupConfirming) return;
            if (this._host.showSoleTrader() && !this._host.soleTraderAdopted()) {
                this._host.registeredOrganisationMode();
            }
        }, POPUP_CLOSE_POLL_MS);
    };

    /**
     * Settles the flight the watcher was holding, so the busy state cannot
     * outlive it — a superseding launch and dispose() both stop a watcher
     * whose popup will never be polled closed.
     */
    SoleTrader.prototype.stopPopupCloseWatcher = function () {
        if (!this._popupCloseWatcherId) return;
        clearInterval(this._popupCloseWatcherId);
        this._popupCloseWatcherId = null;
        this.settleFlight();
    };

    /**
     * A sole-trader round trip is outstanding — the popup being open counts,
     * and so does the ACCEPTED handshake's own lookup. Counted rather than
     * flagged because both can be live at once.
     */
    SoleTrader.prototype.beginFlight = function () {
        flightDepth += 1;
        this._host.soleTraderBusy(true);
    };

    SoleTrader.prototype.settleFlight = function () {
        flightDepth = Math.max(0, flightDepth - 1);
        if (flightDepth === 0) {
            this._host.soleTraderBusy(false);
        }
    };

    // "Select a different sole trader" (TWO-25461 §7). autoselect=false so the
    // hosted flow offers a choice rather than handing back the registration
    // the buyer is asking to replace.
    SoleTrader.prototype.selectDifferentSoleTrader = function () {
        return this.launchSignup({ autoselect: false });
    };

    /**
     * Leave sole-trader mode, discarding the identity it captured.
     *
     * Shared by the two chips that can be clicked while sole trader is
     * active (registered, manual entry). A no-op in the other two modes,
     * which is what makes it safe on the initial registered-mode call.
     *
     * @returns {boolean} whether sole-trader mode was actually left
     */
    SoleTrader.prototype.leaveSoleTraderMode = function () {
        const host = this._host;
        // Read BEFORE the flag is flipped: the discard below is what
        // separates an actual departure from sole-trader mode from the
        // no-op call the other two modes make.
        const wasSoleTrader = host.showSoleTrader();
        host.showSoleTrader(false);
        host.showPopupMessage(false);
        if (wasSoleTrader) {
            // Leaving sole trader discards the sole-trader identity, the
            // mirror of enterSoleTraderUi() clearing on the way in. A
            // sole trader's minted name and synthetic number are not a
            // registered organisation, so carrying them across the mode
            // switch would submit one identity under the other's mode —
            // getData() would otherwise post the sole trader's number
            // under whatever name the buyer then supplies.
            //
            // Runs before the caller's own enableCompanySearch():
            // clearCompany() ends in destroyCompanySearchWidget(), which
            // would otherwise tear down the widget that call had just
            // rebuilt.
            host.clearCompany();
            // The address half of the same discard (TWO-25461 §5). Without
            // it the sole trader's registered address stays in the form and
            // goes out under whatever registered company the buyer searches
            // for next. Only fields still holding what the write put there
            // are cleared, so a buyer's own edits survive.
            companySearch.revertAutofilledAddress();
            // Re-arm the once-per-identity guard: re-entering sole-trader
            // mode has an address to write again.
            adoptedSoleTraderIds.clear();
            this._host.soleTraderAdopted(false);
        }
        return wasSoleTrader;
    };

    // Enter the sole-trader UI. No token or buyer work here — the tokens
    // were minted when the option became available.
    SoleTrader.prototype.enterSoleTraderUi = function () {
        const host = this._host;
        this._host.soleTraderAdopted(false);
        host.showSoleTrader(true);
        host.captureMode('soletrader');
        // Resolve the link BEFORE clearCompany(), which tears the widget
        // down and nulls _$companyNameField.
        const $searchForCompany = host.searchForCompanyLink();
        host.clearCompany();
        $searchForCompany.hide();
    };

    /**
     * Sole-trader chip click — always the hosted signup, no conditional fast
     * path, and no await between the click and `window.open()`.
     *
     * Re-clicking once an identity is adopted is the same re-signup the
     * "select a different sole trader" link launches, so the hosted flow is
     * told to offer a choice rather than hand back what is already on screen.
     *
     * @returns {Window|null} the popup, or null if it was blocked or tokens
     *          were not ready
     */
    SoleTrader.prototype.soleTraderMode = function () {
        if (this._host.showSoleTrader() && this._host.soleTraderAdopted()) {
            return this.launchSignup({ autoselect: false });
        }
        this.enterSoleTraderUi();
        return this.launchSignup();
    };

    /**
     * Open the signup popup and, if it did not open, offer the on-page link
     * that lets the buyer ask for it again — the fallback for a browser that
     * blocked it, and for the narrow window before the up-front mint lands.
     *
     * The retry needs tokens, so a mint is kicked off when there were none;
     * it is deliberately not awaited, because the next open has to stay
     * inside its own click.
     *
     * @param {object} [options] passed through to openIframe()
     * @returns {Window|null}
     */
    SoleTrader.prototype.launchSignup = function (options) {
        const win = this.openIframe(options);
        this._host.showPopupMessage(!win);
        if (!win) {
            // Held for retrySignup(): a blocked "select a different sole
            // trader" that retried without them would hand the buyer back the
            // very registration they are trying to replace.
            this._blockedSignupOptions = options || null;
            this.ensureTokens();
        }
        return win;
    };

    /** The fallback link's own launch, on the terms the blocked one had. */
    SoleTrader.prototype.retrySignup = function () {
        return this.launchSignup(this._blockedSignupOptions);
    };

    /**
     * Whether an identity is adopted and on screen. The one answer every
     * surface asks — the revert decision, the chip's re-signup branch and the
     * "select a different sole trader" link — so they cannot disagree about
     * the same buyer.
     *
     * @returns {boolean}
     */
    SoleTrader.prototype.isSoleTraderAdopted = function () {
        return this._host.soleTraderAdopted();
    };

    // Read the buyer on the Two cookie; resolves to the buyer or null.
    SoleTrader.prototype.fetchBuyer = function () {
        const URL = `${this._host._brandConfig.checkoutApiUrl}/autofill/v1/buyer/current`;
        return fetch(URL, {
            credentials: 'include',
            headers: {
                'two-delegated-authority-token': this.autofillToken
            }
        })
            .then((response) => {
                if (response.ok) return response.json();
                if (response.status == 404) return null;
                throw new Error(`Error response from ${URL}.`);
            })
            .catch(() => null);
    };

    /**
     * Read the buyer the popup has just authenticated.
     *
     * Reached only from the ACCEPTED handshake, so the buyer has proved this
     * identity server-side and the email it authenticated with IS the
     * identity — the order's contact-email field has no say in it. Re-gating
     * on a match there discarded an authenticated buyer and left the company
     * field permanently blank with no route forward (TWO-25461).
     *
     * @returns {Promise<object>} `{ buyer, matches }`
     */
    SoleTrader.prototype.resolveBuyer = function () {
        return this.fetchBuyer().then((buyer) => {
            return { buyer: buyer, matches: !!buyer };
        });
    };

    /**
     * Adopt the sole trader an autofill buyer record describes: the identity
     * into the tile, and the registered ADDRESS into the checkout address
     * form (TWO-25461 §5).
     *
     * NOT gated on `isAddressAreaCompanySearchEnabled` or the server-side
     * `isAddressSearchEnabled`, per §5: those gate an ORDINARY
     * company-search selection's address write, and both are legitimately
     * off wherever company search is not mounted in the address area —
     * which is exactly where the sole-trader entry point lives.
     *
     * The address is written ONCE PER IDENTITY. A repeated `ACCEPTED` must
     * not overwrite a correction the buyer made to the address after the
     * first write. Adopting a DIFFERENT identity writes again, and leaving
     * sole-trader mode re-arms it.
     *
     * No order-intent trigger is added: that stays the host's
     * fillCompanyData(), behind `_orderIntentInFlightFor`.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     */
    SoleTrader.prototype.adoptSoleTraderBuyer = function (buyer) {
        const host = this._host;
        if (!buyer || typeof buyer !== 'object') return;
        // Writes nothing without BOTH a name and a number, so a sole trader
        // with no trading name of their own gets no identity written here —
        // but their ADDRESS is still filled, which is why the address write
        // is not inside that call.
        host.fillCompanyData({
            companyId: buyer.organization_number,
            companyName: buyer.company_name
        });
        // `getCode()` comes from the payment renderer base and is the brand's
        // own method code; guarded because the guard must not be the thing
        // that breaks a mount built without it.
        const brandCode = (typeof host.getCode === 'function' && host.getCode()) || '';
        const identity = soleTraderIdentityKey(brandCode, buyer);
        if (!identity || !adoptedSoleTraderIds.has(identity)) {
            // Isolated: a DOM failure in the address write must not take the
            // identity fill or the popup message with it. Recorded only once
            // the write has actually happened, so a failure — or a record
            // with no address on it — leaves the next attempt free to try
            // again rather than consuming the single chance.
            try {
                const addressWritten = this.writeSoleTraderAddress(buyer);
                this.writeSoleTraderPhone(buyer);
                if (addressWritten && identity) {
                    adoptedSoleTraderIds.add(identity);
                }
            } catch (error) {
                console.error({ logger: 'twoPayment.adoptSoleTraderBuyer', error });
            }
        }
        this._host.soleTraderAdopted(true);
        host.showPopupMessage(false);
    };

    /**
     * Write the buyer's registered address into the billing-role address
     * form.
     *
     * `billing_address` is the registered address and is what fills the
     * form; `shipping_address` is a FALLBACK for a record that carries one
     * and no billing address. A null must never be allowed to blank
     * anything, hence the shape check rather than a truthiness test.
     *
     * Field routing is companySearch.applyAddress()'s, shared with the
     * ordinary company-search write — §2.6 asks for one rule for both, with
     * no sole-trader special case. The SCOPE is this surface's own decision:
     * the tile writes as the billing/invoice role (§1(a.3)), and the payment
     * step has more than one address form in the DOM to get that wrong in.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     * @returns {boolean} whether there was an address to write
     */
    SoleTrader.prototype.writeSoleTraderAddress = function (buyer) {
        const source = buyer.billing_address || buyer.shipping_address || null;
        if (!source || typeof source !== 'object') return false;
        console.debug({ logger: 'twoPayment.writeSoleTraderAddress', source });
        companySearch.applyAddress(source, companySearch.billingRoleFormRoot());
        return true;
    };

    /**
     * Write the buyer's own phone number into checkout. Not routed through
     * applyAddress() — that write deliberately never touches telephone,
     * correct for a registry number that is not the buyer's own, but this
     * record IS the buyer's own verified data (TWO-25503).
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     */
    SoleTrader.prototype.writeSoleTraderPhone = function (buyer) {
        this._host.fillTelephone(buyer.phone_number);
    };

    SoleTrader.prototype.popupMessageListener = function () {
        const host = this._host;
        // Kept so dispose() can detach it. A re-rendering checkout (Amasty
        // and Fire Checkout both rebuild the method list) otherwise stacks
        // one live listener per render, each closed over a disposed renderer
        // and each now writing to the live address form on one message.
        this._popupMessageHandler = (event) => {
            // event.source correlation (TWO-25461 review finding): a
            // second line of defence alongside openIframe()'s
            // close-before-reopen — a message from any popup that is not
            // the one CURRENTLY tracked (stale, already superseded) is
            // ignored, so it can never overwrite a later adoption.
            if (
                host.showSoleTrader() &&
                event.origin == host._brandConfig.checkoutPageUrl &&
                // Truthiness first: `MessageEvent.source` is null for a sender
                // that is not a window (a worker, a window already closed), so
                // comparing against an untracked handle alone would accept
                // such a message before any popup has been opened.
                this._soleTraderPopupWindow &&
                event.source === this._soleTraderPopupWindow
            ) {
                if (event.data == 'ACCEPTED') {
                    // Held across the lookup: the popup can close the instant
                    // it posts, well before the identity has been written, and
                    // the close watcher must not read that as an abandoned
                    // signup and revert the mode out from under it.
                    this._signupConfirming = true;
                    this.beginFlight();
                    this.resolveBuyer()
                        .then((resolved) => {
                            if (resolved.matches) {
                                this.adoptSoleTraderBuyer(resolved.buyer);
                            } else {
                                host.showErrorMessage(host.soleTraderErrorMessage);
                            }
                        })
                        .finally(() => {
                            this._signupConfirming = false;
                            this.settleFlight();
                        });
                } else {
                    host.showErrorMessage(host.soleTraderErrorMessage);
                }
            }
        };
        window.addEventListener('message', this._popupMessageHandler);
    };

    return SoleTrader;
});
