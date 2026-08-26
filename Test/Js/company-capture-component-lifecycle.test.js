/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503 — the guarantees the page-level rewrite exists to provide.
 *
 * The previous attempt was payment-tile-scoped, and three review rounds kept
 * surfacing the same defect wearing different hats: state whose lifetime was
 * per-render standing in for something page-level. Every case here fails
 * against that architecture and passes against this one, so they are the
 * regression guard on the container itself rather than on any one behaviour.
 *
 * The invariants:
 *  - the component is constructed once per page, by the boot component alone;
 *  - a payment-tile re-render takes nothing with it;
 *  - exactly one search control exists, re-pointed rather than duplicated;
 *  - the merchant setting decides WHERE the control mounts, never whether the
 *    buyer can capture a company at all.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

const COMPONENT = 'view/frontend/web/js/model/company-capture-component.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const BOOT = 'view/frontend/web/js/view/company-search-boot.js';
const LAYOUT = 'view/frontend/layout/checkout_index_index.xml';

const ADDRESS_FIELD = '#shipping-new-address-form input[name="company"]';
const TILE_FIELD = '#two_gateway_form input#company_name';

function readSource(relPath) {
    return fs.readFileSync(path.resolve(__dirname, '..', '..', relPath), 'utf8');
}

/**
 * Load the component against the real jsdom document, recording how many
 * search controls it ever constructs.
 *
 * Loaded fresh per test: the component and the identity are page-level
 * singletons, so a shared load would carry one case's state into the next.
 *
 * @param {object} [options] `{ isCompanySearchEnabled, isVirtual }`
 * @returns {object} `{ component, identity, controls, soleTrader }`
 */
function load(options) {
    const opts = options || {};
    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });
    const controls = [];
    const soleTrader = { instances: 0, listeners: 0, ensured: 0 };

    const ControlStub = function (config) {
        const record = { fieldSelector: config.fieldSelector, binds: [], destroys: 0 };
        controls.push(record);
        this.bind = function (bindOptions) { record.binds.push(bindOptions || {}); };
        this.destroy = function () { record.destroys += 1; return true; };
        this.abortActiveRequest = function () {};
        this.showSearchForCompanyLink = function () {};
        this.hideSearchForCompanyLink = function () {};
        this.isBound = function () { return record.binds.length > 0; };
        this.getField = function () { return $(); };
        Object.defineProperty(this, 'fieldSelector', {
            get: function () { return record.fieldSelector; },
            set: function (next) { record.fieldSelector = next; }
        });
    };
    const SoleTraderStub = function () {
        soleTrader.instances += 1;
        this.listenForSignupResult = function () { soleTrader.listeners += 1; };
        this.ensureTokens = function () { soleTrader.ensured += 1; return Promise.resolve(true); };
        this.launchSignup = function () { return {}; };
        this.forgetAdoptions = function () {};
    };

    const component = loadAmdModule(
        COMPONENT,
        {
            jquery: $,
            'Two_Gateway/js/model/company-identity': identity,
            'Two_Gateway/js/model/company-search-control': ControlStub,
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandConfig: function () {
                    return {
                        isCompanySearchEnabled: opts.isCompanySearchEnabled !== false,
                        checkoutApiUrl: 'https://api.example',
                        checkoutPageUrl: 'https://checkout.example',
                        supportedCompanyTypes: {}
                    };
                }
            },
            'Magento_Checkout/js/model/quote': Object.assign(
                {},
                defaultMocks()['Magento_Checkout/js/model/quote'],
                {
                    isVirtual: function () { return !!opts.isVirtual; },
                    billingAddress: function () { return { countryId: 'GB' }; }
                }
            ),
            'Two_Gateway/js/model/company-search': Object.assign(
                {},
                defaultMocks()['Two_Gateway/js/model/company-search'],
                {
                    currentAddressFormCountry: function () { return 'gb'; },
                    revertAutofilledAddress: function () {},
                    billingRoleFormRoot: function () { return null; },
                    applyAddress: function () {},
                    lookupCompanyAddress: function () {}
                }
            )
        },
        { document: document, window: window }
    );
    return {
        component: component,
        identity: identity,
        controls: controls,
        soleTrader: soleTrader
    };
}

/** The payment tile's company field. */
function mountTile() {
    document.body.innerHTML +=
        '<form id="two_gateway_form"><div class="field"><div class="control">' +
        '<input id="company_name" name="company_name" /></div></div></form>';
}

/** The address step's company field, as core renders it. */
function mountAddressForm() {
    document.body.innerHTML =
        '<form id="shipping-new-address-form"><div class="field"><div class="control">' +
        '<input name="company" /></div></div></form>' + document.body.innerHTML;
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('the component is constructed once per page', () => {
    test('the layout declares the boot component exactly once', () => {
        const declarations =
            readSource(LAYOUT).match(/Two_Gateway\/js\/view\/company-search-boot/g) || [];
        expect(declarations).toHaveLength(1);
    });

    test('the boot component is the only thing that starts it', () => {
        expect(readSource(BOOT)).toContain('.start()');
        const others = [
            'view/frontend/web/js/view/payment/method-renderer/gateway_method.js',
            'view/frontend/web/js/view/address-autocomplete.js'
        ];
        others.forEach((relPath) => {
            expect(readSource(relPath)).not.toContain('.start()');
        });
    });

    test('the module exports one shared instance, not a constructor', () => {
        mountTile();
        const { component } = load();
        // A constructor would hand every caller its own component, which is
        // the architecture this replaced.
        expect(typeof component).toBe('object');
        expect(typeof component.start).toBe('function');
    });

    test('a second start() builds no second control and binds no second listener', () => {
        mountTile();
        const { component, controls, soleTrader } = load();

        component.start();
        component.start();
        component.start();

        expect(controls).toHaveLength(1);
        expect(soleTrader.instances).toBe(1);
        expect(soleTrader.listeners).toBe(1);
    });
});

describe('a payment-tile re-render takes nothing with it', () => {
    test('the captured identity survives the tile being torn down and rebuilt', () => {
        mountTile();
        const { component, identity } = load();
        component.start();
        identity.write({ companyName: 'Example Ltd', companyId: '12345678' });

        // What a totals change does to the tile: the whole subtree goes and
        // comes back.
        document.body.innerHTML = '';
        mountTile();
        component.refreshMount();

        expect(identity.companyName()).toBe('Example Ltd');
        expect(identity.companyId()).toBe('12345678');
        expect(identity.isCaptured()).toBe(true);
    });

    test('sole-trader mode and its adoption survive the same re-render', () => {
        mountTile();
        const { component, identity } = load();
        component.start();
        identity.captureMode('soletrader');
        identity.soleTraderAdopted(true);

        document.body.innerHTML = '';
        mountTile();
        component.refreshMount();

        expect(identity.isSoleTrader()).toBe(true);
        expect(identity.soleTraderAdopted()).toBe(true);
    });

    test('the chips come back after a re-render, from the one component', () => {
        mountTile();
        const { component } = load();
        component.start();

        document.body.innerHTML = '';
        mountTile();
        component.refreshMount();

        expect(document.querySelectorAll('.two-company-mode-chips')).toHaveLength(1);
        expect(document.querySelectorAll('.two-company-mode-chip')).toHaveLength(3);
    });

    test('the sole-trader flow is not rebuilt, so its tokens and popup handle persist', () => {
        mountTile();
        const { component, soleTrader } = load();
        component.start();

        document.body.innerHTML = '';
        mountTile();
        component.refreshMount();

        expect(soleTrader.instances).toBe(1);
    });
});

describe('exactly one search control exists', () => {
    test('moving between mounts re-points the same control rather than building a second', () => {
        mountAddressForm();
        mountTile();
        const { component, controls } = load();
        component.start();
        expect(controls).toHaveLength(1);
        expect(controls[0].fieldSelector).toBe(ADDRESS_FIELD);

        // The address form goes away — a buyer picking a saved address.
        $('#shipping-new-address-form').remove();
        component.refreshMount();

        expect(controls).toHaveLength(1);
        expect(controls[0].fieldSelector).toBe(TILE_FIELD);
        expect(controls[0].binds.length).toBeGreaterThan(1);
    });

    test('re-pointing at the mount already bound does not re-bind', () => {
        mountTile();
        const { component, controls } = load();
        component.start();
        const bindsAfterStart = controls[0].binds.length;

        component.refreshMount();
        component.refreshMount();

        expect(controls[0].binds).toHaveLength(bindsAfterStart);
    });
});

describe('the merchant setting decides WHERE, never WHETHER', () => {
    test.each([
        [true, false, ADDRESS_FIELD, 'search in address entry ON, address form present'],
        [false, false, TILE_FIELD, 'search in address entry OFF, so the tile is home'],
        [true, true, TILE_FIELD, 'a virtual cart renders no shipping form']
    ])(
        'enabled=%p virtual=%p mounts at %s (%s)',
        (isCompanySearchEnabled, isVirtual, expected) => {
            mountAddressForm();
            mountTile();
            const { component } = load({
                isCompanySearchEnabled: isCompanySearchEnabled,
                isVirtual: isVirtual
            });
            component.start();

            expect(component.mountSelector()).toBe(expected);
        }
    );

    test('a saved address leaves the tile as the only route to a company', () => {
        // No address-step form exists on this checkout, and without a route
        // the order is refused server-side for want of a company number.
        mountTile();
        const { component } = load({ isCompanySearchEnabled: true });
        component.start();

        expect(component.mountSelector()).toBe(TILE_FIELD);
    });
});
