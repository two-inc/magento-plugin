/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25326, two rules pinned together because the second one is what makes
 * the first one non-obvious.
 *
 * RULE 1 — the order-intent spinner is TILE-LOCAL, never a page overlay.
 * An order-intent check is one payment method deciding whether it can offer
 * itself; covering and blocking the whole checkout for it is wrong. The
 * spinner is now a `visible:`-bound element inside the payment tile
 * (`.two-order-intent-spinner` in gateway_method.html) driven by
 * `orderIntentInProgress`, and `fullScreenLoader` must not be touched by
 * this path at all. `afterPlaceOrder()`'s loader is a DIFFERENT surface —
 * it covers the gap before a redirect to the hosted checkout, after the
 * order is placed — and is deliberately untouched.
 *
 * RULE 2 — that spinner's start/stop pairing is REFERENCE-COUNTED at module
 * scope, and it has to stay that way now the spinner is local.
 *
 * fillCustomerData() re-runs on every fresh instance's init
 * (registeredOrganisationMode() → initObservable()) and re-applies the
 * customer-data section's already-captured company via applyCompanyData()
 * → fillCompanyData(). Amasty rebuilds the payment method list, and Fire
 * Checkout re-renders this payment renderer outright, on every
 * shipping/totals change — so a re-render mid-flight spins up a FRESH
 * renderer instance whose own `_orderIntentInFlightFor` guard is unset,
 * and it independently fires its OWN order_intent POST for the SAME
 * company while the orphaned previous instance's request is still
 * outstanding. With a plain per-instance start/stop pair, whichever request
 * settles FIRST (typically the orphaned one, since it started earlier) hid
 * the spinner while the surviving instance's request — the one whose
 * resolution actually updates the currently-rendered notice text — was
 * still pending.
 *
 * Luma never re-renders this component on totals/shipping changes, so it
 * only ever has one order-intent request in flight at a time and never
 * hit this.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';

/** Plain (non-ko) observable factory matching the sibling TWO-25347 specs. */
function plainObservable(initial) {
    let v = initial;
    const fn = function (next) {
        if (!arguments.length) return v;
        v = next;
        return fn;
    };
    return fn;
}

/**
 * Load the renderer with a `fullScreenLoader` double that RECORDS every call,
 * so "the order-intent path raises no page overlay" is asserted against real
 * calls rather than assumed.
 *
 * @returns {{component: object, fullScreenCalls: string[]}}
 */
function loadRenderer() {
    const fullScreenCalls = [];
    const component = loadAmdModule(RENDERER, {
        'Magento_Checkout/js/model/full-screen-loader': {
            startLoader: function () {
                fullScreenCalls.push('startLoader');
            },
            stopLoader: function () {
                fullScreenCalls.push('stopLoader');
            }
        }
    });
    return { component: component, fullScreenCalls: fullScreenCalls };
}

describe('order-intent spinner is tile-local and refcounted (TWO-25326)', () => {
    test('the spinner stays up until BOTH a re-render\'s new request AND the orphaned old one settle', () => {
        // A single require of the module — exactly one page load, one
        // RequireJS module instance, one module-scope refcount and one
        // module-scope spinner observable shared by every renderer instance
        // built from it, matching real behaviour.
        const { component, fullScreenCalls } = loadRenderer();
        const spinner = component.orderIntentInProgress;

        const deferreds = [];
        function makeDeferred() {
            const d = {
                always: function (fn) {
                    d._always = fn;
                    return d;
                },
                done: function (fn) {
                    d._done = fn;
                    return d;
                },
                fail: function () {
                    return d;
                }
            };
            deferreds.push(d);
            return d;
        }

        function makeCtx() {
            return Object.assign({}, component, {
                companyName: plainObservable(''),
                companyId: plainObservable(''),
                orderIntentApprovedNotice: plainObservable(''),
                orderIntentDeclinedNotice: plainObservable(''),
                resolveOrderIntentApprovedNotice: function () {
                    return 'approved';
                },
                resolveOrderIntentDeclinedNotice: function () {
                    return 'declined';
                },
                isOrderIntentEnabled: true,
                placeOrderIntent: function () {
                    return makeDeferred();
                }
            });
        }

        expect(spinner()).toBe(false);

        // Instance A (e.g. the pre-re-render renderer) captures a company
        // and fires its order-intent request.
        const instanceA = makeCtx();
        instanceA.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });
        expect(spinner()).toBe(true);

        // Amasty/Fire re-render mid-flight: a FRESH instance B is created
        // and its own init re-applies the SAME captured company, firing a
        // second, independent order-intent request while A's is still
        // outstanding.
        const instanceB = makeCtx();
        instanceB.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });
        expect(spinner()).toBe(true);

        // A's orphaned request settles first — this must NOT hide the
        // spinner while B's (the currently-rendered instance's) request is
        // still pending.
        deferreds[0]._always();
        deferreds[0]._done({ approved: true });
        expect(spinner()).toBe(true);

        // B's request — the one that actually matters, since B is the
        // live rendered instance — finally settles. Only now must the
        // spinner come down.
        deferreds[1]._always();
        deferreds[1]._done({ approved: true });
        expect(spinner()).toBe(false);

        // RULE 1: nothing in any of that raised the page-covering loader.
        expect(fullScreenCalls).toEqual([]);
    });

    test('Luma\'s single-instance case is unaffected: one request, spinner up then down', () => {
        const { component, fullScreenCalls } = loadRenderer();
        const spinner = component.orderIntentInProgress;

        let resolveDeferred;
        const ctx = Object.assign({}, component, {
            companyName: plainObservable(''),
            companyId: plainObservable(''),
            isOrderIntentEnabled: true,
            placeOrderIntent: function () {
                return {
                    always: function (fn) {
                        resolveDeferred = fn;
                        return this;
                    },
                    done: function () {
                        return this;
                    },
                    fail: function () {
                        return this;
                    }
                };
            }
        });

        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });
        expect(spinner()).toBe(true);

        resolveDeferred();
        expect(spinner()).toBe(false);
        expect(fullScreenCalls).toEqual([]);
    });

    test('a synchronous throw from placeOrderIntent() still decrements the shared refcount (does not leak the spinner stuck-on)', () => {
        const { component, fullScreenCalls } = loadRenderer();
        const spinner = component.orderIntentInProgress;

        const ctx = Object.assign({}, component, {
            companyName: plainObservable(''),
            companyId: plainObservable(''),
            isOrderIntentEnabled: true,
            generalErrorMessage: 'Something went wrong.',
            showErrorMessage: function () {},
            placeOrderIntent: function () {
                throw new Error('billingAddress was transiently null');
            }
        });

        ctx.fillCompanyData({ companyName: 'Acme Widgets AS', companyId: '123' });

        expect(spinner()).toBe(false);
        expect(fullScreenCalls).toEqual([]);
    });

    test('the tile template scopes the spinner locally — no page-overlay element, bound to orderIntentInProgress', () => {
        const fs = require('fs');
        const path = require('path');
        const markup = fs.readFileSync(
            path.resolve(
                __dirname,
                '..',
                '..',
                'view/frontend/web/template/payment/gateway_method.html'
            ),
            'utf8'
        );
        // Comments stripped: the rationale prose mentions `fullScreenLoader`
        // and `loading-mask` by name, and a check that matched those would
        // pass or fail on documentation rather than on markup.
        const withoutComments = markup.replace(/<!--[\s\S]*?-->/g, '');

        const row = withoutComments.match(
            /<div\b[^>]*class="two-order-intent-loading"[^>]*>[\s\S]*?<\/div>/
        );
        expect(row).not.toBeNull();
        expect(row[0]).toMatch(/role="status"/);
        // The figure itself, still the same tile-local background image.
        expect(row[0]).toMatch(/class="two-order-intent-spinner"/);
        // TWO-25326 (2026-08-05): the region is named by VISIBLE, translated
        // text — the same sentence on all four plugins — and the wordless
        // `aria-label` the figure used to carry is gone, so a screen reader
        // does not hear the label and then the text.
        expect(row[0]).toMatch(/i18n: 'Checking availability'/);
        expect(row[0]).not.toMatch(/aria-label/);
        expect(withoutComments).not.toMatch(/Checking company/);
        // Gated by `ko if:` on the flag, so the `role="status"` node is
        // INSERTED rather than un-hidden — see the template comment. Matched
        // against the RAW markup: ko's virtual bindings are themselves HTML
        // comments, so the comment-stripped copy above cannot see them.
        expect(markup).toMatch(
            /<!--\s*ko if:\s*orderIntentInProgress\(\)\s*-->\s*<div\b[^>]*class="two-order-intent-loading"/
        );

        // The sentence is translatable for every locale this module ships, or
        // the convergence is English-only in practice. Asserted against the
        // shipped CSVs rather than a hard-coded locale list, so a locale added
        // later is covered without editing this spec.
        const i18nDir = path.resolve(__dirname, '..', '..', 'i18n');
        const locales = fs.readdirSync(i18nDir).filter((name) => name.endsWith('.csv'));
        expect(locales.length).toBeGreaterThan(0);
        locales.forEach((name) => {
            const csv = fs.readFileSync(path.join(i18nDir, name), 'utf8');
            const translated = csv.match(/^"Checking availability","([^"]+)"$/m);
            if (translated === null) {
                throw new Error(name + ' has no "Checking availability" row');
            }
            // A row echoing the source string back is an untranslated stub.
            expect(translated[1]).not.toBe('Checking availability');
            // …and the retired string must not linger, or a translator sees
            // two rows for one surface.
            expect(csv).not.toMatch(/^"Checking company"/m);
        });

        // The tile must not reach for Magento's page-covering loader markup.
        expect(withoutComments).not.toMatch(/loading-mask/);
        // …and the spinner must live INSIDE the tile's own subtree, not be
        // appended to the document. This template IS the tile, so any element
        // declared here is scoped to it; what would break that is a JS
        // `document.body` append, pinned in the renderer source below.
        const renderer = fs.readFileSync(
            path.resolve(__dirname, '..', '..', RENDERER),
            'utf8'
        );
        expect(renderer).not.toMatch(/document\.body/);
        // The order-intent path must not raise the full-screen loader. Its
        // ONE remaining legitimate use is afterPlaceOrder()'s pre-redirect
        // cover, so exactly one call site is expected, not zero.
        const loaderCalls = renderer.match(/fullScreenLoader\.\w+\(/g) || [];
        expect(loaderCalls).toEqual(['fullScreenLoader.startLoader(']);
    });

    test('the order-intent request opts out of jQuery\'s GLOBAL ajax events, which raise the body overlay', () => {
        // Found in adversarial review: Magento's `loaderAjax` widget is bound on
        // `<body>` and listens for `ajaxSend`/`ajaxComplete`, raising the same
        // body-wide overlay `fullScreenLoader` does. With `global: true` the
        // page-covering overlay came up for the whole order-intent round trip no
        // matter what this renderer did with its own loader — so "the spinner is
        // tile-local" is only true with this flag off, and nothing in the
        // behavioural specs above can see it.
        const fs = require('fs');
        const path = require('path');
        const src = fs.readFileSync(path.resolve(__dirname, '..', '..', RENDERER), 'utf8');

        const call = src.match(/\/v1\/order_intent[\s\S]{0,1200}?\}\);/);
        expect(call).not.toBeNull();
        const options = call[0].replace(/\/\/[^\n]*/g, '');
        expect(options).toMatch(/global:\s*false/);
        expect(options).not.toMatch(/global:\s*true/);
    });
});
