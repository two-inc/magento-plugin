/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * jsdom keeps one window per file, and the capture host records a manual entry
 * in sessionStorage so it survives a reload (TWO-26775). Cleared between cases,
 * or one case's manual entry is the next one's "reload".
 */

'use strict';

afterEach(function () {
    if (typeof window !== 'undefined' && window.sessionStorage) window.sessionStorage.clear();
});
