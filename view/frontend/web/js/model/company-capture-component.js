/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: the company-capture component — Magento's counterpart to
 * WooCommerce's `class TwoCompanySearch` and PrestaShop's `TwoCompanySearch.js`.
 *
 * ONE component per checkout page, owning BOTH the mode chips and the search
 * mount, exactly as those two do. It is constructed once, by the headless
 * `view/company-search-boot.js` uiComponent, and outlives every payment-tile
 * render: Luma, Amasty and Fire Checkout all re-create payment renderers on
 * every totals change, and a component living inside one had its chips, its
 * mount and its popup handle destroyed underneath the buyer mid-flow.
 *
 * Owns:
 *  - the three chips (Registered company / Sole trader / Enter manually),
 *    built in JS as a sibling of the field they act on, the way both sibling
 *    platforms build theirs;
 *  - the one `CompanySearchControl` and WHERE it is mounted, re-pointing it
 *    between the address step and the payment tile as the checkout changes
 *    shape;
 *  - the billing country the search runs against, and the sole-trader
 *    availability answer for it.
 *
 * Does NOT own: the identity it captures (company-identity.js), the
 * sole-trader flow (sole-trader.js), or the transport and address write-back
 * (company-search.js). Same split as WooCommerce's
 * `twoincCompanyCapture` / `twoincSoleTrader` / search internals.
 */
define([
    'jquery',
    'ko',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'mage/translate',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/company-identity',
    'Two_Gateway/js/model/company-search',
    'Two_Gateway/js/model/company-search-control',
    'Two_Gateway/js/model/sole-trader'
], function ($, ko, quote, url, $t, brandConfig, identity, companySearch, CompanySearchControl, SoleTrader) {
    'use strict';

    /** Where the chips group is inserted, and how its members are found. */
    const CHIPS_WRAPPER_CLASS = 'two-company-mode-chips';
    const CHIP_CLASS = 'two-company-mode-chip';
    const CHIP_SELECTED_CLASS = 'two-company-mode-chip--selected';

    // This plugin's own, because a bare `.hidden` is a theme's to define or not
    // — Luma ships one, several one-page checkouts do not, and a chip that
    // silently fails to hide offers the buyer a mode the country cannot serve.
    const HIDDEN_CLASS = 'two-hidden';

    /** The address step's company field — core's own markup. */
    const ADDRESS_FIELD_SELECTOR = '#shipping-new-address-form input[name="company"]';

    /** The payment tile's company field. One per Two-family brand tile. */
    const TILE_FIELD_SELECTOR = '#two_gateway_form input#company_name';

    function CompanyCaptureComponent() {
        this._config = null;
        this._control = null;
        this._soleTrader = null;
        /** Selector the control is currently bound at, so a re-point is a no-op when nothing moved. */
        this._boundSelector = null;
        this._countryWatcherBound = false;
        /** Availability answers per lower-cased ISO country, for the page's lifetime. */
        this._supportedCompanyTypes = {};
        this._started = false;
    }

    /**
     * Boot. Idempotent — the boot component calls this on every checkout
     * render, and only the first does anything.
     */
    CompanyCaptureComponent.prototype.start = function () {
        if (this._started) return;
        this._started = true;
        this._config = brandConfig.getActiveTwoBrandConfig();
        if (!this._config) {
            // No Two-family method on this checkout: nothing to mount, and no
            // config to mount it with.
            this._started = false;
            return;
        }
        this._soleTrader = new SoleTrader(this);
        this._soleTrader.listenForSignupResult();
        this.watchAddressFormCountry();
        // The baseline a later change is measured against. Without it the first
        // switch reads as the first resolution and keeps a company whose
        // registry no longer applies (TWO-24867). Empty when the quote has no
        // address yet, which is what still lets that genuine first resolution
        // through.
        this._lastCountry = this.countryCode();
        this.refreshMount();
        this.refreshSoleTraderAvailability();
    };

    /** @returns {object} the active brand's checkout config subtree */
    CompanyCaptureComponent.prototype.config = function () {
        return this._config;
    };

    /** @returns {object} the sole-trader flow this component drives */
    CompanyCaptureComponent.prototype.soleTrader = function () {
        return this._soleTrader;
    };

    // ---------------------------------------------------------------- country

    /**
     * The billing country the search and the registry both run against, lower
     * cased.
     *
     * The quote's BILLING address, not the shipping form: the tile has no
     * address fields of its own and captures as the invoice role
     * (TWO-25461 §1(a.3)), so a buyer shipping to one country and invoicing in
     * another must search the country they invoice in. The live DOM read is a
     * fallback only for the window before the quote holds an address at all,
     * which is the state that produced TWO-25326 on one-page checkouts.
     *
     * @returns {string} ISO country code, lower cased, or ''
     */
    CompanyCaptureComponent.prototype.countryCode = function () {
        const billing = quote.billingAddress();
        const fromQuote = billing && billing.countryId;
        if (typeof fromQuote === 'string' && fromQuote) return fromQuote.toLowerCase();
        return companySearch.currentAddressFormCountry() || '';
    };

    /**
     * Keep the country current from the buyer's own address-form `<select>`.
     *
     * Delegated off the document rather than bound to the node: every checkout
     * re-renders its address form freely, and delegation survives that with no
     * re-resolution. Bound once — this component outlives the forms.
     */
    CompanyCaptureComponent.prototype.watchAddressFormCountry = function () {
        if (this._countryWatcherBound) return;
        this._countryWatcherBound = true;
        const self = this;
        $(document).on('change.twoCompanyCapture', 'select[name="country_id"]', function (event) {
            // The select the buyer actually touched, not a document scan: core
            // saves asynchronously so the quote still holds the country they
            // just left, and a shipping-first scan answers for the wrong form
            // when it is the billing country that changed.
            self.onCountryChanged(String($(event.target).val() || '').toLowerCase());
        });
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
        // invalidate — and the boot hook calls this on every address change.
        if (!this._config) return;
        const country = (observedCountry || this.countryCode() || '').toLowerCase();
        if (!country || country === this._lastCountry) return;
        const hadCountry = !!this._lastCountry;
        this._lastCountry = country;
        // Not on first resolution: the quote's own country arrives after load
        // and must not discard a company that same address already carried.
        if (hadCountry) {
            // A search still on the wire would answer for the country the buyer
            // just left and repopulate what this call is clearing.
            if (this._control) this._control.abortActiveRequest();
            identity.clear();
            companySearch.revertAutofilledAddress();
            this._soleTrader.forgetAdoptions();
            if (identity.isSoleTrader()) this.registeredMode();
        }
        this.refreshSoleTraderAvailability(country);
    };

    // ----------------------------------------------------------- availability

    /**
     * Resolve whether the billing country's registry offers sole traders, and
     * mint signup tokens up front if it does.
     *
     * Successful answers — including the legitimate empty list, meaning
     * business-only — are memoised per country. Errors resolve to no
     * sole-trader option and are NOT memoised, so the next country change
     * retries.
     */
    /**
     * @param {string} [observedCountry] see onCountryChanged()
     */
    CompanyCaptureComponent.prototype.refreshSoleTraderAvailability = function (observedCountry) {
        const self = this;
        const country = (observedCountry || this.countryCode() || '').toLowerCase();
        if (!country) {
            identity.soleTraderAvailable(false);
            return Promise.resolve(false);
        }
        return this.getSupportedCompanyTypes(country).then(function (types) {
            // The buyer may have changed country while this was in flight;
            // `_lastCountry` is the freshest answer, ahead of the quote.
            if ((self._lastCountry || self.countryCode()) !== country) {
                return identity.soleTraderAvailable();
            }
            const available = types.indexOf('SOLE_TRADER') !== -1;
            identity.soleTraderAvailable(available);
            if (!available && identity.isSoleTrader()) {
                self.registeredMode();
            }
            if (available) {
                // Minted as soon as the option exists, never at click time:
                // window.open() behind an await is blocker bait.
                self._soleTrader.ensureTokens();
            }
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
        const URL = url.build(`rest/V1/two/supported-company-types/${encodeURIComponent(key)}`);
        return fetch(URL, { headers: { Accept: 'application/json' } })
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
            });
    };

    // ------------------------------------------------------------- the mount

    /**
     * Where the one control belongs right now.
     *
     * The admin setting decides WHERE the control lives, never whether it
     * exists: with company search in address entry ON the address step is its
     * home, EXCEPT on a checkout that renders no such form — a saved address
     * and a virtual cart both do that — where the tile is the buyer's only
     * route to supply a company, without which authorize() refuses the order.
     *
     * @returns {string} the field selector to bind at, or '' when neither host
     *          is present yet
     */
    CompanyCaptureComponent.prototype.mountSelector = function () {
        // No brand config means no Two method on this checkout and nothing to
        // mount. The boot component still re-points on every totals change, so
        // this has to answer rather than throw.
        if (!this._config) return '';
        if (this._config.isCompanySearchEnabled && !quote.isVirtual()) {
            if ($(ADDRESS_FIELD_SELECTOR).length) return ADDRESS_FIELD_SELECTOR;
        }
        if ($(TILE_FIELD_SELECTOR).length) return TILE_FIELD_SELECTOR;
        return '';
    };

    /**
     * Point the one control at wherever it currently belongs, and put the chips
     * beside it.
     *
     * Called on every event that can change the checkout's shape — a payment
     * tile rendering, an address switching between new and saved, a cart going
     * virtual. Cheap and idempotent when nothing moved.
     */
    CompanyCaptureComponent.prototype.refreshMount = function () {
        const selector = this.mountSelector();
        if (!selector) return;
        // A tile replaced under this same selector needs no re-point from here:
        // the control keeps one `$.async` observer per selector for the page's
        // life, and that observer re-initialises on the replacement itself.
        if (selector === this._boundSelector && this._control && this._control.isBound()) {
            this.syncChips();
            return;
        }
        this._boundSelector = selector;
        this.mountControl(selector);
        this.syncChips();
    };

    /**
     * Build the control on first use and bind it at `selector`.
     *
     * One instance for the page's whole life: `bind()` re-points the existing
     * widget, which is how the control was always meant to be re-used, and
     * building a second would leave two pickers writing to one identity.
     *
     * @param {string} selector
     */
    CompanyCaptureComponent.prototype.mountControl = function (selector) {
        const self = this;
        if (!this._control) {
            this._control = new CompanySearchControl({
                fieldSelector: selector,
                config: this._config,
                getCountryCode: function () {
                    return self.countryCode();
                },
                searchForCompanyText: $t('Search for company'),
                // What the field reads when nothing is selected yet: select2
                // renders its own display node, so the placeholder attribute on
                // the input underneath is never the one the buyer sees.
                templateSelectionFallback: function () {
                    return $t('Enter company name to search');
                },
                // The chips are this component's only route to manual entry, on
                // every mount, so the separately worded in-dropdown button is
                // not built at all. That divergence between the two old mounts
                // is what the single component removes.
                manualEntryEnabled: false,
                onSelect: function (selectedItem) {
                    // Authoritative: a pick must overwrite the previous
                    // company's number even when the new one has none.
                    identity.write(
                        { companyId: selectedItem.companyId, companyName: selectedItem.text },
                        { authoritative: true }
                    );
                    companySearch.lookupCompanyAddress(
                        self._config,
                        selectedItem,
                        companySearch.billingRoleFormRoot()
                    );
                },
                onReturnToSearch: function () {
                    identity.captureMode('registered');
                    self.syncChips();
                },
                // select2 renders its own display node on every bind, so a
                // re-mount over an already-captured company shows an empty box
                // unless the current name is painted back into it.
                onBound: function () {
                    $('#select2-company_name-container').text(identity.companyName());
                }
            });
        } else {
            this._control.fieldSelector = selector;
        }
        this._control.bind();
    };

    /** The node the chips are inserted after — the field's own `.field` wrapper. */
    CompanyCaptureComponent.prototype.chipsAnchor = function () {
        const $field = $(this._boundSelector);
        if (!$field.length) return $();
        const $wrapper = $field.closest('.field');
        return $wrapper.length ? $wrapper : $field;
    };

    // ----------------------------------------------------------------- chips

    /**
     * Build the chips group once and keep it beside the live mount.
     *
     * Built in JS, as a sibling of the field they act on — the same shape
     * WooCommerce (`syncSoleTraderChip`/`syncManualEntryButton`) and
     * PrestaShop (`renderChipSelection`) both ship. They were previously
     * rendered by the payment tile's Knockout template, which is what tied
     * them to a surface that is destroyed on every totals change.
     */
    CompanyCaptureComponent.prototype.syncChips = function () {
        const $anchor = this.chipsAnchor();
        if (!$anchor.length) return;
        let $chips = $('.' + CHIPS_WRAPPER_CLASS);
        if (!$chips.length) {
            $chips = this.buildChips();
        }
        if ($chips.prev()[0] !== $anchor[0]) {
            $chips.insertAfter($anchor);
        }
        $chips
            .find('[data-two-chip="soletrader"]')
            .toggleClass(HIDDEN_CLASS, !identity.soleTraderAvailable());
        $chips
            .find('[data-two-chip="manual"]')
            .toggleClass(HIDDEN_CLASS, !this.showManualEntryChip());
        const mode = identity.captureMode();
        $chips.find('.' + CHIP_CLASS).each(function () {
            const $chip = $(this);
            $chip.toggleClass(CHIP_SELECTED_CLASS, $chip.attr('data-two-chip') === mode);
        });
        return $chips;
    };

    /**
     * Manual entry needs somewhere for the registry number to come from later,
     * and with company search out of the address step there is no such lookup
     * on the checkout — so a typed name would be a dead end and is not offered.
     *
     * @returns {boolean}
     */
    CompanyCaptureComponent.prototype.showManualEntryChip = function () {
        return !!this._config.isCompanySearchEnabled;
    };

    /** @returns {object} the chips group, detached */
    CompanyCaptureComponent.prototype.buildChips = function () {
        const self = this;
        const $chips = $('<div></div>').addClass(CHIPS_WRAPPER_CLASS);
        const chips = [
            { mode: 'registered', text: $t('Registered company'), run: function () { self.registeredMode({ openDropdown: true }); } },
            { mode: 'soletrader', text: $t('Sole trader'), run: function () { self.soleTraderMode(); } },
            { mode: 'manual', text: $t('Enter manually'), run: function () { self.manualEntryMode(); } }
        ];
        chips.forEach(function (chip) {
            $('<button type="button"></button>')
                .addClass(CHIP_CLASS)
                .attr('data-two-chip', chip.mode)
                .attr('data-element', 'click-element')
                .text(chip.text)
                // Bound on the element rather than delegated: a delegated
                // handler was proven not to reach these buttons on every
                // checkout, and a chip that silently does nothing is worse than
                // one that is not offered. Propagation stops here because
                // one-page checkouts bind collapse handlers above this node.
                .on('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    chip.run();
                })
                .on('keydown', function (event) {
                    if (event.which !== 13 && event.which !== 32) return;
                    event.preventDefault();
                    event.stopPropagation();
                    chip.run();
                })
                .appendTo($chips);
        });
        return $chips;
    };

    // ----------------------------------------------------------------- modes

    /**
     * Registered-company search — the default, and the way out of the other
     * two.
     *
     * @param {object} [options] `{ openDropdown: true }` to land the buyer in
     *        the search box, which is what a deliberate click means
     */
    CompanyCaptureComponent.prototype.registeredMode = function (options) {
        this.leaveSoleTraderMode();
        identity.captureMode('registered');
        this.refreshMount();
        if (this._control && options && options.openDropdown) {
            this._control.bind({ openDropdown: true });
        }
        this.syncChips();
    };

    /**
     * Manual entry: abandon the company in play and tear the picker down,
     * leaving the field a plain text input the buyer can type into.
     *
     * With no registry number `isCaptured()` stays false and the order is
     * refused server-side — unchanged by this being one click away.
     */
    CompanyCaptureComponent.prototype.manualEntryMode = function () {
        this.leaveSoleTraderMode();
        identity.captureMode('manual');
        if (this._control) {
            // Before the teardown: a search still on the wire would otherwise
            // run select2's bookkeeping over a destroyed picker.
            this._control.abortActiveRequest();
            this._control.destroy();
        }
        identity.clearNumber();
        $(this._boundSelector).val('').attr('type', 'text').trigger('focus');
        // The in-field route back, beside the chip that does the same thing:
        // a buyer who typed into this field is looking at it, not at the chips.
        if (this._control) this._control.showSearchForCompanyLink();
        this.syncChips();
    };

    /**
     * Sole trader — always the hosted signup, opened synchronously inside the
     * click so a popup blocker allows it.
     */
    CompanyCaptureComponent.prototype.soleTraderMode = function () {
        const wasAdopted = identity.isSoleTrader() && identity.soleTraderAdopted();
        if (!wasAdopted) {
            identity.captureMode('soletrader');
            identity.clearNumber();
            if (this._control) this._control.destroy();
            this.syncChips();
        }
        // Re-clicking once adopted is the same re-signup the "select a
        // different sole trader" link launches: offer a choice rather than
        // hand back what is already on screen.
        return this._soleTrader.launchSignup(wasAdopted ? { autoselect: false } : undefined);
    };

    /**
     * Leave sole-trader mode, discarding what it captured. A no-op in the other
     * two modes, which is what makes it safe on every mode entry.
     *
     * @returns {boolean} whether sole-trader mode was actually left
     */
    CompanyCaptureComponent.prototype.leaveSoleTraderMode = function () {
        if (!identity.isSoleTrader()) return false;
        identity.soleTraderAdopted(false);
        // A sole trader's minted name and synthetic number are not a registered
        // organisation, so carrying them across would submit one identity under
        // the other's mode.
        identity.clear();
        // Without this the sole trader's registered address stays in the form
        // and goes out under whatever company the buyer searches for next. Only
        // fields still holding what the write put there are cleared, so the
        // buyer's own edits survive.
        companySearch.revertAutofilledAddress();
        this._soleTrader.forgetAdoptions();
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
        identity.write(
            { companyId: buyer.organization_number, companyName: buyer.company_name },
            { authoritative: true }
        );
        identity.soleTraderAdopted(true);
        this.syncChips();
    };

    /** The buyer abandoned signup with nothing captured. */
    CompanyCaptureComponent.prototype.abandonSoleTrader = function () {
        if (identity.soleTraderAdopted()) return;
        this.registeredMode();
    };

    return new CompanyCaptureComponent();
});
