/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503/TWO-25554: LUMA'S HALF of the shared company-capture controller.
 *
 * The controller is `company-capture-component.js` — framework-free, UMD, and
 * loaded unchanged by Hyvä. This module is the Magento-shaped adapter it is
 * constructed with: RequireJS, the quote model, `$.async`, `mage/url` and the
 * message list, each reduced to the one function the controller asks for.
 *
 * TWO-25554 split the one instance into two — one for the shipping address
 * panel, one for a distinct billing address panel — plus a resolver deciding
 * which one's capture is the buyer's actual paying-as company. Nothing here
 * decides what a mode means or how a mount is chosen; that lives in the
 * shared file. This file only decides WHERE each of the two lives on Luma's
 * DOM and which panel wins.
 */
define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'mage/translate',
    'Magento_Ui/js/model/messageList',
    'Two_Gateway/js/model/brand-config',
    'Two_Gateway/js/model/company-identity',
    'Two_Gateway/js/model/company-search',
    'Two_Gateway/js/model/company-search-panel',
    'Two_Gateway/js/model/company-capture-component',
    'Two_Gateway/js/model/company-source-resolver',
    'Two_Gateway/js/model/sole-trader'
], function (
    $,
    quote,
    url,
    $t,
    messageList,
    brandConfig,
    createCompanyIdentity,
    companySearch,
    CompanySearchPanel,
    CompanyCaptureComponent,
    CompanySourceResolver,
    SoleTrader
) {
    'use strict';

    /** The address step's form — core's own markup. */
    const ADDRESS_FORM_ROOT = '#shipping-new-address-form';

    /** The address step's company field. */
    const ADDRESS_FIELD_SELECTOR = `${ADDRESS_FORM_ROOT} input[name="company"]`;

    /** The country select sitting alongside that company field. */
    const ADDRESS_COUNTRY_SELECTOR = `${ADDRESS_FORM_ROOT} select[name="country_id"]`;

    /** The payment tile's company field. One per Two-family brand tile. */
    const TILE_FIELD_SELECTOR = '#two_gateway_form input#company_name';

    /**
     * The billing form core renders per payment method once "my billing
     * address is the same as shipping" is unchecked. Multi-tile checkouts
     * (more than one Two-family brand rendering its own billing form) are not
     * supported here — confirmed dead (TWO-25554) — so exactly 0 or 1 match
     * is assumed throughout.
     */
    const BILLING_FORM_ROOT = companySearch.SECONDARY_ADDRESS_ROOT_SELECTOR;

    /** The billing panel's own company field. Never falls back to the tile. */
    const BILLING_FIELD_SELECTOR = `${BILLING_FORM_ROOT} input[name="company"]`;

    /** The country select inside that SAME billing form — never a shared one. */
    const BILLING_COUNTRY_SELECTOR = `${BILLING_FORM_ROOT} select[name="country_id"]`;

    /** Any address form's country select, shipping or billing. */
    const COUNTRY_SELECT_SELECTOR = 'select[name="country_id"]';

    /** "My billing and shipping address are the same" — core's own checkbox. */
    const BILLING_TOGGLE_SELECTOR = 'input[name="billing-address-same-as-shipping"]';

    const brandCode = brandConfig.getActiveTwoBrandCode();
    const config = brandCode ? brandConfig(brandCode) : null;

    const shippingIdentity = createCompanyIdentity();
    const billingIdentity = createCompanyIdentity();
    /** The identity every downstream consumer (order-intent, the tile) reads. */
    const resolvedIdentity = createCompanyIdentity();

    /**
     * Present AND visible — never merely present. Core can leave the billing
     * form in the DOM hidden once "same as shipping" is re-checked
     * (TWO-25461's own finding), and a hidden field is neither a live mount
     * nor a distinct address.
     *
     * `.is` is feature-detected rather than assumed: real jQuery always has
     * it, but this runs against several deliberately minimal jQuery-shaped
     * test doubles across this module's own suite that model presence only
     * and have no notion of visibility at all — for those, presence is the
     * best answer available, same as it always was before this field's
     * visibility distinction existed.
     *
     * @param {object} $field a jQuery(-shaped) set
     * @returns {boolean}
     */
    function isVisible($field) {
        if (!$field.length) return false;
        return typeof $field.is === 'function' ? $field.is(':visible') : true;
    }

    /** Is billing currently a distinct address from shipping? @returns {boolean} */
    function billingIsDistinct() {
        return isVisible($(BILLING_FIELD_SELECTOR));
    }

    /**
     * The country select the SHIPPING/tile mount answers to.
     *
     * Mounted in the address form, that is the selector beside it. Mounted on
     * the payment tile, which has no address fields of its own, it is the one in
     * the form holding the buyer's invoice address — the billing form where the
     * buyer unchecked "same as shipping", the shipping form where they did not
     * (TWO-25461 §1(a.3)). The billing panel below has no equivalent branching:
     * it only ever exists at its own form and always reads its own country.
     *
     * @param {string} mountSelector
     * @returns {?object} jQuery set, or `null` when no form can answer yet
     */
    function shippingAdjacentCountrySelect(mountSelector) {
        // For a buyer with saved addresses core renders the shipping form —
        // company field, country select and all — inside the hidden new-address
        // modal, holding store defaults nobody chose.
        const hasShippingForm = companySearch.hasPrimaryAddressForm();
        if (mountSelector === ADDRESS_FIELD_SELECTOR) {
            return hasShippingForm ? $(ADDRESS_COUNTRY_SELECTOR) : null;
        }
        // The same form the tile's own address write-back targets, so the
        // country searched and the address written can never disagree.
        const $root = companySearch.billingRoleFormRoot();
        if (!$root) return null;
        if (!hasShippingForm && $root.is && $root.is(ADDRESS_FORM_ROOT)) return null;
        return $root.find(COUNTRY_SELECT_SELECTOR);
    }

    /**
     * Read a jQuery-wrapped country `<select>`'s value, lower-cased.
     *
     * @param {?object} $select
     * @returns {?string} `null` when there is no such select at all
     */
    function readCountry($select) {
        if (!$select || !$select.length) return null;
        const selected = $select.val();
        return typeof selected === 'string' ? selected : '';
    }

    /**
     * Members every panel's host options share verbatim — the buyer, the
     * transport, and everything that is not "where do I live / what do I
     * write into".
     *
     * @returns {object}
     */
    function sharedHostOptions() {
        return {
            config: config,
            Panel: CompanySearchPanel,
            SoleTraderFlow: SoleTrader,
            search: companySearch,
            translate: $t,
            observe: function (selector, onNode) {
                $.async(selector, onNode);
            },
            isVirtualCart: function () {
                return !!quote.isVirtual();
            },
            getQuoteCountry: function () {
                const billing = quote.billingAddress();
                const fromQuote = billing && billing.countryId;
                return typeof fromQuote === 'string' ? fromQuote.toLowerCase() : '';
            },
            getFallbackCountry: function () {
                return companySearch.currentAddressFormCountry() || '';
            },
            watchCountryChanges: function (onChange) {
                // Delegated off the document rather than bound to the node: every
                // checkout re-renders its address form freely, and delegation
                // survives that with no re-resolution. Shared by both panels —
                // each resolves ITS OWN adjacent country off the changed value via
                // its own getAdjacentCountry(), per TWO-25461 §1(a.3).
                $(document).on('change.twoCompanyCapture', COUNTRY_SELECT_SELECTOR, function (event) {
                    onChange(String($(event.target).val() || '').toLowerCase());
                });
                // A buyer who accepts the default country never fires `change`, and
                // sole-trader availability would stay unresolved.
                $.async(COUNTRY_SELECT_SELECTOR, function () {
                    onChange();
                });
            },
            supportedCompanyTypesUrl: function (country) {
                return url.build(`rest/V1/two/supported-company-types/${encodeURIComponent(country)}`);
            },
            clearField: function (selector) {
                // `change`, not just `val('')`: Knockout's `value:` binding reads the
                // DOM on change only, so without it the buyer sees an empty box while
                // the quote still carries the company they searched for.
                $(selector).val('').trigger('change').trigger('focus');
            },
            tokensUrl: function () {
                return url.build('rest/V1/two/get-tokens');
            },
            quoteId: function () {
                return quote.getQuoteId();
            },
            apiClientParams: function (companyConfig) {
                return companySearch.apiClientParams(companyConfig);
            },
            signupPrefill: function () {
                const billingAddress = quote.billingAddress() || {};
                const street = (billingAddress.street || []).filter((s) => s).join(', ').split(' ');
                return {
                    email: (billingAddress.email || quote.guestEmail || ''),
                    first_name: billingAddress.firstname,
                    last_name: billingAddress.lastname,
                    company_name: resolvedIdentity.companyName(),
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
            },
            signupCountry: function () {
                return ((quote.billingAddress() || {}).countryId || '').toUpperCase();
            },
            applyTelephone: function (phoneNumber) {
                companySearch.applyTelephone(phoneNumber);
            },
            showError: function (message) {
                messageList.addErrorMessage({ message: message });
            }
        };
    }

    /**
     * A sole-trader signup prompt scoped to ONE panel's own mount, so two
     * simultaneously-mounted panels never share (or fight over) one prompt
     * element (TWO-25554). `getComponent` is a thunk rather than a direct
     * reference because the component this prompt belongs to does not exist
     * yet at the point its own host options are built — same
     * self-reference-through-closure the rest of this file already relies on.
     *
     * Anchored OUTSIDE the search popover, after the field's wrapper:
     * entering sole-trader mode closes the popover, so a prompt inside it is
     * a route forward the buyer cannot see. The wrapper is always the bound
     * field's own parent (`CompanySearchPanel._ensureWrap()`), so `.parent()`
     * finds THIS panel's wrap with no class-based, page-wide query to
     * disambiguate.
     *
     * @param {function(): object} getComponent
     * @returns {function(boolean, function())}
     */
    function makeRenderSignupPrompt(getComponent) {
        return function (show, onRetry) {
            const panel = getComponent().panel();
            const fieldSelector = panel && panel.fieldSelector;
            const $wrap = fieldSelector ? $(fieldSelector).parent() : $();
            let $prompt = $wrap.length ? $wrap.next('.two-sole-trader-note') : $();
            if (!show) {
                $prompt.addClass('two-hidden');
                return;
            }
            if (!$prompt.length) {
                if (!$wrap.length) return;
                $prompt = $('<div></div>').addClass('two-sole-trader-note');
                $('<a href="#"></a>')
                    .addClass('two-sole-trader-note__link')
                    .text($t('Click here to log in or sign up as a Sole Trader.'))
                    .on('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        onRetry();
                    })
                    .appendTo($prompt);
                $prompt.insertAfter($wrap);
            }
            $prompt.removeClass('two-hidden');
        };
    }

    let shippingComponent;
    let billingComponent;

    /**
     * Where the SHIPPING panel's own writes land — never the billing panel's
     * form, even though `billingRoleFormRoot()` is happy to return it.
     *
     * `billingRoleFormRoot()` picks a billing candidate the moment ONE
     * EXISTS in the DOM, visible or not (see its own doc) — right for the
     * old single-instance design, where a hidden billing form was still the
     * best guess for "the buyer's one true address form" because nothing
     * else was mounted to disagree with it. TWO-25554 gave that form its own
     * dedicated panel, so on a checkout that pre-renders every payment
     * method's billing fieldset hidden from page load (Amasty's one-step
     * layout, unlike Luma's per-step render) `billingRoleFormRoot()` would
     * keep winning over shipping's own visible form even while the shipping
     * panel is the one in play, misdirecting its picks into billing's fields.
     *
     * Falls back to `billingRoleFormRoot()` only for the one case it was
     * always right for: shipping mounted on the payment-tile fallback, where
     * there is no shipping address form on this checkout to disambiguate
     * against at all.
     *
     * @returns {?object} jQuery set, or null
     */
    function shippingWriteRoot() {
        if (shippingComponent.mountSelector() === ADDRESS_FIELD_SELECTOR) {
            return $(ADDRESS_FORM_ROOT);
        }
        return companySearch.billingRoleFormRoot();
    }

    shippingComponent = new CompanyCaptureComponent(Object.assign(sharedHostOptions(), {
        identity: shippingIdentity,
        addressFieldSelector: ADDRESS_FIELD_SELECTOR,
        tileFieldSelector: TILE_FIELD_SELECTOR,
        fieldExists: function (selector) {
            return !!$(selector).length;
        },
        getAdjacentCountry: function (mountSelector) {
            return readCountry(shippingAdjacentCountrySelect(mountSelector));
        },
        applyCompanyAddress: function (selectedItem) {
            companySearch.lookupCompanyAddress(
                shippingComponent.config(),
                selectedItem,
                shippingWriteRoot(),
                shippingIdentity
            );
        },
        revertAutofilledAddress: function () {
            companySearch.revertAutofilledAddress();
        },
        applyBuyerAddress: function (source) {
            companySearch.applyAddress(source, shippingWriteRoot());
        },
        renderSignupPrompt: makeRenderSignupPrompt(function () { return shippingComponent; })
    }));

    billingComponent = new CompanyCaptureComponent(Object.assign(sharedHostOptions(), {
        identity: billingIdentity,
        addressFieldSelector: BILLING_FIELD_SELECTOR,
        // Never falls back to the tile: the tile is the shipping/no-address-
        // form mount's own fallback, and only one control may ever bind there.
        tileFieldSelector: '',
        fieldExists: function (selector) {
            if (!selector) return false;
            // See isVisible() and billingIsDistinct() above.
            return isVisible($(selector));
        },
        getAdjacentCountry: function () {
            return readCountry($(BILLING_COUNTRY_SELECTOR));
        },
        applyCompanyAddress: function (selectedItem) {
            companySearch.lookupCompanyAddress(
                billingComponent.config(),
                selectedItem,
                $(BILLING_FORM_ROOT),
                billingIdentity
            );
        },
        // Zero-arg by its own contract: it already reverts every mirrored
        // address root, primary and secondary alike, in one pass — see
        // company-search.js's own revertAutofilledAddress() doc.
        revertAutofilledAddress: function () {
            companySearch.revertAutofilledAddress();
        },
        applyBuyerAddress: function (source) {
            companySearch.applyAddress(source, $(BILLING_FORM_ROOT));
        },
        renderSignupPrompt: makeRenderSignupPrompt(function () { return billingComponent; })
    }));

    const resolver = new CompanySourceResolver({
        shipping: shippingIdentity,
        billing: billingIdentity,
        resolved: resolvedIdentity,
        billingIsDistinct: billingIsDistinct,
        watchBillingToggle: function (onChange) {
            // The checkbox itself, not a form re-render: core toggles the
            // billing form's visibility without necessarily rebuilding it.
            $(document).on(
                'change.twoCompanySourceResolver',
                BILLING_TOGGLE_SELECTOR,
                onChange
            );
            // Same reasoning as watchCountryChanges() above: the resolver
            // needs an initial answer, not just a stream of later toggles,
            // and CompanySourceResolver.start() already calls recompute()
            // once for that — this only covers a LATER DOM appearance of the
            // billing form/checkbox that the initial recompute() ran before.
            $.async(BILLING_FIELD_SELECTOR, onChange);
        }
    });

    // See CompanySourceResolver.connect()'s own doc: unconditional, unlike
    // the two components' own start() and unlike watchBillingToggle() below.
    resolver.connect();

    return {
        /** The identity order-intent, `Service\Order` and the tile all read. */
        identity: resolvedIdentity,
        shipping: shippingComponent,
        billing: billingComponent,
        start: function () {
            shippingComponent.start();
            billingComponent.start();
            resolver.watchBillingToggle();
            // watchForMountHost() (company-capture-component.js) mounts the
            // instant a node matching its selector APPEARS — a one-shot
            // check, never re-run on a later visibility change alone. Luma
            // only inserts the billing fieldset once "same as shipping" is
            // unchecked, so that appearance IS the checkbox toggle. Amasty's
            // one-step layout renders every payment method's billing
            // fieldset hidden from page load, so the node already exists
            // before the toggle ever fires — the one-shot check runs and
            // fails while it is still hidden, and nothing re-drives it when
            // the buyer later reveals it. The checkbox is the one event that
            // actually means "check again".
            $(document).on('change.twoCompanyCaptureMount', BILLING_TOGGLE_SELECTOR, function () {
                shippingComponent.refreshMount();
                billingComponent.refreshMount();
            });
        },
        refreshMount: function () {
            shippingComponent.refreshMount();
            billingComponent.refreshMount();
        },
        onCountryChanged: function () {
            shippingComponent.onCountryChanged();
            billingComponent.onCountryChanged();
        }
    };
});
