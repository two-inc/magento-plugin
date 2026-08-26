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
 *  - exactly one search panel exists, re-pointed rather than duplicated;
 *  - the merchant setting decides WHERE the panel mounts, never whether the
 *    buyer can capture a company at all.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const $ = require('jquery');
const {
    loadAmdModule,
    loadCompanySearchPanel,
    defaultMocks,
    installAsyncSimulation
} = require('./amd-harness');

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
 * search panels it ever constructs.
 *
 * The panel is the REAL one, instrumented rather than stubbed: half of what
 * this suite asserts — the chips coming back after a re-render, an observer not
 * stacking — is the panel's own DOM and bookkeeping, which a stub cannot show.
 *
 * Loaded fresh per test: the component and the identity are page-level
 * singletons, so a shared load would carry one case's state into the next.
 *
 * @param {object} [options] `{ isCompanySearchEnabled, isVirtual }`
 * @returns {object} `{ component, identity, panels, soleTrader }`
 */
function load(options) {
    const opts = options || {};
    // The component watches for its host node, so the suite needs the
    // observer simulation rather than real jQuery's absent `$.async`.
    installAsyncSimulation($);
    $.async.reset();
    const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });
    const panels = [];
    const soleTrader = { instances: 0, listeners: 0, ensured: 0 };

    const companySearchMock = Object.assign(
        {},
        defaultMocks()['Two_Gateway/js/model/company-search'],
        {
            currentAddressFormCountry: function () {
                if (!opts.deferCountry) return 'gb';
                // Read the live DOM, so this answers '' until the form
                // carrying the select actually renders.
                const select = document.querySelector('select[name="country_id"]');
                return select ? String(select.value || '').toLowerCase() : '';
            },
            revertAutofilledAddress: function () {},
            billingRoleFormRoot: function () { return null; },
            applyAddress: function () {},
            lookupCompanyAddress: function () {}
        }
    );

    const RealPanel = loadCompanySearchPanel($, companySearchMock, {
        document: document,
        window: window
    });
    const RecordingPanel = function (panelOptions) {
        RealPanel.call(this, panelOptions);
        const self = this;
        const record = {
            binds: [],
            get fieldSelector() { return self.fieldSelector; },
            get boundNode() { return self.getField()[0] || null; }
        };
        panels.push(record);
        this.bind = function (bindOptions) {
            record.binds.push(bindOptions || {});
            return RealPanel.prototype.bind.call(self, bindOptions);
        };
    };
    RecordingPanel.prototype = Object.create(RealPanel.prototype);

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
            'Two_Gateway/js/model/company-search-panel': RecordingPanel,
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': {
                getActiveTwoBrandConfig: function () {
                    return {
                        isCompanySearchEnabled: opts.isCompanySearchEnabled !== false,
                        checkoutApiUrl: 'https://api.example',
                        checkoutPageUrl: 'https://checkout.example',
                        supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
                    };
                }
            },
            'Magento_Checkout/js/model/quote': Object.assign(
                {},
                defaultMocks()['Magento_Checkout/js/model/quote'],
                {
                    isVirtual: function () { return !!opts.isVirtual; },
                    // `deferCountry` reproduces a guest checkout at boot: the
                    // quote carries no address yet, so the country is only
                    // readable once a form exists to read it from.
                    billingAddress: function () {
                        return opts.deferCountry ? null : { countryId: 'GB' };
                    }
                }
            ),
            'Two_Gateway/js/model/company-search': companySearchMock
        },
        { document: document, window: window }
    );
    return {
        component: component,
        identity: identity,
        panels: panels,
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

    test('a second start() builds no second panel and binds no second listener', () => {
        mountTile();
        const { component, panels, soleTrader } = load();

        component.start();
        component.start();
        component.start();

        expect(panels).toHaveLength(1);
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

describe('exactly one search panel exists', () => {
    test('moving between mounts re-points the same panel rather than building a second', () => {
        mountAddressForm();
        mountTile();
        const { component, panels } = load();
        component.start();
        expect(panels).toHaveLength(1);
        expect(panels[0].fieldSelector).toBe(ADDRESS_FIELD);

        // The address form goes away — a buyer picking a saved address.
        $('#shipping-new-address-form').remove();
        component.refreshMount();

        expect(panels).toHaveLength(1);
        expect(panels[0].fieldSelector).toBe(TILE_FIELD);
        expect(panels[0].binds.length).toBeGreaterThan(1);
        expect(document.querySelectorAll('.two-company-dropdown')).toHaveLength(1);
    });

    test('re-pointing at the same live node does not re-bind', () => {
        mountTile();
        const { component, panels } = load();
        component.start();
        const bindsAfterStart = panels[0].binds.length;

        component.refreshMount();
        component.refreshMount();

        expect(panels[0].binds).toHaveLength(bindsAfterStart);
    });

    test('a tile replaced under the same selector stacks no second observer', () => {
        // Amasty and Fire Checkout replace the tile subtree outright. The panel
        // keeps ONE `$.async` observer per selector for the page's life, and
        // stacking them is what froze the renderer — see
        // company-search-async-observer.test.js for the guarantee this leans on.
        mountTile();
        const { component, panels } = load();
        component.start();
        const observersAfterStart = $.async.registrations(TILE_FIELD);

        document.body.innerHTML = '';
        mountTile();
        component.refreshMount();

        expect($.async.registrations(TILE_FIELD)).toBe(observersAfterStart);
        expect(panels).toHaveLength(1);
        expect(panels[0].boundNode).toBe(document.querySelector('#company_name'));
    });
});

describe('a checkout with no Two-family method is left alone', () => {
    /**
     * @returns {object} `{ component, requests }` — `requests` records any
     *          registry lookup the component attempts
     */
    function loadWithoutBrand() {
        const requests = [];
        const identity = loadAmdModule(IDENTITY, {}, { document: document, window: window });
        const component = loadAmdModule(
            COMPONENT,
            {
                jquery: $,
                'Two_Gateway/js/model/company-identity': identity,
                'Two_Gateway/js/model/sole-trader': function () {},
                // No Two-family method on this checkout.
                'Two_Gateway/js/model/brand-config': {
                    getActiveTwoBrandConfig: function () { return null; }
                },
                'Two_Gateway/js/model/company-search': Object.assign(
                    {},
                    defaultMocks()['Two_Gateway/js/model/company-search'],
                    { currentAddressFormCountry: function () { return 'gb'; } }
                )
            },
            {
                document: document,
                window: window,
                fetch: function (url) {
                    requests.push(url);
                    return Promise.resolve({ ok: true, json: function () { return []; } });
                }
            }
        );
        return { component: component, requests: requests };
    }

    test('start() bails and leaves the component inert', () => {
        mountTile();
        const { component } = loadWithoutBrand();

        component.start();

        expect(component.config()).toBeNull();
        expect(component.soleTrader()).toBeNull();
    });

    test.each([
        ['gb', 'the first country the quote resolves'],
        ['es', 'a later change to another country']
    ])('a country change to %s throws nothing (%s)', (country) => {
        // The boot hook calls this on every address and totals change, on every
        // checkout — including merchants who never installed Two.
        mountTile();
        const { component } = loadWithoutBrand();
        component.start();

        expect(() => component.onCountryChanged(country)).not.toThrow();
    });

    test('no registry lookup is made on a checkout that has no Two method', () => {
        mountTile();
        const { component, requests } = loadWithoutBrand();
        component.start();

        component.onCountryChanged('gb');
        component.onCountryChanged('es');

        expect(requests).toEqual([]);
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

describe('an adopted sole trader never inherits half of the previous company', () => {
    /**
     * @param {object} buyer the record the hosted signup authenticated
     * @returns {object} `{ companyName, companyId }` after adoption
     */
    function adoptOver(buyer) {
        mountTile();
        const { component, identity } = load();
        component.start();
        identity.write({ companyName: 'Previous Ltd', companyId: '99999999' });

        component.adoptSoleTrader(buyer);

        return { companyName: identity.companyName(), companyId: identity.companyId() };
    }

    test.each([
        [
            { organization_number: '', company_name: 'Ola Nordmann' },
            { companyName: 'Ola Nordmann', companyId: '' },
            'no registry number of their own'
        ],
        [
            { organization_number: 'ST-1', company_name: '' },
            { companyName: '', companyId: 'ST-1' },
            'no trading name of their own'
        ],
        [
            { organization_number: 'ST-2', company_name: 'Kari Nordmann' },
            { companyName: 'Kari Nordmann', companyId: 'ST-2' },
            'both halves of their own'
        ]
    ])('a sole trader with %p adopts as %p (%s)', (buyer, expected) => {
        expect(adoptOver(buyer)).toEqual(expected);
    });
});

describe('the host arriving after boot still gets a mount', () => {
    test('a checkout whose address form renders after start() still mounts and shows chips', () => {
        // The sidebar this boots from renders before the shipping form, and a
        // guest quote carries no billing address yet — so start()'s own attempt
        // finds no host and nothing else re-drives it. Reproduced live on
        // staging as a checkout with no company search and no chips at all.
        document.body.innerHTML = '';
        const { component, panels } = load();

        component.start();
        expect(panels).toHaveLength(0);

        mountAddressForm();
        $.async.fireAll();

        expect(panels).toHaveLength(1);
        expect(document.querySelectorAll('.two-company-mode-chip')).toHaveLength(3);
    });

    test('sole-trader availability resolves once a country is readable', async () => {
        document.body.innerHTML = '';
        const { component, identity } = load({ deferCountry: true });

        component.start();
        expect(identity.soleTraderAvailable()).toBe(false);

        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '<select name="country_id"><option value="GB" selected>GB</option></select>' +
            '<div class="field"><div class="control"><input name="company" /></div></div>' +
            '</form>';
        $.async.fireAll();
        // The registry answer arrives on a promise, so the chip cannot be
        // offered in the same tick the host appears.
        await Promise.resolve();
        await Promise.resolve();

        expect(identity.soleTraderAvailable()).toBe(true);
    });

    test('a country select arriving after the company field still resolves availability', async () => {
        // Measured live: the company input lands 44ms before the country
        // select, so the mount watcher asks for a country nothing can answer
        // yet, and a buyer keeping the default country never fires the `change`
        // that would ask again.
        document.body.innerHTML = '';
        const { component, identity } = load({ deferCountry: true });

        component.start();
        document.body.innerHTML =
            '<form id="shipping-new-address-form">' +
            '<div class="field"><div class="control"><input name="company" /></div></div>' +
            '</form>';
        $.async.fireAll();
        await Promise.resolve();
        expect(identity.soleTraderAvailable()).toBe(false);

        document.querySelector('#shipping-new-address-form').insertAdjacentHTML(
            'afterbegin',
            '<select name="country_id"><option value="GB" selected>GB</option></select>'
        );
        $.async.fireAll();
        await Promise.resolve();
        await Promise.resolve();

        expect(identity.soleTraderAvailable()).toBe(true);
    });
});
