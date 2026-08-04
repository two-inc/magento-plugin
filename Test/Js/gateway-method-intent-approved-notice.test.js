/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The intent-approved notice is a PERSISTENT inline element inside the
 * payment tile (TWO-25213), replacing the messageContainer success message
 * that checkout cleared on every update. These specs pin the set/clear
 * discipline that makes "persistent" mean something narrower than "forever":
 * it survives a placeOrder validation failure, but not a company change and
 * not a fresh decline/error.
 */

'use strict';

const { loadAmdModule, defaultMocks } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

const DEFAULT_COPY = {
    withCompany: 'This order by {{companyName}} ({{companyNumber}}) is likely to be accepted by Two',
    withoutCompany: 'This order is likely to be accepted by Two',
    companyNameToken: '{{companyName}}',
    companyNumberToken: '{{companyNumber}}'
};

const DECLINED_COPY = {
    withCompany: 'Two is not available for this order by {{companyName}} ({{companyNumber}})',
    withoutCompany: 'Two is not available for this order',
    companyNameToken: '{{companyName}}',
    companyNumberToken: '{{companyNumber}}'
};

/**
 * Build a `this` context standing in for a live renderer instance.
 *
 * Calls the renderer's own initOrderIntentApprovedNotice() — the real
 * observable and the real company-change subscriptions — rather than the
 * whole of initialize(), which drags in company search, the address/quote
 * graph and select2 and would make these specs a mocking exercise.
 */
function makeContext(noticeCopy, declinedCopy) {
    const component = loadAmdModule(RENDERER);
    const ko = defaultMocks().ko;

    const ctx = Object.assign({}, component, {
        companyName: ko.observable(''),
        companyId: ko.observable(''),
        messageContainer: {
            cleared: 0,
            clear: function () {
                this.cleared += 1;
            },
            addSuccessMessage: function () {
                throw new Error('must not use the messages region');
            },
            addErrorMessage: function () {},
            errorMessages: { push: function () {}, remove: function () {} }
        },
        errors: []
    });

    ctx.showErrorMessage = function (message) {
        ctx.errors.push(message);
    };

    component.initOrderIntentApprovedNotice.call(ctx, {
        orderIntentApprovedNotice: noticeCopy,
        // TWO-25326 §7.3: the "not approved" business outcome now renders
        // via the SAME persistent-notice mechanism, with its own copy —
        // undefined here defaults to '' if the individual test doesn't
        // supply it, matching a caller that never wired the key.
        orderIntentDeclinedNotice: declinedCopy
    });

    return ctx;
}

describe('gateway_method intent-approved notice', () => {
    test('approval renders the company-name and -number variant inline, never via the messages region', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.companyId('123456789');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        // addSuccessMessage throws in the stub: reaching here at all proves
        // the renderer no longer routes the notice through getRegion('messages').
        expect(ctx.orderIntentApprovedNotice()).toBe(
            'This order by Acme Widgets AS (123456789) is likely to be accepted by Two'
        );
    });

    test('falls back to the no-company variant when the company name is blank', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.companyName('   ');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        expect(ctx.orderIntentApprovedNotice()).toBe(DEFAULT_COPY.withoutCompany);
    });

    test('takes a company name containing $-sequences literally', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        // String.replace treats $& / $1 in the *replacement* as patterns; the
        // renderer passes a replacer function to avoid that.
        ctx.companyName('A$& B$1 Ltd');
        ctx.companyId('999');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        expect(ctx.orderIntentApprovedNotice()).toContain('A$& B$1 Ltd');
    });

    test('emits nothing at all when the brand suppressed the notice', () => {
        // ConfigProvider ships null for a brand whose brand.xml declares
        // <intent_approved_notice_enabled>false</intent_approved_notice_enabled>.
        // The observable stays '' so the template's `ko if` never emits an
        // element.
        const ctx = makeContext(null, null);
        ctx.companyName('Acme Widgets AS');
        ctx.companyId('123456789');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        expect(ctx.orderIntentApprovedNotice()).toBe('');
    });

    test('a decline clears the approved notice and shows the persistent declined notice instead', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.companyId('123456789');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });
        expect(ctx.orderIntentApprovedNotice()).not.toBe('');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: false });

        expect(ctx.orderIntentApprovedNotice()).toBe('');
        expect(ctx.orderIntentDeclinedNotice()).toBe(
            'Two is not available for this order by Acme Widgets AS (123456789)'
        );
        // No toast for a clean decline — the persistent tile notice is the
        // only surface, matching the "tile shows ONLY the intent message"
        // ruling.
        expect(ctx.errors).toEqual([]);
    });

    test('an intent error clears both notices', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.companyId('123456789');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        ctx.processOrderIntentErrorResponse.call(ctx, {});

        expect(ctx.orderIntentApprovedNotice()).toBe('');
        expect(ctx.orderIntentDeclinedNotice()).toBe('');
    });

    test('changing the company clears both notices', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.companyId('123456789');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });
        expect(ctx.orderIntentApprovedNotice()).not.toBe('');

        ctx.companyName('Different Co AS');

        // The approval was for the previous company; keeping it would be a
        // buyer-facing lie.
        expect(ctx.orderIntentApprovedNotice()).toBe('');
        expect(ctx.orderIntentDeclinedNotice()).toBe('');
    });

    test('changing the company number clears both notices', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.companyId('123456789');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        ctx.companyId('999888777');

        expect(ctx.orderIntentApprovedNotice()).toBe('');
        expect(ctx.orderIntentDeclinedNotice()).toBe('');
    });

    test('the notice survives a messageContainer clear (failed placeOrder validation)', () => {
        const ctx = makeContext(DEFAULT_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });
        const before = ctx.orderIntentApprovedNotice();

        // What placeOrder() does on every submit attempt.
        ctx.messageContainer.clear();

        expect(ctx.messageContainer.cleared).toBe(1);
        expect(ctx.orderIntentApprovedNotice()).toBe(before);
    });

    test('each renderer instance gets its own notice observable', () => {
        // Observables declared in `defaults` are copied by reference onto
        // every instance; this one is created in initialize() precisely so
        // one quote's notice cannot leak into a re-created renderer.
        const first = makeContext(DEFAULT_COPY);
        const second = makeContext(DEFAULT_COPY);

        first.companyName('Acme Widgets AS');
        first.processOrderIntentSuccessResponse.call(first, { approved: true });

        expect(first.orderIntentApprovedNotice()).not.toBe('');
        expect(second.orderIntentApprovedNotice()).toBe('');
    });
});
