/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503. `view/frontend/web/js/model/company-search-panel.js` is one of two
 * copies of the same panel module, so two checkouts render one control. The
 * copies are byte-identical, and `EDIT_LOCK_SHA256` below is what says so: the
 * WooCommerce plugin's own suite locks its copy to the same digest, so two
 * matching constants are the whole parity check, and two different ones are the
 * drift.
 *
 * To change shared panel behaviour: edit here, apply the identical edit to the
 * other copy, re-run both JS suites, and move both digests in the same change
 * set.
 */

'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const PANEL_PATH = 'view/frontend/web/js/model/company-search-panel.js';

/** sha256 of the shared panel module, identical in both plugins. */
const EDIT_LOCK_SHA256 = '2e08d09014bfd8c05e885058fa6f3589852b9daae24aa57f7fb07d81d9d72359';

describe('the vendored company-search panel', () => {
    test('has not been edited in place', () => {
        const bytes = fs.readFileSync(path.join(__dirname, '..', '..', PANEL_PATH));
        const digest = crypto.createHash('sha256').update(bytes).digest('hex');

        expect(digest).toBe(EDIT_LOCK_SHA256);
    });
});
