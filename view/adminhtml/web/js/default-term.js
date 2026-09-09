define([], function () {
    'use strict';

    var PREFERRED = 30;

    /**
     * The term the checkout will preselect, mirroring
     * Repository::getDefaultPaymentTerm() so the admin's surcharge grid and
     * the differential label can name it while the field reads Automatic
     * (ABN-548). 0 when no term is offered.
     *
     * @param {number[]} offered ticked terms, ascending
     * @param {number} chosen the admin's own stored choice, 0 for Automatic
     * @param {number} merchantDefault the merchant's own default term, 0 for none
     * @returns {number}
     */
    return function (offered, chosen, merchantDefault) {
        if (offered.indexOf(chosen) !== -1) {
            return chosen;
        }
        if (offered.indexOf(merchantDefault) !== -1) {
            return merchantDefault;
        }
        if (offered.indexOf(PREFERRED) !== -1) {
            return PREFERRED;
        }
        return offered.length ? offered[0] : 0;
    };
});
