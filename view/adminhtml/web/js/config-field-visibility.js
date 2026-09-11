/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

define(['jquery'], function ($) {
    'use strict';

    /**
     * Show or hide an admin config row, keeping validation scoped to what the
     * merchant can see: Magento's admin validator does not ignore `:hidden`, so
     * a field hidden as irrelevant otherwise refuses the save with its message
     * rendered inside the hidden row (ABN-558). Unlike core's dependence
     * controller this never sets `disabled` — these rows must still post, or
     * the values behind a hidden column are wiped on every save.
     *
     * @param {jQuery} $row container being shown or hidden
     * @param {boolean} relevant
     */
    return function ($row, relevant) {
        $row.toggle(relevant).toggleClass('ignore-validate', !relevant);

        if (relevant) {
            return;
        }

        // A refusal earned while the field was on screen must not outlive it as
        // one the merchant can neither read nor clear.
        $row.find('.mage-error').remove();
        $row.find('[aria-invalid]').removeAttr('aria-invalid').removeAttr('aria-describedby');
    };
});
