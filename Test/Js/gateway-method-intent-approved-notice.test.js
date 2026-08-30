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
 * An observable with REAL knockout's notification semantics: writing the value
 * it already holds notifies nobody.
 *
 * The AMD harness's ko double notifies on every write, equal or not, and the
 * company observables are the one place in these specs where that difference
 * decides an outcome — "the previous verdict is cleared when a new check
 * starts" is trivially true under a double that fires the company-change
 * subscription on an unchanged write, and false in the browser (TWO-25326,
 * 2026-08-05). Only primitives are compared, which is all these two ever hold.
 */
function koObservable(initial) {
    let value = initial;
    const subscribers = [];
    function obs(next) {
        if (arguments.length === 0) return value;
        if (next === value) return obs;
        value = next;
        subscribers.forEach(function (fn) {
            fn(value);
        });
        return obs;
    }
    obs.subscribe = function (fn) {
        subscribers.push(fn);
        return { dispose: function () {} };
    };
    obs.peek = function () {
        return value;
    };
    return obs;
}

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
        companyName: koObservable(''),
        companyId: koObservable(''),
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

    test('an intent error clears both verdict notices', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.companyName('Acme Widgets AS');
        ctx.companyId('123456789');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        ctx.processOrderIntentErrorResponse.call(ctx, {});

        expect(ctx.orderIntentApprovedNotice()).toBe('');
        expect(ctx.orderIntentDeclinedNotice()).toBe('');
    });

    test('an intent error states itself in the tile box, not the checkout toast', () => {
        // TWO-25326 (2026-08-05): all three outcomes share one bordered
        // container across the four plugins. The message region this used to
        // go to is cleared on the next checkout update, so the buyer's only
        // sign the check failed could vanish on its own.
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.generalErrorMessage = 'Something went wrong.';

        ctx.processOrderIntentErrorResponse.call(ctx, {});

        expect(ctx.orderIntentErrorNotice()).toBe('Something went wrong.');
        expect(ctx.isOrderIntentErrorNoticeVisible.call(ctx)).toBe(true);
        expect(ctx.errors).toEqual([]);
    });

    test('the error box is not silenced by the brand suppressing the intent verdict', () => {
        // A brand turning the approved/declined sentence off is declining to
        // state a VERDICT; it has not asked for a failed check to be silent.
        const ctx = makeContext(null, null);
        ctx.generalErrorMessage = 'Something went wrong.';

        ctx.processOrderIntentErrorResponse.call(ctx, {});

        expect(ctx.orderIntentErrorNotice()).toBe('Something went wrong.');
    });

    test('a SCHEMA_ERROR keeps its per-field errors in the message region and leaves the box empty', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.generalErrorMessage = 'Something went wrong.';
        const pushed = [];
        ctx.messageContainer.errorMessages = {
            push: function (msg) {
                pushed.push(msg);
            },
            remove: function () {}
        };

        ctx.processOrderIntentErrorResponse.call(ctx, {
            responseJSON: {
                error_code: 'SCHEMA_ERROR',
                error_json: [{ msg: 'company_name is required' }]
            }
        });

        // Several field-level errors at once belong with the fields, not in a
        // box that states one outcome.
        expect(pushed).toEqual(['company_name is required']);
        expect(ctx.orderIntentErrorNotice()).toBe('');
    });

    test('a later error replaces the earlier one rather than stacking', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.generalErrorMessage = 'Something went wrong.';
        ctx.processOrderIntentErrorResponse.call(ctx, {});

        ctx.processOrderIntentErrorResponse.call(ctx, {
            responseJSON: {
                error_code: 'JSON_MISSING_FIELD',
                error_details: 'billing_address is missing'
            }
        });

        expect(ctx.orderIntentErrorNotice()).toBe('billing_address is missing');
    });

    test('an approval clears a previous error box', () => {
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.generalErrorMessage = 'Something went wrong.';
        ctx.processOrderIntentErrorResponse.call(ctx, {});
        expect(ctx.orderIntentErrorNotice()).not.toBe('');

        ctx.companyName('Acme Widgets AS');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });

        expect(ctx.orderIntentErrorNotice()).toBe('');
        expect(ctx.orderIntentApprovedNotice()).not.toBe('');
    });

    test('a new check clears the previous verdict AS IT STARTS, even when the company observables did not change', () => {
        // The company-change subscriptions cannot cover this: a re-render
        // re-applying the already-captured company, or a repeat pick of the
        // company already in the field, writes the SAME values and ko
        // notifies nothing — so before TWO-25326 (2026-08-05) the previous
        // verdict sat on screen under a spinner checking something else.
        const ctx = makeContext(DEFAULT_COPY, DECLINED_COPY);
        ctx.isOrderIntentEnabled = true;
        ctx.companyName('Acme Widgets AS');
        ctx.companyId('123456789');
        ctx.processOrderIntentSuccessResponse.call(ctx, { approved: true });
        expect(ctx.orderIntentApprovedNotice()).not.toBe('');

        // A request that never settles, so what is asserted is the state
        // DURING the check rather than after it.
        let settle;
        ctx.placeOrderIntent = function () {
            return {
                always: function (fn) {
                    settle = fn;
                    return this;
                },
                done: function () {
                    return this;
                },
                fail: function () {
                    return this;
                }
            };
        };

        ctx.fillCompanyData.call(ctx, {
            companyName: 'Acme Widgets AS',
            companyId: '123456789'
        });

        expect(typeof settle).toBe('function');
        expect(ctx.orderIntentApprovedNotice()).toBe('');
        expect(ctx.orderIntentDeclinedNotice()).toBe('');
        expect(ctx.orderIntentErrorNotice()).toBe('');

        // Let it settle so the module-scope spinner refcount is not left up
        // for the specs that follow.
        settle();
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

/**
 * A failed address lookup reaches the buyer through the SAME bordered box,
 * carried on the identity bus. It used to go to the checkout-wide message
 * list, which nothing in this flow ever cleared.
 */
describe('the address-lookup failure notice lands in the tile box', () => {
    function contextOn(addressNotice) {
        const component = loadAmdModule(RENDERER, {
            'Two_Gateway/js/model/company-identity': { addressNotice: addressNotice }
        });
        const ctx = Object.assign({}, component, {
            companyName: koObservable(''),
            companyId: koObservable(''),
            errors: []
        });
        ctx.showErrorMessage = function (message) { ctx.errors.push(message); };
        component.initOrderIntentApprovedNotice.call(ctx, {});
        return ctx;
    }

    test.each([
        ['We could not fetch this company\'s address. Please enter it below.', true],
        ['', false]
    ])('a bus value of %p reaches the box: %p', (text, expected) => {
        const addressNotice = koObservable('');
        const ctx = contextOn(addressNotice);

        addressNotice(text);

        expect(ctx.isOrderIntentErrorNoticeVisible()).toBe(expected);
        if (expected) expect(ctx.orderIntentErrorNotice()).toBe(text);
        // Never the checkout-wide region.
        expect(ctx.errors).toEqual([]);
    });

    test('a later company pick retires it', () => {
        const addressNotice = koObservable('');
        const ctx = contextOn(addressNotice);
        addressNotice('We could not fetch this company\'s address. Please enter it below.');

        ctx.companyId('123456789');

        expect(ctx.orderIntentErrorNotice()).toBe('');
    });
});

/**
 * The box itself. TWO-25326 (2026-08-05): one bordered container, the same
 * three semantic colours, and the message ALONE inside it on all four
 * plugins — PrestaShop drops its own "Buy now, pay later" title in the same
 * batch, so a title reintroduced here would break the convergence rather
 * than just look different.
 */
describe('order-intent message box markup and palette', () => {
    const fs = require('fs');
    const path = require('path');
    const ROOT = path.resolve(__dirname, '..', '..');

    function template() {
        return fs
            .readFileSync(
                path.join(ROOT, 'view/frontend/web/template/payment/gateway_method.html'),
                'utf8'
            )
            .replace(/<!--(?!\s*\/?ko\b)[\s\S]*?-->/g, '');
    }

    function stylesheet() {
        return fs.readFileSync(path.join(ROOT, 'view/frontend/web/css/style.css'), 'utf8');
    }

    /** The declaration block of a single flat-class rule. */
    function ruleBody(css, selector) {
        const match = css.match(
            new RegExp('\\n' + selector.replace(/\./g, '\\.') + '\\s*\\{([^}]*)\\}')
        );
        if (match === null) {
            throw new Error('style.css has no `' + selector + '` rule');
        }
        return match[1];
    }

    test('all three outcomes render in the same container class, each with its own modifier', () => {
        const markup = template();
        ['approved', 'declined', 'error'].forEach((state) => {
            const tag = markup.match(
                new RegExp('<div\\b[^>]*class="two-order-intent-message ' + state + '"[^>]*>')
            );
            if (tag === null) {
                throw new Error('no .two-order-intent-message.' + state + ' element');
            }
        });
    });

    test('the box holds the intent message and nothing else — no title element above it', () => {
        const markup = template();
        const blocks = markup.match(
            /<div\b[^>]*class="two-order-intent-message [a-z]+"[\s\S]*?<\/div>/g
        );
        expect(blocks).toHaveLength(3);
        blocks.forEach((block) => {
            // A single `text:`-bound element: no nested element to put a
            // heading in, and no literal copy in the markup either.
            expect(block).toMatch(/data-bind="text: orderIntent\w+Notice"/);
            expect(block).not.toMatch(/<(h\d|strong|p|span)\b/);
        });
        // Nor a heading immediately before the boxes.
        expect(markup).not.toMatch(/<(h\d)\b[^>]*>[\s\S]*?two-order-intent-message/);
    });

    test('the palette is the shared semantic one: success green, danger red, neutral error', () => {
        const css = stylesheet();
        const approved = ruleBody(css, '.two-order-intent-message.approved');
        expect(approved).toMatch(/border-color:\s*#28a745/);
        expect(approved).toMatch(/background:\s*#d4edda/);
        expect(approved).toMatch(/color:\s*#155724/);

        const declined = ruleBody(css, '.two-order-intent-message.declined');
        expect(declined).toMatch(/border-color:\s*#dc3545/);
        expect(declined).toMatch(/background:\s*#f8d7da/);
        expect(declined).toMatch(/color:\s*#721c24/);

        // The error state deliberately carries the base neutral values rather
        // than a third colour, matching PrestaShop.
        const base = ruleBody(css, '.two-order-intent-message');
        const error = ruleBody(css, '.two-order-intent-message.error');
        expect(error).toMatch(/border-color:\s*#e9ecef/);
        expect(error).toMatch(/background:\s*#fff\b/);
        expect(error).toMatch(/color:\s*#495057/);
        expect(base).toMatch(/border:\s*1px solid #e9ecef/);
        // …and it is a bordered, padded box in the first place, which is the
        // whole point of the convergence.
        expect(base).toMatch(/padding:/);
        expect(base).toMatch(/border-radius:/);
    });

    test('the in-flight row keeps the spokes GIF and puts the text beside it', () => {
        const css = stylesheet();
        expect(ruleBody(css, '.two-order-intent-spinner')).toMatch(
            /background-image:\s*url\("\.\.\/images\/loader\.gif"\)/
        );
        expect(fs.existsSync(path.join(ROOT, 'view/frontend/web/images/loader.gif'))).toBe(true);
        const row = ruleBody(css, '.two-order-intent-loading');
        expect(row).toMatch(/display:\s*flex/);
        expect(row).toMatch(/align-items:\s*center/);
    });
});
