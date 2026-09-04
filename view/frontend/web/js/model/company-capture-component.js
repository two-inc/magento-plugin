/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: the company-capture component — Magento's counterpart to
 * WooCommerce's `class TwoCompanySearch` and PrestaShop's `TwoCompanySearch.js`.
 *
 * ONE component per checkout page, owning BOTH the mode chips and the search
 * mount, exactly as those two do. It outlives every payment-tile render: Luma,
 * Amasty and Fire Checkout re-create payment renderers on every totals change
 * and Hyvä's Magewire rebuilds the subtree, and a component living inside one
 * had its chips, its mount and its popup handle destroyed underneath the buyer
 * mid-flow.
 *
 * FRAMEWORK-FREE, with a UMD tail, exactly as `company-search-panel.js` is and
 * for the same reason: BOTH checkouts load this one file — Luma's
 * RequireJS/Knockout one and Hyvä's Alpine/Magewire one, which ships no jQuery,
 * no Knockout and no RequireJS. Everything platform-shaped is injected, so
 * neither side owns a second capture controller to drift from the first.
 *
 * Owns:
 *  - the three modes (Registered company / Sole trader / Enter manually) and
 *    what each one means;
 *  - the one `CompanySearchPanel` and WHERE it is mounted, re-pointing it
 *    between the address step and the payment tile as the checkout changes
 *    shape;
 *  - the country the search runs against, and the sole-trader availability
 *    answer for it;
 *  - the sole-trader flow, through the injected `SoleTraderFlow`.
 *
 * Does NOT own: the chips' markup or the popover they live in
 * (company-search-panel.js), the identity it captures (company-identity.js), or
 * either host's transport, storage and address write-back — which arrive as
 * options the way the panel's `search` does.
 */
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.TwoCompanyCaptureComponent = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * Every member the host adapter must carry. Checked at construction for the
     * reason the panel checks its own `search` contract: a host that supplies a
     * partial one fails silently and deep inside a buyer's flow — a missing
     * `revertAutofilledAddress` throws on a country change, a missing
     * `signupPrefill` opens a hosted signup with no buyer in it.
     */
    const HOST_CONTRACT = [
        'fieldExists',
        'isVirtualCart',
        'getAdjacentCountry',
        'getQuoteCountry',
        'getFallbackCountry',
        'watchCountryChanges',
        'supportedCompanyTypesUrl',
        'applyCompanyAddress',
        'revertAutofilledAddress',
        'clearField',
        'tokensUrl',
        'quoteId',
        'apiClientParams',
        'signupPrefill',
        'signupCountry',
        'applyBuyerAddress',
        'applyTelephone',
        'showError',
        'renderSignupPrompt'
    ];

    /** style.css keys this label's alignment off the class. */
    const COMPANY_NUMBER_CLASS = 'two-company-id-text';

    const SOLE_TRADER_LINK_CLASS = 'two-select-different-sole-trader';

    /**
     * Why the picked company's address could not be filled in. Styled as the
     * tile's own notice box (`two-order-intent-message error`) and hooked by
     * this class alone, so the chrome lookup cannot reach the tile's boxes.
     */
    const ADDRESS_NOTICE_CLASS = 'two-company-address-notice';

    const ADDRESS_NOTICE_STYLE_CLASSES = ['two-order-intent-message', 'error'];

    /**
     * A company number the checkout restored into an address form, under either
     * host's field naming.
     */
    const RESTORED_NUMBER_SELECTOR = 'input[name$="[company_id]"], input[name="company_id"]';

    function assertHost(options) {
        HOST_CONTRACT.forEach(function (member) {
            if (typeof options[member] !== 'function') {
                throw new Error(`CompanyCaptureComponent: host option "${member}" is required.`);
            }
        });
    }

    /**
     * @param {object} options
     * @param {object} options.config the active brand's checkout config subtree
     *        — needs `checkoutApiUrl`, `checkoutPageUrl`,
     *        `isCompanySearchEnabled`, optionally `supportedCompanyTypes`.
     * @param {function} options.Panel the `CompanySearchPanel` constructor.
     * @param {function} options.SoleTraderFlow the `SoleTrader` constructor.
     * @param {object} options.identity the page-level company identity.
     * @param {object} options.search the panel's transport, carrying every
     *        member of its own contract. Luma passes its `company-search`
     *        module; Hyvä passes an adapter over its own engine.
     * @param {function(string): string} [options.translate] identity when the
     *        host has already localised.
     * @param {function(string, function(Element))} [options.observe] report the
     *        node matching a selector, now and on every later re-render. Luma
     *        passes Magento_Ui's `$.async`; Hyvä has no equivalent and drives
     *        `refreshMount()` off its own re-render hook instead.
     * @param {string} options.addressFieldSelector the address step's company
     *        field.
     * @param {string} [options.addressFormRootSelector] the form that field
     *        belongs to — the one `addressFieldSelector` is built from. Bounds
     *        every DOM read this panel makes, so absent it reads nothing.
     * @param {string} options.tileFieldSelector the payment tile's company
     *        field.
     * @param {function(string): boolean} options.fieldExists whether a selector
     *        matches anything right now.
     * @param {function(): boolean} options.isVirtualCart
     * @param {function(): ?string} options.getAdjacentCountry the country
     *        selected in the host's OWN form, lower cased. `null` — a different
     *        answer from `''` — when there is no such select at all.
     * @param {function(): string} options.getQuoteCountry the quote's billing
     *        country, lower cased.
     * @param {function(): string} options.getFallbackCountry a live read for the
     *        window before the quote holds an address (TWO-25326).
     * @param {function(function(string))} options.watchCountryChanges report
     *        every country the buyer selects, lower cased.
     * @param {function(string): string} options.supportedCompanyTypesUrl the
     *        plugin's server-side registry relay for a country.
     * @param {function(object)} options.applyCompanyAddress write the address of
     *        a picked registry result.
     * @param {function()} options.revertAutofilledAddress undo what the last
     *        such write put in, leaving the buyer's own edits.
     * @param {function(string)} options.clearField blank the field at a
     *        selector, and tell the host's own state.
     * @param {function(): string} options.tokensUrl
     * @param {function(): string} options.quoteId
     * @param {function(object): object} options.apiClientParams query params
     *        identifying this client to checkout-api.
     * @param {function(): object} options.signupPrefill the hosted signup's
     *        prefill payload.
     * @param {function(): string} options.signupCountry ISO code, upper cased,
     *        server-resolved.
     * @param {function(object)} options.applyBuyerAddress
     * @param {function(string)} options.applyTelephone
     * @param {function(string)} options.showError
     * @param {function(boolean, function())} options.renderSignupPrompt show or
     *        withdraw the blocked-popup fallback link.
     */
    function CompanyCaptureComponent(options) {
        options = options || {};
        assertHost(options);

        this._options = options;
        this._config = options.config || null;
        this._identity = options.identity;
        this._panel = null;
        this._soleTrader = null;
        /** Selector the panel is currently bound at, so a re-point is a no-op when nothing moved. */
        this._boundSelector = null;
        /** Availability answers per lower-cased ISO country, for the page's lifetime. */
        this._supportedCompanyTypes = {};
        /** Country -> the request currently on the wire for it. */
        this._typesInFlight = {};
        this._lastCountry = '';
        this._started = false;
        /** Selectors with a manual-edit MutationObserver already registered. */
        this._manualWatchedSelectors = {};
        /** @see subscribeMount */
        this._mountSubs = [];

        this.translate = options.translate || function (text) { return text; };
        this.observe = options.observe || null;
    }

    /** @returns {object} the host adapter, as `sole-trader.js` reaches it */
    CompanyCaptureComponent.prototype.host = function () {
        return this._options;
    };

    /** @returns {object} the page-level identity this component writes */
    CompanyCaptureComponent.prototype.identity = function () {
        return this._identity;
    };

    /** @returns {object} the active brand's checkout config subtree */
    CompanyCaptureComponent.prototype.config = function () {
        return this._config;
    };

    /** @returns {object} the sole-trader flow this component drives */
    CompanyCaptureComponent.prototype.soleTrader = function () {
        return this._soleTrader;
    };

    /** @returns {?object} the popover, for tests and for hosts that drive it */
    CompanyCaptureComponent.prototype.panel = function () {
        return this._panel;
    };

    /**
     * Boot. Idempotent — a host may call this on every checkout render, and only
     * the first does anything.
     */
    CompanyCaptureComponent.prototype.start = function () {
        if (this._started) return;
        // No Two-family method on this checkout: nothing to mount, and no config
        // to mount it with.
        if (!this._config) return;
        this._started = true;

        this._soleTrader = new this._options.SoleTraderFlow(this);
        this._soleTrader.listenForSignupResult();
        this._options.watchCountryChanges(this.onCountryChanged.bind(this));
        // The baseline a later change is measured against. Without it the first
        // switch reads as the first resolution and keeps a company whose
        // registry no longer applies (TWO-24867). Empty when the quote has no
        // address yet, which is what still lets that genuine first resolution
        // through.
        this._lastCountry = this.countryCode();
        const self = this;
        this._identity.subscribe(function () {
            self.renderChrome();
        });
        this.watchForMountHost();
        this.refreshMount();
        this.refreshSoleTraderAvailability();
        // Unconditional and decoupled from whichever country is currently
        // selected (TWO-25547): Bifrost's registry coverage is global, not
        // merchant-scoped, so there is nothing to gate on — mint and look the
        // buyer up as soon as checkout is reached, full stop.
        this._soleTrader.prefetchBuyer();
    };

    /**
     * Mount when a host field appears: Luma boots this from the sidebar, before
     * any address form exists, and no quote event re-drives it for a guest. A
     * host with no observer of its own re-drives `refreshMount()` from its own
     * render hook instead.
     */
    CompanyCaptureComponent.prototype.watchForMountHost = function () {
        if (!this.observe) return;
        const self = this;
        [this._options.addressFieldSelector, this._options.tileFieldSelector].forEach(function (selector) {
            self.observe(selector, function () {
                self.refreshMount();
                if (!self._identity.soleTraderAvailable()) self.refreshSoleTraderAvailability();
            });
        });
    };

    // ---------------------------------------------------------------- country

    /**
     * The country the search and the registry both run against, lower cased.
     *
     * Sourced from the address form the mounted control answers to. The quote's
     * billing country is the last resort, for a checkout rendering no address
     * form with a country select at all, and the live host read behind that
     * covers the window before the quote holds an address (TWO-25326).
     *
     * Answers for where the control is going as well as where it is: `start()`
     * resolves a country before the first `refreshMount()`.
     *
     * @returns {string} ISO country code, lower cased, or ''
     */
    CompanyCaptureComponent.prototype.countryCode = function () {
        const adjacent = this.adjacentCountry();
        if (adjacent !== null) return adjacent;
        return this._options.getQuoteCountry() || this._options.getFallbackCountry() || '';
    };

    /**
     * The country in the form the mounted control answers to.
     *
     * @returns {?string} `null` when there is no such select at all, which is a
     *          different answer from `''` — a present one with nothing chosen
     */
    CompanyCaptureComponent.prototype.adjacentCountry = function () {
        // Unmounted the panel has no form to answer for, and the quote read
        // behind this is the honest fallback.
        if (!(this._boundSelector || this.mountSelector())) return null;
        const answer = this._options.getAdjacentCountry();
        return typeof answer === 'string' ? answer.toLowerCase() : null;
    };

    /**
     * A country change invalidates the captured company (TWO-24867): a registry
     * number means nothing outside the registry that issued it. Re-resolves
     * sole-trader availability for the new country.
     *
     * @param {string} [observedCountry] the country the buyer just selected,
     *        where a caller has a fresher answer than the quote does
     */
    CompanyCaptureComponent.prototype.onCountryChanged = function (observedCountry) {
        // A checkout with no Two-family method never started this component, so
        // there is no flow to tell, no registry to ask and nothing captured to
        // invalidate — and a host hook calls this on every address change.
        if (!this._config || !this._started) return;
        const country = String(observedCountry || this.countryCode() || '').toLowerCase();
        if (!country || country === this._lastCountry) return;
        const hadCountry = !!this._lastCountry;
        this._lastCountry = country;
        // Not on first resolution: the quote's own country arrives after load
        // and must not discard a company that same address already carried.
        if (hadCountry) {
            // A search still on the wire would answer for the country the buyer
            // just left and repopulate what this call is clearing.
            if (this._panel) this._panel.abortActiveRequest();
            // Read before the retirement, which resets the mode itself: the
            // panel still has to be handed back as a search trigger.
            const wasSoleTrader = this._identity.isSoleTrader();
            this._identity.clear();
            this._options.revertAutofilledAddress();
            this._soleTrader.forgetAdoptions();
            if (wasSoleTrader) this.registeredMode();
        }
        // The held buyer answer comes from the session cookie, not the
        // registry the form currently targets, so a country change does not
        // retire it.
        this.refreshSoleTraderAvailability(country);
    };

    // ----------------------------------------------------------- availability

    /**
     * Resolve whether the billing country's registry offers sole traders, and
     * if it does, mint signup tokens and look the buyer's own session up, both
     * up front.
     *
     * Successful answers — including the legitimate empty list, meaning
     * business-only — are memoised per country. Errors resolve to no
     * sole-trader option and are NOT memoised, so the next country change
     * retries.
     *
     * @param {string} [observedCountry] see onCountryChanged()
     * @returns {Promise<boolean>}
     */
    CompanyCaptureComponent.prototype.refreshSoleTraderAvailability = function (observedCountry) {
        const self = this;
        const country = String(observedCountry || this.countryCode() || '').toLowerCase();
        if (!country) {
            this._identity.soleTraderAvailable(false);
            return Promise.resolve(false);
        }
        return this.getSupportedCompanyTypes(country).then(function (types) {
            // The buyer may have changed country while this was in flight;
            // `_lastCountry` is the freshest answer, ahead of the quote.
            if ((self._lastCountry || self.countryCode()) !== country) {
                return self._identity.soleTraderAvailable();
            }
            const available = types.indexOf('SOLE_TRADER') !== -1;
            self._identity.soleTraderAvailable(available);
            if (!available && self._identity.isSoleTrader()) {
                self.registeredMode();
            }
            // Minting itself is unconditional, from start() alone (TWO-25547)
            // — this per-country answer only ever decides the chip's own
            // visibility.
            self.syncChips();
            return available;
        });
    };

    /**
     * The registry's supported-company-types answer for a country, via the
     * plugin's server-side relay — the merchant API key never reaches the
     * browser.
     *
     * @param {string} countryCode
     * @returns {Promise<Array<string>>}
     */
    CompanyCaptureComponent.prototype.getSupportedCompanyTypes = function (countryCode) {
        const self = this;
        const key = String(countryCode).toLowerCase();
        const seeded = (this._config && this._config.supportedCompanyTypes) || {};
        if (Object.prototype.hasOwnProperty.call(this._supportedCompanyTypes, key)) {
            return Promise.resolve(this._supportedCompanyTypes[key]);
        }
        if (Object.prototype.hasOwnProperty.call(seeded, key)) {
            this._supportedCompanyTypes[key] = seeded[key];
            return Promise.resolve(seeded[key]);
        }
        // One request per country in flight. A re-render sweep re-asks on every
        // morph, and an error is deliberately not memoised — so without this a
        // failing relay gets one request per morph.
        if (this._typesInFlight[key]) return this._typesInFlight[key];
        const URL = this._options.supportedCompanyTypesUrl(key);
        this._typesInFlight[key] = fetch(URL, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) throw new Error(`Error response from ${URL}.`);
                return response.json();
            })
            .then(function (types) {
                if (!Array.isArray(types)) throw new Error(`Malformed response from ${URL}.`);
                self._supportedCompanyTypes[key] = types;
                return types;
            })
            .catch(function (error) {
                console.error({ logger: 'twoPayment.getSupportedCompanyTypes', error });
                return [];
            })
            .finally(function () {
                delete self._typesInFlight[key];
            });
        return this._typesInFlight[key];
    };

    // ------------------------------------------------------------- the mount

    /**
     * Where the one control belongs right now.
     *
     * The admin setting decides WHERE the control lives, never whether it
     * exists: with company search in address entry ON the address step is its
     * home, EXCEPT on a checkout that renders no such form — a saved address and
     * a virtual cart both do that — where the tile is the buyer's only route to
     * supply a company, without which authorize() refuses the order.
     *
     * @returns {string} the field selector to bind at, or '' when neither host
     *          is present yet
     */
    CompanyCaptureComponent.prototype.mountSelector = function () {
        // No brand config means no Two method on this checkout and nothing to
        // mount. A host re-points on every totals change, so this has to answer
        // rather than throw.
        if (!this._config) return '';
        if (this._config.isCompanySearchEnabled && !this._options.isVirtualCart()) {
            if (this._options.fieldExists(this._options.addressFieldSelector)) {
                return this._options.addressFieldSelector;
            }
        }
        // A host with no tile fallback at all (TWO-25554's billing panel,
        // which only ever lives at its own address field) passes ''; that must
        // never reach fieldExists() as a query.
        if (this._options.tileFieldSelector && this._options.fieldExists(this._options.tileFieldSelector)) {
            return this._options.tileFieldSelector;
        }
        return '';
    };

    /**
     * Point the one panel at wherever it currently belongs.
     *
     * Called on every event that can change the checkout's shape — a payment
     * tile rendering, an address switching between new and saved, a cart going
     * virtual. Cheap and idempotent when nothing moved.
     */
    CompanyCaptureComponent.prototype.refreshMount = function () {
        const selector = this.mountSelector();
        const previous = this._boundSelector;
        // While the OLD selector is still bound — every chrome lookup goes
        // through the bound field, so the host being left is unreachable once
        // `_boundSelector` moves, and its number and sole-trader link would
        // stand alongside the new host's (TWO-25554).
        if (previous !== selector) this._removeChrome();
        if (!selector) {
            // Neither host is on the page any more. Forgetting where the control
            // was is what stops `adjacentCountry()` answering for a form that has
            // gone, and lets the next host that appears mount cleanly.
            this._boundSelector = null;
            if (this._panel) this._panel.unmount();
            if (previous !== null) this._notifyMount();
            return;
        }
        if (selector === this._boundSelector && this._panel && this._panel.isBound()) {
            this.syncChips();
            this.renderChrome();
            return;
        }
        this._boundSelector = selector;
        this.mountPanel(selector);
        this.syncChips();
        this.renderChrome();
        if (previous !== selector) this._notifyMount();
    };

    /**
     * Report every move of this component's mount, including its loss.
     *
     * A host binding the visibility of a field this component can take over —
     * Luma's payment tile — has no other way to learn the mount moved, and one
     * control per field cannot hold if it learns only when a caller remembers
     * to say so (TWO-25554).
     *
     * @param {function(string)} onChange receives the new mount selector, '' for
     *        none
     */
    CompanyCaptureComponent.prototype.subscribeMount = function (onChange) {
        this._mountSubs.push(onChange);
    };

    CompanyCaptureComponent.prototype._notifyMount = function () {
        const selector = this._boundSelector || '';
        this._mountSubs.forEach(function (onChange) {
            onChange(selector);
        });
    };

    /**
     * Build the panel on first use and anchor it at `selector`.
     *
     * One instance for the page's whole life: `bind()` re-points the existing
     * panel, and building a second would leave two popovers writing to one
     * identity.
     *
     * @param {string} selector
     */
    CompanyCaptureComponent.prototype.mountPanel = function (selector) {
        const self = this;
        if (!this._panel) {
            this._panel = new this._options.Panel({
                fieldSelector: selector,
                config: this._config,
                search: this._options.search,
                translate: function (text) { return self.translate(text); },
                observe: this.observe ? function (fieldSelector, onNode) {
                    self.observe(fieldSelector, onNode);
                } : null,
                getCountryCode: function () {
                    return self.countryCode();
                },
                getSearchScope: function () {
                    return self._identity;
                },
                getChips: function () {
                    return self.chipDefinitions();
                },
                isChipVisible: function (mode) {
                    return self.isModeOffered(mode);
                },
                getSelectedMode: function () {
                    return self._identity.captureMode();
                },
                getDisplayText: function () {
                    return self._identity.companyName();
                },
                onExitManualEntry: function () {
                    self.registeredMode({ openDropdown: true });
                },
                onSelect: function (selectedItem) {
                    self.selectCompany(selectedItem);
                }
            });
        } else {
            this._panel.fieldSelector = selector;
        }
        this._panel.bind();
        // A re-render takes the return link with the wrapper, and without it
        // manual entry is a dead end.
        if (this._identity.captureMode() === 'manual') this._panel.releaseField();
    };

    // ---------------------------------------------------------------- chrome

    /** @returns {?Element} this panel's own bound field, or null with no mount */
    CompanyCaptureComponent.prototype.fieldNode = function () {
        if (!this._boundSelector) return null;
        return document.querySelector(this._boundSelector);
    };

    /**
     * The wrapper the panel puts around its own field
     * (`CompanySearchPanel._ensureWrap()`) — the one element belonging to THIS
     * panel and nothing else, so no page-wide query can reach another's chrome.
     *
     * @returns {?Element}
     */
    CompanyCaptureComponent.prototype._chromeAnchor = function () {
        const field = this.fieldNode();
        return (field && field.parentElement) || null;
    };

    /**
     * Where this panel's chrome lives: alongside the wrapper, never within it.
     * The popover is positioned off the wrapper's own box (`top: 100%`), so
     * chrome inside it pushes the popover off the field by the chrome's height.
     *
     * @returns {?Element}
     */
    CompanyCaptureComponent.prototype._chromeHost = function () {
        const anchor = this._chromeAnchor();
        return (anchor && anchor.parentElement) || null;
    };

    /**
     * A piece of this panel's chrome, matched only among the wrapper's own
     * siblings so the lookup cannot descend into the popover or another form.
     *
     * @param {string} className
     * @returns {?Element}
     */
    CompanyCaptureComponent.prototype._chromeNode = function (className) {
        const host = this._chromeHost();
        if (!host) return null;
        return Array.prototype.find.call(host.children, function (child) {
            return child.classList.contains(className);
        }) || null;
    };

    /** Repaint every piece of chrome this panel renders for its own identity. */
    CompanyCaptureComponent.prototype.renderChrome = function () {
        this.renderCompanyNumber();
        this.renderSoleTraderLink();
        this.renderAddressNotice();
    };

    /**
     * Take this panel's chrome back off the page. A SIBLING of the wrapper, so
     * `unmount()` leaves it standing over a torn-down flow (TWO-25554).
     */
    CompanyCaptureComponent.prototype._removeChrome = function () {
        const self = this;
        [COMPANY_NUMBER_CLASS, SOLE_TRADER_LINK_CLASS, ADDRESS_NOTICE_CLASS].forEach(function (className) {
            const node = self._chromeNode(className);
            if (node) node.remove();
        });
    };

    /**
     * The captured company number as it may be SHOWN, or '' for nothing to
     * show. Manual entry is name-only capture, so a number shown there would
     * claim a registry identity the buyer never picked.
     *
     * @returns {string}
     */
    CompanyCaptureComponent.prototype.displayCompanyNumber = function () {
        if (this._identity.captureMode() === 'manual') return '';
        // The one shared display filter, so an internal prefixed identifier is
        // withheld here too (TWO-25326); a host adapter without it shows no
        // number rather than one the buyer cannot make sense of.
        const format = this._options.search && this._options.search.formatCompanyNumber;
        if (typeof format !== 'function') return '';
        return format(this._identity.companyId() || this._restoredCompanyNumber());
    };

    /**
     * A company number a reload restored into this panel's own form.
     *
     * Read rather than written into the identity, which would let a restored
     * company drive order intent.
     *
     * @returns {string}
     */
    CompanyCaptureComponent.prototype._restoredCompanyNumber = function () {
        // The tile carries no address fields, so any number reachable from
        // there belongs to some other panel's form.
        if (this._boundSelector === this._options.tileFieldSelector) return '';
        const field = this.fieldNode();
        if (!field) return '';
        const root = this._ownFormRoot(field);
        if (!root) return '';
        let node = field.parentElement;
        while (node && root.contains(node)) {
            const found = node.querySelectorAll(RESTORED_NUMBER_SELECTOR);
            // Exactly one: several under one ancestor means it spans a second
            // address form, so neither is answerable as this panel's own.
            if (found.length === 1) return found[0].value || '';
            node = node.parentElement;
        }
        return '';
    };

    /**
     * The form this panel's field belongs to — the ceiling on every DOM read it
     * makes, so no read can reach the other panel's form.
     *
     * `closest` off the field, not a page-wide query for the root: the billing
     * root selector matches per-payment-method fieldsets, and the one holding
     * THIS field is the only one that is this panel's own.
     *
     * @param {Element} field
     * @returns {?Element}
     */
    CompanyCaptureComponent.prototype._ownFormRoot = function (field) {
        const selector = this._options.addressFormRootSelector;
        if (!selector) return null;
        return field.closest(selector);
    };

    /**
     * Paint the company number as plain text under this panel's field.
     *
     * The caption is an `aria-label`, not visible text: TWO-25326 §7 forbids an
     * additional visible label, and a bare number with no accessible name is
     * unreadable to a screen reader.
     */
    CompanyCaptureComponent.prototype.renderCompanyNumber = function () {
        const anchor = this._chromeAnchor();
        const host = this._chromeHost();
        if (!anchor || !host) return;
        const existing = this._chromeNode(COMPANY_NUMBER_CLASS);
        if (existing) existing.remove();
        const number = this.displayCompanyNumber();
        if (!number) return;
        const label = document.createElement('div');
        label.className = COMPANY_NUMBER_CLASS;
        label.setAttribute('aria-label', this.translate('Company Number'));
        label.textContent = number;
        host.insertBefore(label, anchor.nextSibling);
    };

    /**
     * "Select a different sole trader" under this panel's field, gated on
     * adoption rather than capture: a sole trader with no trading name of their
     * own has no company number, and keying on capture left them no route out
     * (TWO-25461 §7).
     */
    CompanyCaptureComponent.prototype.renderSoleTraderLink = function () {
        const anchor = this._chromeAnchor();
        const host = this._chromeHost();
        if (!anchor || !host) return;
        const existing = this._chromeNode(SOLE_TRADER_LINK_CLASS);
        const adopted = this._identity.soleTraderAdopted();
        if (!adopted) {
            if (existing) existing.remove();
            return;
        }
        if (existing) return;
        const self = this;
        const wrapper = document.createElement('div');
        wrapper.className = SOLE_TRADER_LINK_CLASS;
        const link = document.createElement('button');
        link.type = 'button';
        link.className = `${SOLE_TRADER_LINK_CLASS}__link`;
        link.textContent = this.translate('Select a different sole trader');
        link.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            self._soleTrader.selectDifferentSoleTrader();
        });
        wrapper.appendChild(link);
        // After the number when there is one, so the two keep a stable order
        // across repaints regardless of which was rendered first.
        const after = this._chromeNode(COMPANY_NUMBER_CLASS) || anchor;
        host.insertBefore(wrapper, after.nextSibling);
    };

    /**
     * Why this panel's picked company could not have its address filled in,
     * under this panel's OWN field.
     *
     * At the field rather than in the payment tile because the copy sends the
     * buyer to the address fields "below" it, and a notice rendered anywhere
     * but beside the panel that raised it points at the wrong form — or, for a
     * panel the tile is not mounted on, at no form the buyer can see
     * (TWO-25554).
     */
    CompanyCaptureComponent.prototype.renderAddressNotice = function () {
        const anchor = this._chromeAnchor();
        const host = this._chromeHost();
        if (!anchor || !host) return;
        const existing = this._chromeNode(ADDRESS_NOTICE_CLASS);
        if (existing) existing.remove();
        const notice = this._identity.addressNotice();
        if (!notice) return;
        const box = document.createElement('div');
        box.className = [ADDRESS_NOTICE_CLASS].concat(ADDRESS_NOTICE_STYLE_CLASSES).join(' ');
        // The lookup answers after the buyer has moved on from the field, so
        // nothing else would announce the failure to a screen reader.
        box.setAttribute('role', 'alert');
        box.textContent = notice;
        const after = this._chromeNode(SOLE_TRADER_LINK_CLASS)
            || this._chromeNode(COMPANY_NUMBER_CLASS)
            || anchor;
        host.insertBefore(box, after.nextSibling);
    };

    // ----------------------------------------------------------------- chips

    /**
     * The three modes, in display order, as the panel renders them.
     *
     * @returns {Array<{mode: string, text: string, onActivate: function}>}
     */
    CompanyCaptureComponent.prototype.chipDefinitions = function () {
        const self = this;
        return [
            {
                mode: 'registered',
                text: this.translate('Registered company'),
                onActivate: function () { self.registeredMode({ openDropdown: true }); }
            },
            {
                mode: 'soletrader',
                text: this.translate('Sole trader'),
                onActivate: function () { return self.soleTraderMode(); }
            },
            {
                mode: 'manual',
                text: this.translate('Enter manually'),
                onActivate: function () { self.manualEntryMode(); }
            }
        ];
    };

    /**
     * Whether a mode is offered on this checkout at all.
     *
     * Sole trader follows the billing country's registry. Manual entry needs
     * somewhere for the registry number to come from later, and with company
     * search out of the address step there is no such lookup on the checkout —
     * so a typed name would be a dead end and is not offered.
     *
     * @param {string} mode
     * @returns {boolean}
     */
    CompanyCaptureComponent.prototype.isModeOffered = function (mode) {
        if (mode === 'soletrader') return !!this._identity.soleTraderAvailable();
        if (mode === 'manual') return !!this._config.isCompanySearchEnabled;
        return true;
    };

    /** Repaint the chips for the current mode and availability. */
    CompanyCaptureComponent.prototype.syncChips = function () {
        if (!this._panel) return;
        this._panel.syncChips();
    };

    // ----------------------------------------------------------------- modes

    /**
     * Registered-company search — the default, and the way out of the other two.
     *
     * @param {object} [options] `{ openDropdown: true }` to land the buyer in
     *        the search box, which is what a deliberate click means
     */
    CompanyCaptureComponent.prototype.registeredMode = function (options) {
        this.leaveSoleTraderMode();
        this._identity.captureMode('registered');
        this.refreshMount();
        if (this._panel) {
            // Manual entry leaves the field typeable and the panel shut; coming
            // back has to make it a trigger again before opening it.
            this._panel.reclaimField();
            if (options && options.openDropdown) this._panel.bind({ open: true });
        }
        this.syncChips();
    };

    /**
     * Manual entry: abandon the company in play and hand the field back as a
     * plain text input the buyer can type into.
     *
     * With no registry number `isCaptured()` stays false and the order is
     * refused server-side — unchanged by this being one click away.
     */
    CompanyCaptureComponent.prototype.manualEntryMode = function () {
        this.leaveSoleTraderMode();
        this._identity.captureMode('manual');
        if (this._panel) {
            // Before the release: a search still on the wire would otherwise
            // paint results into a panel the buyer has closed.
            this._panel.abortActiveRequest();
            this._panel.releaseField();
        }
        this._identity.clearNumber();
        if (this._boundSelector) this._options.clearField(this._boundSelector);
        this._watchManualEdits();
        this.syncChips();
    };

    /**
     * The field `releaseField()` just handed back is a plain input now, so
     * nothing else reads what the buyer types into it. One listener per node —
     * `observe()` re-fires on every re-render, and a second one would double
     * `commitManualCompany()` on every keystroke.
     *
     * ONE `observe()` registration per selector, EVER, not one per component
     * lifetime: `_boundSelector` re-points between the address field and the
     * tile field as the cart flips virtual, and a single lifetime flag would
     * leave the new selector's manual edits never observed (TWO-25503 round 5).
     */
    CompanyCaptureComponent.prototype._watchManualEdits = function () {
        if (!this.observe || !this._boundSelector) return;
        if (this._manualWatchedSelectors[this._boundSelector]) return;
        this._manualWatchedSelectors[this._boundSelector] = true;
        const self = this;
        this.observe(this._boundSelector, function (node) {
            if (!node || typeof node.addEventListener !== 'function') return;
            if (node === self._manualNode) return;
            self._manualNode = node;
            node.addEventListener('input', function () {
                self.commitManualCompany(node.value);
            });
        });
    };

    /**
     * Record a name the buyer typed into the released field. No number is
     * vouched for it, and a name that has diverged from the one a registry
     * number was written for drops that number with it.
     *
     * @param {string} name
     */
    CompanyCaptureComponent.prototype.commitManualCompany = function (name) {
        const typed = String(name || '');
        if (this._identity.hasVouchedNumber() && typed !== this._identity.companyName()) {
            this._identity.clearNumber();
        }
        // The accessor, not `write()`: this path owns the NAME alone, and an
        // emptied field must land as an empty name rather than be read as
        // "nothing to write" and leave the previous company's on screen.
        this._identity.companyName(typed);
    };

    /**
     * A registry pick. Authoritative: it must overwrite the previous company's
     * number even when the new one has none.
     *
     * @param {{text: string, companyId: string, lookupId: string}} selectedItem
     */
    CompanyCaptureComponent.prototype.selectCompany = function (selectedItem) {
        this._identity.write(
            {
                companyName: selectedItem.text,
                companyId: selectedItem.companyId,
                companyIdSource: selectedItem.companyId ? 'registry' : ''
            },
            { authoritative: true }
        );
        this._options.applyCompanyAddress(selectedItem);
        this.syncChips();
    };

    /**
     * Sole trader — the identity the buyer's own Two session already carries,
     * and the hosted signup only when it carries none (TWO-40).
     *
     * Synchronous from top to bottom: the lookup ran when the tokens were
     * minted, so the answer is already in hand and the popup opens inside the
     * click a blocker will allow.
     *
     * @returns {Window|null} the popup where one opened
     */
    CompanyCaptureComponent.prototype.soleTraderMode = function () {
        // The one gesture that means "the popup is what I want": clicking this
        // chip returns focus to the page, which otherwise takes the popup down.
        // Raise it rather than replacing it with a second signup.
        if (this._soleTrader.focusSignupPopup()) return null;
        // Re-clicking once adopted is the same re-signup the "select a different
        // sole trader" link launches: offer a choice rather than hand back what
        // is already on screen — so it skips autofill for the same reason that
        // link does.
        if (this._identity.isSoleTrader() && this._identity.soleTraderAdopted()) {
            return this._soleTrader.launchSignup({ autoselect: false });
        }
        this._identity.captureMode('soletrader');
        this._identity.clearNumber();
        // The popover stays OPEN behind the signup popup, so the chips stay
        // on screen and the buyer can click Sole trader again to raise the
        // popup rather than having to reach it through the company field —
        // which would itself read as "focus is back on checkout" and take
        // the popup down. It closes when they return to checkout and settle
        // somewhere other than this control.
        this.syncChips();
        const buyer = this._soleTrader.autofilledSoleTrader();
        if (!buyer) return this._soleTrader.launchSignup();
        this._soleTrader.adoptBuyer(buyer);
        return null;
    };

    /**
     * Leave sole-trader mode, discarding what it captured. A no-op in the other
     * two modes, which is what makes it safe on every mode entry.
     *
     * @returns {boolean} whether sole-trader mode was actually left
     */
    CompanyCaptureComponent.prototype.leaveSoleTraderMode = function () {
        if (!this._identity.isSoleTrader()) return false;
        this._identity.soleTraderAdopted(false);
        // A sole trader's minted name and synthetic number are not a registered
        // organisation, so carrying them across would submit one identity under
        // the other's mode.
        this._identity.clear();
        // Without this the sole trader's registered address stays in the form
        // and goes out under whatever company the buyer searches for next. Only
        // fields still holding what the write put there are cleared, so the
        // buyer's own edits survive.
        this._options.revertAutofilledAddress();
        this._soleTrader.forgetAdoptions();
        // An adopted answer is spent; one still held is left alone — the
        // session stands behind it either way.
        if (!this._soleTrader.autofilledSoleTrader()) {
            this._soleTrader.forgetAutofilledBuyer();
            this._soleTrader.prefetchBuyer();
        }
        return true;
    };

    // -------------------------------------------------- sole-trader callbacks

    /**
     * Adopt an identity the hosted signup authenticated. The sole-trader flow's
     * one route back into the checkout.
     *
     * @param {object} buyer `/autofill/v1/buyer/current` record
     */
    CompanyCaptureComponent.prototype.adoptSoleTrader = function (buyer) {
        // Authoritative: a sole trader with no registry number of their own must
        // not inherit the number of whatever company was captured before them,
        // which a non-authoritative write leaves standing.
        this._identity.write(
            {
                companyName: buyer.company_name,
                companyId: buyer.organization_number,
                companyIdSource: buyer.organization_number ? 'registry' : ''
            },
            { authoritative: true }
        );
        this._identity.soleTraderAdopted(true);
        if (this._panel) {
            this._panel.setDisplayText(this._identity.companyName());
            // The popover was only held open so the chip stayed reachable while
            // the signup was up. The signup has answered, the company is in the
            // field, and there is nothing left in the popover to act on.
            this._panel.close();
        }
        this.syncChips();
    };

    /** The buyer abandoned signup with nothing captured. */
    CompanyCaptureComponent.prototype.abandonSoleTrader = function () {
        if (this._identity.soleTraderAdopted()) return;
        this.registeredMode();
    };

    CompanyCaptureComponent.HOST_CONTRACT = HOST_CONTRACT;

    return CompanyCaptureComponent;
}));
