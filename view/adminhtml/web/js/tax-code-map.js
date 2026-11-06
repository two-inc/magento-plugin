/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * "Tax codes for 0% lines" (TWO-26153): writes the dropdowns into the one
 * hidden field that is posted, as a JSON object of row key => code, so the
 * setting costs one POST variable however many rows the shop has. Rows the
 * form does not show are kept from the field's data-carried attribute; a row
 * on (none) is left out.
 */
define([], function () {
    'use strict';

    function serialise(rows, input) {
        var map = JSON.parse(input.getAttribute('data-carried') || '{}');

        Array.prototype.forEach.call(rows.querySelectorAll('select[data-key]'), function (select) {
            var key = select.getAttribute('data-key');

            if (select.value) {
                map[key] = select.value;
            } else {
                delete map[key];
            }
        });
        input.value = JSON.stringify(map);
    }

    return function (config, rows) {
        var input = rows.ownerDocument.getElementById(config.input);

        rows.addEventListener('change', function () {
            serialise(rows, input);
        });
        serialise(rows, input);
    };
});
