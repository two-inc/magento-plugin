/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * ABN-563: a declined order intent disables the Place Order button, so the
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
const { loadAmdModule, defaultMocks } = require('./amd-harness');

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

const PLATFORM_FALLBACK = 'This payment method is not available for the selected company.';
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

describe('a declined order intent always explains itself (ABN-563)', () => {
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

        expect(ctx.orderIntentErrorNotice()).toBe('Something went wrong.');
        expect(ctx.orderIntentDeclinedNotice()).toBe('');
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

describe('the decline sentence is announced, not merely present (ABN-563)', () => {
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

    test('the region is rendered unconditionally and the box inside it conditionally', () => {
        const { attributes, body } = region();
        const markup = withoutComments(template());

        // No `ko if` immediately above the region: its element exists before
        // any verdict lands, which is what a live region needs.
        expect(markup).not.toMatch(
            /<!--\s*ko\s+if:[^>]*-->\s*<div[^>]*class="two-order-intent-declined-region"/
        );
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
