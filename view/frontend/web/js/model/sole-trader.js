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
 * point, the quote, the address write-back and the error surface, arrives
 * through `host`, which the capture component assembles from
 * its own options.
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
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.TwoSoleTrader = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    // WooCommerce's `scheduleTokenRefresh` and PrestaShop's
    // `_TOKEN_REFRESH_INTERVAL_MS` both use this. A buyer who sits on checkout
    // past expiry would otherwise find the signup URL rejected.
    const TOKEN_REFRESH_INTERVAL_MS = 30 * 60 * 1000;

    // There is no event for "the popup went away", so the opener polls.
    const POPUP_CLOSE_POLL_MS = 300;

    // Jittered, so a shop's checkout population does not re-mint in lockstep.
    const MINT_RETRY_DELAY_MS = 3000;
    const MINT_RETRY_JITTER_MS = 2000;

    /** Whether a failure settles the request rather than the moment — 408 and 429 do not. */
    function isSettledFailure(status) {
        if (status === 408 || status === 429) return false;
        return status >= 400 && status < 500;
    }

    /** The one control whose focus raises the signup popup instead of closing it. */
    const SOLE_TRADER_CHIP_SELECTOR = '[data-two-chip="soletrader"]';


    /** company-search-panel.js's `CLASSES.PANEL`, which this module cannot import. */
    const CAPTURE_POPOVER_CLASS = 'two-company-dropdown';

    /**
     * This capture's own popover. The panel builds it as the field's SIBLING and
     * `isBound()` holds the two to one parent, so a sibling scan cannot reach another
     * capture's — which a descendant search under a container holding both can.
     *
     * @param {?Element} field
     * @returns {?Element}
     */
    function ownPopover(field) {
        const parent = field && field.parentElement;
        const children = (parent && parent.children) || [];
        for (let i = 0; i < children.length; i += 1) {
            if (children[i].classList && children[i].classList.contains(CAPTURE_POPOVER_CLASS)) {
                return children[i];
            }
        }
        return null;
    }

    /**
     * Page-level, not per-flow: the host builds one capture flow per address
     * panel, and only one delegation/autofill pair may be live per checkout
     * (TWO-25646).
     */
    const page = {
        delegationToken: '',
        autofillToken: '',
        _mintChain: null,
        _tokenRefreshId: null,
        _prefetch: null,
        _autofillBuyer: null,
        _autofillGeneration: 0,
        _prefetchOwed: false,
        _mintRetryId: null
    };

    /**
     * An autofill address with its blank values dropped. A blank says the record
     * has nothing for that field, which is not an instruction to empty it: the
     * host clears a field the record leaves out only where the plugin wrote
     * what it holds (TWO-26775, PrestaShop's `adoptSoleTraderBuyer` rules).
     *
     * @param {object} address
     * @returns {object}
     */
    function withoutBlanks(address) {
        const kept = {};
        Object.keys(address).forEach(function (key) {
            const value = address[key];
            if (value === undefined || value === null) return;
            if (typeof value === 'string' && !value.trim()) return;
            kept[key] = value;
        });
        return kept;
    }

    /**
     * Whether a held autofill answer is the buyer at this checkout, judged by the
     * email the checkout holds (TWO-26775, PrestaShop's `buyerMatchesCheckout`).
     * A held answer for anyone else is offered through the popup instead.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     * @param {string} checkoutEmail
     * @returns {boolean}
     */
    function buyerMatchesCheckout(buyer, checkoutEmail) {
        const entered = String(checkoutEmail || '').trim().toLowerCase();
        const own = String((buyer && buyer.email) || '').trim().toLowerCase();
        return !!(entered && own && own === entered);
    }

    /**
     * Whether an autofill record carries enough to adopt.
     *
     * Keyed on the number, as PrestaShop's adoption is: a record without one
     * would replace the captured company with a sole trader the order cannot be
     * placed for. A blank name is adoptable, and keeps the name in the field
     * (TWO-26775).
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     * @returns {boolean}
     */
    function isUsableSoleTrader(buyer) {
        if (!buyer || typeof buyer !== 'object') return false;
        const number = buyer.organization_number;
        return !!String(number === null || number === undefined ? '' : number).trim();
    }

    /**
     * @param {object} component the company-capture component this flow serves.
     *        Supplies `config()`, `identity()`, `host()`, `adoptSoleTrader()`,
     *        `abandonSoleTrader()`.
     */
    function SoleTrader(component) {
        this._component = component;
        this._popupWindow = null;
        this._popupCloseWatcherId = null;
        this._messageHandler = null;
        this._returnHandler = null;
        /** Where the launch parked the focus the popup took; the return watch reads it as its own. */
        this._parkedFocus = null;
        // The handshake's own buyer lookup is still out. The popup can close
        // the instant it posts, and that lookup is the authority from then on.
        this._signupConfirming = false;
        this._blockedSignupOptions = null;
        /** The control the open popup was launched from; focus goes back to it on a hand-close. */
        this._launcher = null;
        /** The popup was closed by a focus or click rule rather than by the buyer's own hand. */
        this._closedByRule = false;
        liveFlows.add(this);
    }

    /**
     * Every flow alive on this page. The one shared refresh answers to all of
     * them: a tick that read only its own flow's identity would mint over the
     * pair a signup opened from another panel is running on.
     */
    const liveFlows = new Set();

    /**
     * @returns {boolean} whether a hosted signup is up anywhere on the checkout
     */
    function anySignupOpen() {
        let open = false;
        liveFlows.forEach(function (flow) {
            if (flow.isPopupOpen()) open = true;
        });
        return open;
    }

    /** @returns {boolean} whether any flow on the page has a round trip out */
    function anyFlowBusy() {
        let busy = false;
        liveFlows.forEach(function (flow) {
            if (flow.identity().isBusy()) busy = true;
        });
        return busy;
    }

    Object.keys(page).forEach(function (name) {
        Object.defineProperty(SoleTrader.prototype, name, {
            get: function () { return page[name]; },
            set: function (value) { page[name] = value; }
        });
    });

    /** @returns {object} the host adapter the component was built with */
    SoleTrader.prototype.host = function () {
        return this._component.host();
    };

    /** @returns {object} this flow's own per-panel identity */
    SoleTrader.prototype.identity = function () {
        return this._component.identity();
    };

    SoleTrader.prototype.getTokens = function () {
        const URL = this.host().tokensUrl();
        return fetch(URL, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ cartId: this.host().quoteId() })
        })
            .then((response) => {
                if (!response.ok) {
                    const error = new Error(`Error response from ${URL}.`);
                    error.status = response.status;
                    throw error;
                }
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
    SoleTrader.prototype.hasSignupTokens = function () {
        return !!(this.delegationToken && this.autofillToken);
    };

    /**
     * Mint a fresh pair, replacing whatever is held.
     *
     * @returns {Promise<number>} 0 means retryable (answered with an empty token, or never answered)
     */
    SoleTrader.prototype.mintTokens = function () {
        return this.getTokens()
            .then((json) => {
                this.delegationToken = (json && json.delegation_token) || '';
                this.autofillToken = (json && json.autofill_token) || '';
                return 0;
            })
            .catch((error) => (error && error.status) || 0);
    };

    /**
     * Have tokens ready BEFORE the buyer clicks anything, so the click handler's
     * `window.open()` runs inside the gesture that triggered it. Called
     * unconditionally as soon as checkout is reached (TWO-25547) — the
     * registry coverage behind the lookup is global, so there is no country
     * or merchant gate to wait on.
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
            .then((status) => {
                const minted = this.hasSignupTokens();
                if (minted) {
                    this.startTokenRefresh();
                    this.rerunOwedPrefetch();
                } else {
                    this.scheduleMintRetry(status);
                }
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
     * One refresh tick. Skipped while ANY flow on the page has a round trip
     * outstanding — the tokens a popup was launched with must stay valid for
     * the flow it is running, and that flight's own completion leaves them
     * fresh anyway.
     */
    SoleTrader.prototype.refreshTokens = function () {
        if (anyFlowBusy()) return;
        return this.mintTokens();
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
        this.retireFinishedPopup();

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
            // TWO-25658: a control that keeps focus is re-focused on window return, which reads as leaving the signup.
            if (document.activeElement && document.activeElement !== document.body) document.activeElement.blur();
            this.watchPopupClose(this._popupWindow);
            this.watchForReturnToCheckout();
            this.parkFocusDroppedByPopup();
        }
        return this._popupWindow;
    };

    /**
     * Close a prior popup and stand down everything armed for it: its close poll,
     * its return watch and the field opener hold, and the record of how it closed,
     * which would otherwise misread the next popup's hand-close (TWO-26775).
     */
    SoleTrader.prototype.retireFinishedPopup = function () {
        if (this.isPopupOpen()) this._popupWindow.close();
        this._closedByRule = false;
        this.stopPopupCloseWatcher();
        // With it goes the close poll that would have released the panel's opener hold,
        // and a blocked re-open arms no replacement to release it later.
        this.stopReturnToCheckoutWatcher();
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
     * @param {Element} [launcher] the control the buyer launched it from
     * @returns {Window|null}
     */
    SoleTrader.prototype.launchSignup = function (options, launcher) {
        const win = this.openPopup(options);
        if (win) this._launcher = launcher || null;
        this.showSignupPanel(!win);
        if (!win) {
            // Held for retrySignup(): a blocked replacement that retried
            // without them would hand back the identity being replaced.
            this._blockedSignupOptions = options || null;
            this.ensureTokens();
        }
        return win;
    };

    /** The blocked-popup panel's own launch, on the terms the blocked one had. */
    SoleTrader.prototype.retrySignup = function () {
        return this.launchSignup(this._blockedSignupOptions);
    };

    /**
     * "Select a different sole trader": offer a choice, not what is on screen.
     *
     * @param {Element} [launcher] the link the buyer used
     */
    SoleTrader.prototype.selectDifferentSoleTrader = function (launcher) {
        return this.launchSignup({ autoselect: false }, launcher);
    };

    /**
     * The control a hand-closed popup hands focus back to: its launcher while
     * that is still on screen, else null for the company field (guide row 1.39).
     *
     * @returns {?Element}
     */
    SoleTrader.prototype.launcherToRefocus = function () {
        const launcher = this._launcher;
        if (!launcher || !launcher.isConnected || typeof launcher.focus !== 'function') return null;
        if (launcher.closest && launcher.closest('[hidden]')) return null;
        return launcher;
    };

    /**
     * Look the buyer's Two session up ahead of any click, so a buyer Two
     * already knows never sees the signup popup (TWO-40).
     *
     * Runs where the tokens are minted rather than inside the click: the
     * lookup needs the autofill token, and a click that had to wait for either
     * could not open a popup a blocker would allow. Idempotent, and a real
     * answer is held until something supersedes it; a failed mint is not an
     * answer and is retried on the next call.
     *
     * The answer is never revalidated, so a buyer who signs out of Two in
     * another tab mid-checkout is still offered the trader it found. Accepted:
     * "Select a different sole trader" is the way off it, and the order is
     * authorised against the session, not against this record.
     *
     * @returns {Promise<?object>} the usable record, or null for nobody
     */
    SoleTrader.prototype.prefetchBuyer = function () {
        if (this._prefetch) return this._prefetch;
        const generation = this._autofillGeneration;
        const attempt = this.ensureTokens()
            .then(() => {
                // The autofill token ALONE: a 200 can carry an empty delegation token, which only the popup needs (TWO-25653).
                if (!this.autofillToken) {
                    // Release only this attempt's memo, never the held buyer.
                    if (this._prefetch === attempt) this._prefetch = null;
                    this._prefetchOwed = true;
                    return null;
                }
                this._prefetchOwed = false;
                return this.fetchBuyer().then((buyer) => {
                    // A lookup superseded while it was out is not an answer: a
                    // signup or a country change since has already decided who
                    // the checkout holds.
                    if (generation !== this._autofillGeneration) return null;
                    this._autofillBuyer = isUsableSoleTrader(buyer) ? buyer : null;
                    return this._autofillBuyer;
                });
            });
        this._prefetch = attempt;
        return attempt;
    };

    /** Re-run a lookup a failed mint dropped — nothing else re-invokes it (TWO-25653). */
    SoleTrader.prototype.rerunOwedPrefetch = function () {
        // prefetchBuyer() memoises, so a lookup already out IS this re-run.
        if (this._prefetchOwed && !this._autofillBuyer) this.prefetchBuyer();
    };

    /** One retry only — the timer id outlives the firing, and a click mints again anyway. */
    SoleTrader.prototype.scheduleMintRetry = function (status) {
        if (this._mintRetryId) return;
        if (isSettledFailure(status)) return;
        this._mintRetryId = setTimeout(
            () => this.ensureTokens(),
            MINT_RETRY_DELAY_MS + Math.floor(Math.random() * MINT_RETRY_JITTER_MS)
        );
    };

    /**
     * The sole trader this session already identifies, if the lookup has landed
     * and found one. Synchronous, so the click that reads it can still open a
     * popup inside its own gesture when the answer is nobody.
     *
     * @returns {?object} `/autofill/v1/buyer/current` record
     */
    SoleTrader.prototype.autofilledSoleTrader = function () {
        return this._autofillBuyer || null;
    };

    /**
     * The held answer where it is the buyer at this checkout, so the click can
     * adopt it without a popup; null otherwise.
     *
     * @returns {?object} `/autofill/v1/buyer/current` record
     */
    SoleTrader.prototype.matchingSoleTrader = function () {
        const buyer = this.autofilledSoleTrader();
        if (!buyer) return null;
        const prefill = this.host().signupPrefill() || {};
        return buyerMatchesCheckout(buyer, prefill.email) ? buyer : null;
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
            // The close is bookkeeping: the field re-fire it will provoke comes with the
            // window's return, which is unbounded from here.
            this.stopReturnToCheckoutWatcher({ releaseOpener: false });
            // The handshake's buyer lookup can still be out; it owns the
            // outcome from here and will write whatever identity it resolves.
            if (this._signupConfirming) return;
            const handClosed = !this._closedByRule;
            this._closedByRule = false;
            // A signup still up anywhere on the checkout is a handover: that
            // popup owns focus, and this flow's field must not take it back.
            this._component.abandonSoleTrader({ returnFocus: !anySignupOpen(), handClosed: handClosed });
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
     * Put the focus the signup popup took onto the company field.
     *
     * The blur above leaves the popover on screen around a document focusing
     * nothing, where no keystroke reaches any control. Deferred a tick so the
     * launching click's own rebuild of the chips has settled first, and
     * skipped where focus has landed somewhere the buyer put it.
     */
    SoleTrader.prototype.parkFocusDroppedByPopup = function () {
        setTimeout(() => {
            // A flight already over owns its own focus: the abandon reclaim reads where focus is.
            if (!this.isPopupOpen()) return;
            const active = document.activeElement;
            if (active && active !== document.body && active !== document.documentElement) return;
            const panel = this._component.panel();
            const field = panel && panel.getField && panel.getField()[0];
            if (!field || !document.contains(field)) return;
            // Before the focus, which the return watch sees synchronously.
            this._parkedFocus = field;
            // For the whole flight: every window return re-fires focus on the field.
            panel.holdFieldOpener(true, { flight: () => this.isPopupOpen() });
            panel.restoreFieldFocus();
        }, 0);
    };

    /**
     * Focus arriving on THIS capture's Sole trader chip moves the signup popup neither way;
     * arriving on another control closes the popup, and on one outside the capture popover
     * closes the popover too (TWO-25658). The company field counts as inside: it is the
     * popover's own trigger, and its focus opener would otherwise race the popover close on
     * event order. Another capture's Sole trader chip is one of those other controls, and
     * gets a popup of its own.
     *
     * A focusin a browser re-fires on window return counts as the buyer focusing that
     * control, unless it is the field the launch parked focus on. The park stands for the
     * whole flight, because the window losing focus to the popup blurs that field and the
     * return's re-fire is the first focus it gets back.
     */
    SoleTrader.prototype.watchForReturnToCheckout = function () {
        if (this._returnHandler) return;
        this._returnHandler = (event) => {
            if (!this.isPopupOpen()) return;
            const target = event.target;
            // A press on the parked field is the buyer's own; a focus re-fired there is the window's.
            if (event.type === 'focusin' && target === this._parkedFocus) return;
            const panel = this._component.panel();
            const field = panel && panel.getField && panel.getField()[0];
            // Off the field, never `getPanelElement()`: a morph re-render deletes the wrap and the
            // popover and keeps the field, and that stale stored node makes this capture's own
            // re-rendered chip read as another capture's, inverting the rule on it.
            const popover = ownPopover(field);
            const inside = !!(target && ((popover && popover.contains(target)) || target === field));
            const chip = target && target.closest && target.closest(SOLE_TRADER_CHIP_SELECTOR);
            if (inside && chip) {
                // Only an activation moves the popup: Tabbing through the chip must leave it as the buyer left it.
                return;
            }
            // The CLOSE half only: the enrolment stays live and resumable, tokens unspent.
            this.closeSignupPopup({ byRule: true });
            // Outside the popover the buyer has left capture, not just the signup.
            if (!inside && panel && panel.close) panel.close();
            // Another capture's chip is a different control, and its own click handler is the one
            // place a launch is spelled out. A press is followed by that click anyway.
            if (event.type === 'focusin' && chip && typeof chip.click === 'function') chip.click();
        };
        document.addEventListener('focusin', this._returnHandler, true);
        // A press counts as well as a focus: one on page content nothing can focus moves no focus,
        // and it is still the buyer back on the checkout (TWO-26775).
        document.addEventListener('mousedown', this._returnHandler, true);
    };

    /**
     * Release the watcher with the popup it was armed for.
     *
     * @param {object} [options] `{ releaseOpener: false }` where the popup's own
     *        disappearance is all that has happened, and the field's opener stays
     *        held for the one window return still to come.
     */
    SoleTrader.prototype.stopReturnToCheckoutWatcher = function (options) {
        const panel = this._component.panel();
        if (panel && !(options && options.releaseOpener === false)) panel.holdFieldOpener(false);
        // The flight's own park is not a place the buyer chose, so the abandon reclaim
        // that follows reads the unplaced focus the launch actually left it.
        if (this._parkedFocus && document.activeElement === this._parkedFocus) this._parkedFocus.blur();
        this._parkedFocus = null;
        if (!this._returnHandler) return;
        document.removeEventListener('focusin', this._returnHandler, true);
        document.removeEventListener('mousedown', this._returnHandler, true);
        this._returnHandler = null;
    };

    /**
     * Close the popup this flow opened, if it is still up.
     *
     * @param {object} [options] `{ byRule: true }` where a focus or click rule
     *        closed it, so the close watcher does not read it as the buyer's
     *        hand-close and take the popover and focus with it
     * @returns {boolean} whether there was a popup to close
     */
    SoleTrader.prototype.closeSignupPopup = function (options) {
        if (!this.isPopupOpen()) return false;
        this._closedByRule = !!(options && options.byRule);
        this._popupWindow.close();
        this.stopReturnToCheckoutWatcher();
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
        try {
            this._popupWindow.focus();
        } catch (error) {
            // `closed` can flip between the check and the call.
            return false;
        }
        return true;
    };

    /**
     * Retire the held answer, in flight or already in hand. The caller owns
     * re-arming the lookup.
     */
    SoleTrader.prototype.forgetAutofilledBuyer = function () {
        this._autofillGeneration += 1;
        this._prefetch = null;
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
    SoleTrader.prototype.fetchBuyer = function () {
        return this.lookupBuyer().then((result) => result.buyer);
    };

    /**
     * fetchBuyer(), telling "Two knows nobody" apart from a lookup that failed:
     * after a signup the two lead different ways (TWO-26775).
     *
     * @returns {Promise<{buyer: ?object, failed: boolean}>}
     */
    SoleTrader.prototype.lookupBuyer = function () {
        const config = this._component.config();
        const params = new URLSearchParams(this.host().apiClientParams(config)).toString();
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
            .then((buyer) => ({ buyer: buyer || null, failed: false }))
            .catch(() => ({ buyer: null, failed: true }));
    };

    /**
     * Adopt the sole trader an autofill record describes: the identity through
     * the component's single write path, the registered ADDRESS and the phone
     * into the checkout form (TWO-25461).
     *
     * The address write is NOT gated on the merchant's address-autofill switch,
     * which gates an ordinary registry pick: that switch is legitimately off
     * wherever company search is not in the address area, which is exactly
     * where this flow lives. Both sibling platforms bypass it here too.
     *
     * Written on every adoption, by PrestaShop's rules (TWO-26775): a blank in
     * the record writes nothing, and the host clears a field the record leaves
     * out only where the plugin wrote what it holds.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     */
    SoleTrader.prototype.adoptBuyer = function (buyer) {
        // Refused whole: nothing of a record with no number is written.
        if (!isUsableSoleTrader(buyer)) return;
        // Any adoption supersedes the held answer, in flight or already in
        // hand, so a later click cannot re-adopt it over the identity that won.
        this._autofillGeneration += 1;
        this._autofillBuyer = null;
        this._component.adoptSoleTrader(buyer);
        // Isolated: a DOM failure in the address write must not take the
        // identity fill with it.
        try {
            const source = buyer.billing_address || buyer.shipping_address || null;
            if (source && typeof source === 'object') {
                this.host().applyBuyerAddress(withoutBlanks(source));
            }
            // Routed separately because the address writer deliberately never
            // touches telephone — correct for a registry number that is not the
            // buyer's own, where this record IS the buyer's own verified data.
            const phone = typeof buyer.phone_number === 'string' ? buyer.phone_number.trim() : '';
            this.host().applyTelephone(phone);
        } catch (error) {
            console.error({ logger: 'twoPayment.adoptSoleTraderBuyer', error });
        }
        this.showSignupPanel(false);
    };

    /**
     * Show or withdraw the blocked-popup panel: one button over the popover,
     * whose click is a gesture a blocker allows (TWO-26775). The popover owns
     * it, and takes it down on any mode change and on its own close.
     *
     * @param {boolean} show
     */
    SoleTrader.prototype.showSignupPanel = function (show) {
        const panel = this._component.panel();
        if (!panel) return;
        if (!show) {
            panel.hideSignupPanel();
            return;
        }
        panel.showSignupPanel(
            this._component.translate('Sign up or log in as a sole trader'),
            () => this.retrySignup()
        );
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
            this.lookupBuyer()
                .then((result) => {
                    // A mode chosen while the lookup was out is the buyer's answer.
                    if (!this.identity().isSoleTrader()) return;
                    if (isUsableSoleTrader(result.buyer)) {
                        this.adoptBuyer(result.buyer);
                    } else if (result.failed) {
                        this.showSignupError();
                        // The close watcher stood down for this lookup, so the
                        // abandon is owed here, or the checkout stays in
                        // sole-trader mode with nothing captured (TWO-26775).
                        if (!this.isPopupOpen()) this._component.abandonSoleTrader({ handClosed: true });
                    } else {
                        // Signed up, and Two still knows nobody it can adopt:
                        // ask again, as PrestaShop does (TWO-26775).
                        this._component.signupFoundNobody();
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
        this.host().showError(this._component.config().soleTraderErrorMessage);
    };

    /** Release everything this flow armed on the page. */
    SoleTrader.prototype.dispose = function () {
        if (this._messageHandler) {
            window.removeEventListener('message', this._messageHandler);
            this._messageHandler = null;
        }
        this.stopReturnToCheckoutWatcher();
        liveFlows.delete(this);
        // The refresh is the page's: it outlives this flow while another still
        // holds the pair.
        if (!liveFlows.size) {
            this.stopTokenRefresh();
            if (this._mintRetryId) {
                clearTimeout(this._mintRetryId);
                this._mintRetryId = null;
            }
        }
        this.stopPopupCloseWatcher();
    };

    return SoleTrader;
}));
