/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25263. The order note moved out of the Two payment tile and into the
 * shipping address area, but the wire format did NOT change: it must still
 * leave the browser as `orderNote` in the payment method's additional data,
 * because that is what Observer/DataAssignObserver reads and what
 * Service/Order/ComposeOrder maps to the API's `order_note`.
 *
 * These tests pin the two things that keep that true:
 *   1. what the shipping-step component writes is what getData() emits, and
 *   2. the payment tile keeps a fallback field for the checkouts where the
 *      shipping area never renders (virtual carts, front-ends whose jsLayout
 *      lacks `shipping-address-fieldset`) — otherwise the field would vanish
 *      silently rather than fall back.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule, defaultMocks } = require('./amd-harness');

// The same knockout mock the loaded modules receive. Its observables share one
// module-level dependency-tracking stack, so a computed built here tracks
// observables created inside a separately-loaded module.
const ko = defaultMocks().ko;

const RENDERER_SRC = fs.readFileSync(
    path.resolve(__dirname, '../../view/frontend/web/js/view/payment/method-renderer/gateway_method.js'),
    'utf8'
);
const TILE_TEMPLATE = fs.readFileSync(
    path.resolve(__dirname, '../../view/frontend/web/template/payment/gateway_method.html'),
    'utf8'
);

const MODEL = 'view/frontend/web/js/model/order-note.js';
const SHIPPING_COMPONENT = 'view/frontend/web/js/view/checkout/shipping/order-note.js';
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

/**
 * Loads the REAL shared model, then hands that same instance to both the
 * shipping component and the payment renderer. Each loadAmdModule() call
 * gets its own vm context, so passing the instance through extraMocks is
 * what reproduces RequireJS's single-instance-per-page behaviour.
 */
function loadWithSharedModel(orderNoteEnabled) {
    const model = loadAmdModule(MODEL);
    const brandConfig = function () { return { isOrderNoteFieldEnabled: orderNoteEnabled }; };

    brandConfig.getActiveTwoBrandCode = function () { return 'two_payment'; };
    brandConfig.getActiveTwoBrandConfig = function () {
        return { isOrderNoteFieldEnabled: orderNoteEnabled };
    };

    const mocks = {
        'Two_Gateway/js/model/order-note': model,
        'Two_Gateway/js/model/brand-config': brandConfig
    };

    return {
        model: model,
        ShippingComponent: loadAmdModule(SHIPPING_COMPONENT, mocks),
        Renderer: loadAmdModule(RENDERER, mocks)
    };
}

/**
 * getData() reads a handful of sibling observables. Build a `this` that
 * supplies them so the assertion is about `orderNote` and nothing else.
 */
function getDataContext(Renderer) {
    const stub = function (v) { return function () { return v; }; };

    return {
        getCode: stub('two_payment'),
        companyName: stub('Example Ltd'),
        companyId: stub('123456789'),
        project: stub('Atrium'),
        department: stub('Facilities'),
        orderNote: Renderer.orderNote,
        poNumber: stub('PO-1'),
        invoiceEmails: stub('ap@example.com'),
        selectedTerm: stub(14)
    };
}

describe('order note collected in the shipping address area', () => {
    test('what the shipping component writes is what getData() submits as orderNote', () => {
        const { ShippingComponent, Renderer } = loadWithSharedModel(true);
        const shipping = new ShippingComponent();

        shipping.orderNote('Deliver to the loading bay, ask for Sam');

        const data = Renderer.getData.call(getDataContext(Renderer));

        expect(data.additional_data.orderNote).toBe('Deliver to the loading bay, ask for Sam');
    });

    test('the shipping component claims the field, so the payment tile hides its copy', () => {
        const { model, ShippingComponent, Renderer } = loadWithSharedModel(true);
        const tile = { isOrderNoteFieldEnabled: true };

        expect(model.renderedInShippingArea()).toBe(false);
        expect(Renderer.orderNoteFallbackVisible.call(tile)).toBe(true);

        new ShippingComponent();

        expect(model.renderedInShippingArea()).toBe(true);
        expect(Renderer.orderNoteFallbackVisible.call(tile)).toBe(false);
    });

    test('with no shipping area rendered the tile keeps the field as a fallback', () => {
        const { Renderer } = loadWithSharedModel(true);

        // Nothing instantiated the shipping component — a virtual cart, or a
        // front-end without the shipping-address-fieldset node.
        expect(
            Renderer.orderNoteFallbackVisible.call({ isOrderNoteFieldEnabled: true })
        ).toBe(true);
    });

    test('the tile computed re-evaluates when the shipping area claims the field', () => {
        // The template binds the `isOrderNoteFieldInTile` ko.computed, so it has
        // to flip on its own when renderedInShippingArea() changes. A one-shot
        // snapshot would satisfy every other test in this file and still leave
        // two order-note fields on screen, so assert the reactivity itself,
        // driving the renderer's real predicate through ko exactly as
        // initialize() wires it up.
        const { model, Renderer } = loadWithSharedModel(true);
        const owner = { isOrderNoteFieldEnabled: true };
        const flag = ko.computed(Renderer.orderNoteFallbackVisible, owner);

        expect(flag()).toBe(true);

        model.renderedInShippingArea(true);

        expect(flag()).toBe(false);
    });

    test('initialize builds that computed, and the template binds it', () => {
        // Guards the wiring the test above assumes: a regression that bound the
        // plain predicate (`if: orderNoteFallbackVisible`) would always be truthy
        // — a function object — and the fallback would render unconditionally.
        expect(RENDERER_SRC).toMatch(
            /this\.isOrderNoteFieldInTile\s*=\s*ko\.computed\(\s*this\.orderNoteFallbackVisible\s*,\s*this\s*\)/
        );
        expect(TILE_TEMPLATE).toContain('<!-- ko if: isOrderNoteFieldInTile -->');
        expect(TILE_TEMPLATE).not.toContain('orderNoteFallbackVisible');
    });

    test('the toggle still gates both surfaces when the merchant disables it', () => {
        const { model, ShippingComponent, Renderer } = loadWithSharedModel(false);
        const shipping = new ShippingComponent();

        expect(shipping.isOrderNoteFieldEnabled).toBe(false);
        expect(model.renderedInShippingArea()).toBe(false);
        expect(
            Renderer.orderNoteFallbackVisible.call({ isOrderNoteFieldEnabled: false })
        ).toBe(false);
    });
});
