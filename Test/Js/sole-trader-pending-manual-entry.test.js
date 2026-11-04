/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-26775: a Sole trader click from manual entry leaves manual entry in place
 * until a sole trader is adopted over it, so a saved address arriving while the
 * signup is up must not write its company number. That covers the close poll's
 * grace too: a popup the buyer has closed reads as up until the poll acts on it.
 * Driven through the real capture, panel and flow (`sole-trader-stack.js`) and
 * the real payment renderer.
 */

'use strict';

const { loadAmdModule, defaultMocks, quoteAddressValue } = require('./amd-harness');
const { startStack, chip, teardown } = require('./sole-trader-stack');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const TYPED = 'Typed Name';
const SAVED = { name: 'Saved Company Ltd', id: '12345678' };

afterEach(teardown);

/** A saved address-book entry, carrying the company of the buyer's last Two order. */
function savedAddress() {
    return quoteAddressValue({
        company: SAVED.name,
        customAttributes: [{ attribute_code: 'company_id', value: SAVED.id }]
    });
}

/**
 * A Sole trader click left pending over the given resting mode.
 *
 * @param {string} resting 'manual' or 'registered'
 * @param {boolean} handClosed the buyer has closed the popup and the close poll has not yet acted
 * @returns {Promise<object>} the stack, with the real renderer over its capture
 */
async function pendingOver(resting, handClosed) {
    const ctx = await startStack();
    ctx.identity.write({ companyName: TYPED, companyId: '' }, { authoritative: true });
    if (resting === 'manual') ctx.component.manualEntryMode();
    chip('soletrader').click();
    expect(ctx.rec.handles).toHaveLength(1);
    if (handClosed) ctx.rec.handles[0].closed = true;
    const renderer = loadAmdModule(RENDERER, Object.assign({}, defaultMocks(), ctx.mocks, {
        'Two_Gateway/js/model/company-capture': ctx.capture
    }), ctx.globals);
    renderer.getCode = function () { return 'two_payment'; };
    renderer.isOrderIntentEnabled = false;
    return Object.assign({ renderer: renderer }, ctx);
}

/** @returns {string} the number a quote address carries */
function companyIdOf(address) {
    return address.customAttributes.filter(function (item) {
        return item.attribute_code === 'company_id';
    })[0].value;
}

describe('a Sole trader click pending over manual entry is still manual entry (TWO-26775)', () => {
    test.each([
        ['manual', false, '', 'the popup is up'],
        ['manual', true, '', 'the popup is closed and the poll has not acted'],
        ['registered', false, SAVED.id, 'over a registered capture a saved address keeps its number']
    ])('over %s (hand-closed %p) a saved quote address carries %p (%s)', async (resting, handClosed, expected, why) => {
        const ctx = await pendingOver(resting, handClosed);
        const address = savedAddress();

        ctx.mocks['Magento_Checkout/js/model/quote'].shippingAddress(address);

        expect([why, ctx.identity.captureMode(), companyIdOf(address)]).toEqual([why, 'soletrader', expected]);
    });

    test.each([
        ['fillCompanyData', 'manual', '', 'a shipping-panel relay'],
        ['updateBillingAddress', 'manual', '', 'the billing address, whose role the shipping panel holds'],
        ['fillCompanyData', 'registered', SAVED.id, 'over a registered capture the relay still seeds it'],
        ['updateBillingAddress', 'registered', SAVED.id, 'over a registered capture the billing address still seeds it']
    ])('%s over %s leaves the number %p (%s)', async (method, resting, expected, why) => {
        const ctx = await pendingOver(resting, true);

        if (method === 'fillCompanyData') {
            ctx.renderer.fillCompanyData({ companyName: SAVED.name, companyId: SAVED.id });
        } else {
            ctx.renderer.updateBillingAddress(savedAddress());
        }

        expect([why, ctx.identity.captureMode(), ctx.identity.companyId()]).toEqual([why, 'soletrader', expected]);
    });
});
