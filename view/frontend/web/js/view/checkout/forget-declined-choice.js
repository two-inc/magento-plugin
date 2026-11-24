/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Forgets the payment method this browser remembers for a buyer the method
 * did not approve (TWO-27389).
 *
 * The decline return gives the buyer their basket back on the payment step to
 * choose another method. The shop has already removed the method from the
 * basket; Luma also remembers the buyer's last choice in the browser's
 * checkout data and selects it again when the payment step renders. The
 * return marks that one redirect with a query parameter, and this component
 * clears that remembered choice, and nothing else the browser keeps (a guest's
 * email and any address typed in stay), before the payment step renders. The
 * method stays offered; it is just not chosen.
 *
 * One-shot: the marker is taken out of the address bar as it is read, so a
 * reload or a shared link cannot clear a choice the buyer has made since.
 *
 * The return's message reaches this page queued in Magento's `mage-messages`
 * cookie, which only the page messages block reads, and the one-page checkout
 * removes that block. Left there it would surface on the buyer's next page,
 * typically the next order's success page (TWO-27396). So on the marker this
 * component also takes the newest queued error, shows it in the checkout's
 * own message area (sticky, see messages-sticky-errors-mixin.js), and empties
 * the queue the way Magento_Theme/js/view/messages does.
 *
 * Mounted from checkout_index_index.xml under the always-present sidebar,
 * beside the express preselect, which renders before the payment step.
 */
define([
    'jquery',
    'uiComponent',
    'Magento_Checkout/js/checkout-data',
    'Magento_Ui/js/model/messageList',
    'mage/cookies',
    'jquery/jquery-storageapi'
], function ($, Component, checkoutData, messageList) {
    'use strict';

    /**
     * Mirrors Controller\Payment\Cancel::DECLINED_PARAM, which emits it.
     */
    var MARKER = 'two_declined=1';

    /**
     * Whether this page load is the decline return's redirect, taking the
     * marker out of the address bar in the same breath.
     *
     * @returns {Boolean}
     */
    function takeMarker() {
        var location = window.location || {};
        var search = location.search || '';
        var pattern = new RegExp('([?&])' + MARKER + '(&|$)');
        var stripped;

        if (!pattern.test(search)) {
            return false;
        }

        try {
            stripped = search.replace(pattern, function (match, lead, trail) {
                return trail ? lead : '';
            });
            window.history.replaceState(
                window.history.state,
                '',
                location.pathname + (stripped === '?' ? '' : stripped) + (location.hash || '')
            );
        } catch (e) {
            // Refused (a sandboxed or embedded view): the marker stays in
            // this page's address, so a reload of it clears the remembered
            // choice again. The buyer can still choose; nothing is selected
            // for them.
        }

        return true;
    }

    /**
     * The cookie Magento queues a redirect's messages in (MessagePlugin).
     */
    var QUEUE = 'mage-messages';

    /**
     * Shows the newest error queued for the page messages block in the
     * checkout's message area, and empties the queue so nothing queued
     * before the landing turns up on a later page.
     */
    function showQueuedError() {
        var queued = $.cookieStorage.get(QUEUE);
        var newest = null;

        (Array.isArray(queued) ? queued : []).forEach(function (message) {
            if (message && message.type === 'error' && message.text) {
                newest = String(message.text);
            }
        });

        $.mage.cookies.set(QUEUE, '', {
            samesite: 'strict',
            domain: ''
        });

        if (newest) {
            messageList.addErrorMessage({ message: newest });
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

            if (takeMarker()) {
                checkoutData.setSelectedPaymentMethod(null);
                showQueuedError();
            }

            return this;
        }
    });
});
