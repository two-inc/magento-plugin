/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25326: the single class that owns the company-search picker's full
 * lifecycle end to end — select2 construction, dropdown open/close wiring,
 * the manual-entry button, the "Search for company" return link, and
 * open-on-type — built on top of the shared, mount-agnostic primitives in
 * `company-search.js` (the search request, result mapping, address
 * write-back, and the in-field chrome helpers).
 *
 * Mirrors PrestaShop's `TwoCompanySearch` class: ONE implementation of the
 * select2 wiring, configured entirely through the constructor options
 * below, never duplicated per mount. `address-autocomplete.js` (shipping
 * step) and `gateway_method.js` (payment tile) each construct exactly one
 * instance of this class rather than each rolling their own `.select2({…})`
 * call — see those two files' `enableCompanySearch()` for the call sites.
 *
 * Magento's checkout is more dynamic than PrestaShop's: a payment renderer
 * is pushed once PER Two-family brand (so a checkout offering two brands has
 * two independent tile widgets alive at once), and which mount — address
 * area or tile — is the buyer's active route to search can flip at runtime
 * (virtual cart, saved address) without a page reload. Those two facts are
 * why this stays two call sites rather than PrestaShop's one
 * `TwoCheckoutManager`-owned singleton: each call site is a different
 * Knockout component with its own dispose() lifecycle already scoped to
 * "the widget THIS component bound", and forcing a page-wide singleton would
 * fight that existing multi-brand safety model rather than simplify it. The
 * mutual-exclusion invariant PrestaShop gets from a single decision point,
 * Magento gets from the existing `isCompanySearchEnabled` /
 * `isTileCompanySearchActive()` guards each call site already checks before
 * constructing or (re)binding its instance — at most one of the two mounts
 * is ever actively bound to a live select2 widget for a given brand at a
 * time.
 */
define(['jquery', 'Two_Gateway/js/model/company-search'], function ($, companySearch) {
    'use strict';

    /**
     * @param {object} options
     * @param {string} options.fieldSelector jQuery selector for the
     *        company-name input this control binds to. Re-resolved via
     *        `$.async` on every bind() call, so a node replaced by a
     *        checkout re-render is picked up automatically.
     * @param {object} options.config brand config subtree — needs
     *        `checkoutApiUrl`, `companySearchLimit`, `isAddressSearchEnabled`.
     * @param {function(): (string|undefined)} [options.getCountryCode]
     *        returns the current ISO country code (any case), read fresh on
     *        every search request.
     * @param {function(object, object)} [options.onSelect] called with
     *        (selectedItem, $field) when the buyer picks a company from the
     *        dropdown. `selectedItem` is select2's result item
     *        (`{id, text, html, companyId, lookupId}` — see
     *        company-search.js's `processResults`).
     * @param {boolean} [options.manualEntryEnabled=true] build the
     *        "My company is not on the list" button inside the dropdown. The
     *        payment tile turns this OFF (TWO-25503): manual entry is a peer
     *        chip in its mode control, and a second, differently worded route
     *        to the same mode is what that control replaced.
     * @param {function} [options.onReturnToSearch] called when the "Search
     *        for company" link puts the buyer back in search mode, for a
     *        mount that tracks the active mode itself.
     * @param {function(object, object)} [options.onManualEntryActivated]
     *        called with ($field, bindToken) once the buyer has actually
     *        activated the "My company is not on the list" button. Owns
     *        whatever "manual entry" means for this mount — the two call
     *        sites deliberately differ here (address step: convert the
     *        field to a plain typeable text input; payment tile: tear the
     *        widget down entirely and show the "Search for company" link).
     * @param {function(object)} [options.onBound] called with ($field) once
     *        per bind(), after every handler is wired — the hook for
     *        mount-specific paint that has to run after binding (placeholder
     *        text, a pre-filled selection's rendered text, …).
     * @param {function(): string} [options.templateSelectionFallback] text
     *        shown in the closed combobox when select2 has no `text` to
     *        render for the current selection.
     * @param {string} [options.searchForCompanyText] label for the
     *        "Search for company" return link.
     * @param {string} [options.searchForCompanyId] `id` attribute to give
     *        the "Search for company" link and to resolve it by. Omit when
     *        multiple instances of this control can exist on one page (the
     *        payment tile, pushed once per brand) — the link is then found
     *        and scoped by CONTAINER instead, exactly as the class already
     *        scopes every other per-bind lookup.
     */
    function CompanySearchControl(options) {
        options = options || {};
        this.fieldSelector = options.fieldSelector;
        this.config = options.config;
        this.getCountryCode = options.getCountryCode || function () { return ''; };
        this.onSelect = options.onSelect || function () {};
        this.manualEntryEnabled = options.manualEntryEnabled !== false;
        this.onManualEntryActivated = options.onManualEntryActivated || function () {};
        this.onReturnToSearch = options.onReturnToSearch || function () {};
        this.onBound = options.onBound || function () {};
        this.templateSelectionFallback = options.templateSelectionFallback || function () { return ''; };
        this.searchForCompanyText = options.searchForCompanyText || '';
        this.searchForCompanyId = options.searchForCompanyId || null;

        this._$field = null;
        this._bindToken = null;
        this._$searchForCompanyLink = null;
    }

    /**
     * (Re-)bind the picker to whatever node `fieldSelector` currently
     * matches. Safe to call repeatedly — select2 4.1's own constructor
     * destroys any existing instance on the same node, so re-init is the
     * correct way to re-point the widget (and its handlers' closures) at a
     * re-rendered form, exactly as both original call sites relied on.
     *
     * @param {object} [bindOptions]
     * @param {boolean} [bindOptions.openDropdown] open the picker as soon as
     *        it is (re)bound. Set only by the "Search for company" link:
     *        returning to search mode should land the buyer in the search
     *        box, not on a closed picker they must click again. Leave off
     *        for a checkout's initial bind, where popping a dropdown open
     *        unasked would steal focus from the form.
     */
    CompanySearchControl.prototype.bind = function (bindOptions) {
        const self = this;
        // One-shot, and deliberately a local rather than an instance field:
        // `$.async` is a MutationObserver that can fire again on every
        // re-render (Fire Checkout re-renders a lot), so a flag that
        // survived past the first fire would pop the dropdown open under a
        // buyer who has moved on to another field.
        let pendingOpen = !!(bindOptions && bindOptions.openDropdown);

        require(['Two_Gateway/select2-4.1.0/js/select2.min'], function () {
            $.async(self.fieldSelector, function (fieldNode) {
                const $field = $(fieldNode);
                self._$field = $field;

                // Identity for this bind, so a previous widget's still-in-flight
                // response cannot paint chrome onto the widget that replaced it.
                const bindToken = {};
                self._bindToken = bindToken;

                // select2's destroy() only does `$element.off('.select2')` —
                // our own handlers are not in that namespace and would
                // otherwise survive every re-init, stacking one more copy per
                // re-render.
                $field.off(companySearch.EVENT_NS);
                // select2's destroy() doesn't disconnect this either, and a
                // re-render while the picker is open can replace the select2
                // instance without emitting `select2:close` first — leaving a
                // manual-entry button from the PREVIOUS bind wired to the
                // widget this call is about to replace.
                companySearch.detachManualEntryButton($field);

                $field
                    .select2({
                        minimumInputLength: companySearch.MIN_INPUT_LENGTH,
                        // Shared with the sibling mount so the two surfaces
                        // cannot show different wording for the same state.
                        language: companySearch.buildLanguageOptions(),
                        // Shared stable hook for style.css's dropdown-row
                        // fixes — drawn from the one constant so this and the
                        // sibling mount's dropdown CSS can't drift apart on a
                        // rename.
                        dropdownCssClass: companySearch.DROPDOWN_CSS_CLASS,
                        width: '100%',
                        escapeMarkup: function (markup) {
                            return markup;
                        },
                        templateResult: function (data) {
                            return data.html;
                        },
                        templateSelection: function (data) {
                            return data.text || self.templateSelectionFallback();
                        },
                        ajax: companySearch.buildSearchAjaxOptions({
                            config: self.config,
                            token: bindToken,
                            getCountryCode: self.getCountryCode,
                            // Bound to THIS node, not to the selector: a
                            // search issued by a widget since destroyed finds
                            // no instance on its own element and no-ops,
                            // rather than painting a stale failure onto
                            // whichever picker is live now.
                            onSearching: function (isSearching) {
                                companySearch.setSearching($field, isSearching, bindToken);
                            },
                            onUnavailable: function (isUnavailable) {
                                companySearch.setUnavailable($field, isUnavailable, bindToken);
                            }
                        })
                    })
                    .on('select2:open' + companySearch.EVENT_NS, function () {
                        // select2 only detaches the dropdown on close and only
                        // clears the search input's value, so anything
                        // appended into the search box survives a reopen —
                        // clear it here or a reopened picker shows the
                        // previous search's "unavailable" notice.
                        companySearch.clearSearchChrome($field, bindToken);
                        // A SIBLING of the results list (#30.x.15), not a row
                        // inside it, so it stays visible outside select2's
                        // own scroll/clip and needs no selection-cancelling
                        // dance — its own click handler is the only thing
                        // that activates it.
                        if (self.manualEntryEnabled) {
                            companySearch.attachManualEntryButton($field, bindToken, function () {
                                self.onManualEntryActivated($field, bindToken);
                            });
                        }
                        document.querySelector('.select2-search__field').focus();
                    })
                    .on('select2:close' + companySearch.EVENT_NS, function () {
                        // Every open re-attaches, so nothing is lost by
                        // dropping the button here — and a checkout that
                        // re-renders the form while the picker is closed
                        // would otherwise leave a button from this bind wired
                        // to a disposed renderer for the life of the page.
                        companySearch.detachManualEntryButton($field);
                    })
                    .on('select2:select' + companySearch.EVENT_NS, function (e) {
                        self.onSelect(e.params.data, $field);
                    });

                companySearch.markSearchBinding($field, bindToken);
                // TWO-25326 §1: any character opens the dropdown, not just
                // the Space/Enter select2 4.1 handles on its own.
                companySearch.attachOpenOnType($field, bindToken);

                self._bindSearchForCompanyLink($field);
                self.onBound($field);

                // Last, so every handler above — including `select2:open`,
                // which is what puts the caret in the search box — is
                // already bound when the dropdown opens.
                if (pendingOpen) {
                    pendingOpen = false;
                    $field.select2('open');
                }
            });
        });
    };

    /**
     * Build (if absent) and (re)wire the "Search for company" return link
     * for the field this bind owns.
     *
     * @param {object} $field jQuery-wrapped picker input
     */
    CompanySearchControl.prototype._bindSearchForCompanyLink = function ($field) {
        const self = this;
        const $container = $field.closest('.field');
        const resolve = function () {
            return self.searchForCompanyId
                ? $('#' + self.searchForCompanyId)
                : $container.find('.search_for_company');
        };

        if (resolve().length === 0) {
            const idAttr = self.searchForCompanyId ? ` id="${self.searchForCompanyId}"` : '';
            $container.append(
                `<div${idAttr} class="search_for_company" role="button" tabindex="0" ` +
                    `title="${self.searchForCompanyText}">` +
                    `<span>${self.searchForCompanyText}</span>` +
                    '</div>'
            );
        }

        const $link = resolve();
        self._$searchForCompanyLink = $link;

        const activate = function () {
            // Guards against a double-activation: this is a `role="button"`
            // on a plain div, not a native <button>, and some assistive-
            // tech/browser combinations forward a synthetic `click` in
            // addition to the Enter keydown for exactly that shape of
            // widget. Once hidden, a second call is a no-op rather than
            // re-opening a dropdown the buyer already opened.
            const $el = $link.first();
            if ($el.length && $el.get(0).style.display === 'none') return;
            self.bind({ openDropdown: true });
            $link.hide();
            self.onReturnToSearch();
        };

        $link
            .off('click' + companySearch.EVENT_NS)
            .off('keydown' + companySearch.EVENT_NS)
            .on('click' + companySearch.EVENT_NS, activate)
            // Keyboard reachability: this div has no native semantics, so
            // Enter/Space have to be wired up by hand to match the
            // role="button" contract set on the markup above.
            .on('keydown' + companySearch.EVENT_NS, function (e) {
                if (e.key !== 'Enter' && e.key !== ' ' && e.which !== 13 && e.which !== 32) {
                    return;
                }
                e.preventDefault();
                activate();
            });
        $link.hide();
    };

    /**
     * The "Search for company" link this bind owns, or an empty jQuery set
     * before the first bind().
     *
     * @returns {object}
     */
    CompanySearchControl.prototype.getSearchForCompanyLink = function () {
        return this._$searchForCompanyLink || $();
    };

    /**
     * Show the "Search for company" return link — the call sites' own hook
     * for "the buyer just left search mode" (manual entry, a company
     * cleared, …).
     *
     * @param {boolean} [focus] also focus the link — the manual-entry
     *        activation path needs this: destroying the widget removes the
     *        button that had focus, and nothing else refocuses anything.
     */
    CompanySearchControl.prototype.showSearchForCompanyLink = function (focus) {
        if (!this._$searchForCompanyLink) return;
        this._$searchForCompanyLink.show();
        if (focus) this._$searchForCompanyLink.trigger('focus');
    };

    /**
     * Retire the "Search for company" return link — for a mount that offers a
     * second route back into search mode (the payment tile's registered
     * chip, TWO-25503) and must not leave two live affordances for it.
     */
    CompanySearchControl.prototype.hideSearchForCompanyLink = function () {
        if (!this._$searchForCompanyLink) return;
        this._$searchForCompanyLink.hide();
    };

    /**
     * Cancel the in-flight search for the current bind, if any.
     *
     * @returns {boolean} true when a request was actually aborted
     */
    CompanySearchControl.prototype.abortActiveRequest = function () {
        return companySearch.abortActiveRequest(this._bindToken);
    };

    /**
     * The identity token stamped on the current bind, or null before the
     * first bind(). Exposed for tests that pin bind/rebind identity — see
     * the "live bind token" tests in `company-search-country-switch.test.js`.
     *
     * @returns {object|null}
     */
    CompanySearchControl.prototype.getBindToken = function () {
        return this._bindToken;
    };

    /**
     * Whether this control currently owns a live select2 instance.
     *
     * @returns {boolean}
     */
    CompanySearchControl.prototype.isBound = function () {
        const $field = this._$field;
        return !!($field && $field.length && $field.data && $field.data('select2'));
    };

    /**
     * The field node this control is currently bound to, or an empty jQuery
     * set before the first bind().
     *
     * @returns {object}
     */
    CompanySearchControl.prototype.getField = function () {
        return this._$field || $();
    };

    /**
     * Tear down the widget this control owns — detach the manual-entry
     * button, drop our own namespaced handlers, and destroy select2. Safe to
     * call repeatedly; a no-op once already torn down.
     *
     * @returns {boolean} true when a widget was actually torn down, false
     *          when this was already a no-op — callers that only need to
     *          convert the field back to a plain text input on an ACTUAL
     *          teardown (not on a repeat call) use this to gate that.
     */
    CompanySearchControl.prototype.destroy = function () {
        const $field = this._$field;
        if (!this.isBound()) return false;
        // Belt-and-braces alongside the `select2:close` handler above:
        // destroy() does not always route through a `close` event first, and
        // an undetached button would stay wired to this disposed control for
        // the life of the page.
        companySearch.detachManualEntryButton($field);
        $field.off(companySearch.EVENT_NS);
        $field.select2('destroy');
        return true;
    };

    return CompanySearchControl;
});
