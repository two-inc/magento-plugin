/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25800 — the product-page button's add-to-cart contract.
 *
 * Every case here is written against an OUTCOME the buyer can observe, never
 * against the mechanism that produces it: how many add requests one click
 * causes, and where the buyer ends up. The mechanism is not the contract, and a
 * test pinned to it cannot survive the mechanism changing.
 *
 * What the primary sources actually say (Magento 2.4.7):
 *
 *   catalog-add-to-cart.js, success handler:
 *       $(document).trigger('ajax:addToCart', {... 'form': form, 'response': res});
 *       ...
 *       if (res.backUrl) { self._redirect(res.backUrl); return; }
 *
 *   Checkout/Controller/Cart/Add.php, both the success and the failure path
 *   end at goBack(), and goBack() emits ONLY:
 *       $result['backUrl'] = $backUrl;      // or
 *       $result['product'] = ['statusText' => __('Out of stock')];
 *
 * So the JSON carries no success or failure flag at all, and `backUrl` appears
 * on BOTH paths — on success when the store redirects to the cart, and on
 * failure as the place to send the buyer back to. The outcome is simply not in
 * the response, which is why reading it harder cannot settle anything.
 *
 * It IS in the controller, which already decides where to send the buyer. So
 * the button asks for its destination up front, through core's own
 * `return_url` parameter, and core applies it on the success path only:
 *
 *   Add::execute() success:  return $this->goBack(null, $product);
 *       -> $backUrl null -> getBackUrl() -> our return_url wins
 *   Add::execute() LocalizedException:  $url = ...; return $this->goBack($url);
 *       -> $backUrl set   -> getBackUrl() never consulted -> buyer goes back
 */

'use strict';

const $ = require('jquery');
const { loadAmdModule } = require('./amd-harness');

const MODULE = 'view/frontend/web/js/view/product/express-button.js';
// The button's destination is now our confirmation controller, which asks the
// quote what happened before it hands the buyer to checkout.
// The route confirms from a server-side stamp keyed on the attempt's own
// token, which the button mints and appends.
const RETURN_URL = 'https://store.example.test/two/express/confirm/?product=42';
const CART_URL = 'https://store.example.test/checkout/cart/';
const ACTION_URL = 'https://store.example.test/checkout/cart/add/';

/**
 * Where the buyer ends up, however the module arranges it.
 *
 * Two mechanisms can produce the same outcome and the tests must not care
 * which: the module may navigate itself, or it may ask core to navigate by
 * putting a return_url on the form core posts. Either counts.
 *
 * @param {Object} nav  captured window.location.assign calls
 * @param {jQuery} form
 * @returns {String|null}
 */
function destination(nav, form) {
    if (nav.assigned.length) {
        return nav.assigned[nav.assigned.length - 1];
    }

    const returnUrl = form.find('input[name="return_url"]').val();

    return returnUrl || null;
}

function setup() {
    document.body.innerHTML =
        '<form id="product_addtocart_form" action="' +
        ACTION_URL +
        '">' +
        '  <input type="hidden" name="product" value="42" />' +
        '  <input type="number" name="qty" value="1" />' +
        '</form>' +
        '<div class="two-product-express">' +
        '  <button type="button" id="two-express">Buy with Two</button>' +
        '</div>';

    const form = $('#product_addtocart_form');
    const button = $('#two-express');
    const submits = [];

    // Core's widget answers the form's submit; here we only count it.
    form.on('submit', function (event) {
        event.preventDefault();
        submits.push(true);
    });
    // jQuery's .submit()/.trigger('submit') reaches the handler above, but a
    // direct form.submit() would not, so the native one is counted too.
    form[0].submit = function () {
        submits.push(true);
    };

    const nav = { assigned: [] };
    delete window.location;
    window.location = {
        href: 'https://store.example.test/product.html',
        assign: function (url) {
            nav.assigned.push(url);
        }
    };

    // The module runs in a vm context, so it must be handed THIS window or its
    // `$(window)` binds to a different global than the test triggers on.
    const factory = loadAmdModule(MODULE, { jquery: $ }, { window: window });
    factory({ returnUrl: RETURN_URL }, button[0]);

    return { form, button, submits, nav };
}

/** Core fires this from its SUCCESS callback, carrying the form and the JSON. */
function fireAddToCart(form, response) {
    $(document).trigger('ajax:addToCart', {
        sku: 'SKU-42',
        form: form,
        response: response
    });
}

afterEach(() => {
    $(document).off('ajax:addToCart ajax:addToCart:error ajaxError');
    document.body.innerHTML = '';
});

describe('TWO-25800 product page button: one click, one add, one destination', () => {
    /**
     * "After Adding a Product Redirect to Shopping Cart" is a stock Magento
     * setting. With it on, a SUCCESSFUL add returns a cart backUrl — so a
     * classifier that reads backUrl as failure strands the buyer on exactly the
     * stores that use it.
     */
    test('a successful add that returns a cart backUrl still reaches the confirmation step', () => {
        const { form, button, nav } = setup();

        button.trigger('click');
        fireAddToCart(form, { backUrl: CART_URL });

        expect(destination(nav, form)).toMatch(
            /^https:\/\/store\.example\.test\/two\/express\/confirm\/\?product=42&t=.+/
        );
    });

    /**
     * Any page carries requests this button knows nothing about: customer-data
     * sections, analytics, another extension's poll. One of those failing
     * between the click and the cart response says nothing about the add.
     */
    test('an unrelated failed request does not change where the buyer ends up', () => {
        const { form, button, nav } = setup();

        button.trigger('click');
        $(document).trigger('ajaxError', [{ status: 500 }, { url: '/customer/section/load' }]);
        fireAddToCart(form, {});

        expect(destination(nav, form)).toMatch(
            /^https:\/\/store\.example\.test\/two\/express\/confirm\/\?product=42&t=.+/
        );
    });

    /**
     * The button sits OUTSIDE the form, so it is not the control core disables
     * for the duration of its own request. Without a latch of its own, a
     * double-click is two concurrent adds and two units in the basket from one
     * intended purchase.
     */
    test('a double click causes exactly one add', () => {
        const { button, submits } = setup();

        button.trigger('click');
        button.trigger('click');

        expect(submits.length).toBe(1);
    });

    /**
     * Every outcome of the attempt navigates, so there is nothing to unlatch
     * in-page. A back-forward cache restore is the exception: it returns the
     * page exactly as it was left, latch and all, and a dead button is what the
     * buyer would find.
     */
    test('a back-forward cache restore releases the latch', () => {
        const { button } = setup();

        button.trigger('click');
        expect(button.prop('disabled')).toBe(true);

        const restore = $.Event('pageshow');
        restore.originalEvent = { persisted: true };
        $(window).trigger(restore);

        expect(button.prop('disabled')).toBe(false);
    });

    /**
     * An ordinary load is not a restore, and must not unlatch an attempt that
     * is still in flight.
     */
    test('an ordinary pageshow leaves an in-flight attempt latched', () => {
        const { button } = setup();

        button.trigger('click');
        const load = $.Event('pageshow');
        load.originalEvent = { persisted: false };
        $(window).trigger(load);

        expect(button.prop('disabled')).toBe(true);
    });

    /**
     * Core renders its refusal beside the field that failed, which on a
     * configurable product is the swatches — several hundred pixels above this
     * button. A buyer who clicked with the viewport on the button otherwise
     * gets no visible reaction at all.
     */
    test('a refused click brings core own message into view', () => {
        const { form, button, submits } = setup();
        const scrolled = [];

        form.append('<div class="mage-error" id="err">This is a required field.</div>');
        document.getElementById('err').scrollIntoView = function (opts) {
            scrolled.push(opts);
        };
        // Core's validation widget, refusing. Stubbed on the prototype because
        // the handler resolves the form itself rather than using this object.
        $.fn.validation = () => false;

        button.trigger('click');

        delete $.fn.validation;

        expect(submits.length).toBe(0);
        expect(scrolled.length).toBe(1);
        expect(scrolled[0].block).toBe('center');
    });

    /**
     * A successful click navigates, so scrolling the page it is about to leave
     * is a jump for no reason.
     */
    test('an accepted click scrolls nothing', () => {
        const { form, button, submits } = setup();
        const scrolled = [];

        form.append('<div class="mage-error" id="err">stale</div>');
        document.getElementById('err').scrollIntoView = function () {
            scrolled.push(1);
        };

        button.trigger('click');

        expect(submits.length).toBe(1);
        expect(scrolled.length).toBe(0);
    });

    /**
     * A destination the theme or another extension already supplied is the
     * page's, not ours. A restored page gets it back rather than losing it.
     */
    test('a bfcache return restores a pre-existing return_url', () => {
        document.body.innerHTML = '';
        const { form, button } = setup();

        form.append('<input type="hidden" name="return_url" value="/theme/destination" />');
        button.trigger('click');
        expect(form.find('input[name="return_url"]').val()).toContain('&t=');

        const restore = $.Event('pageshow');
        restore.originalEvent = { persisted: true };
        $(window).trigger(restore);

        expect(form.find('input[name="return_url"]').val()).toBe('/theme/destination');
        expect(button.prop('disabled')).toBe(false);
    });

    /** One we created is removed, not left pointing at a finished attempt. */
    test('a bfcache return removes a return_url we added', () => {
        const { form, button } = setup();

        button.trigger('click');
        expect(form.find('input[name="return_url"]').length).toBe(1);

        const restore = $.Event('pageshow');
        restore.originalEvent = { persisted: true };
        $(window).trigger(restore);

        expect(form.find('input[name="return_url"]').length).toBe(0);
    });

    /**
     * Each attempt is named, so a second purchase of the same product is a
     * different attempt rather than a replay of the first.
     */
    test('two clicks on the same product mint different tokens', () => {
        const { form, button } = setup();

        button.trigger('click');
        const first = form.find('input[name="return_url"]').val();

        const restore = $.Event('pageshow');
        restore.originalEvent = { persisted: true };
        $(window).trigger(restore);

        button.trigger('click');
        const second = form.find('input[name="return_url"]').val();

        expect(first).toContain('&t=');
        expect(second).toContain('&t=');
        expect(second).not.toBe(first);
    });
});
