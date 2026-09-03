/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * TWO-25503/TWO-25554: LUMA'S HALF of the shared company-capture controller.
 *
 * The controller is `company-capture-component.js` — framework-free, UMD, and
 * loaded unchanged by Hyvä. This module is the Magento-shaped adapter it is
 * constructed with, and it decides only WHERE each of the two panels (shipping
 * address, distinct billing address) lives on Luma's DOM.
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
     * address is the same as shipping" is unchecked. Multi-tile checkouts are
     * confirmed dead (TWO-25554), so 0 or 1 match is assumed throughout.
     */
    const BILLING_FORM_ROOT = companySearch.SECONDARY_ADDRESS_ROOT_SELECTOR;

    /** The billing panel's own company field. Never falls back to the tile. */
    const BILLING_FIELD_SELECTOR = `${BILLING_FORM_ROOT} input[name="company"]`;

    /** The country select inside that SAME billing form — never a shared one. */
    const BILLING_COUNTRY_SELECTOR = `${BILLING_FORM_ROOT} select[name="country_id"]`;

    /** "My billing and shipping address are the same" — core's own checkbox. */
    const BILLING_TOGGLE_SELECTOR = 'input[name="billing-address-same-as-shipping"]';

    /** @see soleAddressForm — what makes a container an address form. */
    const ADDRESS_STREET_SELECTOR = 'input[name="street[0]"]';

    /** @see soleAddressForm — the element kinds a checkout wraps one in. */
    const ADDRESS_FORM_CONTAINER_SELECTOR = 'form, fieldset, [data-form]';

    /** @see soleAddressForm — the second field that makes one a whole address. */
    const ADDRESS_CITY_SELECTOR = 'input[name="city"]';

    const brandCode = brandConfig.getActiveTwoBrandCode();
    const config = brandCode ? brandConfig(brandCode) : null;

    const shippingIdentity = createCompanyIdentity();
    const billingIdentity = createCompanyIdentity();
    /** The identity every downstream consumer (order-intent, the tile) reads. */
    const resolvedIdentity = createCompanyIdentity();

    /**
     * Present AND visible — never merely present. Core leaves the billing form
     * in the DOM hidden once "same as shipping" is re-checked, and a hidden
     * field is neither a live mount nor a distinct address.
     *
     * `.is` is feature-detected: jQuery-shaped test doubles model presence
     * only, and presence is the best answer available for those.
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
     * The country select the SHIPPING/tile mount answers to — its OWN form's,
     * never the billing form's, whose switches belong to the other panel
     * (TWO-25554). With no shipping form in play there is no adjacent country
     * at all, and `null` sends the component to the quote instead.
     *
     * @returns {?object} jQuery set, or `null` when no form can answer yet
     */
    function shippingAdjacentCountrySelect() {
        // For a buyer with saved addresses core renders the shipping form —
        // company field, country select and all — inside the hidden new-address
        // modal, holding store defaults nobody chose.
        return companySearch.hasPrimaryAddressForm() ? $(ADDRESS_COUNTRY_SELECTOR) : null;
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
     * One panel's own country listener, for the selector of ITS OWN form's
     * select — never a shared `select[name="country_id"]` delegation, which
     * would answer for the other panel's form too (TWO-25554).
     *
     * @param {string} selectSelector
     * @returns {function(function(string))}
     */
    function makeWatchCountryChanges(selectSelector) {
        return function (onChange) {
            // Delegated off the document rather than bound to the node: every
            // checkout re-renders its address form freely, and delegation
            // survives that with no re-resolution.
            $(document).on('change.twoCompanyCapture', selectSelector, function (event) {
                onChange(String($(event.target).val() || '').toLowerCase());
            });
            // A buyer who accepts the default country never fires `change`, and
            // sole-trader availability would stay unresolved.
            $.async(selectSelector, function () {
                onChange();
            });
        };
    }

    /**
     * The hosted signup's prefill, carrying the company of the panel the buyer
     * opened it from — never the resolved one, which is the other panel's
     * capture whenever that panel wins (TWO-25554).
     *
     * @param {object} identity
     * @returns {function(): object}
     */
    function makeSignupPrefill(identity) {
        return function () {
            const billingAddress = quote.billingAddress() || {};
            const street = (billingAddress.street || []).filter((s) => s).join(', ').split(' ');
            return {
                email: (billingAddress.email || quote.guestEmail || ''),
                first_name: billingAddress.firstname,
                last_name: billingAddress.lastname,
                company_name: identity.companyName(),
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
        };
    }

    /**
     * Members every panel's host options share verbatim — the buyer, the
     * transport, and everything that is not "where do I live / what do I
     * write into". Everything panel-scoped — the country it reads, the form it
     * writes, the identity it prefills a signup from — is supplied per panel
     * below instead.
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
            signupCountry: function () {
                return ((quote.billingAddress() || {}).countryId || '').toUpperCase();
            },
            showError: function (message) {
                messageList.addErrorMessage({ message: message });
            }
        };
    }

    /**
     * A sole-trader signup prompt scoped to ONE panel's own mount, so two
     * simultaneously-mounted panels never share one prompt element
     * (TWO-25554). `getComponent` is a thunk because the component this prompt
     * belongs to does not exist yet when its host options are built.
     *
     * Anchored OUTSIDE the search popover, after the field's wrapper: entering
     * sole-trader mode closes the popover, so a prompt inside it is a route
     * forward the buyer cannot see.
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
     * The one address form a checkout supplying its own markup renders, or
     * `null` when that cannot be established.
     *
     * Either core form present means the panels' own forms are in play and this
     * is not that checkout. Beyond them an address form is recognised by its
     * first street line, and the answer stands only while there is exactly one
     * — anything else is a choice between destinations rather than a
     * destination (TWO-25554).
     *
     * @returns {?object} jQuery set, or null
     */
    function soleAddressForm() {
        if ($(ADDRESS_FORM_ROOT).length || $(BILLING_FORM_ROOT).length) return null;
        const $streets = $(ADDRESS_STREET_SELECTOR);
        if ($streets.length !== 1) return null;
        // `closest()` answers with the NEAREST container, which on a themed
        // checkout is often a row holding the street line alone — a write scoped
        // there fills in a street and nothing else. The city is what makes the
        // container the whole address form.
        const $form = $streets.closest(ADDRESS_FORM_CONTAINER_SELECTOR);
        if (!$form.length) return null;
        return $form.find(ADDRESS_CITY_SELECTOR).length ? $form : null;
    }

    /**
     * Where the SHIPPING panel's own writes land, or `null` when there is no
     * single destination for them.
     *
     * Its own form while it is mounted there. Tile-mounted it has no form of its
     * own, and the checkout's own markup is the only address form there is —
     * tileIsShippingPanels() already means no billing panel is mounted, and
     * soleAddressForm() refuses while either core form exists, so the write
     * cannot reach a second panel's fields (TWO-25554). The sole-trader address
     * and phone write-back is owed to the buyer wherever the control is mounted
     * (TWO-25461 §5), so refusing outright there fills nothing in and says
     * nothing.
     *
     * @returns {?object} jQuery set, or null
     */
    function shippingWriteRoot() {
        const mount = shippingComponent.mountSelector();
        if (mount === ADDRESS_FIELD_SELECTOR) return $(ADDRESS_FORM_ROOT);
        if (mount !== TILE_FIELD_SELECTOR) return null;
        return soleAddressForm();
    }

    /**
     * shippingWriteRoot(), and a notice on the shipping identity when there is
     * none — a pick that fills nothing in and says nothing reads to the buyer
     * as the picker having done nothing (TWO-25461 §5).
     *
     * @returns {?object} jQuery set, or null
     */
    function shippingWriteTarget() {
        const root = shippingWriteRoot();
        if (!root) companySearch.announceAddressUndeliverable(shippingIdentity);
        return root;
    }

    /**
     * Where the BILLING panel's own writes land, or `null` when there is no
     * destination for them.
     *
     * Its own form and only ever its own form: it has no tile fallback and the
     * shipping form is the other panel's (TWO-25554). Keyed on the live mount
     * rather than on the selector matching, because core leaves the fieldset in
     * the DOM hidden once "same as shipping" is re-checked, and a hidden field
     * is nowhere the buyer can read what was written.
     *
     * @returns {?object} jQuery set, or null
     */
    function billingWriteRoot() {
        if (billingComponent.mountSelector() !== BILLING_FIELD_SELECTOR) return null;
        const $root = $(BILLING_FORM_ROOT);
        return $root.length ? $root : null;
    }

    /**
     * billingWriteRoot(), and a notice on the billing identity when there is
     * none — shippingWriteTarget()'s counterpart, for the same reason.
     *
     * @returns {?object} jQuery set, or null
     */
    function billingWriteTarget() {
        const root = billingWriteRoot();
        if (!root) companySearch.announceAddressUndeliverable(billingIdentity);
        return root;
    }

    /**
     * One control per field: a mounted billing panel is already the buyer's
     * route to supply a company, and a second required one the resolver ignores
     * cannot be completed at all (TWO-25554).
     *
     * @returns {boolean}
     */
    function tileIsShippingPanels() {
        return !billingComponent.mountSelector();
    }

    shippingComponent = new CompanyCaptureComponent(Object.assign(sharedHostOptions(), {
        identity: shippingIdentity,
        addressFieldSelector: ADDRESS_FIELD_SELECTOR,
        addressFormRootSelector: ADDRESS_FORM_ROOT,
        tileFieldSelector: TILE_FIELD_SELECTOR,
        fieldExists: function (selector) {
            if (selector === TILE_FIELD_SELECTOR && !tileIsShippingPanels()) return false;
            return !!$(selector).length;
        },
        getAdjacentCountry: function () {
            return readCountry(shippingAdjacentCountrySelect());
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
            companySearch.revertAutofilledAddress(shippingWriteRoot(), shippingIdentity);
        },
        applyBuyerAddress: function (source) {
            companySearch.applyAddress(source, shippingWriteTarget(), shippingIdentity);
        },
        applyTelephone: function (phoneNumber) {
            companySearch.applyTelephone(phoneNumber, shippingWriteTarget());
        },
        getFallbackCountry: function () {
            return companySearch.hasPrimaryAddressForm()
                ? companySearch.currentAddressFormCountry($(ADDRESS_FORM_ROOT))
                : '';
        },
        watchCountryChanges: makeWatchCountryChanges(ADDRESS_COUNTRY_SELECTOR),
        signupPrefill: makeSignupPrefill(shippingIdentity),
        renderSignupPrompt: makeRenderSignupPrompt(function () { return shippingComponent; })
    }));

    billingComponent = new CompanyCaptureComponent(Object.assign(sharedHostOptions(), {
        identity: billingIdentity,
        addressFieldSelector: BILLING_FIELD_SELECTOR,
        addressFormRootSelector: BILLING_FORM_ROOT,
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
                billingWriteRoot(),
                billingIdentity
            );
        },
        revertAutofilledAddress: function () {
            companySearch.revertAutofilledAddress(billingWriteRoot(), billingIdentity);
        },
        applyBuyerAddress: function (source) {
            companySearch.applyAddress(source, billingWriteTarget(), billingIdentity);
        },
        applyTelephone: function (phoneNumber) {
            companySearch.applyTelephone(phoneNumber, billingWriteTarget());
        },
        getFallbackCountry: function () {
            return companySearch.currentAddressFormCountry($(BILLING_FORM_ROOT));
        },
        watchCountryChanges: makeWatchCountryChanges(BILLING_COUNTRY_SELECTOR),
        signupPrefill: makeSignupPrefill(billingIdentity),
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
        /**
         * The identity that speaks for the quote's BILLING address — one
         * destination, chosen before the seed is written.
         *
         * The billing panel while billing is a distinct address. Otherwise
         * billing IS shipping, and the shipping identity is the only capture
         * the resolver reads (company-source-resolver.js); seeding the billing
         * panel there discards a saved company outright.
         *
         * @returns {object}
         */
        billingRoleIdentity: function () {
            return billingIsDistinct() ? billingIdentity : shippingIdentity;
        },
        start: function () {
            shippingComponent.start();
            billingComponent.start();
            resolver.watchBillingToggle();
            // watchForMountHost() mounts on a node APPEARING, a one-shot check
            // that never re-runs on a later visibility change. Amasty's
            // one-step layout renders every payment method's billing fieldset
            // hidden from page load, so the checkbox is the only event that
            // means "check again".
            $(document).on('change.twoCompanyCaptureMount', BILLING_TOGGLE_SELECTOR, function () {
                // The checkbox is the buyer saying so, which a re-render of the
                // billing fieldset is not: billing being shipping again retires
                // what the billing panel captured, and nothing else does.
                if (!billingIsDistinct()) billingIdentity.clear();
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
