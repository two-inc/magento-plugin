/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Registers a Two-family payment renderer with Luma's payment list, and
 * creates the renderer itself when the list has already gone past it
 * (TWO-26297).
 *
 * Luma's payment list (Magento_Checkout/js/view/payment/list) creates a
 * renderer for each method in the method list from whatever renderer-list
 * holds at that moment, and afterwards only for methods the method list
 * ADDS. A renderer type pushed after that moment is never consulted for a
 * method that is already listed.
 *
 * On a quote with shipping that cannot happen: the method list is empty
 * until the shipping step is saved, long after every renderer module has
 * loaded. On a virtual quote it can. Core seeds the method list from
 * checkoutConfig.paymentMethods while the checkout is still booting, so the
 * list builds its renderers on the first pass, and a renderer module that
 * arrives later (a slow or uncached static file) misses it. The method then
 * stays out of the page for the life of that page load: a billing-address
 * save refetches the same methods, core keeps the existing entries, so
 * nothing is ever "added", and only a reload brings it back. Core restores a
 * previously selected method from the method list, not from the rendered
 * methods, so it can even be selected while invisible.
 *
 * So, after pushing: if the list has already run its first pass (the list
 * exists and so does its default method group, whose arrival is what runs
 * the pass), create the renderer for any listed method of this type that has
 * none. If the group is still pending, the list's own queued pass reads
 * renderer-list later and finds this entry, so doing anything here would
 * create a second renderer.
 */
define([
    'uiRegistry',
    'Magento_Checkout/js/model/payment/renderer-list',
    'Magento_Checkout/js/model/payment/method-list'
], function (registry, rendererList, methodList) {
    'use strict';

    var PAYMENT_LIST = 'index = payments-list';

    // Types this module has already asked the list to create. Core registers a
    // renderer only once its component module has loaded, so the registry
    // check alone would let a second call in that window create a second one.
    var backfilled = {};

    /**
     * @param {String} type      payment method code
     * @param {String} component renderer component path
     * @returns {void}
     */
    return function registerRenderer(type, component) {
        var list;
        var groupName;

        rendererList.push({
            type: type,
            component: component
        });

        list = registry.get(PAYMENT_LIST);

        if (!list || typeof list.createRenderer !== 'function') {
            return;
        }

        groupName = list.configDefaultGroup && list.configDefaultGroup.name;

        if (!groupName || !registry.get(groupName)) {
            return;
        }

        (methodList() || []).forEach(function (method) {
            if (
                method &&
                method.method === type &&
                !backfilled[type] &&
                !registry.get(list.name + '.' + type)
            ) {
                backfilled[type] = true;
                list.createRenderer(method);
            }
        });
    };
});
