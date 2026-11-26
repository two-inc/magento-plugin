/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * A declined order intent disables the Place Order button, so the
 * buyer must be told why. These specs pin the sentence being present, correct
 * and carried by a live region — and that no brand configuration can withhold
 * it, which is the state the defect was reported from.
 *
 * jsdom has no accessibility layer, so nothing here proves what a screen
 * reader utters. What it proves is the markup contract announcement depends
 * on: a live region rendered ahead of its content, and the button associated
 * with the sentence that explains it. The utterance itself is a browser check.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { loadAmdModule } = require('./amd-harness');

const ROOT = path.join(__dirname, '..', '..');
const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

const BRAND_DECLINED_COPY = {
    withCompany: 'Two is not available for this order by {{companyName}} ({{companyNumber}})',
    withoutCompany: 'Two is not available for this order',
    companyNameToken: '{{companyName}}',
    companyNumberToken: '{{companyNumber}}'
};

const APPROVED_COPY = {
    withCompany: 'This order by {{companyName}} ({{companyNumber}}) is likely to be accepted by Two',
    withoutCompany: 'This order is likely to be accepted by Two',
    companyNameToken: '{{companyName}}',
    companyNumberToken: '{{companyNumber}}'
};

const PLATFORM_FALLBACK = 'Invoice purchase with Two is not available for this order.';
const BRAND_SENTENCE = 'Two is not available for this order by Acme Widgets AS (123456789)';

/** An observable with real knockout's "an equal write notifies nobody". */
function koObservable(initial) {
    let value = initial,
        subscribers = [];
    const obs = function (next) {
        if (arguments.length === 0) {
            return value;
        }
        if (next === value) {
            return value;
        }
        value = next;
        subscribers.forEach(function (fn) {
            fn(value);
        });
        return value;
    };
    obs.subscribe = function (fn) {
        subscribers.push(fn);
        return { dispose: function () {} };
    };
    return obs;
}

/** A `this` standing in for a live renderer instance, wired for the notices only. */
function makeContext(declinedCopy) {
    const component = loadAmdModule(RENDERER);
    const ctx = Object.assign({}, component, {
        companyName: koObservable(''),
        companyId: koObservable(''),
        generalErrorMessage: 'Something went wrong.',
        orderIntentNotAvailableMessage: PLATFORM_FALLBACK,
        messageContainer: {
            clear: function () {},
            addSuccessMessage: function () {},
            addErrorMessage: function () {},
            errorMessages: { push: function () {}, remove: function () {} }
        }
    });
    ctx.showErrorMessage = function () {};
    ctx.getCode = function () {
        return 'two_payment';
    };
    component.initOrderIntentApprovedNotice.call(ctx, {
        orderIntentApprovedNotice: APPROVED_COPY,
        orderIntentDeclinedNotice: declinedCopy
    });
    ctx.companyName('Acme Widgets AS');
    ctx.companyId('123456789');
    return ctx;
}

/** Replay a sequence of intent replies; a `null` entry is a failed check. */
function replay(ctx, outcomes) {
    outcomes.forEach(function (outcome) {
        if (outcome === null) {
            ctx.processOrderIntentErrorResponse.call(ctx, {});
            return;
        }
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: outcome });
    });
}

function template() {
    return fs.readFileSync(
        path.join(ROOT, 'view/frontend/web/template/payment/gateway_method.html'),
        'utf8'
    );
}

function withoutComments(markup) {
    return markup.replace(/<!--(?!\s*\/?ko\b)[\s\S]*?-->/g, '');
}

describe('a declined order intent always explains itself', () => {
    test.each([
        {
            copy: BRAND_DECLINED_COPY,
            outcomes: [false],
            sentence: BRAND_SENTENCE,
            visible: true,
            case: "a decline states the brand's own wording"
        },
        {
            copy: null,
            outcomes: [false],
            sentence: PLATFORM_FALLBACK,
            visible: true,
            case: 'a decline on a brand that withheld the copy states platform wording instead'
        },
        {
            copy: BRAND_DECLINED_COPY,
            outcomes: [true],
            sentence: '',
            visible: false,
            case: 'an approval leaves the decline region empty'
        },
        {
            copy: null,
            outcomes: [null],
            sentence: '',
            visible: false,
            case: 'a FAILED check is not a decline and states nothing in this region'
        },
        {
            copy: null,
            outcomes: [false, true],
            sentence: '',
            visible: false,
            case: 'switching back to an approved company clears the decline with no reload'
        },
        {
            copy: null,
            outcomes: [false, null],
            sentence: '',
            visible: false,
            case: 'a failed check after a decline retires the decline rather than stacking'
        }
    ])('the decline region: $case', ({ copy, outcomes, sentence, visible }) => {
        const ctx = makeContext(copy);

        replay(ctx, outcomes);

        expect(ctx.orderIntentDeclinedNotice()).toBe(sentence);
        expect(ctx.isOrderIntentDeclinedNoticeVisible()).toBe(visible);
    });

    test('a failed check states itself in its own region, not the decline one', () => {
        const ctx = makeContext(null);

        ctx.processOrderIntentErrorResponse.call(ctx, {});

        expect(ctx.orderIntentErrorNotice()).toBe(PLATFORM_FALLBACK);
        expect(ctx.orderIntentDeclinedNotice()).toBe('');
    });

    // ABN-643: every failure of the check states the not-available sentence a
    // decline gives, whoever failed: Two refusing, erring or timing out, the
    // shop's own route, or this module refusing before calling Two, a refusal
    // naming a field included. Only a rate limit says something else.
    test.each([
        { copy: null, failure: { status: 400, responseJSON: { error_code: 'BUYER_REFUSED' } }, notice: PLATFORM_FALLBACK, case: 'an API refusal states the not-available sentence' },
        { copy: BRAND_DECLINED_COPY, failure: { status: 400, responseJSON: { error_code: 'BUYER_REFUSED' } }, notice: BRAND_SENTENCE, case: 'an API refusal names the company, as a decline does' },
        { copy: BRAND_DECLINED_COPY, failure: { status: 502, responseJSON: { error_code: 'PROXY_REFUSED', error_message: 'The service is temporarily unavailable. Please try again.' } }, notice: BRAND_SENTENCE, case: 'an API server error names the company, as a decline does' },
        // The two bodies the envelope relays for a refusal with nothing relayable:
        // the adapter's own reading of an empty body, and its stand-in for a body
        // carrying none of the relayed keys.
        { copy: null, failure: { status: 403, responseJSON: { error_code: 400, error_message: 'Invalid API response from Two.' } }, notice: PLATFORM_FALLBACK, case: 'an API refusal with an empty body states the not-available sentence' },
        { copy: null, failure: { status: 403, responseJSON: { error_code: 'PROXY_REFUSED', error_message: 'The service is temporarily unavailable. Please try again.' } }, notice: PLATFORM_FALLBACK, case: 'an API refusal with no relayable keys states the not-available sentence' },
        { copy: null, failure: { status: 400, responseJSON: { error_json: [{ loc: ['buyer', 'representative', 'phone_number'], msg: 'bad' }] } }, notice: PLATFORM_FALLBACK, case: 'a validation refusal naming a field states the not-available sentence' },
        { copy: BRAND_DECLINED_COPY, failure: { status: 400, responseJSON: { error_code: 'SCHEMA_ERROR', error_json: [{ loc: ['buyer', 'representative', 'phone_number'], msg: 'bad' }] } }, notice: BRAND_SENTENCE, case: 'a validation refusal naming a field names the company, as a decline does' },
        { copy: null, failure: { status: 400, responseJSON: { error_json: [{ loc: ['body'], msg: 'bad' }] } }, notice: PLATFORM_FALLBACK, case: 'a validation refusal naming nothing fixable states the not-available sentence' },
        { copy: null, failure: { status: 503, responseJSON: { error_code: 'PROXY_REFUSED', error_message: 'Module refused.' } }, notice: PLATFORM_FALLBACK, case: 'a refusal made by this module states the not-available sentence' },
        { copy: null, failure: { status: 502, responseJSON: { error_code: 'PROXY_REFUSED', error_message: 'The service is temporarily unavailable. Please try again.' } }, notice: PLATFORM_FALLBACK, case: 'an API server error states the not-available sentence' },
        { copy: null, failure: { status: 0, responseJSON: { error_code: 'PROXY_REFUSED', error_message: 'The service is temporarily unavailable. Please try again.' } }, notice: PLATFORM_FALLBACK, case: 'an API timeout states the not-available sentence' },
        { copy: null, failure: { status: 404, responseJSON: { message: 'Request does not match any route.' } }, notice: PLATFORM_FALLBACK, case: 'the shop route missing after a cache flush states the not-available sentence' },
        { copy: null, failure: { status: 400, responseJSON: { message: '"payload" is required.' } }, notice: PLATFORM_FALLBACK, case: 'a shop webapi input fault states the not-available sentence' },
        { copy: null, failure: { status: 401, responseJSON: { message: 'Not authorized.' } }, notice: PLATFORM_FALLBACK, case: 'a shop webapi auth fault states the not-available sentence' },
        { copy: null, failure: { status: 400 }, notice: PLATFORM_FALLBACK, case: 'a bodyless shop 400 states the not-available sentence' },
        { copy: null, failure: { status: 503, responseJSON: {} }, notice: PLATFORM_FALLBACK, case: 'a shop server error states the not-available sentence' },
        { copy: null, failure: { status: 0 }, notice: PLATFORM_FALLBACK, case: 'a transport failure states the not-available sentence' },
        { copy: null, failure: { status: 429 }, notice: 'Too many requests. Please wait a moment and try again.', case: 'a rate limit asks the buyer to wait' }
    ])('the error region: $case', ({ copy, failure, notice, case: description }) => {
        const ctx = makeContext(copy);

        ctx.processOrderIntentErrorResponse.call(ctx, failure);

        expect([description, ctx.orderIntentErrorNotice()]).toEqual([description, notice]);
        expect([description, ctx.orderIntentDeclinedNotice()]).toEqual([description, '']);
    });

    // A checkout config cached before `orderIntentNotAvailableMessage` shipped
    // must not leave either box blank.
    test.each([
        { run: (ctx) => ctx.processOrderIntentSuccessResponse.call(ctx, { approved: false }), read: (ctx) => ctx.orderIntentDeclinedNotice(), case: 'a decline' },
        { run: (ctx) => ctx.processOrderIntentErrorResponse.call(ctx, { status: 400, responseJSON: { error_code: 'BUYER_REFUSED' } }), read: (ctx) => ctx.orderIntentErrorNotice(), case: 'an API refusal' }
    ])('with no not-available sentence in the config, $case shows the general error', ({ run, read, case: description }) => {
        const ctx = makeContext(null);
        delete ctx.orderIntentNotAvailableMessage;

        run(ctx);

        expect([description, read(ctx)]).toEqual([description, 'Something went wrong.']);
    });

    test('the region id is per payment code, so sibling brand tiles cannot collide', () => {
        const ctx = makeContext(null);
        expect(ctx.orderIntentDeclinedRegionId()).toBe('two-order-intent-declined-two_payment');

        ctx.getCode = function () {
            return 'two_payment_other_brand';
        };
        expect(ctx.orderIntentDeclinedRegionId()).toBe(
            'two-order-intent-declined-two_payment_other_brand'
        );
    });
});

describe('the decline sentence is announced, not merely present', () => {
    /** The decline region element, with its conditional content still attached. */
    function region() {
        const markup = withoutComments(template());
        const match = markup.match(
            /<div\b([^>]*class="two-order-intent-declined-region"[^>]*)>([\s\S]*?)<\/div>\s*<\/div>/
        );
        if (match === null) {
            throw new Error('the template has no .two-order-intent-declined-region element');
        }
        return { attributes: match[1], body: match[2] };
    }

    /** The knockout containerless bindings still open at the region's element. */
    function enclosingBindings() {
        const markup = withoutComments(template());
        const before = markup.slice(0, markup.indexOf('class="two-order-intent-declined-region"'));
        const stack = [];
        (before.match(/<!--\s*(\/?)ko\s*([a-z]*)/g) || []).forEach(function (marker) {
            if (marker.indexOf('/ko') !== -1) {
                stack.pop();
                return;
            }
            stack.push(marker.replace(/<!--\s*ko\s*/, ''));
        });
        return stack;
    }

    test('the region is rendered unconditionally and the box inside it conditionally', () => {
        const { attributes, body } = region();

        // The element must exist before any verdict lands, which is what a live
        // region needs — so nothing conditional encloses it, at any depth.
        expect(enclosingBindings()).toEqual([]);
        expect(attributes).toMatch(/role="alert"/);
        expect(body).toMatch(/<!--\s*ko\s+if:\s*isOrderIntentDeclinedNoticeVisible\(\)\s*-->/);
        expect(body).toMatch(/class="two-order-intent-message declined"/);
        expect(body).toMatch(/data-bind="text: orderIntentDeclinedNotice"/);
    });

    test('the region carries the id the Place Order button describes itself by', () => {
        const { attributes } = region();
        expect(attributes).toMatch(/attr:\s*\{id:\s*orderIntentDeclinedRegionId\(\)\}/);

        const button = withoutComments(template()).match(
            /<button\b[^>]*data-role="review-save"[\s\S]*?>/
        );
        if (button === null) {
            throw new Error('the template has no data-role="review-save" button');
        }
        expect(button[0]).toMatch(
            /'aria-describedby':\s*isOrderIntentDeclinedNoticeVisible\(\)\s*\?\s*orderIntentDeclinedRegionId\(\)\s*:\s*null/
        );
    });

    test('the region stays unstyled, so an empty one paints no box', () => {
        const css = fs.readFileSync(path.join(ROOT, 'view/frontend/web/css/style.css'), 'utf8');
        expect(css).not.toMatch(/\.two-order-intent-declined-region\s*\{/);
    });
});
