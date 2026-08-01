/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
define([
    'jquery',
    'mage/translate',
    'underscore',
    'Magento_Ui/js/form/form',
    'Magento_Customer/js/customer-data',
    'Magento_Checkout/js/model/step-navigator',
    'uiRegistry',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/company-search'
], function (
    $,
    $t,
    _,
    Component,
    customerData,
    stepNavigator,
    uiRegistry,
    brandConfig,
    companySearch
) {
    'use strict';

    // Resolve the active Two-family brand subtree so overlays
    // (acme_payment, …) get their own checkoutApiUrl /
    // isCompanySearchEnabled / companySearchLimit instead of falling
    // through to an empty object when the vanilla `two_payment` key
    // isn't present.
    const config = brandConfig.getActiveTwoBrandConfig();

    // Our own event namespace, separate from company-search's. The picker
    // teardown paths clear `companySearch.EVENT_NS` off the company-name input
    // and select2's own destroy() clears `.select2`; the company-number
    // handlers below have to survive both.
    const EVENT_NS = '.twoAddressCompanyId';

    return Component.extend({
        countrySelector: '#shipping-new-address-form select[name="country_id"]',
        companyNameSelector: '#shipping-new-address-form input[name="company"]',
        companyNameLabel: 'div[name="shippingAddress.company"] label',
        companyIdSelector: '#shipping-new-address-form input[name="custom_attributes[company_id]"]',
        shippingTelephoneSelector: '#shipping-new-address-form input[name="telephone"]',
        companyNamePlaceholder: $t('Enter company name to search'),
        searchForCompanyText: $t('Search for company'),
        searchForCompanyButton: '#shipping_search_for_company',
        initialize: function () {
            let self = this;
            this._super();

            $.async(this.countrySelector, function (countrySelector) {
                self.toggleCompanyVisibility();
                $(countrySelector).on('change', function () {
                    self.toggleCompanyVisibility();
                });
            });
            this.enableCompanySearch();
            this.enableManualCompanyId();
            const setTwoTelephone = (e) => customerData.set('shippingTelephone', e.target.value);
            $.async(self.shippingTelephoneSelector, function (telephoneSelector) {
                $(telephoneSelector).on('change keyup', setTwoTelephone);
                const telephone = $(self.shippingTelephoneSelector).val();
                customerData.set('shippingTelephone', telephone);
            });
        },
        toggleCompanyVisibility: function () {
            const countryCode = $(this.countrySelector).val().toLowerCase();
            customerData.set('countryCode', countryCode);
            let field = $(this.companyNameSelector).closest('.field');
            field.show();
            field.attr('style', function (i, style) {
                return (style || '') + 'width: 100% !important;';
            });
        },
        /**
         * THE single writer of the `companyData` customer-data section.
         *
         * Its own method so that every path which has to publish — a registry
         * pick, the manual-entry row, and the company-number field the
         * buyer types into — shares one call site. The payment step treats a
         * change NOTIFICATION on this section as an act of selection, and that
         * reading is only sound while the section has exactly one writer.
         */
        publishCompanyData: function (companyId, companyName) {
            customerData.set('companyData', { companyId: companyId, companyName: companyName });
        },
        setCompanyData: function (companyId = '', companyName = '') {
            console.debug({ logger: 'addressAutocomplete.setCompanyData', companyId, companyName });
            this.publishCompanyData(companyId, companyName);
            $('.select2-selection__rendered').text(companyName);
            $(this.companyNameSelector).val(companyName);
            $(this.companyIdSelector).val(companyId);
            this.syncCompanyIdEditable();
        },
        /**
         * True while select2 owns the company-name input, i.e. while the buyer
         * cannot type into it and every company that comes into play arrives
         * through setCompanyData().
         */
        isCompanySearchActive: function () {
            const $field = $(this.companyNameSelector);
            return !!($field.length && $field.data('select2'));
        },
        /**
         * The company name currently in play. While the picker owns the input,
         * the published section is preferred: setCompanyData() writes the name
         * to both, so the two agree and the section is the one the picker's own
         * chrome cannot get in front of. Once the buyer has switched to manual
         * entry the input wins — that is the one state in which their typing is
         * the sole record of the name, because nothing publishes it per
         * keystroke.
         */
        currentCompanyName: function () {
            const published = (customerData.get('companyData')() || {}).companyName || '';
            if (this.isCompanySearchActive()) {
                return published;
            }
            return $(this.companyNameSelector).val() || published;
        },
        /**
         * The buyer has to supply the company number by hand exactly when a
         * company is in play but no registry identifier came with it.
         *
         * This is now the ONLY place that derivation exists — but read that as
         * "the only surviving copy of the code", NOT as "the surviving route for
         * the buyer". The payment tile used to apply the same derivation to its
         * own company-number field; TWO-25288 made that field read-only in every
         * mode. This step's field is no route either: it is CSS-hidden
         * unconditionally (`.two-company-id-hidden`), so the derivation still
         * runs and still gates a field nobody can see. Nothing in the plugin
         * currently lets a buyer hand-type an organisation number; an
         * identifier-less company is refused by Model/Two.php::authorize().
         */
        needsManualCompanyId: function () {
            return !!this.currentCompanyName() && !$(this.companyIdSelector).val();
        },
        /**
         * uiRegistry name of the layout node the company-number input belongs
         * to — the `company_id` child of the shipping-address fieldset declared
         * in Plugin/Model/Checkout/LayoutProcessorPlugin.php. uiRegistry names
         * are the layout's own `children` path with the `children` links
         * dropped.
         */
        companyIdComponent:
            'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.company_id',
        /**
         * Set the company-number field's disabled state through the UI
         * component, not only the DOM.
         *
         * The layout declares `disabled` as a component property and
         * `ui/form/element/input` binds it inside a compound `attr: {...}`
         * binding alongside `error`, `required` and friends. Knockout
         * re-evaluates that whole binding when ANY observable it reads changes,
         * so a raw `prop('disabled', …)` write survives only until the next
         * such re-evaluation, which then reinstates the component's stale
         * value. The component is therefore the authoritative one.
         *
         * The DOM write is kept as well, and deliberately: `uiRegistry.get()`
         * yields nothing if this runs before the component registers, and the
         * derivation below reads the field's own value off the DOM, so the two
         * have to be written together to stay consistent within a tick.
         */
        setCompanyIdDisabled: function (isDisabled) {
            const component = uiRegistry.get(this.companyIdComponent);
            if (component && typeof component.disabled === 'function') {
                component.disabled(isDisabled);
            }
            $(this.companyIdSelector).prop('disabled', isDisabled);
        },
        /**
         * Company search owns `company_id` while it can fill it: the number
         * arrives with the picked company, so an editable field would only let
         * the buyer contradict the registry.
         *
         * Deliberately NOT called from the company-number field's own change
         * handler, nor from the company-name one. The derivation reads the
         * number field's value, so re-deriving after the buyer has typed into
         * it would disable the field they are still using. Only the paths that
         * learn a number from the registry — setCompanyData(), and the one-shot
         * derivation on a pre-filled form — may disable.
         */
        syncCompanyIdEditable: function () {
            this.setCompanyIdDisabled(!this.needsManualCompanyId());
        },
        /**
         * Enable-only half of the derivation, for events that can reveal a
         * company with no number behind it but can never establish that a
         * number came from the registry.
         */
        enableCompanyIdIfNeeded: function () {
            if (this.needsManualCompanyId()) {
                this.setCompanyIdDisabled(false);
            }
        },
        /**
         * Make the company-number field usable: publish what the buyer types
         * into it, and enable it once a manually-typed company name appears.
         *
         * `change`, never `keyup`: the payment step fires an order intent as
         * soon as it holds both a company name and a number, so publishing per
         * keystroke would fire one credit-check request per character. The
         * telephone handler above can afford `keyup` because nothing downstream
         * of it calls out.
         */
        enableManualCompanyId: function () {
            const self = this;
            $.async(this.companyIdSelector, function (companyIdField) {
                $(companyIdField)
                    .off('change' + EVENT_NS)
                    .on('change' + EVENT_NS, function () {
                        self.publishCompanyData(
                            $(self.companyIdSelector).val() || '',
                            self.currentCompanyName()
                        );
                    });
                // A form rendered with an address already on it (returning
                // customer, or a reload mid-checkout) never passes through
                // setCompanyData(), so derive once on resolve.
                self.syncCompanyIdEditable();
            });
            $.async(this.companyNameSelector, function (companyNameField) {
                // Only meaningful after the manual-entry row has destroyed
                // the widget: until then the buyer cannot type here at all and
                // picks arrive through setCompanyData(). ENABLES only — the
                // buyer may come back to edit the name after typing a number,
                // and the full derivation reads that number, so re-deriving
                // here would disable the field and lock them out of what they
                // just typed. Nothing published either: the manually typed NAME
                // reaches the payment step through the quote's billing address,
                // and publishing per keystroke would fire order intents.
                $(companyNameField)
                    .off('input' + EVENT_NS)
                    .on('input' + EVENT_NS, function () {
                        if (self.isCompanySearchActive()) return;
                        self.enableCompanyIdIfNeeded();
                    });
            });
        },
        setAddressData: function (address) {
            companySearch.applyAddress(address);
        },
        addressLookup: function (selectedCompany) {
            return companySearch.lookupCompanyAddress(config, selectedCompany);
        },
        /**
         * Hand the company-name input back to the buyer.
         *
         * Clears the company in play first: whatever they type next is a
         * different company from the one the picker had, and the published
         * section is what the payment step credit-checks.
         *
         * @param {object} $companyNameField the node THIS bind owns — not the
         *        document-wide selector, so a re-rendered form's picker is
         *        never the one torn down
         * @param {object} bindToken identity for this bind, so the search the
         *        buyer has just given up on can be cancelled
         */
        enterDetailsManually: function ($companyNameField, bindToken) {
            // First, before anything else touches the widget. Cancelling the
            // selection leaves the dropdown open, so a search still on the
            // wire would come back up to 30s later and run select2's
            // highlight and scroll bookkeeping over a torn-down picker.
            companySearch.abortActiveRequest(bindToken);
            this.setCompanyData();
            companySearch.detachManualEntryObserver($companyNameField);
            $companyNameField.off(companySearch.EVENT_NS);
            $companyNameField.select2('destroy');
            $companyNameField.attr('type', 'text');
            $companyNameField.val('');
            $(this.searchForCompanyButton).show();
        },
        /**
         * (Re-)bind the company-search picker to the company-name input.
         *
         * @param {object} [options]
         * @param {boolean} [options.openDropdown] open the picker as soon as
         *        the widget is bound. Set only by the "Search for company"
         *        link: returning to search mode should land the buyer in the
         *        search box, not on a closed picker they must click again.
         *        Leave it off for the initial checkout bind, where popping a
         *        dropdown open unasked would steal focus from the form.
         */
        enableCompanySearch: function (options) {
            if (!config.isCompanySearchEnabled) return;
            const self = this;
            // One-shot, and deliberately NOT a property on the component:
            // `$.async` is a MutationObserver that fires again on every
            // re-render, so a flag that survived the first bind would pop the
            // dropdown open under a buyer who has moved on to another field.
            let pendingOpen = !!(options && options.openDropdown);
            require(['Two_Gateway/select2-4.1.0/js/select2.min'], function () {
                $.async(self.companyNameSelector, function (companyNameField) {
                    // Re-binding on every `$.async` fire is intentional:
                    // select2 4.1's constructor destroys any existing
                    // instance on the same node, so re-init re-points the
                    // widget and its handlers at the current component. An
                    // early-return guard here would both keep a stale widget
                    // alive and skip the placeholder / manual-entry
                    // housekeeping below.
                    const $companyNameField = $(companyNameField);
                    // Identity for this bind, so a previous widget's late
                    // response cannot paint chrome on its replacement.
                    const bindToken = {};
                    // select2's destroy() only clears its own `.select2`
                    // namespace, so our handlers would stack one copy per
                    // re-render and a single pick would fire N address
                    // lookups. Clear ours before re-binding.
                    $companyNameField.off(companySearch.EVENT_NS);
                    $companyNameField
                        .select2({
                            minimumInputLength: companySearch.MIN_INPUT_LENGTH,
                            // Displaces select2's own built-in English
                            // "input too short" text, which is baked into the
                            // vendored bundle and counts down the REMAINING
                            // characters. Ours is translatable and names the
                            // threshold outright.
                            language: {
                                inputTooShort: function () {
                                    return companySearch.minInputLengthMessage();
                                }
                            },
                            width: '100%',
                            escapeMarkup: function (markup) {
                                return markup;
                            },
                            templateResult: function (data) {
                                return data.html;
                            },
                            templateSelection: function (data) {
                                return data.text || self.companyNamePlaceholder;
                            },
                            ajax: companySearch.buildSearchAjaxOptions({
                                config: config,
                                token: bindToken,
                                getCountryCode: function () {
                                    return $(self.countrySelector).val();
                                },
                                // Bound to THIS node, not to the selector, so
                                // a destroyed widget's late response cannot
                                // paint onto the live picker.
                                onSearching: function (isSearching) {
                                    companySearch.setSearching(
                                        $companyNameField,
                                        isSearching,
                                        bindToken
                                    );
                                },
                                onUnavailable: function (isUnavailable) {
                                    companySearch.setUnavailable(
                                        $companyNameField,
                                        isUnavailable,
                                        bindToken
                                    );
                                }
                            })
                        })
                        .on('select2:open' + companySearch.EVENT_NS, function () {
                            // Nothing else removes what we appended into the
                            // search box, so a reopened picker would show the
                            // previous search's "unavailable" notice.
                            companySearch.clearSearchChrome($companyNameField, bindToken);
                            // The manual-entry affordance is a row INSIDE the
                            // results list, so the model owns its whole
                            // lifecycle (appear at the threshold, survive each
                            // re-render). Bound to this node and this bind
                            // token, so a stale widget cannot paint a row onto
                            // its replacement.
                            companySearch.attachManualEntryRow($companyNameField, bindToken);
                            document.querySelector('.select2-search__field').focus();
                        })
                        .on('select2:close' + companySearch.EVENT_NS, function () {
                            // Every open re-attaches, so nothing is lost by
                            // dropping the watcher here — and a checkout that
                            // re-renders the form while the picker is closed
                            // would otherwise leave this node's observer
                            // pinning a detached results list for the life of
                            // the page.
                            companySearch.detachManualEntryObserver($companyNameField);
                        })
                        /*
                         * `select2:selecting` is the PREVENTABLE pre-event for
                         * a selection, and the manual-entry row is not a
                         * company: letting it through would write the sentinel
                         * id into the company name and fire an address lookup
                         * for it. Cancelling here also covers mouse, Enter and
                         * touch in one place, because all three arrive as a
                         * selection.
                         */
                        .on('select2:selecting' + companySearch.EVENT_NS, function (e) {
                            const data = e.params && e.params.args && e.params.args.data;
                            if (!companySearch.isManualEntryOption(data)) return;
                            e.preventDefault();
                            self.enterDetailsManually($companyNameField, bindToken);
                        })
                        .on('select2:select' + companySearch.EVENT_NS, function (e) {
                            const selectedItem = e.params.data;
                            $('.select2-selection__rendered').text(selectedItem.id);
                            self.setCompanyData(selectedItem.companyId, selectedItem.text);
                            // Gate lives in companySearch.lookupCompanyAddress
                            // (config.isAddressSearchEnabled = the single
                            // `enable_address_search` setting), shared with the
                            // payment-step picker.
                            self.addressLookup(selectedItem);
                        });
                    companySearch.markSearchBinding($companyNameField, bindToken);
                    // Set initial placeholder text for the company search
                    if (!$(self.companyNameSelector).val()) {
                        $(self.companyNameSelector)
                            .closest('.field')
                            .find('.select2-selection__rendered')
                            .text(self.companyNamePlaceholder);
                    }
                    if ($(self.companyNameSelector).val()) {
                        // pre-fill on checkout render
                        $('.select2-selection__rendered').text($(self.companyNameSelector).val());
                    }
                    if ($(self.searchForCompanyButton).length == 0) {
                        $(self.companyNameSelector)
                            .closest('.field')
                            .append(
                                `<div id="shipping_search_for_company" class="search_for_company" ` +
                                    `role="button" tabindex="0" title="${self.searchForCompanyText}">` +
                                    `<span>${self.searchForCompanyText}</span>` +
                                    '</div>'
                            );
                    }
                    // Re-bound unconditionally: the div survives a re-render,
                    // so the append guard above was false and this handler kept
                    // closing over the first, stale component.
                    const activateSearchForCompany = function () {
                        // Guards against a double-activation: this is a
                        // `role="button"` on a plain div, not a native
                        // <button>, and some assistive-tech/browser
                        // combinations forward a synthetic `click` in
                        // addition to the Enter keydown for exactly that
                        // shape of widget. Once hidden, a second call is a
                        // no-op rather than re-opening a dropdown the buyer
                        // already opened.
                        const $button = $(self.searchForCompanyButton).first();
                        if ($button.length && $button.get(0).style.display === 'none') return;
                        self.enableCompanySearch({ openDropdown: true });
                        $(self.searchForCompanyButton).hide();
                    };
                    $(self.searchForCompanyButton)
                        .off('click' + companySearch.EVENT_NS)
                        .off('keydown' + companySearch.EVENT_NS)
                        .on('click' + companySearch.EVENT_NS, activateSearchForCompany)
                        // Keyboard reachability (TWO parity with WooCommerce /
                        // Hyvä): this div has no native semantics, so Enter/
                        // Space have to be wired up by hand to match the
                        // role="button" contract set on the markup above.
                        .on('keydown' + companySearch.EVENT_NS, function (e) {
                            if (e.key !== 'Enter' && e.key !== ' ' && e.which !== 13 && e.which !== 32) {
                                return;
                            }
                            e.preventDefault();
                            activateSearchForCompany();
                        });
                    $(self.searchForCompanyButton).hide();
                    // Last, so every handler above — including `select2:open`,
                    // which is what puts the caret in the search box — is
                    // already bound when the dropdown opens.
                    if (pendingOpen) {
                        pendingOpen = false;
                        $companyNameField.select2('open');
                    }
                });
            });
        }
    });
});
