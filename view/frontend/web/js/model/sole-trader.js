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
 * Owns the whole flow end to end: minting the delegation/autofill token pair,
 * the passive buyer lookup on the Two cookie and its per-email dedup, the
 * hosted signup popup (including the country parameter the popup's identity
 * step depends on), adopting a resolved buyer into the checkout (identity,
 * registered address, phone) and the `postMessage` handshake the popup
 * finishes on.
 *
 * The HOST is whatever mounts this — today the payment tile's Knockout
 * renderer. Everything shared with the rest of the checkout is reached
 * through it and never duplicated here: the brand config, the buyer's email,
 * the company identity observables and their writers, the mode observables
 * the chips drive, and the error surface. Everything private to the flow —
 * the tokens, the lookup record and its generation counter, the popup handle,
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
     *        `companyName` / `showSoleTrader` / `showPopupMessage` /
     *        `captureMode` / `showModeTab` / `soleTraderLookupInFlight`
     *        observables, and `fillCompanyData()`, `fillTelephone()`,
     *        `clearCompany()`, `searchForCompanyLink()`, `showErrorMessage()`.
     */
    function SoleTrader(host) {
        this._host = host;
        this.delegationToken = '';
        this.autofillToken = '';

        // Autofill lookup result for the entered email, recorded by the
        // sole-trader chip click. ready=false until that lookup resolves;
        // matches=true when the buyer it found owns the entered email.
        this.soleTraderLookup = { ready: false, buyer: null, matches: false };
        this.soleTraderLookupEmail = null;
        this._soleTraderLookupChain = null;
        this._soleTraderLookupGeneration = 0;
        this._soleTraderPopupWindow = null;
        this._popupMessageHandler = null;
    }

    /** Detach the popup handshake listener. Safe to call twice. */
    SoleTrader.prototype.dispose = function () {
        // The popup's `message` listener — see popupMessageListener().
        if (this._popupMessageHandler) {
            window.removeEventListener('message', this._popupMessageHandler);
            this._popupMessageHandler = null;
        }
    };

    /**
     * Re-arm the once-per-identity address guard, for the events that mean
     * "there is an address to write again" — leaving sole-trader mode, and a
     * billing-country change (whose address revert the address step performs
     * on the same switch).
     */
    SoleTrader.prototype.forgetAdoptions = function () {
        adoptedSoleTraderIds.clear();
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
        return btoa(JSON.stringify(data));
    };

    // True once the signup URL can be built. Both tokens are minted
    // together by lookupSoleTrader(), and neither is optional in the
    // URL — an empty one produces a signup link the hosted flow rejects.
    SoleTrader.prototype.hasSignupTokens = function () {
        return !!(this.delegationToken && this.autofillToken);
    };

    // @param {Object} [options] `{ autoselect: false }` skips the hosted
    //   flow's silent autoselect of the buyer's existing registration —
    //   the only caller passing it is selectDifferentSoleTrader(). The
    //   flag is currently unread server-side (PS/WC precedent); wired
    //   through unconditionally, no client-side branching on its value.
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
    SoleTrader.prototype.openIframe = function (options) {
        const config = this._host._brandConfig;
        if (!this.hasSignupTokens()) {
            return null;
        }
        if (this._soleTraderPopupWindow && !this._soleTraderPopupWindow.closed) {
            this._soleTraderPopupWindow.close();
        }
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
        return this._soleTraderPopupWindow;
    };

    // "Select a different sole trader" (TWO-25461 §7). Only rendered once
    // an identity has already been adopted (see the template's `visible:`
    // binding), so tokens are already minted — skip the passive
    // cookie/email-match pre-check entirely and launch the popup directly,
    // synchronously with the click, with autoselect=false so the hosted
    // flow doesn't silently re-pick the same registration.
    SoleTrader.prototype.selectDifferentSoleTrader = function () {
        return this.openIframe({ autoselect: false });
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
        }
        return wasSoleTrader;
    };

    // Enter the sole-trader UI. No token/buyer work here — that is owned
    // by the chip-click handler.
    SoleTrader.prototype.enterSoleTraderUi = function () {
        const host = this._host;
        host.showSoleTrader(true);
        host.captureMode('soletrader');
        // Resolve the link BEFORE clearCompany(), which tears the widget
        // down and nulls _$companyNameField.
        const $searchForCompany = host.searchForCompanyLink();
        host.clearCompany();
        $searchForCompany.hide();
    };

    /**
     * Sole-trader chip click — the ONLY entry point to sole-trader
     * autofill (TWO-25503). Nothing is minted, looked up or adopted off
     * the back of email entry alone: the chip click is the buyer asking,
     * and the same explicit-click rule the company-search chip follows.
     *
     * A resolved-and-matching buyer is adopted; anything else sends the
     * buyer to signup. The popup therefore opens after an await and can
     * be blocker-killed, which is what showPopupMessage()'s link fallback
     * is for — but only once the tokens exist, since a link built with an
     * empty businessToken/autofillToken is rejected by the hosted flow.
     *
     * That async open is the accepted tradeoff of minting on the click
     * rather than up front, and it follows PrestaShop, which opens off
     * the same chained-fetch continuation and backs it with an on-page
     * prompt. (WooCommerce takes the other side: it mints once per page,
     * so its open stays inside the gesture. Here the chip click is the
     * only thing that mints at all — the explicit-click rule above — so
     * no mint can precede it.)
     */
    SoleTrader.prototype.soleTraderMode = function () {
        this.enterSoleTraderUi();
        return this.lookupSoleTrader().then(() => {
            const pf = this.soleTraderLookup;
            if (pf.ready && pf.matches && pf.buyer) {
                this.adoptSoleTraderBuyer(pf.buyer);
                return;
            }
            const win = this.openIframe();
            this._host.showPopupMessage(!win && this.hasSignupTokens());
        });
    };

    // Mint tokens + read the buyer on the Two cookie for the entered email.
    // Deduped per email so a repeated chip click doesn't re-mint — the
    // recorded result stands.
    //
    // Always returns a promise, including on the skip paths, so a caller
    // can sequence on the tokens being minted. A duplicate call returns
    // the OUTSTANDING chain rather than a resolved promise: the dedupe key
    // is set synchronously, so a second click landing mid-flight would
    // otherwise resume immediately on a lookup that has recorded nothing
    // and minted nothing — no adoption, no popup, and no link fallback
    // either, since that needs the tokens too.
    SoleTrader.prototype.lookupSoleTrader = function () {
        const host = this._host;
        if (!host.showModeTab()) {
            return Promise.resolve();
        }
        const email = (host.getEmail() || '').trim();
        if (!email) {
            // Nothing to look up — never hand back an in-flight chain for
            // a DIFFERENT email, which the caller would wrongly treat as
            // this (empty) attempt's own result.
            return Promise.resolve();
        }
        if (email === this.soleTraderLookupEmail) {
            return this._soleTraderLookupChain || Promise.resolve();
        }
        this.soleTraderLookupEmail = email;
        this.soleTraderLookup = { ready: false, buyer: null, matches: false };
        // Spinner covers the whole round trip; cleared on every terminal
        // branch below (success, failure) via .finally(), never a timeout.
        host.soleTraderLookupInFlight(true);
        // A chain is tied to the email it started for. An edit starts a
        // second one, the two settle in no guaranteed order, and the loser
        // must not record its buyer or act on it: `matches` is computed
        // against the email in the form NOW, so a late-landing chain for the
        // previous email resolves to a non-match, reverts sole-trader mode
        // and — before this guard — left the OTHER buyer's address in the
        // form with no identity beside it. The minted token travels as a
        // local for the same reason.
        const generation = (this._soleTraderLookupGeneration || 0) + 1;
        this._soleTraderLookupGeneration = generation;
        const isCurrent = () => this._soleTraderLookupGeneration === generation;
        this._soleTraderLookupChain = this.getTokens()
            .then((json) => {
                if (!isCurrent()) return null;
                this.delegationToken = json.delegation_token;
                this.autofillToken = json.autofill_token;
                return this.resolveBuyer(false, json.autofill_token, isCurrent);
            })
            .catch(() => {
                if (!isCurrent()) return;
                this.soleTraderLookup = { ready: true, buyer: null, matches: false };
            })
            .finally(() => {
                // Guarded on THIS call's email (review finding): an
                // earlier, still-outstanding call's .finally must not
                // clear the flag out from under a newer call that started
                // after an email edit — flightDepth-style ref-counting
                // without needing a counter, since soleTraderLookupEmail
                // already identifies "the current one".
                if (this.soleTraderLookupEmail === email) {
                    host.soleTraderLookupInFlight(false);
                }
            });

        return this._soleTraderLookupChain;
    };

    // Read the buyer on the Two cookie; resolves to the buyer or null. No
    // UI side effects — the caller decides what to do with the result.
    SoleTrader.prototype.fetchBuyer = function (autofillToken) {
        const URL = `${this._host._brandConfig.checkoutApiUrl}/autofill/v1/buyer/current`;
        return fetch(URL, {
            credentials: 'include',
            headers: {
                'two-delegated-authority-token': autofillToken || this.autofillToken
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
     * Read the buyer on the Two cookie and record it as the lookup
     * result, resolving to it.
     *
     * `authenticated` is the difference between the two contexts that
     * reach here, and it is not cosmetic. On the PASSIVE pre-auth path
     * nothing proves the cookie's buyer is the person checking out, so
     * the buyer only counts when its email matches the one entered on
     * the form. After the signup popup reports ACCEPTED the buyer has
     * just authenticated server-side, and the email it authenticated
     * with IS the identity — the order's contact-email field has no say
     * in it. Requiring a match there discarded an authenticated buyer
     * and left the company field permanently blank with no route
     * forward (TWO-25461).
     *
     * @param {boolean} authenticated buyer already proved this identity
     * @param {string} [autofillToken] token this lookup belongs to — the
     *        lookup passes the one IT minted, so a superseded chain cannot
     *        read the cookie under a newer chain's token
     * @param {function(): boolean} [isCurrent] answers whether this lookup is
     *        still the live one; a superseded lookup resolves to its result
     *        without recording it as the lookup result
     * @returns {Promise<object>} the result, recorded unless superseded
     */
    SoleTrader.prototype.resolveBuyer = function (authenticated, autofillToken, isCurrent) {
        return this.fetchBuyer(autofillToken).then((buyer) => {
            let matches;
            if (authenticated) {
                matches = !!buyer;
            } else {
                const entered = (this._host.getEmail() || '').trim().toLowerCase();
                matches = !!(
                    buyer &&
                    buyer.email &&
                    String(buyer.email).toLowerCase() === entered
                );
            }
            const resolved = { ready: true, buyer: buyer, matches: matches };
            if (isCurrent && !isCurrent()) return resolved;
            this.soleTraderLookup = resolved;
            return resolved;
        });
    };

    /**
     * Adopt the sole trader an autofill buyer record describes: the identity
     * into the tile, and the registered ADDRESS into the checkout address
     * form (TWO-25461 §5).
     *
     * The single write-back path for both contexts that resolve a buyer:
     * the chip click's own lookup, and the popup's post-signup `ACCEPTED`
     * message.
     *
     * NOT gated on `isAddressAreaCompanySearchEnabled` or the server-side
     * `isAddressSearchEnabled`, per §5: those gate an ORDINARY
     * company-search selection's address write, and both are legitimately
     * off wherever company search is not mounted in the address area —
     * which is exactly where the sole-trader entry point lives.
     *
     * The address is written ONCE PER IDENTITY. Both paths can fire for
     * the same buyer (a repeated chip click, a repeated `ACCEPTED`),
     * and a replay must not overwrite a correction the buyer made to the
     * address after the first write. Adopting a DIFFERENT identity writes
     * again, and leaving sole-trader mode re-arms it.
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
                    // Signup complete: the buyer authenticated in the
                    // popup, so re-read and autofill whatever identity
                    // that produced, staying in sole-trader mode. No
                    // email-match check — see resolveBuyer().
                    // Supersede any lookup still in flight. Its `matches`
                    // is computed against the form's email, so a late
                    // pre-auth answer would disagree with the identity the
                    // buyer just authenticated, revert sole-trader mode and
                    // take this adoption's address back out with it.
                    this._soleTraderLookupGeneration = (this._soleTraderLookupGeneration || 0) + 1;
                    this.resolveBuyer(true).then((pf) => {
                        if (pf.matches && pf.buyer) {
                            this.adoptSoleTraderBuyer(pf.buyer);
                        }
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
