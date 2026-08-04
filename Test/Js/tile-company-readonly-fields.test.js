/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The payment tile's company display, as the 2026-08-03 ruling on TWO-25326
 * (§7.3) now defines it: NO standalone label at all. The captured company
 * name/number appear ONLY inside the order-intent notice sentence itself —
 * `orderIntentApprovedNotice` / `orderIntentDeclinedNotice` — which has its
 * own gate (whether an intent was placed and what it said), independent of
 * the tile's company CONTROLS, which remain gated on isCompanyCaptured() —
 * a separate and unchanged rule. A company captured with no intent outcome
 * on screen hides the controls and shows neither notice.
 *
 * This supersedes three earlier shapes in turn. TWO-25288 first made the
 * captured number a read-only `<input>`; the ruling after that replaced the
 * input with a "Company Number" caption plus a separately-rendered value.
 * §7 (pre-2026-08-03) replaced that pair with one `.two-company-label` line,
 * gated on the intent message's own visibility. The 2026-08-03 ruling
 * removes that label too — company display now lives ONLY in the notice
 * text, not beside it.
 *
 * The control is HIDDEN, not deleted, and that distinction is pinned here
 * deliberately. The tile's picker is the only company-capture surface for a
 * buyer on a saved address (no `#shipping-new-address-form` exists, and that
 * is the only selector address-autocomplete.js binds) or on a virtual cart
 * (no shipping step at all). Deleting it would block those orders outright.
 *
 * Four groups, and they are NOT the same kind of test. Be honest about which
 * is which before trusting a green run:
 *
 *  1. NO-INPUT PINS — 'the payment tile shows the number as an uneditable
 *     label, not a field'. These fail against the old `<input readonly>`
 *     shape and against any future reinstatement of an editable NUMBER field,
 *     because there is no `<input id="company_id">` left to satisfy either.
 *
 *  2. GATE PINS — two separate gates, pinned separately. The label and the
 *     order-intent message read the IDENTICAL expression (asserted as string
 *     equality between the two bindings, not as two matches against one
 *     pattern, because two different expressions that both happen to be true
 *     is precisely the failure the ruling forbids). The capture controls read
 *     `!isCompanyCaptured()`, which is a different gate on purpose. Every
 *     expression is read out of the template and pinned to an exact shape
 *     before being evaluated, so a drift to some other predicate — or to an
 *     always-true stub — fails the string match rather than passing on a
 *     coincidence.
 *
 *  3. WHAT-THE-BUYER-SEES PINS — 'what each capture mode puts in front of the
 *     buyer'. These do not restate the markup: they read the `text:` binding
 *     target out of the template, then evaluate it against a renderer driven
 *     through the real capture flow, so a label bound to the wrong method
 *     fails here even though the markup is present and correct.
 *
 *  4. ACCEPTED-SOURCE REGRESSION PINS — most of 'an accepted organisation
 *     number still reaches the order'. These pass against the pre-change code
 *     too, because every accepted source already wrote the observable. They
 *     do NOT prove anything about the label. They exist because the
 *     observable is the ONLY carrier — the label has no `name` and submits
 *     nothing — so a future change that breaks one of those writer paths
 *     loses the number outright rather than falling back to a field, and the
 *     sole-trader routes are the ones most likely to break silently, their
 *     value being minted rather than picked.
 *
 * Group 4 is deliberately asserted through `getData()` rather than the DOM.
 * That is the actual submit path (`Observer/DataAssignObserver.php` reads
 * `additional_data`), and it reads the observable. Asserting the DOM there
 * would prove nothing.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const { loadAmdModule } = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';

function readTemplate() {
    const full = path.join(__dirname, '..', '..', TEMPLATE);
    const markup = fs.readFileSync(full, 'utf8');
    // Guard the fixture itself. A silently-empty read would make every
    // "no longer contains" assertion below pass for the wrong reason.
    if (markup.length < 1000) {
        throw new Error('template fixture looks truncated: ' + markup.length + ' bytes');
    }
    return markup;
}

/**
 * Strip explanatory PROSE comments before asserting on markup — but NOT
 * knockout's own `<!-- ko ... -->` / `<!-- /ko -->` virtual-element comments,
 * which are real template syntax (e.g. the i18n macro around "Company
 * Number") rather than developer prose. The name field and the number label
 * both carry long explanatory comments that name `company_id`, `readonly`
 * and `input` on purpose, and a raw substring search would match those and
 * report a passing pin for prose; stripping ko's own comments too would
 * instead make every i18n-wrapped string invisible to these assertions.
 */
function withoutComments(markup) {
    return markup.replace(/<!--[\s\S]*?-->/g, function (comment) {
        const inner = comment.slice(4, -3).trim();
        return /^(ko\b|\/ko$)/.test(inner) ? comment : '';
    });
}

/**
 * The <input> tag for `id`, comments already stripped. Throws rather than
 * returning null: every caller treats a missing field as a failure, and an
 * `expect(undefined)` further down reports the wrong thing.
 */
function inputTag(id) {
    const markup = withoutComments(readTemplate());
    const tags = (markup.match(/<input\b[^>]*>/g) || []).filter(function (tag) {
        return new RegExp('\\bid\\s*=\\s*["\']' + id + '["\']').test(tag);
    });
    if (tags.length !== 1) {
        throw new Error('expected exactly one <input id="' + id + '">, found ' + tags.length);
    }
    return tags[0];
}

/**
 * What ko would paint into `id`'s value, derived rather than restated: read the
 * `value:` binding target out of the template, look that name up on a live
 * renderer, and evaluate it. A field bound to the wrong observable — or to none
 * at all — fails here rather than passing on a restated expectation.
 */
function renderedValue(id, renderer) {
    const bound = inputTag(id).match(/value:\s*([A-Za-z_$][\w$]*)/);
    if (!bound) {
        throw new Error('<input id="' + id + '"> has no `value:` binding');
    }
    const target = renderer[bound[1]];
    if (typeof target !== 'function') {
        throw new Error(
            '<input id="' + id + '"> is bound to `' + bound[1] + '`, which the renderer has not'
        );
    }
    return target.call(renderer);
}

/**
 * Whether the approved / declined order-intent notice would currently
 * render, and what it says — both derived from the live observable rather
 * than restated, so a rewired binding fails here rather than passing on an
 * assumed string. TWO-25326 §7.3 (2026-08-03 ruling): these observables are
 * now the ONLY surface the captured company name/number appear on in the
 * tile — there is no separate label to keep in sync with them.
 */
function approvedNoticeVisible(renderer) {
    return !!renderer.orderIntentApprovedNotice();
}

function approvedNoticeText(renderer) {
    return renderer.orderIntentApprovedNotice();
}

function declinedNoticeVisible(renderer) {
    return !!renderer.orderIntentDeclinedNotice();
}

function declinedNoticeText(renderer) {
    return renderer.orderIntentDeclinedNotice();
}

/**
 * Confirms the template gates each notice `<div>` on its OWN observable
 * (`orderIntentApprovedNotice()` / `orderIntentDeclinedNotice()`) via a
 * `ko if`, rather than on some shared predicate that could drift from what
 * the renderer actually resolved. Read from source rather than assumed.
 */
function noticeGateExpression(cssClass) {
    const pattern = new RegExp(
        '<!--\\s*ko\\s+if:\\s*([^>]+?)\\s*-->\\s*<div[^>]*class="two-order-intent-message ' +
            cssClass
    );
    const pair = readTemplate().match(pattern);
    if (!pair) {
        throw new Error('could not find the `ko if` guarding .' + cssClass + ' notice');
    }
    return pair[1].trim();
}

/**
 * Whether the tile's company-name FIELD (the capture control) would currently
 * render. Same derive-then-evaluate discipline. Pinned to
 * `!isCompanyCaptured()`, which is DELIBERATELY not the label's gate any more:
 * the controls swap on capture, the label swaps on the intent message.
 */
function nameFieldVisible(renderer) {
    const markup = withoutComments(readTemplate());
    const tags =
        markup.match(/<div\b[^>]*class="field field-text required"[^>]*>/g) || [];
    if (tags.length !== 1) {
        throw new Error(
            'expected exactly one company-name field wrapper, found ' + tags.length
        );
    }
    // TWO-25326 §7.1/decline-recovery (2026-08-04 adversarial-review, round
    // 1 then round 2): the field also re-shows on a declined intent, even
    // though isCompanyCaptured() is still true then — see the template's
    // own comment. Gated on isCompanyRecoveryNeeded(), NOT on the declined
    // NOTICE's own visibility — round 2 found the notice-visibility gate
    // reproduced the dead-end for a brand with order_intent enabled but the
    // notice UI suppressed. Pinned to the exact shape so a drift silently
    // narrowing or widening the gate fails the string match, not just the
    // behavioural cases below.
    if (
        !/visible:\s*\(!isCompanyCaptured\(\)\s*\|\|\s*isCompanyRecoveryNeeded\(\)\)\s*&&\s*isTileCompanySearchActive\(\)/.test(
            tags[0]
        )
    ) {
        throw new Error(
            "the company-name field's `visible:` binding is not the pinned "
                + '`(!isCompanyCaptured() || isCompanyRecoveryNeeded()) && isTileCompanySearchActive()` shape'
        );
    }
    return (
        (!renderer.isCompanyCaptured() || renderer.isCompanyRecoveryNeeded()) &&
        renderer.isTileCompanySearchActive()
    );
}

/**
 * Whether `tag` carries `name` as a real HTML ATTRIBUTE — preceded by
 * whitespace and followed by `=`, `>`, `/` or more whitespace.
 *
 * Not `\bname\b`, and the difference is not pedantry: `\breadonly\b` matches
 * inside the class name `two-company-id-readonly`, because `-` is a non-word
 * character and therefore a word boundary. Every read-only assertion in this
 * file passed against a template with the attribute DELETED until this was
 * anchored — caught by mutation, not by review.
 */
function hasAttribute(tag, name) {
    return new RegExp('\\s' + name + '(?=[\\s=>/])').test(tag);
}

/**
 * Whether the buyer can type into `id`, in the state `renderer` is currently
 * in. A static `readonly`/`disabled` attribute settles it outright; otherwise
 * the ko `attr` binding's target is evaluated the same way renderedValue()
 * does it, so a `readonly` bound to an observable is answered for THIS state
 * rather than assumed.
 */
function isReadOnly(id, renderer) {
    const tag = inputTag(id);
    if (hasAttribute(tag, 'readonly') || hasAttribute(tag, 'disabled')) {
        return true;
    }
    const bound = tag.match(/\breadonly:\s*([A-Za-z_$][\w$]*)/);
    if (!bound) {
        return false;
    }
    const target = renderer[bound[1]];
    if (typeof target !== 'function') {
        throw new Error(
            '<input id="' + id + '"> binds readonly to `' + bound[1] + '`, not on the renderer'
        );
    }
    return !!target.call(renderer);
}

describe('the payment tile shows the number as an uneditable label, not a field', () => {
    test('there is no input#company_id left in the template', () => {
        const markup = withoutComments(readTemplate());
        expect(markup).not.toMatch(/<input\b[^>]*\bid\s*=\s*["']company_id["']/);
    });

    test('the standalone company label is gone — no class, no builder method', () => {
        // TWO-25326 §7.3 (2026-08-03 ruling): the pre-ruling `.two-company-label`
        // element and its companyDisplayLabel() builder are REMOVED, not
        // relocated. Company display now lives only inside the notice text.
        const markup = withoutComments(readTemplate());

        expect(markup).not.toContain('two-company-label');
        expect(markup).not.toMatch(/companyDisplayLabel/);
    });

    test('the superseded caption and its separate number span are gone', () => {
        // Both halves, because either surviving alone is a defect: the
        // caption without its number is a label for nothing, and the number
        // rendered separately is the duplicate display §7 removes.
        const markup = withoutComments(readTemplate());

        expect(markup).not.toContain('two-company-id-label');
        expect(markup).not.toContain('two-company-id-label__caption');
    });

    /**
     * The reason the field is HIDDEN rather than deleted, pinned so a future
     * tidy-up cannot quietly delete it: the tile's picker is the only
     * company-capture surface for a buyer on a saved address (no
     * `#shipping-new-address-form` is rendered, and that is the only selector
     * address-autocomplete.js binds) or on a virtual cart (no shipping step
     * at all). Removing it blocks those orders outright, because
     * Model/Two.php::authorize() refuses a company with no organisation
     * number.
     */
    test('the capture control is retained and hides on capture', () => {
        const { renderer } = loadRendererOnly();

        // Still present in the template — not deleted.
        expect(withoutComments(readTemplate())).toMatch(
            /<input\b[^>]*\bid\s*=\s*["']company_name["']/
        );

        // Nothing captured: the control is available.
        expect(nameFieldVisible(renderer)).toBe(true);

        // Captured: it hides. This rule is unchanged by the 2026-08-03 label
        // ruling and must stay that way — it is what preserves the
        // saved-address / virtual-cart capture route described above.
        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        expect(nameFieldVisible(renderer)).toBe(false);
    });

    test('a name with no number leaves the capture control up — name-only is not captured', () => {
        // §6: manual entry (name, no number) must not make the payment method
        // usable, so capture is NOT complete and the buyer must keep the
        // search box. This is the case most likely to be got wrong by gating
        // the control on the name alone.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'Typed By Hand Ltd', companyId: '' },
            { authoritative: true }
        );

        expect(renderer.isCompanyCaptured()).toBe(false);
        expect(nameFieldVisible(renderer)).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
    });

    test('the name field is read-only only once a company has been captured', () => {
        // Not a static attribute, and the asymmetry is the point. Sole trader is
        // the one mode where this node is a plain text box holding a captured
        // name. In search mode select2 replaces it; in manual-entry mode the
        // buyer MUST be able to type. A static `readonly` bricks manual entry.
        const { renderer } = loadRendererOnly();

        renderer.showSoleTrader(true);
        renderer.fillCompanyData({
            companyName: 'Sole Trader Example',
            companyId: 'ST-SYNTH-005'
        });
        expect(isReadOnly('company_name', renderer)).toBe(true);

        renderer.showSoleTrader(false);
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('the name field is never both empty and locked', () => {
        // THE REGRESSION THIS GUARDS, and it is not hypothetical — an earlier
        // draft of the read-only-input version shipped it, and nothing about
        // the label change touches this path. The input is `required`, and
        // jQuery Validation enforces `required` on a `[readonly]` field (its
        // `elements()` skips `:disabled` only). So a state that is both empty
        // and locked is a validation error with no buyer action that clears it.
        //
        // Reached by entering sole-trader mode where the prefetch did NOT match
        // a buyer: enterSoleTraderUi() blanks the input and the autofill never
        // lands, because fillCompanyData() early-returns unless BOTH name and
        // number are non-empty. Keying `readonly` on the mode alone stranded the
        // buyer here.
        const { renderer } = loadRendererOnly();

        renderer.enterSoleTraderUi();

        expect(renderer.showSoleTrader()).toBe(true);
        expect(renderer.companyId()).toBe('');
        // The name the buyer must now supply by hand — so the field cannot be
        // locked, whatever mode says.
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('a prior search does not leave the name locked after switching to sole trader', () => {
        // Same trap by a different route: a captured company, THEN the mode
        // switch. enterSoleTraderUi() → clearCompany() blanks the input AND the
        // number, and on the unmatched-prefetch branch nothing refills either.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        renderer.enterSoleTraderUi();

        expect(renderer.companyId()).toBe('');
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('the name field keeps its required flag and its submit name', () => {
        // Address-step name capture is Magento core's `company` attribute, gated
        // on `customer/address/company_show`, which this module does not own — so
        // weakening this input could leave a shop with no company-name capture.
        const tag = inputTag('company_name');

        expect(hasAttribute(tag, 'required')).toBe(true);
        expect(tag).toMatch(/name="payment\[company_name\]"/);
        expect(tag).toMatch(/value:\s*companyName\b/);
    });

    test('company_name is still the only required input in the payment form', () => {
        // Pins what `$(formSelector).valid()` enforces. The number is
        // deliberately not a validatable input at all any more.
        const markup = withoutComments(readTemplate());

        // Every spelling jQuery Validation would honour, not just
        // `required="true"`: a bare `required`, `required="required"`, and the
        // `data-validate` form all count.
        const requiredInputs = (markup.match(/<input\b[^>]*>/g) || []).filter(function (tag) {
            return (
                hasAttribute(tag, 'required') ||
                /data-validate\s*=\s*["'][^"']*required/.test(tag)
            );
        });

        expect(requiredInputs).toHaveLength(1);
        expect(requiredInputs[0]).toMatch(/\bid\s*=\s*["']company_name["']/);
    });

    test('the renderer keeps no editable-state apparatus for the number', () => {
        // The number is shown but has no editability derivation of its own —
        // it never had one to remove, and a label reinstating one would be a
        // sign the label is on its way to becoming a field again.
        const { renderer } = loadRendererOnly();

        expect(renderer.companyIdSelector).toBeUndefined();
        expect(renderer.needsManualCompanyId).toBeUndefined();
        expect(renderer.syncCompanyIdEditable).toBeUndefined();
        // The name field's selector is still live — company search binds to it.
        expect(renderer.companyNameSelector).toBe('input#company_name');
    });

    test('clearCompany takes no disable argument and touches no number field', () => {
        const { renderer } = loadRendererOnly();

        // Guard the subject first. `String(undefined)` is the string
        // "undefined", which contains none of the substrings below, so a
        // renamed or deleted clearCompany would make all three assertions pass
        // vacuously.
        expect(typeof renderer.clearCompany).toBe('function');

        // Asserting on the function's own SOURCE, not on `.length`. A default
        // parameter (`function (disableCompanyId = false)`) does not count
        // toward `Function.length`, so a length assertion cannot fail if the
        // parameter comes back in the form it had before — which is exactly the
        // form it had. Verified by mutation: the length version survived.
        const source = String(renderer.clearCompany);

        expect(source).not.toContain('disableCompanyId');
        expect(source).not.toContain('companyIdSelector');
        expect(source).not.toContain('input#company_id');
    });
});

describe('what each capture mode puts in front of the buyer', () => {
    test('search mode: an approved intent embeds "Name (number)" in the notice sentence, and hides the control', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);

        // The name observable still carries the name — the control is only
        // hidden, so it is still bound and still the thing sole-trader mode
        // and the picker write to.
        expect(renderedValue('company_name', renderer)).toBe('First Example Ltd');
        expect(approvedNoticeText(renderer)).toBe('Approved for First Example Ltd (12345678).');
        expect(approvedNoticeVisible(renderer)).toBe(true);
        expect(nameFieldVisible(renderer)).toBe(false);
    });

    test('sole-trader mode: the minted name and synthetic number are embedded the same way', () => {
        const { renderer } = loadRendererOnly();

        renderer.prefetched = {
            ready: true,
            matches: true,
            buyer: {
                organization_number: 'ST-SYNTH-004',
                company_name: 'Sole Trader Example'
            }
        };
        renderer.soleTraderMode();
        approveIntent(renderer);

        expect(renderedValue('company_name', renderer)).toBe('Sole Trader Example');
        expect(approvedNoticeText(renderer)).toBe(
            'Approved for Sole Trader Example (ST-SYNTH-004).'
        );
        expect(approvedNoticeVisible(renderer)).toBe(true);
        expect(nameFieldVisible(renderer)).toBe(false);
        // The field is hidden here, but its readonly binding must STILL read
        // locked. Hiding is a display decision and sole trader can be left
        // (registeredOrganisationMode()) — if the binding had been allowed to
        // relax on the assumption nobody can see the field, re-showing it
        // would hand the buyer an editable copy of a name they must not edit.
        expect(isReadOnly('company_name', renderer)).toBe(true);
    });

    test('manual-entry mode: the notice disappears and the capture control comes back', () => {
        // Driven through the real manual-entry path — clearCompany() is what the
        // sentinel row's handler calls — not by poking the observable, so this
        // fails if that path stops clearing the abandoned company's number.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);

        renderer.clearCompany();

        expect(renderer.companyId()).toBe('');
        expect(approvedNoticeVisible(renderer)).toBe(false);
        // The control is back, and typeable — which is the whole point of the
        // mode, and the reason the swap is a visibility toggle rather than a
        // removal.
        expect(nameFieldVisible(renderer)).toBe(true);
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    /**
     * The regression §7's swap introduces if nothing is done about it, caught
     * in adversarial review rather than by the original test set.
     *
     * registeredOrganisationMode() did not clear the captured company. While
     * the tile's control was always visible that was untidy but recoverable —
     * the buyer could just search, and the pick overwrote the stale identity.
     * Once the control HIDES on capture it is not recoverable: a buyer
     * leaving sole-trader mode would face a sole-trader notice, no search box,
     * and no way back.
     */
    test('leaving sole-trader mode brings the capture control back', () => {
        const { renderer } = loadRendererOnly();

        renderer.prefetched = {
            ready: true,
            matches: true,
            buyer: {
                organization_number: 'ST-SYNTH-004',
                company_name: 'Sole Trader Example'
            }
        };
        renderer.soleTraderMode();
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);
        expect(nameFieldVisible(renderer)).toBe(false);

        // fillCustomerData() reaches into the quote's address objects
        // (getCacheKey()), which this file's renderer-only harness does not
        // model. Stubbed rather than worked around, and asserted below so the
        // stub cannot quietly hide the call disappearing from the path.
        const fillCustomerData = jest.fn();
        renderer.fillCustomerData = fillCustomerData;

        renderer.registeredOrganisationMode();

        expect(fillCustomerData).toHaveBeenCalled();

        expect(renderer.showSoleTrader()).toBe(false);
        // The sole-trader identity does not survive the mode switch...
        expect(renderer.companyId()).toBe('');
        expect(renderer.isCompanyCaptured()).toBe(false);
        // ...so the buyer gets the search box back.
        expect(nameFieldVisible(renderer)).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
    });

    /**
     * The other half of that fix: registeredOrganisationMode() is ALSO the
     * tile's own initialiser (initObservable() calls it), and clearing
     * unconditionally would wipe a company captured on the ADDRESS step
     * before the tile ever rendered — silently turning a completed capture
     * into an unpayable order. The clear is therefore guarded on having
     * actually been in sole-trader mode, and this pins that guard.
     */
    test('initialising the tile does not wipe a company captured upstream', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'Captured Upstream Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        expect(renderer.showSoleTrader()).toBe(false);

        // fillCustomerData() reaches into the quote's address objects
        // (getCacheKey()), which this file's renderer-only harness does not
        // model. Stubbed rather than worked around, and asserted below so the
        // stub cannot quietly hide the call disappearing from the path.
        const fillCustomerData = jest.fn();
        renderer.fillCustomerData = fillCustomerData;

        // The init-path call, with sole trader never having been entered.
        renderer.registeredOrganisationMode();

        expect(fillCustomerData).toHaveBeenCalled();

        expect(renderer.companyId()).toBe('12345678');
        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(nameFieldVisible(renderer)).toBe(false);
        // ...and once the intent for it is approved, the notice appears.
        // Ordered this way round on purpose: the notice's companyId
        // subscription clears it whenever the company changes, so an
        // approval taken BEFORE the init-path call would prove nothing about
        // what survives the call.
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);
    });

    /**
     * Hiding rather than removing means select2 can be initialised against a
     * node that is already `display: none` (a company captured on the address
     * step before the tile rendered). select2 measures a width at init, and
     * `_resolveWidth` returns anything that is not `resolve`/`element`/
     * `style`/`computedstyle` VERBATIM — so the literal `'100%'` this picker
     * passes is a CSS percentage that resolves whenever the node is shown,
     * not a pixel count frozen at zero.
     *
     * Switching that option to `'resolve'` or `'element'` would measure
     * `outerWidth()` at init instead, which is 0 on a hidden node, and the
     * buyer who returns to manual entry would get a zero-width search box.
     * Pinned as an option value because there is no way to observe the
     * consequence in jsdom (no layout), and the failure is invisible until a
     * real browser renders it.
     */
    test('the picker takes a percentage width, so a hidden init cannot freeze it at zero', () => {
        const source = fs.readFileSync(path.join(__dirname, '..', '..', RENDERER), 'utf8');
        const widths = source.match(/width:\s*'([^']*)'/g) || [];

        expect(widths.length).toBeGreaterThan(0);
        widths.forEach(function (width) {
            expect(width).toMatch(/width:\s*'\d+%'/);
        });
    });

    test('an identifier-less pick keeps the control up, with no notice', () => {
        // The other route to a blank number: the registry holds no identifier
        // for the picked company. Distinct from manual entry — a company IS
        // selected here.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            { authoritative: true }
        );

        expect(renderedValue('company_name', renderer)).toBe('Second Example Ltd');
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(false);
        expect(nameFieldVisible(renderer)).toBe(true);
    });
});

/**
 * TWO-25326 §7.3 (2026-08-03 ruling): each notice is gated on its OWN
 * observable via a `ko if`, and each observable is set exactly once the
 * corresponding order-intent outcome is known. There is no longer a shared
 * "the label follows the message" predicate to pin, because there is no
 * label — company display and intent outcome are now the SAME element.
 *
 * The behavioural cases are the mutation-sensitive part: each of them
 * exercises a state that a naive "captured ⇒ show something" gate would get
 * wrong (captured-but-no-intent, declined, errored, brand-suppressed,
 * order-intent-off, Dutch non-BV, edited company).
 */
describe('the notices are gated on their own observables, not on capture', () => {
    test('each notice template block guards on its own observable expression', () => {
        expect(noticeGateExpression('approved')).toBe('isOrderIntentApprovedNoticeVisible()');
        expect(noticeGateExpression('declined')).toBe('isOrderIntentDeclinedNoticeVisible()');
    });

    test('a captured company with no intent placed yet shows neither notice', () => {
        // THE case the ruling changes, and the one a capture-only gate would
        // get wrong: the company is fully captured, so `isCompanyCaptured()`
        // is true. No intent has been placed, so there is no message.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(false);
    });

    test('an approved intent shows the approved notice only, with the company embedded', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);

        expect(approvedNoticeVisible(renderer)).toBe(true);
        expect(declinedNoticeVisible(renderer)).toBe(false);
        expect(approvedNoticeText(renderer)).toBe('Approved for First Example Ltd (12345678).');
    });

    test('a declined intent shows the declined notice only, with the company embedded — not a toast', () => {
        const { renderer } = loadRendererOnly();
        const errors = [];
        renderer.showErrorMessage = function (message) {
            errors.push(message);
        };

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);

        renderer.processOrderIntentSuccessResponse({ approved: false });

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(true);
        expect(declinedNoticeText(renderer)).toBe('Declined for First Example Ltd (12345678).');
        // TWO-25326 §7.3 (2026-08-03 ruling): a clean decline is a persistent
        // tile notice now, not a toast that a later checkout update wipes.
        expect(errors).toEqual([]);
    });

    test('a decline re-opens the capture control instead of trapping the buyer (found in adversarial review, 2026-08-04)', () => {
        // BLOCKER found reviewing this PR: isCompanyCaptured() stays true
        // after a decline (only an approval's own company-change
        // subscriptions clear the observables), so gating the field purely
        // on isCompanyCaptured() left a declined buyer staring at a
        // persistent "not available" notice with NO control anywhere on the
        // tile to try a different company — a dead end without a page
        // reload on a saved-address/virtual-cart/setting-off checkout,
        // where the tile is the buyer's ONLY route to search.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(nameFieldVisible(renderer)).toBe(false);

        renderer.processOrderIntentSuccessResponse({ approved: false });

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(declinedNoticeVisible(renderer)).toBe(true);
        // The recovery path: the field is back, letting the buyer search
        // again.
        expect(nameFieldVisible(renderer)).toBe(true);

        // Picking a different company clears the declined notice AND
        // completes a new capture, so the field correctly hides again once
        // there is something new to hide it for.
        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '87654321' },
            { authoritative: true }
        );
        expect(declinedNoticeVisible(renderer)).toBe(false);
        expect(nameFieldVisible(renderer)).toBe(false);
    });

    test('an errored intent shows neither notice', () => {
        // Distinct call site from the declined branch — a separate handler —
        // and it clears both notices for its own reason (an error says
        // nothing about approval OR decline). Verified by mutation that the
        // declined case above does not cover it.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);

        renderer.processOrderIntentErrorResponse({});

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(false);
    });

    test('editing the company after approval clears the notice', () => {
        // The notice is cleared by its own companyName / companyId
        // subscriptions, because the approval it reports was for the
        // previous company.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '87654321' },
            { authoritative: true }
        );

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(false);
    });

    test('editing only the NAME after approval clears the notice', () => {
        // The companyName subscription specifically. The test above changes the
        // company through applyCompanyData(), which writes BOTH observables, so
        // the companyId subscription alone satisfies it — verified by mutation:
        // deleting the companyName clear left the whole suite green. This is the
        // hand-edited-name fail-closed path initOrderIntentApprovedNotice()'s own
        // comment calls load-bearing, so it gets its own case.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);

        // Only the name, as a buyer typing into the input would.
        renderer.companyName('First Example Limited');

        expect(renderer.companyId()).toBe('12345678');
        expect(approvedNoticeVisible(renderer)).toBe(false);
    });

    test('order intent turned off means neither notice, ever', () => {
        // `enable_order_intent` off: fillCompanyData() never calls
        // placeOrderIntent(), so nothing sets either notice. Pinned so it
        // reads as a decision rather than an oversight, and so widening
        // either predicate cannot happen silently.
        const { renderer } = loadRendererOnly();
        const placeOrderIntent = jest.fn();
        renderer.placeOrderIntent = placeOrderIntent;
        renderer.isOrderIntentEnabled = false;

        renderer.fillCompanyData({
            companyName: 'First Example Ltd',
            companyId: '12345678'
        });

        // Guard the premise: no intent was even attempted.
        expect(placeOrderIntent).not.toHaveBeenCalled();
        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(false);
    });

    test('a falsy intent response never sets either notice', () => {
        // The falsy branch of processOrderIntentSuccessResponse(). That branch is
        // how the Dutch non-BV route surfaces — placeOrderIntent() early-returns
        // a Deferred resolved with `null` — but be honest about the scope: this
        // pins the HANDLER, not that route. Verified by mutation that neutering
        // the NL/BV early return leaves the whole suite green, because nothing
        // here touches billingAddress or BVCompanyRegex. Pinning the route
        // itself needs a test driving fillCompanyData() with an NL non-BV
        // billing address, and that is not this test.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);

        renderer.processOrderIntentSuccessResponse(null);

        // Nothing was set OR cleared by the null response — but the company
        // write that precedes a real intent already cleared it, which is what
        // makes the notice absent on that path.
        renderer.applyCompanyData(
            { companyName: 'Dutch Example VOF', companyId: '87654321' },
            { authoritative: true }
        );
        renderer.processOrderIntentSuccessResponse(null);

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(false);
    });

    test('a brand that suppresses the notice shows neither variant', () => {
        // <intent_approved_notice_enabled>false</intent_approved_notice_enabled>
        // leaves both notice-copy objects null, so neither notice text is
        // ever non-empty — the SAME switch suppresses both (§7.4: they are
        // one on/off unit).
        const { renderer } = loadRendererOnly();

        renderer.initOrderIntentApprovedNotice({});
        expect(renderer.orderIntentApprovedNoticeCopy).toBeNull();
        expect(renderer.orderIntentDeclinedNoticeCopy).toBeNull();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(false);

        declineIntent(renderer);
        expect(declinedNoticeVisible(renderer)).toBe(false);

        // Round 2 of adversarial review, 2026-08-04: THIS is the case round
        // 1's decline-recovery fix missed. `enable_order_intent` and
        // `<intent_approved_notice_enabled>` are independent brand.xml
        // switches — order_intent can fire for real (as it just did above)
        // while the notice UI stays off. Gating the field's re-show on the
        // declined NOTICE's own visibility (always false for this brand)
        // would silently reproduce the original dead-end. It must re-show
        // regardless.
        expect(nameFieldVisible(renderer)).toBe(true);
    });

    test('an approved-notice override does not leak into the declined notice, or vice versa', () => {
        // TWO-25326 §7.4: the two overrides are independent knobs.
        const { renderer } = loadRendererOnly();
        renderer.initOrderIntentApprovedNotice({
            orderIntentApprovedNotice: {
                withCompany: 'Brand-specific approval for {company}.',
                withoutCompany: 'Brand-specific approval.',
                companyNameToken: '{company}',
                companyNumberToken: '{number}'
            },
            orderIntentDeclinedNotice: DECLINED_NOTICE_COPY
        });

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        declineIntent(renderer);

        expect(declinedNoticeText(renderer)).not.toContain('Brand-specific approval');
        expect(declinedNoticeText(renderer)).toBe('Declined for First Example Ltd (12345678).');
    });

    test('both notice observables survive a renderer that was never initialised', () => {
        // Each notice is read from a `ko if` binding, and both observables are
        // created in initOrderIntentApprovedNotice() rather than in
        // `defaults` — so on an uninitialised renderer they are absent. An
        // unguarded template read would throw and take the whole payment
        // tile down; the template guards with `orderIntentApprovedNotice()`/
        // `orderIntentDeclinedNotice()` directly, which is undefined-safe
        // only because `ko if` treats a thrown binding as a hard failure —
        // this pins that the observables exist post-init, not a defensive
        // wrapper in the getters themselves.
        const dom = makeRecordingDom();
        const bare = loadAmdModule(RENDERER, { jquery: dom.$ });

        expect(bare.orderIntentApprovedNotice).toBeUndefined();
        expect(bare.orderIntentDeclinedNotice).toBeUndefined();
    });
});

/**
 * jQuery double that records writes per selector, so a claim that the renderer
 * "no longer writes the number field" is checkable rather than assumed. The
 * default harness jQuery reports `length: 0` and returns the same inert object
 * from every setter, which cannot distinguish a write from no write.
 */
function makeRecordingDom() {
    const writes = [];
    const nodes = {};

    function node(selector) {
        if (nodes[selector]) return nodes[selector];
        const n = {
            selector: selector,
            length: 1,
            value: '',
            props: {},
            handlers: {},
            val: function (next) {
                if (!arguments.length) return n.value;
                writes.push([selector, next]);
                n.value = next;
                return n;
            },
            prop: function (name, next) {
                if (arguments.length < 2) return n.props[name];
                n.props[name] = next;
                return n;
            },
            text: function () {
                return n;
            },
            on: function (event, fn) {
                n.handlers[String(event).split('.')[0]] = fn;
                return n;
            },
            off: () => n,
            closest: (sel) => node(selector + ' >closest> ' + sel),
            find: (sel) => node(selector + ' >find> ' + sel),
            append: () => n,
            attr: () => n,
            data: () => null,
            hide: () => n,
            show: () => n,
            select2: () => n
        };
        nodes[selector] = n;
        return n;
    }

    function $(selector) {
        return node(typeof selector === 'string' ? selector : String(selector));
    }
    $.async = function (selector, cb) {
        cb(selector);
    };
    $.each = function (xs, fn) {
        (xs || []).forEach(function (x, i) {
            fn(i, x);
        });
    };
    $.ajax = function () {
        return { done: () => this, fail: () => this, always: () => this };
    };
    $.Deferred = function () {
        const d = {
            resolve: () => d,
            reject: () => d,
            promise: () => d,
            done: () => d,
            fail: () => d,
            always: () => d
        };
        return d;
    };
    $.mage = { cookies: { get: () => null, set: () => {} }, redirect: () => {} };
    $.extend = Object.assign;
    $.fn = {};

    return { $: $, node: node, writes: writes };
}

/**
 * The brand copy ConfigProvider ships for the intent-approved / -declined
 * notices. Real shape (both variants plus both tokens), because
 * resolveCompanyNotice() substitutes into it and the tests below depend on
 * each notice actually being non-empty when it should be.
 */
const NOTICE_COPY = {
    withCompany: 'Approved for {company} ({number}).',
    withoutCompany: 'Approved.',
    companyNameToken: '{company}',
    companyNumberToken: '{number}'
};

const DECLINED_NOTICE_COPY = {
    withCompany: 'Declined for {company} ({number}).',
    withoutCompany: 'Declined.',
    companyNameToken: '{company}',
    companyNumberToken: '{number}'
};

function loadRendererOnly(extraMocks) {
    const dom = makeRecordingDom();
    const renderer = loadAmdModule(
        RENDERER,
        Object.assign({ jquery: dom.$ }, extraMocks || {})
    );
    // `getCode()` comes from the Magento Component base class, which the
    // harness's Component double does not provide.
    renderer.getCode = function () {
        return 'two_payment';
    };
    // The intent-approved/-declined notice observables are created in
    // initOrderIntentApprovedNotice(), which initialize() calls — and this
    // harness deliberately does not boot the component. Called explicitly
    // rather than faked, so the real observables AND their real companyName /
    // companyId subscriptions (which clear both notices when the buyer's
    // company changes) are what the tests below run against. Without this
    // the notices are absent and neither could ever show.
    renderer.initOrderIntentApprovedNotice({
        orderIntentApprovedNotice: NOTICE_COPY,
        orderIntentDeclinedNotice: DECLINED_NOTICE_COPY
    });
    // showErrorMessage() routes through the payment block's messageContainer,
    // which the renderer-only harness does not model.
    renderer.messageContainer = {
        addErrorMessage: function () {},
        errorMessages: { remove: function () {} }
    };
    return { renderer: renderer, dom: dom };
}

/**
 * Drive the renderer to the state where the inline intent-approved notice is
 * on screen, through the REAL path — the ajax success handler — rather than by
 * poking the observable. A test that set the observable by hand would still
 * pass if processOrderIntentSuccessResponse() stopped setting it.
 */
function approveIntent(renderer) {
    renderer.processOrderIntentSuccessResponse({ approved: true });
}

/**
 * Same, for the "not approved" business outcome (TWO-25326 §7.3, 2026-08-03
 * ruling) — a clean response with `approved: false`.
 */
function declineIntent(renderer) {
    renderer.processOrderIntentSuccessResponse({ approved: false });
}

describe('an accepted organisation number still reaches the order', () => {
    test('a company-search pick reaches getData()', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        expect(renderer.getData().additional_data.companyId).toBe('12345678');
        expect(renderer.getData().additional_data.companyName).toBe('First Example Ltd');
    });

    test('the sole-trader synthetic number reaches getData() via applyPrefetch', () => {
        // The autofill endpoint MINTS this number; it is never picked from a
        // registry and never typed. applyPrefetch() is the path that lands it.
        const { renderer } = loadRendererOnly();

        renderer.prefetched = {
            ready: true,
            buyer: {
                organization_number: 'ST-SYNTH-001',
                company_name: 'Sole Trader Example'
            },
            matches: true
        };

        renderer.applyPrefetch();

        expect(renderer.companyId()).toBe('ST-SYNTH-001');
        expect(renderer.getData().additional_data.companyId).toBe('ST-SYNTH-001');
        expect(renderer.getData().additional_data.companyName).toBe('Sole Trader Example');
    });

    test('the sole-trader synthetic number reaches getData() via the chip click', () => {
        // soleTraderMode() is the SECOND route that lands a minted number, and
        // it is a separate call site from applyPrefetch(). Verified by mutation
        // that the applyPrefetch test above does not cover it.
        const { renderer } = loadRendererOnly();

        renderer.prefetched = {
            ready: true,
            matches: true,
            buyer: {
                organization_number: 'ST-SYNTH-003',
                company_name: 'Chip Click Example'
            }
        };

        renderer.soleTraderMode();

        expect(renderer.companyId()).toBe('ST-SYNTH-003');
        expect(renderer.getData().additional_data.companyId).toBe('ST-SYNTH-003');
    });

    test('the sole-trader number survives with no write to any company_id node', () => {
        // ko's `text: companyId` binding is the ONLY painter of the label.
        // Prove the renderer writes nothing to a `company_id` selector itself
        // while still delivering the number to the submit path — a second
        // painter could disagree with the observable, and the observable is
        // what ships.
        const { renderer, dom } = loadRendererOnly();

        renderer.fillCompanyData({
            companyId: 'ST-SYNTH-002',
            companyName: 'Sole Trader Example'
        });

        expect(renderer.getData().additional_data.companyId).toBe('ST-SYNTH-002');

        const numberFieldWrites = dom.writes.filter(function (w) {
            return /company_id/.test(w[0]);
        });
        expect(numberFieldWrites).toEqual([]);
        // ...while the name field IS still written, so an inert double cannot
        // be what made the assertion above pass.
        const nameFieldWrites = dom.writes.filter(function (w) {
            return w[0] === 'input#company_name';
        });
        expect(nameFieldWrites).toEqual([['input#company_name', 'Sole Trader Example']]);
    });

    test('showing the number does not change what the form submits', () => {
        // The regression this guards: reinstating a carrier for the number
        // beside the observable — a `name`, or a DOM read in getData() — so
        // the payload could come from the DOM instead. Asserted against the
        // WHOLE additional_data payload rather than the two keys, because a
        // new company key smuggled in beside them is the same defect.
        const { renderer, dom } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        const additional = renderer.getData().additional_data;
        expect(additional.companyName).toBe('First Example Ltd');
        expect(additional.companyId).toBe('12345678');
        expect(
            Object.keys(additional).filter(function (key) {
                return /company/i.test(key);
            })
        ).toEqual(['companyName', 'companyId']);

        // getData() must not have consulted the DOM for either value: with a
        // recording double every node reports an empty `val()`, so a DOM read
        // would have produced '' above. Belt-and-braces on that, prove the
        // renderer never wrote any company_id node either.
        expect(
            dom.writes.filter(function (w) {
                return /company_id/.test(w[0]);
            })
        ).toEqual([]);
    });

    test('an identifier-less selection leaves the number empty for the server to refuse', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '' },
            { authoritative: true }
        );

        // No client-side block. Model/Two.php::authorize() refuses this order.
        expect(renderer.getData().additional_data.companyName).toBe('Second Example Ltd');
        expect(renderer.getData().additional_data.companyId).toBe('');
    });
});
