define([], function () {
    'use strict';

    var PREFERRED = 30;

    /**
     * The term the checkout will preselect, mirroring
     * Repository::getDefaultPaymentTerm() so the admin's surcharge grid and
     * the differential label can name it while the field reads Automatic
     * (ABN-548). 0 when nothing is offered.
     *
     * `candidates` is intersected with `merchantOffered` here rather than by
     * each caller: the legacy custom-term field can name a day the merchant's
     * record does not offer, and the checkout never resolves one of those.
     *
     * @param {number[]} candidates configured terms — ticked plus any custom day
     * @param {number[]} merchantOffered every term the merchant's record offers
     * @param {number} chosen the admin's own stored choice, 0 for Automatic
     * @param {number} merchantDefault the merchant's own default term, 0 for none
     * @returns {number}
     */
    return function (candidates, merchantOffered, chosen, merchantDefault) {
        var offered = candidates.filter(function (days) {
            return merchantOffered.indexOf(days) !== -1;
        });

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
