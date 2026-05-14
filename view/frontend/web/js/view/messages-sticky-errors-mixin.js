/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Suppress the 5s auto-hide on the checkout messages widget when there is at
 * least one error message in the container. Buyers need long enough to read
 * (and copy) the message — including any Two trace ID — without it disappearing
 * mid-read. Success messages still fade per Magento default.
 */
define([], function () {
    'use strict';

    return function (Component) {
        return Component.extend({
            onHiddenChange: function (isHidden) {
                if (isHidden
                    && this.messageContainer
                    && typeof this.messageContainer.getErrorMessages === 'function'
                    && this.messageContainer.getErrorMessages().length > 0
                ) {
                    return;
                }
                return this._super(isHidden);
            }
        });
    };
});
