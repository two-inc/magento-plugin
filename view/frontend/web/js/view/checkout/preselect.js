/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Preselects the Two-family method at checkout for a buyer who arrived via the
 * product page "Buy with <brand>" button (TWO-25800).
 *
 * The intent is a query parameter on the URL core redirected to, put there by
 * Block/Product/ExpressButton as its `return_url`. Core applies that parameter
 * on the successful-add path only, so the intent exists if and only if the item
 * actually went into the basket — there is no client-side flag to leave behind
 * after a failed add, and none to survive into an unrelated later checkout.
 *
 * Selecting is best-effort by design, and every way it can decline is a way it
 * must decline:
 *   - The method is not offered for this basket (below the minimum order
 *     value, an unsupported buyer country, a currency the merchant does not
 *     take). A product page cannot know any of that, so the button never
 *     promised it. Nothing is selected and the buyer chooses as normal.
 *   - The buyer already chose a method. Theirs wins; we are a default, not an
 *     override.
 *   - The method list never arrives. We simply never fire.
 *
 * Mounted from checkout_index_index.xml under the always-present sidebar, for
 * the same reason as payment-availability: while the method is hidden its
 * renderer is not instantiated, so a component living there could not act when
 * the method later appears.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment-service',
    'Magento_Checkout/js/model/payment/method-list',
    'Magento_Checkout/js/action/select-payment-method',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/checkout-data',
    'Two_Gateway/js/model/brand-config'
], function (
    Component,
    paymentService,
    methodList,
    selectPaymentMethodAction,
    quote,
    checkoutData,
    brandConfig
) {
    'use strict';

    /**
     * The marker's name and value shape, defined once.
     *
     * Reading it and stripping it are two operations over the same thing, so
     * both are built from these rather than each spelling out a pattern of its
     * own: written separately they can disagree about what a marker is, and a
     * strip that matches nothing the read accepts silently never fires.
     *
     * Mirrors Controller\Express\Confirm::EXPRESS_PARAM, which emits it.
     */
    var MARKER_NAME = 'two_express';
    var MARKER_VALUE = '[A-Za-z0-9_.-]+';

    /**
     * Whether this page load is the one the button sent the buyer to, and
     * consuming the intent in the same breath.
     *
     * Consumption is one question with two records, because neither alone is
     * reliable: the marker is stripped from the address bar so a reload or a
     * shared link cannot reapply it, AND the fact of consuming it is written to
     * sessionStorage so that a browser which refuses the history write — a
     * sandboxed or embedded view — still cannot reapply it. Either record being
     * present means consumed.
     *
     * Consumed ALWAYS, and before anything is selected, not only when the
     * method turns out to be selectable. An intent that outlives its checkout
     * preselects one nobody asked for, which is worse than never preselecting.
     *
     * @returns {Boolean}
     */
    function takeExpressIntent() {
        var search = (window.location && window.location.search) || '';
        var found = new RegExp('[?&]' + MARKER_NAME + '=(' + MARKER_VALUE + ')').exec(search);
        var key;
        var already = false;

        if (!found) {
            return false;
        }

        // Keyed on the ATTEMPT, not on the query. The same buyer buying the
        // same product again produces an identical query and a different
        // token, so the repeat is honoured rather than read as a replay.
        key = 'two_gateway_express_consumed:' + found[1];

        try {
            already = window.sessionStorage.getItem(key) === '1';
        } catch (e) {
            already = false;
        }

        // Attempted on every visit carrying a marker, including one this tab
        // has already consumed. The two records are independent: a history
        // write that was refused once — a sandboxed or embedded view — may be
        // accepted on the next load, and returning early on the storage record
        // alone leaves the marker in the address bar for the life of the page,
        // where it can be copied out of the URL bar into a context that has
        // never seen it.
        stripMarker(search);

        if (already) {
            return false;
        }

        try {
            window.sessionStorage.setItem(key, '1');
        } catch (e) {
            // A storage that refuses leaves the history write as the only
            // record, which is the same position as before it was added.
        }

        return true;
    }

    /**
     * Take the marker out of the address bar, so a reload or a shared link
     * cannot carry the intent anywhere.
     *
     * Best-effort: a browser that refuses the history write leaves the
     * sessionStorage record as the only one, and this is retried on the next
     * load that still carries a marker.
     *
     * @param {String} search
     * @returns {void}
     */
    function stripMarker(search) {
        var stripped;

        try {
            stripped = search.replace(
                new RegExp('([?&])' + MARKER_NAME + '=' + MARKER_VALUE + '(&|$)'),
                function (match, lead, trail) {
                    return trail ? lead : '';
                }
            );

            window.history.replaceState(
                window.history.state,
                '',
                window.location.pathname + (stripped === '?' ? '' : stripped) + window.location.hash
            );
        } catch (e) {
            // Refused; the sessionStorage record answers instead.
        }
    }

    return Component.extend({
        defaults: {
            template: null
        },

        /**
         * @returns {Object} chainable
         */
        initialize: function () {
            this._super();

            if (!takeExpressIntent()) {
                return this;
            }

            this._done = false;
            // The PREVIOUS checkout's selection, read once at mount. See
            // _buyerChoseDuringThisCheckout() for why it is captured rather
            // than re-read.
            //
            // Synchronous, and not subject to customer-data hydration:
            // checkout-data's own getData() falls back to reading
            // `mage-cache-storage` out of localStorage directly when the
            // customer-data observable is still empty. Core's restore reads
            // through that same function, so whatever it later restores is
            // exactly the value captured here — a restore can never look like
            // a different selection, whenever it lands.
            this._priorSelection = checkoutData.getSelectedPaymentMethod() || null;
            this._trySelect();

            if (!this._done) {
                // The list is fetched asynchronously, and Two may also appear
                // later when the basket crosses the minimum order value.
                //
                // Subscribed to method-list, not to
                // paymentService.getAvailablePaymentMethods(): that returns a
                // plain filtered array, while method-list is the
                // observableArray the service writes through.
                this._subscription = methodList.subscribe(this._trySelect.bind(this));
            }

            return this;
        },

        /**
         * Drop the subscription so a torn-down checkout (a one-step-checkout
         * derivative re-rendering) leaves no handler behind.
         */
        destroy: function () {
            this._unsubscribe();
            this._super();
        },

        /**
         * @returns {void}
         */
        _unsubscribe: function () {
            if (this._subscription) {
                this._subscription.dispose();
                this._subscription = null;
            }
        },

        /**
         * The currently selected method code, or null.
         *
         * @returns {String|null}
         */
        _currentMethod: function () {
            var selected = quote.paymentMethod();

            return (selected && selected.method) || null;
        },

        /**
         * Whether the buyer chose a method during THIS checkout, as opposed to
         * core restoring one they used before.
         *
         * Measured against the value checkoutData held AT MOUNT, not against
         * its live value. checkoutData is written on both paths — core's
         * restore and the buyer's own click, through
         * `Magento_Checkout/js/view/payment/default::selectPaymentMethod()` —
         * so comparing with the live value classifies a fresh choice as a
         * restore, and this component would then overwrite the buyer's own
         * selection.
         *
         * The mount-time value cannot have that problem. This component is
         * mounted from the sidebar, which renders before the payment step
         * exists, so nothing the buyer does in this checkout can have reached
         * checkoutData yet; what it holds is the previous checkout's selection,
         * read synchronously from local storage. The asynchronous restore can
         * therefore only ever produce that same value, whenever it lands, while
         * any other value is the buyer acting now.
         *
         * A buyer who re-picks the method they used last time is
         * indistinguishable from the restore and is overridden once. Accepted,
         * and stated rather than hidden: the alternative is losing the
         * preselect for every returning buyer. Once the selection is made this
         * component is done and never competes again.
         *
         * @returns {Boolean}
         */
        _buyerChoseDuringThisCheckout: function () {
            var current = this._currentMethod();

            if (current === null) {
                return false;
            }

            return current !== this._priorSelection;
        },

        /**
         * Select the active Two-family method if it is on offer and the buyer
         * has not already chosen for themselves.
         *
         * @returns {void}
         */
        _trySelect: function () {
            if (this._done) {
                return;
            }

            var code = brandConfig.getActiveTwoBrandCode();

            if (!code) {
                return;
            }

            // Theirs wins, but only a choice made HERE. See the method below.
            if (this._buyerChoseDuringThisCheckout()) {
                this._done = true;
                this._unsubscribe();

                return;
            }

            var offered = (paymentService.getAvailablePaymentMethods() || []).filter(
                function (method) {
                    return method.method === code;
                }
            );

            if (!offered.length) {
                return;
            }

            this._done = true;
            this._unsubscribe();

            selectPaymentMethodAction(offered[0]);
            checkoutData.setSelectedPaymentMethod(code);
        }
    });
});
