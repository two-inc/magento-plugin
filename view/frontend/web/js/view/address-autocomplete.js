/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
define([
    'jquery',
    'ko',
    'mage/translate',
    'underscore',
    'Magento_Ui/js/form/form',
    'Magento_Customer/js/customer-data',
    'Magento_Checkout/js/model/step-navigator',
    'uiRegistry',
    'Two_Gateway/js/model/company-identity',
    'Two_Gateway/js/model/company-search',
    // `$.async`, used below — it decorates jQuery as a side effect and takes no
    // factory parameter, hence LAST in the list. Declared rather than relied on
    // transitively: it only resolved because a knockout bootstrap dependency
    // happens to pull it in, which is an ordering this file does not control.
    'Magento_Ui/js/lib/view/utils/async'
], function (
    $,
    ko,
    $t,
    _,
    Component,
    customerData,
    stepNavigator,
    uiRegistry,
    identity,
    companySearch
) {
    'use strict';

    // Our own event namespace, separate from company-search's. The picker
    // teardown paths clear `companySearch.EVENT_NS` off the company-name input
    // and select2's own destroy() clears `.select2`; the company-number
    // handlers below have to survive both.
    const EVENT_NS = '.twoAddressCompanyId';

    return Component.extend({
        countrySelector: '#shipping-new-address-form select[name="country_id"]',
        companyNameSelector: '#shipping-new-address-form input[name="company"]',
        companyIdSelector: '#shipping-new-address-form input[name="custom_attributes[company_id]"]',
        shippingTelephoneSelector: '#shipping-new-address-form input[name="telephone"]',
        initialize: function () {
            let self = this;
            this._super();

            $.async(this.countrySelector, function (countrySelector) {
                self.toggleCompanyVisibility();
                self._lastCountryCode = self.currentCountryCode();
                $(countrySelector)
                    .off('change' + EVENT_NS)
                    .on('change' + EVENT_NS, function () {
                        self.onCountryChanged();
                    });
            });
            // Record what each billing address form was rendered holding, the
            // moment core puts it in the document. `$.async` is what makes that
            // moment reachable — the form appears when the buyer unchecks "My
            // billing and shipping address are the same", long after this
            // component initialises, and it reappears on every re-render. See
            // companySearch.captureSecondaryAddressBaseline() for why the
            // baseline has to be taken before the buyer can touch the form.
            // Watched on the country SELECT inside the form, not the form
            // itself, then walked back up. Core inserts the fieldset before its
            // child field components resolve their own templates, and `$.async`
            // fires on the insertion — so watching the fieldset would run this
            // against a form with no fields in it yet. The select is the last
            // thing to arrive that the baseline needs.
            $.async(
                companySearch.SECONDARY_ADDRESS_ROOT_SELECTOR + ' select[name="country_id"]',
                function (countrySelect) {
                    companySearch.captureSecondaryAddressBaseline(
                        $(countrySelect).closest(companySearch.SECONDARY_ADDRESS_ROOT_SELECTOR)
                    );
                }
            );
            this.watchCapturedIdentity();
            this.enableManualCompanyId();
            const setTwoTelephone = (e) => customerData.set('shippingTelephone', e.target.value);
            $.async(self.shippingTelephoneSelector, function (telephoneSelector) {
                $(telephoneSelector).on('change keyup', setTwoTelephone);
                const telephone = $(self.shippingTelephoneSelector).val();
                customerData.set('shippingTelephone', telephone);
            });
        },
        /**
         * The address form's currently selected country, lower-cased, or ''
         * when the select is absent or has no value.
         *
         * Guarded rather than `.val().toLowerCase()`: jQuery's `.val()` on an
         * empty set is `undefined`, and this runs from a `$.async` callback
         * whose node can be replaced by a re-render between resolve and call.
         *
         * @returns {string}
         */
        currentCountryCode: function () {
            const value = $(this.countrySelector).val();
            return typeof value === 'string' ? value.toLowerCase() : '';
        },
        /**
         * The buyer changed the address country mid-checkout (TWO-24867).
         *
         * Everything the company search produced belongs to the country it was
         * searched in: the company itself, its organisation number, and the
         * address autofilled from the registry entry. None of it describes the
         * new country, and none of it is re-derivable — so a switch has to
         * retract it rather than leave it on screen looking chosen.
         *
         * The concrete failure this closes is not cosmetic. `companyData` is a
         * localStorage customer-data section and the payment tile credit-checks
         * whatever is in it, so a GB organisation number survived a switch to
         * ES and was submitted under an ES billing address — refused upstream,
         * and surfaced to the buyer as a generic failure with nothing on screen
         * explaining which field was wrong.
         *
         * The picker is not touched here at all: it belongs to the page-level
         * capture component, which watches the same select and re-resolves the
         * country per request.
         */
        onCountryChanged: function () {
            const countryCode = this.currentCountryCode();
            const previous = this._lastCountryCode;
            this._lastCountryCode = countryCode;
            // `change` also fires for a re-render that re-selects the same
            // country, and Magento fires it once as the form initialises. Only
            // an actual change is a reason to discard a company — doing this on
            // a no-op would blank a returning customer's prefilled address the
            // moment their form loads.
            if (previous === countryCode) {
                this.toggleCompanyVisibility();
                return;
            }
            companySearch.revertAutofilledAddress();
            // Clears the name input, the number field, and the published
            // `companyData` section the payment tile reads — every surviving
            // copy of the previous country's company.
            this.setCompanyData();
            // …then propagate the country the buyer just chose. AFTER the two
            // clears above, deliberately: both of them run over the billing
            // address as well, and both consult the sync pin, so a country
            // written first would be a value they then had to judge as
            // already-ours mid-sequence.
            companySearch.mirrorFieldsToSecondaryAddresses(['country']);
            this.toggleCompanyVisibility();
        },
        toggleCompanyVisibility: function () {
            const countryCode = this.currentCountryCode();
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
            customerData.set('companyData', {
                companyId: companyId,
                companyName: companyName,
                // The country this company was captured in (TWO-24867).
                //
                // `companyData` is a localStorage section, so it outlives the
                // page and the order: a buyer who searched a GB company, left,
                // and came back to a checkout now sitting on an ES address
                // would otherwise have the GB organisation number read back and
                // credit-checked under ES. The in-page country-change reset
                // cannot reach that case — nothing changed in THIS page — so
                // the record has to say which country it belongs to and the
                // reader has to check.
                companyCountry: this.currentCountryCode()
            });
        },
        /**
         * Mirror a captured identity onto the address step: the published
         * section, the name input, the `company_id` field, and the label.
         *
         * @param {string} [companyId]
         * @param {string} [companyName]
         */
        setCompanyData: function (companyId = '', companyName = '') {
            console.debug({ logger: 'addressAutocomplete.setCompanyData', companyId, companyName });
            this._appliedCompanyId = companyId;
            this._appliedCompanyName = companyName;
            this.publishCompanyData(companyId, companyName);
            $('.select2-selection__rendered').text(companyName);
            $(this.companyNameSelector).val(companyName);
            this.setCompanyIdValue(companyId);
            this.syncCompanyIdEditable();
            this.renderCompanyIdText();
            // The company half of the two-address propagation. Every path that
            // establishes a company on the default address comes through here —
            // a registry pick, the manual-entry reset, the country-switch clear
            // — so this is the one place it has to be wired, and the pin inside
            // decides whether a given billing address accepts it.
            // The number travels with the name. Both are in the pin's field
            // set, and a field the pin JUDGES but the mirror never WRITES is a
            // field that can only ever freeze the address.
            companySearch.mirrorFieldsToSecondaryAddresses(['company', 'organization']);
        },
        /**
         * Write the captured organisation number so that it SURVIVES A PAGE
         * RELOAD, which a `$(…).val()` write on its own does not.
         *
         * The asymmetry this closes: after picking a company, a reload kept the
         * name on the form and lost the number. Nothing about the number was
         * being forgotten deliberately — it never reached the store the name
         * reaches.
         *
         * Magento persists the address form by listening for changes on the
         * `checkoutProvider`'s `shippingAddress` data
         * (`Magento_Checkout/js/view/shipping.js` → `checkoutData
         * .setShippingAddressFromData`), writes them to the `checkout-data`
         * localStorage section, and on the next load pushes the whole saved
         * object — `custom_attributes` included — back into the provider before
         * the fields render. So the provider is the persistence boundary, and
         * only a value the provider has SEEN is restored.
         *
         * The company NAME crosses that boundary for free: select2 fires a
         * native `change` on the input when a result is picked, `ui/form/
         * element/input`'s `value:` binding reads the DOM on `change`, and the
         * element's `value` observable is two-way linked to its provider path.
         * The company NUMBER had no such route — it is written by us, not by a
         * widget, and a jQuery `.val()` write raises no event Knockout listens
         * for. The provider therefore never learned the number, nothing was
         * saved, and there was nothing to restore.
         *
         * Writing through the component is what publishes to the provider; the
         * DOM write is kept for the same reason `setCompanyIdDisabled()` keeps
         * one — `uiRegistry.get()` yields nothing if this runs before the
         * component registers, and `needsManualCompanyId()` reads the field's
         * value straight off the DOM in the same tick.
         *
         * Deliberately NOT done by triggering `change` on the input instead:
         * that would also fire our own change handler, which republishes the
         * `companyData` customer-data section — and the payment step reads a
         * change NOTIFICATION on that section as an act of selection, so every
         * pick would announce itself twice.
         *
         * The DOM write goes FIRST. The component write notifies subscribers
         * synchronously, one of which re-renders the number label off
         * `capturedCompanyId()`, which reads the DOM before the component — so
         * writing the component first paints the PREVIOUS company's number for
         * the rest of the tick, and paints a number at all on the clearing path.
         */
        setCompanyIdValue: function (companyId) {
            $(this.companyIdSelector).val(companyId);
            const component = uiRegistry.get(this.companyIdComponent);
            if (component && typeof component.value === 'function') {
                component.value(companyId);
            }
        },
        /**
         * Re-paint the number label whenever the company-number component's
         * value changes underneath us.
         *
         * The one case this is for is a reload: the number is restored into the
         * form by Magento pushing the saved `shippingAddress` object back into
         * the `checkoutProvider`, and that push is not ordered against either of
         * this component's `$.async` resolves. If it lands last, both render
         * calls have already run against an empty field and the buyer sees the
         * name with no number — the original bug, in a narrower window.
         *
         * ONE subscription at a time, and it belongs to the CURRENT view. The
         * `company_id` uiRegistry component outlives this view — that is exactly
         * why `$.async` refires on a re-render while `uiRegistry.get()` keeps
         * returning the same object — so a subscription simply left in place
         * both retains every superseded view for the life of the page and leaves
         * the live subscriber closed over a stale one. Disposing the previous
         * subscription and taking a fresh one is what keeps those two properties
         * ("no stacking", "the current view renders") from being in tension. The
         * handle is stored on the COMPONENT, because the view being replaced is
         * the thing that cannot be relied on to still be around.
         *
         * Deliberately does NOT re-derive editability (`syncCompanyIdEditable`).
         * That derivation reads the number field's own value, and this fires on
         * the buyer's own `change` too — so re-deriving here would disable the
         * field the moment they blurred it, which is the exact footgun the
         * derivation's own docblock warns against. The cost is that a number
         * restored LATE leaves the field enabled when it should be disabled;
         * that is invisible today because the field is CSS-hidden
         * unconditionally, and it is the lesser of the two.
         *
         * Same reason this fires on a buyer's own edit at all: Knockout's
         * `value:` binding writes the observable on `change`. A hand-typed number
         * therefore gets country-checked against the stamp of the PREVIOUS,
         * searched company. Also unreachable while the field is CSS-hidden, and
         * recorded here rather than guarded because the guard's own subject —
         * a number restored from a previous visit — is the only thing that
         * reaches it today.
         */
        watchCompanyIdComponent: function () {
            const component = uiRegistry.get(this.companyIdComponent);
            if (!component || typeof component.value !== 'function') return;
            if (typeof component.value.subscribe !== 'function') return;
            if (
                component._twoCompanyIdSubscription &&
                typeof component._twoCompanyIdSubscription.dispose === 'function'
            ) {
                component._twoCompanyIdSubscription.dispose();
            }
            const self = this;
            component._twoCompanyIdSubscription = component.value.subscribe(function () {
                // Guard BEFORE render, and on this path specifically: the
                // one-shot guard on the `$.async` resolve runs while the field is
                // still empty, so a number restored late is a number the guard
                // has never seen. Without this the whole country check is dead on
                // exactly the ordering this subscription exists for.
                self.discardForeignCountryCompanyId();
                self.renderCompanyIdText();
            });
        },
        /**
         * Discard a restored organisation number that belongs to a different
         * country from the one the form is now showing (TWO-24867).
         *
         * The number now persists in `checkout-data` and is restored into the
         * form on the next load — which is the point of this fix — but that
         * saved blob carries no record of the country it was captured in, while
         * the `companyData` customer-data section does. Left unchecked, a GB
         * organisation number could be restored onto a checkout sitting on an ES
         * address and credit-checked there: refused upstream, and surfaced to
         * the buyer as a generic failure with nothing on screen explaining it.
         * `onCountryChanged` cannot reach this case, because nothing changed in
         * THIS page.
         *
         * Fails OPEN on a missing stamp, matching the same guard on the payment
         * step: records written before the stamp existed carry no country, and
         * treating "unstamped" as "wrong country" would drop a legitimate
         * company on the first load after an upgrade.
         *
         * Clears the number only, never the name. A name with no identifier is
         * an understood state — the payment step routes it to
         * `selectCompanyWithoutIdentifier()` and the order is refused
         * server-side — whereas a name silently blanked on load looks like data
         * loss.
         *
         * Re-entrant by construction and terminating: the clear runs through
         * `setCompanyIdValue()`, which notifies the component subscribers, one of
         * which calls this again — and under the input-first order that method
         * uses, the second pass reads empty from the input AND from the component
         * and returns at the first line. That is the whole of it; no reliance on
         * Knockout suppressing a same-value notification. Reverse those two
         * writes and termination would rest on that suppression instead, which is
         * a second reason not to (the first being the stale label).
         *
         * Reads the country off the SELECT, which makes this dependent on the
         * select being rendered and holding the restored country by the time the
         * number arrives. Two things hold that up rather than one, so it is
         * stated rather than assumed: `country_id` is forced to sort before
         * `company`/`street` (LayoutProcessorPlugin::moveCountryBeforeCompany,
         * for unrelated reasons) while this field sorts at 65, and an absent or
         * empty select fails open below. If a store ever did present the store
         * default here before the restore landed, this would discard a VALID
         * number persistently — the clear reaches `checkout-data` — so that
         * ordering is the thing to check first if a valid number ever goes
         * missing.
         */
        discardForeignCountryCompanyId: function () {
            // `capturedCompanyId()`, not the input alone. This runs immediately
            // before the render, off the same notification, and the render reads
            // the component when the input has not caught up — so an
            // input-only read here would bail out on exactly the ordering the
            // render refuses to assume, and the number it declined to check is
            // the one that then gets painted and left in the provider.
            if (!this.capturedCompanyId()) return;
            const capturedCountry = (customerData.get('companyData')() || {}).companyCountry;
            const currentCountry = this.currentCountryCode();
            if (!capturedCountry || !currentCountry) return;
            if (String(capturedCountry).toLowerCase() === String(currentCountry).toLowerCase()) {
                return;
            }
            console.debug({
                logger: 'addressAutocomplete.discardForeignCountryCompanyId',
                capturedCountry,
                currentCountry
            });
            this.setCompanyIdValue('');
        },
        /**
         * Class on the address-step company-number text label. Also the hook
         * style.css aligns it against the name field's end edge.
         */
        companyIdTextClass: 'two-company-id-text',
        /**
         * Render the captured company number as PLAIN TEXT under the
         * company-name field (TWO-25326 §5), replacing whatever was there.
         *
         * Not an input, and not a second copy of the hidden
         * `custom_attributes[company_id]` field: that input still exists and
         * still submits, but it is display:none'd outright
         * (`.two-company-id-hidden`) precisely because a visible box implies
         * the number is typeable, and it is not — an identifier-less company
         * is refused by Model/Two.php::authorize() rather than hand-filled.
         *
         * Three states, and only one of them shows anything:
         *
         *  - a captured identity -> the number, right-aligned;
         *  - nothing captured yet -> nothing (§5: "not visible before a result
         *    is selected");
         *  - manual-entry mode -> nothing, ever, whatever the number field
         *    happens to still hold. Manual entry is name-only capture per the
         *    three-mode model, so a number rendered here would be claiming a
         *    registry identity the buyer never picked.
         *
         * Keyed on the capture mode rather than on select2 being present: the
         * page-level component owns the mount and can bind it after a restored
         * number has already been rendered, with nothing to trigger a repaint.
         *
         * Rebuilt from scratch on each call rather than toggled: the label is
         * a single text node with no state worth preserving, and a `.remove()`
         * first means the manual-entry and pre-selection cases share the exact
         * same code path as "hide it".
         *
         * The caption is an `aria-label`, not visible text: §7 forbids any
         * additional visible text label in the address area, but a bare number
         * with no accessible name is unreadable to a screen reader.
         */
        renderCompanyIdText: function () {
            const $field = $(this.companyNameSelector);
            if (!$field.length) return;
            const $control = $field.closest('.control');
            if (!$control.length) return;
            $control.find('.' + this.companyIdTextClass).remove();
            if (identity.captureMode() === 'manual') return;
            // Through the shared display formatter, so an internal
            // `TWO:`-prefixed identifier renders NO label at all rather than
            // a label the buyer cannot make sense of (TWO-25326). Same
            // helper the dropdown row, the tile label and the order-intent
            // notice use — see companySearch.formatCompanyNumber().
            const companyId = companySearch.formatCompanyNumber(this.capturedCompanyId());
            if (!companyId) return;
            $control.append(
                $('<div></div>')
                    .addClass(this.companyIdTextClass)
                    .attr('aria-label', $t('Company Number'))
                    .text(companyId)
            );
        },
        /**
         * The organisation number currently captured, for DISPLAY.
         *
         * The DOM field first, then the UI component. Not an ordering
         * preference — the two are written together by setCompanyIdValue() and
         * agree — but an ordering that needs no assumption about which of them
         * a reload populates first. On the restore path Knockout's `value:`
         * binding copies the component's value into the input, and this is read
         * from a subscriber on that same observable, so "the DOM is already
         * updated" would be a claim about Knockout's internal subscription
         * order. Reading both removes the claim.
         *
         * Display only. `needsManualCompanyId()` still reads the DOM alone and
         * must keep doing so: it derives whether the buyer may TYPE here, and
         * the value they are mid-way through typing lives in the input.
         *
         * @returns {string}
         */
        capturedCompanyId: function () {
            const fromDom = $(this.companyIdSelector).val();
            if (fromDom) return fromDom;
            const component = uiRegistry.get(this.companyIdComponent);
            if (component && typeof component.value === 'function') {
                const value = component.value();
                return value == null ? '' : String(value);
            }
            return '';
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
                // Before the derivation and the label, both of which would
                // otherwise act on a number belonging to another country.
                self.discardForeignCountryCompanyId();
                self.syncCompanyIdEditable();
                // …and paint the number label for that same case. A reload
                // restores the number into this field from the provider, not
                // through setCompanyData(), so nothing else would render it.
                self.renderCompanyIdText();
                self.watchCompanyIdComponent();
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
        /**
         * Follow the page-level captured identity: this step no longer owns a
         * picker, but it still owns the `company_id` field, its persistence
         * through the checkoutProvider, and the `companyData` section.
         *
         * Deferred by one turn so that the name and the number — separate
         * observables, written back to back — are published together rather
         * than as a name under the previous company's number.
         */
        watchCapturedIdentity: function () {
            const self = this;
            // One live watcher, belonging to the CURRENT view: the identity
            // singleton outlives every re-render of this step, so a watcher
            // left in place would keep a superseded view painting the form.
            if (
                identity._addressStepWatcher &&
                typeof identity._addressStepWatcher.dispose === 'function'
            ) {
                identity._addressStepWatcher.dispose();
            }
            const captured = ko
                .computed(function () {
                    // Manual entry is the buyer's own typing, published by the
                    // company-number field's own change handler, so nothing is
                    // mirrored for it here.
                    if (identity.captureMode() === 'manual') {
                        return { companyId: '', companyName: '' };
                    }
                    return {
                        companyId: identity.companyId(),
                        companyName: identity.companyName()
                    };
                })
                .extend({ rateLimit: 0 });
            identity._addressStepWatcher = captured;
            captured.subscribe(function (value) {
                if (
                    value.companyId === self._appliedCompanyId &&
                    value.companyName === self._appliedCompanyName
                ) {
                    return;
                }
                self.setCompanyData(value.companyId, value.companyName);
            });
        }
    });
});
