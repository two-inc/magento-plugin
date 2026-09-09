/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-548. The admin's own copy of the checkout's resolution order, so the
 * surcharge grid can disable the row the server will price against and the
 * differential label can name it while the field reads Automatic. Any drift
 * from Repository::getDefaultPaymentTerm() shows here first.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

const resolve = loadAmdModule('view/adminhtml/web/js/default-term.js', defaultMocks());

const ALL = [7, 14, 30, 45, 60];

describe('the term the admin surfaces name', () => {
    it.each([
        [[7, 30, 60], ALL, 60, 0, 60, "the admin's own choice wins"],
        [[7, 30, 60], ALL, 60, 7, 60, "and outranks the merchant's own default term"],
        [[7, 30, 60], ALL, 14, 60, 60, "an unoffered choice falls to the merchant's default term"],
        [[7, 30], ALL, 0, 45, 30, 'a merchant default term outside the configured set is ignored'],
        [[7, 30], ALL, 0, 0, 30, '30 is preferred over a shorter offered term'],
        [[7, 14], ALL, 0, 0, 7, 'without 30 offered the shortest offered term is used'],
        [[7, 14], ALL, 30, 0, 7, 'a choice of 30 that is not offered is ignored too'],
        [[], ALL, 0, 30, 0, 'nothing configured names no term at all'],
        // The legacy custom-term field can name a day the record does not
        // offer, and the checkout never resolves one of those.
        [[45, 60], [7, 30, 60], 0, 0, 60, 'a configured term the merchant does not offer is dropped'],
        [[45], [7, 30, 60], 0, 0, 0, 'nothing left after that names no term at all'],
        [[30, 45], [7, 45, 60], 0, 0, 45, 'and 30 is not preferred when the merchant does not offer it']
    ])(
        'configured %s of offered %s, chosen %s, merchant %s -> %s — %s',
        (configured, merchantOffered, chosen, merchantDefault, expected) => {
            expect(resolve(configured, merchantOffered, chosen, merchantDefault)).toBe(expected);
        }
    );
});
