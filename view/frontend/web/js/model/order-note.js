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
 * shipping-step component sets it when it renders; the payment tile keeps
 * its own order-note field but only shows it while the flag is false. That
 * covers the two cases where the shipping area is absent or unreachable:
 *
 *   - virtual / downloadable-only carts, which skip the shipping step
 *     entirely, and
 *   - any checkout front-end whose jsLayout does not carry the
 *     `shipping-address-fieldset` node the component mounts into.
 *
 * Without it, either case would silently drop the field instead of falling
 * back to where it used to live.
 */
define(['ko'], function (ko) {
    'use strict';

    return {
        orderNote: ko.observable(''),
        renderedInShippingArea: ko.observable(false)
    };
});
