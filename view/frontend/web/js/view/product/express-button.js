/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Product page "Buy with <brand>" button (TWO-25800).
 *
 * It owns one add-to-cart attempt at a time, and it decides nothing about how
 * that attempt turned out. It asks for its destination up front and lets the
 * server apply it.
 *
 * The reason is in Magento's own source. catalog-add-to-cart.js fires
 * `ajax:addToCart` from its SUCCESS callback, so the event reaches a listener
 * on any HTTP 200; and Checkout/Controller/Cart/Add.php ends BOTH its success
 * and its failure path at `goBack()`, which emits only `backUrl` and
 * occasionally `product.statusText`. There is no success flag in the JSON, and
 * `backUrl` is present on success (a store set to redirect to the cart) and on
 * failure (where to send the buyer back to) alike. The outcome is not in the
 * response, so no amount of reading it can classify the add.
 *
 * The controller, on the other hand, already knows, and already decides where
 * the buyer goes. `return_url` is core's own parameter for that, and it is
 * honoured on exactly one path:
 *
 *     success:              goBack(null, $product)  -> getBackUrl() -> return_url
 *     LocalizedException:   goBack($url)            -> return_url never consulted
 *
 * So a successful add lands the buyer on checkout carrying our marker, and a
 * refused one sends them back with core's own error message, with no branch
 * here to get wrong.
 */
define(['jquery'], function ($) {
    'use strict';

    /**
     * The add-to-cart form this button belongs to.
     *
     * Scoped to the nearest enclosing form first so a page listing several
     * products cannot submit the wrong one, falling back to the id core's
     * product view template uses.
     *
     * @param {HTMLElement} element
     * @returns {jQuery}
     */
    function addToCartForm(element) {
        var enclosing = $(element).closest('form');

        return enclosing.length ? enclosing : $('#product_addtocart_form');
    }

    /**
     * Whether core's validation passes, when core's validation is present.
     *
     * A configurable product with no swatch chosen must behave exactly as it
     * does for add to cart: say what is missing and stay put. With no
     * validation widget on the page we invent no verdict and let the server
     * decide.
     *
     * @param {jQuery} form
     * @returns {Boolean}
     */
    function passesValidation(form) {
        if (typeof form.validation !== 'function') {
            return true;
        }

        return form.validation('isValid') !== false;
    }

    /**
     * Put core's refusal where the buyer can see it.
     *
     * Core renders its messages beside the field that failed, which for a
     * configurable product is the swatches — and this button sits BELOW add to
     * cart, so a buyer who clicked it with the viewport on it gets a refusal
     * several hundred pixels above the fold and a control that appears to do
     * nothing. Core's own Add to Cart does not have that problem because it
     * sits directly under the swatches.
     *
     * The messages themselves are core's, found by the class core's validation
     * puts on them, so nothing here duplicates or reimplements the validating.
     * Only called on a refusal: a successful click navigates, and scrolling a
     * page that is about to be replaced is a jump for no reason.
     *
     * @param {jQuery} form
     * @returns {void}
     */
    function showRefusal(form) {
        var message = form.find('.mage-error').filter(':visible').first();
        var target = message.length ? message : form.find('.mage-error').first();

        if (!target.length || typeof target[0].scrollIntoView !== 'function') {
            return;
        }

        target[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    /**
     * A name for one attempt, so every later question is about THAT one.
     *
     * Without it each record can only say "an add like this happened": a
     * session stamp is a single slot any add can fill, and a marker keyed on
     * the product repeats the moment the same buyer buys the same thing twice.
     * The token is carried to the server, stamped against the add that actually
     * completed, required back by the confirmation, and finally keys the
     * marker's one-shot consumption at checkout.
     *
     * It identifies, it does not authorise: the stamp it is matched against is
     * written server-side and cleared on read, so a guessed token buys nothing.
     *
     * @returns {String}
     */
    function mintToken() {
        var random = Math.random().toString(36).slice(2, 10);

        try {
            if (window.crypto && window.crypto.getRandomValues) {
                random =
                    window.crypto.getRandomValues(new Uint32Array(2))[0].toString(36) +
                    window.crypto.getRandomValues(new Uint32Array(2))[0].toString(36);
            }
        } catch (e) {
            // Keeps the Math.random name above; uniqueness within one tab is
            // all this needs.
        }

        return Date.now().toString(36) + random;
    }

    /**
     * One express attempt, and everything this page changed to start it.
     *
     * The latch and the destination are one object, not two things managed
     * separately: begin() records what the page looked like and changes it,
     * abandon() puts it back exactly as it was. A restored page abandons;
     * nothing else has to know which parts to undo.
     *
     * @param {jQuery} form
     * @param {jQuery} button
     * @param {String} returnUrl
     * @returns {Object}
     */
    function attempt(form, button, returnUrl) {
        var field = form.find('input[name="return_url"]');
        var weAddedIt = !field.length;
        var priorUrl = weAddedIt ? null : field.val();
        var token = mintToken();

        return {
            begin: function () {
                if (weAddedIt) {
                    field = $('<input>', { type: 'hidden', name: 'return_url' }).appendTo(form);
                }
                field.val(returnUrl + (returnUrl.indexOf('?') === -1 ? '?' : '&') + 't=' + token);
                button.prop('disabled', true);
            },

            /**
             * Exactly as the page was: a destination the theme or another
             * extension supplied is restored rather than removed, and one we
             * created is removed rather than left pointing at a finished
             * attempt.
             */
            abandon: function () {
                if (weAddedIt) {
                    field.remove();
                } else {
                    field.val(priorUrl);
                }
                button.prop('disabled', false);
            }
        };
    }

    return function (config, element) {
        var button = $(element);
        var returnUrl = (config && config.returnUrl) || '';
        var inFlight = null;

        if (!returnUrl) {
            return;
        }

        button.on('click', function () {
            var form = addToCartForm(element);

            // One attempt at a time. This button sits outside the form, so it
            // is not the control core disables for the duration of its own
            // request — without this a double click is two adds and two units
            // in the basket from one intended purchase.
            if (inFlight || !form.length) {
                return;
            }

            if (!passesValidation(form)) {
                // Core has said why; this only makes sure the buyer can see it.
                showRefusal(form);

                return;
            }

            inFlight = attempt(form, button, returnUrl);
            inFlight.begin();

            form.submit();
        });

        // Every outcome of the attempt navigates: core redirects on success, on
        // a refused add and on an unexpected one, and its own `complete`
        // handler reloads a rejected request. So there is nothing to undo
        // in-page — except on a back-forward cache restore, which hands back
        // the page exactly as the attempt left it.
        $(window).on('pageshow', function (event) {
            if (!event.originalEvent || !event.originalEvent.persisted || !inFlight) {
                return;
            }

            inFlight.abandon();
            inFlight = null;
        });
    };
});
