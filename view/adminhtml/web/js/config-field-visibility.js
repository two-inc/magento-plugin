/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

define(['jquery'], function ($) {
    'use strict';

    /**
     * Show or hide an admin config row and scope validation with it: Magento's
     * admin validator does not ignore `:hidden` (ABN-558). Never sets
     * `disabled` — these rows must still post or hidden values are wiped.
     */
    return function ($row, relevant) {
        $row.toggle(relevant).toggleClass('ignore-validate', !relevant);

        if (relevant) {
            return;
        }

        // A refusal earned while visible must not outlive the field.
        $row.find('.mage-error').remove();
        $row.find('[aria-invalid]').removeAttr('aria-invalid').removeAttr('aria-describedby');
    };
});
