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

const { loadAmdModule } = require('./amd-harness');

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
        expect(Renderer.isOrderNoteFieldInTile.call(tile)).toBe(true);

        new ShippingComponent();

        expect(model.renderedInShippingArea()).toBe(true);
        expect(Renderer.isOrderNoteFieldInTile.call(tile)).toBe(false);
    });

    test('with no shipping area rendered the tile keeps the field as a fallback', () => {
        const { Renderer } = loadWithSharedModel(true);

        // Nothing instantiated the shipping component — a virtual cart, or a
        // front-end without the shipping-address-fieldset node.
        expect(
            Renderer.isOrderNoteFieldInTile.call({ isOrderNoteFieldEnabled: true })
        ).toBe(true);
    });

    test('the toggle still gates both surfaces when the merchant disables it', () => {
        const { model, ShippingComponent, Renderer } = loadWithSharedModel(false);
        const shipping = new ShippingComponent();

        expect(shipping.isOrderNoteFieldEnabled).toBe(false);
        expect(model.renderedInShippingArea()).toBe(false);
        expect(
            Renderer.isOrderNoteFieldInTile.call({ isOrderNoteFieldEnabled: false })
        ).toBe(false);
    });
});
