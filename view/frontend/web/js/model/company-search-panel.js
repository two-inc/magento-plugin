/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503: the company-capture popover — one anchored panel that owns the
 * query field, the result list AND the mode chips, matching PrestaShop's
 * `TwoCompanySearch`. There is no separate chip row: opening the control shows
 * the buyer one box containing every route it offers.
 *
 * This replaces the select2 picker. select2 could never satisfy that
 * requirement: it appends its dropdown to `<body>` and rewrites the contents on
 * every open, so anything of ours placed beside the field was a sibling the
 * dropdown drew over rather than a part of the control. The chips were
 * therefore hidden precisely when the buyer opened the thing that offers them.
 *
 * DOM ORDER IS THE DESIGN, not an implementation detail. Everything lives
 * inside the same `.two-company-field-wrap` as the company-name input, in this
 * order:
 *
 *   input[company] -> query field -> results host -> mode chips
 *
 * so the browser's own tab order walks the control the way it reads, and the
 * chips stay reachable without tabbing through up to 50 results. The panel is
 * `hidden` while closed, so none of it is a tab stop until the buyer opens it.
 *
 * Owns: the panel DOM, open/close, the query field, result rendering and
 * keyboard navigation, and the chips' markup and selected state.
 * Does NOT own: which chip means what (company-capture-component.js), the
 * search request or address write-back (company-search.js).
 */
define([
    'jquery',
    'mage/translate',
    'Two_Gateway/js/model/company-search'
], function ($, $t, companySearch) {
    'use strict';

    const WRAP_CLASS = 'two-company-field-wrap';
    const PANEL_CLASS = 'two-company-dropdown';
    const QUERY_CLASS = 'two-company-dropdown__query';
    const SPINNER_CLASS = 'two-company-dropdown__spinner';
    const RESULTS_CLASS = 'two-company-dropdown__results';
    const MESSAGE_CLASS = 'two-company-dropdown__message';
    const ROW_CLASS = 'two-company-dropdown__row';
    const ROW_ACTIVE_CLASS = 'two-company-dropdown__row--active';
    const CHIPS_CLASS = 'two-company-mode-chips';
    const CHIP_CLASS = 'two-company-mode-chip';
    const CHIP_SELECTED_CLASS = 'two-company-mode-chip--selected';

    // This plugin's own, because a bare `.hidden` is a theme's to define or not
    // — Luma ships one, several one-page checkouts do not, and a chip that
    // silently fails to hide offers the buyer a mode the country cannot serve.
    const HIDDEN_CLASS = 'two-hidden';

    const EVENT_NS = '.twoCompanyPanel';

    /** Ids are per-panel: one page can host a panel per Two-family brand tile. */
    let instanceSeq = 0;

    /**
     * @param {object} options
     * @param {string} options.fieldSelector jQuery selector for the
     *        company-name input this panel anchors to. Re-read on every
     *        `bind()`, so a node replaced by a checkout re-render is picked up.
     * @param {object} options.config brand config subtree — needs
     *        `checkoutApiUrl`, `companySearchLimit`.
     * @param {function(): (string|undefined)} [options.getCountryCode] the
     *        current ISO country code, read fresh on every search.
     * @param {function(): Array<{mode: string, text: string,
     *        onActivate: function}>} options.getChips the chips to render, in
     *        display order. Called on every sync, so a chip's label can follow
     *        the state it describes.
     * @param {function(string): boolean} [options.isChipVisible] whether a
     *        chip's mode is offered right now — country eligibility for sole
     *        trader, the admin setting for manual entry.
     * @param {function(): string} [options.getSelectedMode] which chip reads as
     *        selected.
     * @param {function(object)} [options.onSelect] the buyer picked a company;
     *        called with the result row.
     * @param {function(): string} [options.getDisplayText] what the field shows
     *        when the panel is closed — the captured company, or ''.
     */
    function CompanySearchPanel(options) {
        options = options || {};
        this.fieldSelector = options.fieldSelector;
        this.config = options.config;
        this.getCountryCode = options.getCountryCode || function () { return ''; };
        this.getChips = options.getChips || function () { return []; };
        this.isChipVisible = options.isChipVisible || function () { return true; };
        this.getSelectedMode = options.getSelectedMode || function () { return ''; };
        this.onSelect = options.onSelect || function () {};
        this.getDisplayText = options.getDisplayText || function () { return ''; };

        this._id = ++instanceSeq;
        this._$field = null;
        this._$panel = null;
        this._$query = null;
        this._$results = null;
        this._$chips = null;
        this._open = false;
        this._items = [];
        this._activeIndex = -1;
        this._debounceId = null;
        /** Identity for the live bind, so a superseded search cannot paint over it. */
        this._token = null;
        /** Ordinal of the latest search, so an out-of-order response is dropped. */
        this._searchSeq = 0;
        /** Selector this panel already has a `$.async` observer watching. */
        this._asyncSelector = null;
        /** Suppresses the field's own open-on-focus while the panel closes itself. */
        this._closing = false;
    }

    // ------------------------------------------------------------------ build

    /**
     * Point the panel at whatever `fieldSelector` currently matches, building
     * it there if it is not there yet. Safe to call repeatedly.
     *
     * @param {object} [bindOptions]
     * @param {boolean} [bindOptions.open] open as soon as it is bound — what a
     *        deliberate click on the registered-company chip means. Left off
     *        for a checkout's initial bind, where popping the panel open
     *        unasked would steal focus from the form.
     */
    CompanySearchPanel.prototype.bind = function (bindOptions) {
        const self = this;
        const wantsOpen = !!(bindOptions && bindOptions.open);

        // ONE observer per selector, for the life of the panel. `$.async` is a
        // MutationObserver that is never disconnected, and building the panel
        // mutates the DOM — so a second registration makes the first one's own
        // mutations re-trigger this callback, and every later render re-fires
        // every observer ever registered (TWO-25503, the checkout freeze).
        if (this._asyncSelector !== this.fieldSelector) {
            this._asyncSelector = this.fieldSelector;
            $.async(this.fieldSelector, function (fieldNode) {
                self._attach($(fieldNode));
            });
        }

        const $field = $(this.fieldSelector).first();
        if ($field.length) this._attach($field);
        if (wantsOpen) this.open();
    };

    /**
     * Build (or adopt) the panel on `$field` and wire the field's openers.
     *
     * @param {object} $field jQuery-wrapped company-name input
     */
    CompanySearchPanel.prototype._attach = function ($field) {
        if (!$field.length) return;
        const rebinding = this._$field && this._$field[0] === $field[0];
        this._$field = $field;
        // Fresh identity per attach, so a search issued by the node this call
        // replaces resolves into a token nothing is listening for.
        if (!rebinding) this._token = {};

        const $wrap = this._ensureWrap($field);
        this._buildPanel($wrap);
        this._bindFieldOpeners($field);
        this.syncChips();
        this.setDisplayText(this.getDisplayText());
    };

    /**
     * The inline positioning context the panel is absolutely positioned
     * against. A `<span>`, not a `<div>`: the company field sits inside markup
     * that themes style as inline content, and a block wrapper reflows the
     * field's own row.
     *
     * @param {object} $field
     * @returns {object} jQuery-wrapped wrapper
     */
    CompanySearchPanel.prototype._ensureWrap = function ($field) {
        const $parent = $field.parent();
        if ($parent.length && $parent.hasClass(WRAP_CLASS)) return $parent;
        $field.wrap(`<span class="${WRAP_CLASS}"></span>`);
        return $field.parent();
    };

    /**
     * Build the panel once per wrapper, or adopt the one already there.
     *
     * Adoption matters as much as construction: this runs again on every
     * re-render the observer reports, and a second panel in the same wrapper
     * would leave two query fields writing to one identity.
     *
     * @param {object} $wrap
     */
    CompanySearchPanel.prototype._buildPanel = function ($wrap) {
        const self = this;
        const existing = $wrap.children('.' + PANEL_CLASS).first();
        if (existing.length) {
            this._$panel = existing;
            this._$query = existing.find('.' + QUERY_CLASS).first();
            this._$results = existing.find('.' + RESULTS_CLASS).first();
            this._$chips = existing.find('.' + CHIPS_CLASS).first();
            // RE-BIND rather than merely adopt: every handler on an existing
            // panel closes over the attach that built it.
            this._bindPanelHandlers();
            return;
        }

        const $panel = $(`<div class="${PANEL_CLASS}" hidden></div>`);

        const $search = $(`<div class="two-company-dropdown__search"></div>`);
        // `placeholder` carries the LENGTH REQUIREMENT, not the watermark the
        // company field already showed to get here. `aria-label` deliberately
        // does not mirror it: that is the field's accessible NAME, and naming
        // the field after a transient hint leaves a screen-reader user tabbing
        // back in after a full query still hearing "Enter 3 or more
        // characters" as what the field IS.
        this._$query = $('<input type="text" autocomplete="off" />')
            .addClass(QUERY_CLASS)
            .attr('placeholder', companySearch.minInputLengthMessage())
            .attr('aria-label', $t('Search for company'))
            .attr('role', 'combobox')
            .attr('aria-autocomplete', 'list')
            .attr('aria-expanded', 'true')
            .attr('aria-controls', `two-company-results-${this._id}`);
        // A real element rather than a background on the input, so it sits at
        // the field's end regardless of the theme's input padding.
        $search
            .append(this._$query)
            .append($(`<span class="${SPINNER_CLASS}" aria-hidden="true"></span>`));

        this._$results = $('<div></div>')
            .addClass(RESULTS_CLASS)
            .attr('id', `two-company-results-${this._id}`)
            .attr('role', 'listbox');

        this._$chips = $(`<div class="${CHIPS_CLASS}"></div>`);

        // Chips AFTER the results host, so "the query field is the next tab
        // stop after the company-name field" stays true.
        $panel.append($search).append(this._$results).append(this._$chips);
        $wrap.append($panel);
        this._$panel = $panel;

        this._bindPanelHandlers();
        // Closing on an outside click is the panel's own business, and one
        // listener serves every open/close cycle for its lifetime.
        $(document).on('mousedown' + EVENT_NS + this._id, function (event) {
            if (!self._open) return;
            if (self._$panel && self._$panel[0].contains(event.target)) return;
            if (self._$field && self._$field[0] === event.target) return;
            self.close();
        });
    };

    // --------------------------------------------------------------- handlers

    /** Wire the query field and the results list. Idempotent. */
    CompanySearchPanel.prototype._bindPanelHandlers = function () {
        const self = this;

        this._$query
            .off(EVENT_NS)
            .on('input' + EVENT_NS, function () {
                self._queueSearch($(this).val());
            })
            .on('keydown' + EVENT_NS, function (event) {
                self._onQueryKeydown(event);
            });

        this._$results
            .off(EVENT_NS)
            // Delegated: rows are replaced on every search.
            .on('mousedown' + EVENT_NS, '.' + ROW_CLASS, function (event) {
                // Before the blur a click would otherwise fire first, which
                // would close the panel out from under the selection.
                event.preventDefault();
                self._selectIndex($(this).index());
            });
    };

    /**
     * The field is the control's trigger: clicking it, or typing into it, opens
     * the panel. A character typed there is seeded into the query field, so the
     * buyer never has to type their first letter twice.
     *
     * @param {object} $field
     */
    CompanySearchPanel.prototype._bindFieldOpeners = function ($field) {
        const self = this;
        $field
            .off(EVENT_NS)
            .on('mousedown' + EVENT_NS, function () {
                if (self._closing) return;
                self.open();
            })
            .on('focus' + EVENT_NS, function () {
                if (self._closing) return;
                self.open();
            })
            .on('keydown' + EVENT_NS, function (event) {
                if (event.ctrlKey || event.metaKey || event.altKey) return;
                if (event.key === 'Tab') return;
                self.open();
                if (typeof event.key !== 'string' || event.key.length !== 1) return;
                event.preventDefault();
                self._$query.val(event.key);
                self._queueSearch(event.key);
            });
    };

    /**
     * Arrow keys walk the results, Enter takes the active one, Escape closes.
     *
     * Tab is deliberately untouched: the next tab stop is the chips, which is
     * the tab order the DOM already describes.
     *
     * @param {object} event jQuery keydown event
     */
    CompanySearchPanel.prototype._onQueryKeydown = function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close({ returnFocus: true });
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            if (this._activeIndex >= 0) this._selectIndex(this._activeIndex);
            return;
        }
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
        if (!this._items.length) return;
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        const next = this._activeIndex + step;
        // Clamped rather than wrapped: wrapping from the last row to the first
        // reads as the list having reloaded under the buyer.
        this._setActiveIndex(Math.min(Math.max(next, 0), this._items.length - 1));
    };

    // ----------------------------------------------------------- open / close

    /** Open the panel and put the caret in the query field. */
    CompanySearchPanel.prototype.open = function () {
        if (!this._$panel || this._open) return;
        this._open = true;
        this._$panel.removeAttr('hidden');
        this.syncChips();
        // The previous session's rows would otherwise be the first thing the
        // buyer sees for a query they have not typed yet.
        this._$query.val('');
        this._renderMessage(companySearch.minInputLengthMessage());
        this._$query.trigger('focus');
    };

    /**
     * Close the panel and drop whatever the last search left in it.
     *
     * @param {object} [options]
     * @param {boolean} [options.returnFocus] put focus back on the field —
     *        what Escape means. Left off for a click elsewhere, where the
     *        buyer has already chosen where to go.
     */
    CompanySearchPanel.prototype.close = function (options) {
        if (!this._$panel || !this._open) return;
        this._open = false;
        this._cancelPendingSearch();
        this._$panel.attr('hidden', 'hidden');
        this._items = [];
        this._activeIndex = -1;
        if (options && options.returnFocus && this._$field) {
            // Guards the field's own focus opener against reopening the panel
            // this call is closing.
            this._closing = true;
            this._$field.trigger('focus');
            this._closing = false;
        }
    };

    /** @returns {boolean} whether the panel is currently open */
    CompanySearchPanel.prototype.isOpen = function () {
        return this._open;
    };

    // ----------------------------------------------------------------- search

    /**
     * Debounce a keystroke into a search.
     *
     * @param {string} term raw query-field value
     */
    CompanySearchPanel.prototype._queueSearch = function (term) {
        const self = this;
        const query = String(term || '').trim();
        this._cancelPendingSearch();
        if (query.length < companySearch.MIN_INPUT_LENGTH) {
            // Not merely "no results yet": a search already on the wire would
            // answer for a term the buyer has backspaced away from.
            companySearch.abortActiveRequest(this._token);
            this._setSearching(false);
            this._renderMessage(companySearch.minInputLengthMessage());
            return;
        }
        this._setSearching(true);
        this._debounceId = setTimeout(function () {
            self._debounceId = null;
            self._runSearch(query);
        }, companySearch.SEARCH_DEBOUNCE_MS);
    };

    /** Drop a debounced search that has not fired yet. */
    CompanySearchPanel.prototype._cancelPendingSearch = function () {
        if (this._debounceId === null) return;
        clearTimeout(this._debounceId);
        this._debounceId = null;
    };

    /**
     * Issue a search and render whatever comes back.
     *
     * @param {string} query already past the length threshold
     */
    CompanySearchPanel.prototype._runSearch = function (query) {
        const self = this;
        const seq = ++this._searchSeq;
        companySearch.abortActiveRequest(this._token);
        companySearch
            .searchCompanies({
                config: this.config,
                token: this._token,
                term: query,
                getCountryCode: this.getCountryCode
            })
            .then(function (result) {
                // A response for a term the buyer has already typed past.
                if (seq !== self._searchSeq) return;
                if (result.aborted) return;
                self._setSearching(false);
                if (result.unavailable) {
                    self._renderMessage(
                        $t('Company search is unavailable right now. Please try again shortly.')
                    );
                    return;
                }
                self._renderResults(result.items);
            });
    };

    /**
     * @param {boolean} isSearching
     */
    CompanySearchPanel.prototype._setSearching = function (isSearching) {
        if (!this._$panel) return;
        this._$panel
            .find('.' + SPINNER_CLASS)
            .toggleClass(SPINNER_CLASS + '--active', !!isSearching);
    };

    // --------------------------------------------------------------- results

    /**
     * @param {Array} items rows from companySearch.searchCompanies()
     */
    CompanySearchPanel.prototype._renderResults = function (items) {
        this._items = items || [];
        this._activeIndex = -1;
        if (!this._items.length) {
            this._renderMessage(companySearch.noResultsMessage());
            return;
        }
        const rows = this._items.map(function (item, index) {
            return $('<div></div>')
                .addClass(ROW_CLASS)
                .attr('role', 'option')
                .attr('aria-selected', 'false')
                .attr('id', `two-company-row-${index}`)
                // `html`, not `text`: the API marks the matched substring, and
                // it is built from the buyer's own query server-side.
                .html(item.html);
        });
        this._$results.empty().append(rows);
    };

    /**
     * Replace the result rows with a single line of copy — too-short, no
     * matches, or the search being down. Rendered in the results host rather
     * than above it so the chips stay the last thing in the panel.
     *
     * @param {string} text
     */
    CompanySearchPanel.prototype._renderMessage = function (text) {
        if (!this._$results) return;
        this._items = [];
        this._activeIndex = -1;
        this._$results.empty().append($('<div></div>').addClass(MESSAGE_CLASS).text(text));
    };

    /**
     * @param {number} index row to mark active
     */
    CompanySearchPanel.prototype._setActiveIndex = function (index) {
        this._activeIndex = index;
        const $rows = this._$results.children('.' + ROW_CLASS);
        $rows.removeClass(ROW_ACTIVE_CLASS).attr('aria-selected', 'false');
        const $active = $rows.eq(index);
        $active.addClass(ROW_ACTIVE_CLASS).attr('aria-selected', 'true');
        this._$query.attr('aria-activedescendant', $active.attr('id') || '');
        const row = $active[0];
        if (row && row.scrollIntoView) row.scrollIntoView({ block: 'nearest' });
    };

    /**
     * Take the row at `index` as the buyer's answer.
     *
     * @param {number} index
     */
    CompanySearchPanel.prototype._selectIndex = function (index) {
        const item = this._items[index];
        if (!item) return;
        this.setDisplayText(item.text);
        this.close();
        this.onSelect(item);
    };

    // ----------------------------------------------------------------- chips

    /**
     * Render the chips inside the panel and mark the selected one.
     *
     * Rebuilt from `getChips()` rather than mutated in place: the set itself
     * changes with the country and the admin setting, and a rebuild cannot
     * leave a stale chip wired to a mode that is no longer offered.
     */
    CompanySearchPanel.prototype.syncChips = function () {
        const self = this;
        if (!this._$chips) return;
        const selected = this.getSelectedMode();
        this._$chips.empty();
        this.getChips().forEach(function (chip) {
            $('<button type="button"></button>')
                .addClass(CHIP_CLASS)
                .toggleClass(CHIP_SELECTED_CLASS, chip.mode === selected)
                .toggleClass(HIDDEN_CLASS, !self.isChipVisible(chip.mode))
                .attr('data-two-chip', chip.mode)
                .attr('data-element', 'click-element')
                .attr('aria-pressed', chip.mode === selected ? 'true' : 'false')
                .text(chip.text)
                // Propagation stops here because one-page checkouts bind
                // collapse handlers above this node.
                .on('mousedown' + EVENT_NS, function (event) {
                    // The panel closes on an outside mousedown; this one is
                    // inside it, but the query field's blur would still fire
                    // before the click reaches the chip.
                    event.preventDefault();
                })
                .on('click' + EVENT_NS, function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    chip.onActivate();
                })
                .appendTo(self._$chips);
        });
    };

    // ------------------------------------------------------------------ field

    /**
     * Paint the company-name field with what is currently captured.
     *
     * @param {string} text
     */
    CompanySearchPanel.prototype.setDisplayText = function (text) {
        if (!this._$field || !this._$field.length) return;
        this._$field.val(text || '');
        // Magento's own KO binding reads the field on `change`, so a value
        // written here is invisible to the quote without it.
        this._$field.trigger('change');
    };

    /**
     * Hand the field back as a plain typeable input — manual entry, where the
     * buyer supplies a name the registry does not have.
     */
    CompanySearchPanel.prototype.releaseField = function () {
        this._cancelPendingSearch();
        companySearch.abortActiveRequest(this._token);
        this.close();
        if (this._$field) this._$field.off(EVENT_NS);
    };

    /** Re-take a field released for manual entry. */
    CompanySearchPanel.prototype.reclaimField = function () {
        if (this._$field && this._$field.length) this._bindFieldOpeners(this._$field);
    };

    /**
     * Cancel the in-flight search for the current bind, if any.
     *
     * @returns {boolean} true when a request was actually aborted
     */
    CompanySearchPanel.prototype.abortActiveRequest = function () {
        this._cancelPendingSearch();
        return companySearch.abortActiveRequest(this._token);
    };

    /** @returns {object} the field this panel is anchored to, or an empty set */
    CompanySearchPanel.prototype.getField = function () {
        return this._$field || $();
    };

    /** @returns {boolean} whether the panel is built and anchored */
    CompanySearchPanel.prototype.isBound = function () {
        return !!(this._$field && this._$field.length && this._$field[0].isConnected && this._$panel);
    };

    /** @returns {object|null} the current bind identity — for tests that pin it */
    CompanySearchPanel.prototype.getBindToken = function () {
        return this._token;
    };

    /** Tear the panel down entirely, leaving the field as core rendered it. */
    CompanySearchPanel.prototype.destroy = function () {
        this._cancelPendingSearch();
        companySearch.abortActiveRequest(this._token);
        $(document).off('mousedown' + EVENT_NS + this._id);
        if (this._$field) this._$field.off(EVENT_NS);
        if (this._$panel) this._$panel.off(EVENT_NS).remove();
        this._$panel = null;
        this._$query = null;
        this._$results = null;
        this._$chips = null;
        this._open = false;
    };

    CompanySearchPanel.CLASSES = {
        WRAP: WRAP_CLASS,
        PANEL: PANEL_CLASS,
        QUERY: QUERY_CLASS,
        RESULTS: RESULTS_CLASS,
        MESSAGE: MESSAGE_CLASS,
        ROW: ROW_CLASS,
        CHIPS: CHIPS_CLASS,
        CHIP: CHIP_CLASS,
        CHIP_SELECTED: CHIP_SELECTED_CLASS,
        HIDDEN: HIDDEN_CLASS
    };

    return CompanySearchPanel;
});
