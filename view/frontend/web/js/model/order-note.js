/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Shared order-note state (TWO-25263).
 *
 * The buyer's order note is collected in the SHIPPING ADDRESS area
 * (Two_Gateway/js/view/checkout/shipping/order-note) rather than inside the
 * Two payment tile, but it is still submitted as part of the payment
 * method's additional data — `payment[orderNote]` — so the server-side
 * relay is untouched:
 *
 *   Observer/DataAssignObserver  reads additionalData['orderNote']
 *   Service/Order/ComposeOrder   maps it to the API's `order_note`
 *
 * A single module-scope ko.observable is what makes that possible: the
 * shipping-step component writes to it and the payment renderer's
 * getData() reads from it, with no dependency between the two components
 * and no change to the submitted key. RequireJS caches the module, so both
 * sides hold the same observable for the life of the page — including
 * across the renderer re-creations Magento performs whenever the
 * payment-method list refreshes.
 *
 * `renderedInShippingArea` is a fallback signal, not decoration. The
 * shipping-step component sets it from its template's `afterRender` — on
 * actual paint, NOT on construction, because Magento builds every jsLayout
 * component whether or not it is ever rendered. The payment tile keeps its
 * own order-note field but shows it only while this flag is false, which
 * covers the cases where the shipping field never appears:
 *
 *   - virtual / downloadable-only carts, which never render the shipping
 *     step,
 *   - a returning customer with a saved address, where the shipping address
 *     fieldset is only rendered inside the "New Address" flow, and
 *   - any checkout front-end whose jsLayout does not carry the
 *     `shipping-address-fieldset` node the component mounts into.
 *
 * Without it, every one of those would silently drop the field instead of
 * falling back to where it used to live. Claiming at construction time would
 * be worse than not claiming at all: it suppresses the fallback in precisely
 * the situations the fallback exists for.
 */
define(['ko'], function (ko) {
    'use strict';

    return {
        orderNote: ko.observable(''),
        renderedInShippingArea: ko.observable(false)
    };
});
