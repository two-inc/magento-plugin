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
    withCompany:
        'Your invoice with Two is likely to be accepted for {{companyName}}, subject to additional checks.',
    withoutCompany: 'Your invoice with Two is likely to be accepted, subject to additional checks.',
    companyNameToken: '{{companyName}}'
};

/**
 * Build a `this` context standing in for a live renderer instance.
 *
 * Calls the renderer's own initOrderIntentApprovedNotice() — the real
 * observable and the real company-change subscriptions — rather than the
 * whole of initialize(), which drags in company search, the address/quote
 * graph and select2 and would make these specs a mocking exercise.
 */
function makeContext(noticeCopy) {
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
        orderIntentDeclinedMessage: 'Declined.'
    });
    ctx.orderIntentDeclinedMessage = 'Declined.';

    return ctx;
}

describe('gateway_method intent-approved notice', () => {
    test('approval renders the company-name variant inline, never via the messages region', () => {
        const ctx = makeContext(DEFAULT_COPY);
        ctx.companyName('Acme Widgets AS');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        // addSuccessMessage throws in the stub: reaching here at all proves
        // the renderer no longer routes the notice through getRegion('messages').
        expect(ctx.orderIntentApprovedNotice()).toBe(
            'Your invoice with Two is likely to be accepted for Acme Widgets AS, subject to additional checks.'
        );
    });

    test('falls back to the no-company variant when the company name is blank', () => {
        const ctx = makeContext(DEFAULT_COPY);
        ctx.companyName('   ');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        expect(ctx.orderIntentApprovedNotice()).toBe(DEFAULT_COPY.withoutCompany);
    });

    test('takes a company name containing $-sequences literally', () => {
        const ctx = makeContext(DEFAULT_COPY);
        // String.replace treats $& / $1 in the *replacement* as patterns; the
        // renderer passes a replacer function to avoid that.
        ctx.companyName('A$& B$1 Ltd');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        expect(ctx.orderIntentApprovedNotice()).toContain('A$& B$1 Ltd');
    });

    test('emits nothing at all when the brand suppressed the notice', () => {
        // ConfigProvider ships null for a brand whose brand.xml declares
        // <intent_approved_notice_enabled>false</intent_approved_notice_enabled>.
        // The observable stays '' so the template's `ko if` never emits an
        // element.
        const ctx = makeContext(null);
        ctx.companyName('Acme Widgets AS');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        expect(ctx.orderIntentApprovedNotice()).toBe('');
    });

    test('a decline clears the notice and still shows the decline message', () => {
        const ctx = makeContext(DEFAULT_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });
        expect(ctx.orderIntentApprovedNotice()).not.toBe('');

        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: false });

        expect(ctx.orderIntentApprovedNotice()).toBe('');
        expect(ctx.errors).toEqual(['Declined.']);
    });

    test('an intent error clears the notice', () => {
        const ctx = makeContext(DEFAULT_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        ctx.processOrderIntentErrorResponse.call(ctx, {});

        expect(ctx.orderIntentApprovedNotice()).toBe('');
    });

    test('changing the company clears the notice', () => {
        const ctx = makeContext(DEFAULT_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });
        expect(ctx.orderIntentApprovedNotice()).not.toBe('');

        ctx.companyName('Different Co AS');

        // The approval was for the previous company; keeping it would be a
        // buyer-facing lie.
        expect(ctx.orderIntentApprovedNotice()).toBe('');
    });

    test('changing the company number clears the notice', () => {
        const ctx = makeContext(DEFAULT_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        ctx.companyId('999888777');

        expect(ctx.orderIntentApprovedNotice()).toBe('');
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
