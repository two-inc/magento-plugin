/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25503: `adoptSoleTraderBuyer()` writes the buyer's own phone number,
 * same as WooCommerce (PR #496) and PrestaShop (PR #186) now do. Unlike an
 * ordinary company-search selection — where `applyAddress()` deliberately
 * never writes telephone, because a registry business number is not the
 * buyer's own — this buyer record IS the buyer's own verified data.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const SEARCH = 'view/frontend/web/js/model/company-search.js';

const BUYER = {
    email: 'entered@example.com',
    organization_number: '999888777',
    company_name: 'Example Trader',
    billing_address: { street: 'Mill Lane', city: 'Ashford', country_code: 'GB' }
};

function loadRenderer(buyer, priorTelephone) {
    const companySearch = Object.assign(
        {},
        loadAmdModule(SEARCH, { jquery: require('./amd-harness').defaultMocks().jquery }),
        {
            applyAddress: function () {},
            billingRoleFormRoot: function () {
                return {};
            }
        }
    );

    const renderer = loadAmdModule(
        RENDERER,
        { 'Two_Gateway/js/model/company-search': companySearch },
        { window: { checkoutConfig: { payment: {} }, addEventListener: function () {} } }
    );

    renderer._brandConfig = { isAddressSearchEnabled: false };
    renderer.isAddressAreaCompanySearchEnabled = false;
    renderer.isOrderIntentEnabled = false;
    renderer.telephone(priorTelephone || '');

    return renderer;
}

describe('the sole-trader write-back includes the buyer\'s phone number', () => {
    test.each([
        ['+442012345678', '', '+442012345678', 'a phone number on the buyer is written to checkout'],
        [undefined, '+441234567890', '+441234567890', 'no phone number on the buyer leaves the field untouched'],
        ['', '+441234567890', '+441234567890', 'an empty string phone number leaves the field untouched']
    ])('%p (prior %p) -> %p (%s)', (buyerPhone, priorTelephone, expectedTelephone) => {
        const renderer = loadRenderer(BUYER, priorTelephone);

        renderer.adoptSoleTraderBuyer(Object.assign({}, BUYER, { phone_number: buyerPhone }));

        expect(renderer.telephone()).toBe(expectedTelephone);
    });

    test('a repeated adoption of the same identity does not re-run the phone write', () => {
        const renderer = loadRenderer(BUYER, '');
        const buyer = Object.assign({}, BUYER, { phone_number: '+442012345678' });
        let calls = 0;
        const soleTrader = renderer.soleTrader();
        const original = soleTrader.writeSoleTraderPhone.bind(soleTrader);
        soleTrader.writeSoleTraderPhone = function (b) {
            calls += 1;
            return original(b);
        };

        renderer.adoptSoleTraderBuyer(buyer);
        renderer.adoptSoleTraderBuyer(buyer);

        expect(calls).toBe(1);
    });
});
