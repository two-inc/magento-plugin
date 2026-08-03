/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The payment tile's company display, as TWO-25326 §7 defines it: ONE line of
 * plain text, "Name (12345678)", between the term chips and the order-intent
 * notice.
 *
 * WHEN it shows is the 2026-08-03 ruling on TWO-25326, and it is NOT the same
 * as the one that hides the controls: the label is shown exactly when the
 * inline order-intent message is shown, and hidden exactly when that message
 * is hidden. The tile's own company controls remain gated on
 * isCompanyCaptured(), which is a separate and unchanged rule — so a company
 * captured with no approved intent on screen hides the controls and shows no
 * label. Both of those are pinned below.
 *
 * This supersedes three earlier shapes in turn. TWO-25288 first made the
 * captured number a read-only `<input>`; the ruling after that replaced the
 * input with a "Company Number" caption plus a separately-rendered value.
 * §7 removed that pair too — a caption and a number below an editable
 * company-name box is three renderings of one company in one tile — and gated
 * the resulting single line on isCompanyCaptured(). The 2026-08-03 ruling
 * replaces that gate with the intent message's own.
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
 * The `<div class="two-company-id-label">...</div>` block, comments already
 * stripped, INCLUDING its children (the caption span and the number span).
 * Non-greedy up to the first `</div>` — safe because this block's own
 * content is two flat `<span>` elements with no nested `<div>`. Throws
 * rather than returning null, same reasoning as inputTag() above.
 */
function companyLabelTag() {
    const markup = withoutComments(readTemplate());
    const tags = markup.match(/<div\b[^>]*class="two-company-label"[^>]*>[\s\S]*?<\/div>/g) || [];
    if (tags.length !== 1) {
        throw new Error(
            'expected exactly one <div class="two-company-label">, found ' + tags.length
        );
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
 * What ko would paint as the tile's company label, derived the same way
 * renderedValue() does for an input — read the `text:` binding target out of
 * the template, look it up on the live renderer, evaluate it. A label rewired
 * to a different (or missing) method fails here rather than passing on a
 * restated string.
 */
function renderedLabelText(renderer) {
    const bound = companyLabelTag().match(/text:\s*([A-Za-z_$][\w$]*)\(\)/);
    if (!bound) {
        throw new Error('the company label has no `text:` binding to a renderer method');
    }
    const target = renderer[bound[1]];
    if (typeof target !== 'function') {
        throw new Error(
            'the company label is bound to `' + bound[1] + '`, which the renderer has not'
        );
    }
    return target.call(renderer);
}

/**
 * The `visible:` expression on the company label, verbatim and whitespace-
 * normalised, so it can be compared against the intent message's own gate as
 * a STRING rather than by two independent pattern matches.
 *
 * Anchored on `visible:` up to the next binding (`,` `text:` …) or the end of
 * the `data-bind` value.
 */
function labelGateExpression() {
    const bound = companyLabelTag().match(/visible:\s*([^,"]+)/);
    if (!bound) {
        throw new Error("the company label has no `visible:` binding");
    }
    return bound[1].trim();
}

/**
 * The `if:` expression on the inline order-intent message's ko virtual
 * element, same normalisation, so the two gates can be compared directly.
 */
function intentMessageGateExpression() {
    // The notice block is `<!-- ko if: X -->` immediately followed by the div
    // carrying `two-order-intent-message`. Match that PAIR rather than
    // guessing which of the template's several `ko if:` blocks it is — a
    // positional match would silently retarget if a sibling block moved.
    const pair = readTemplate().match(
        /<!--\s*ko\s+if:\s*([^>]+?)\s*-->\s*<div[^>]*class="two-order-intent-message/
    );
    if (!pair) {
        throw new Error(
            'could not find the `<!-- ko if: … -->` guarding the order-intent message'
        );
    }
    return pair[1].trim();
}

/**
 * Whether the tile's company label would currently render: read the
 * `visible:` expression out of the template, PIN it to the exact
 * `isOrderIntentMessageVisible()` shape (so a drift to some other predicate,
 * or to an always-true stub, fails the string match), then evaluate that
 * predicate against the live renderer for the actual truth value.
 *
 * The pinned shape is the intent message's gate, NOT a capture check. That is
 * the 2026-08-03 ruling: the label is shown exactly when the message is.
 */
function labelVisible(renderer) {
    if (labelGateExpression() !== 'isOrderIntentMessageVisible()') {
        throw new Error(
            "the company label's `visible:` binding is not the pinned "
                + '`isOrderIntentMessageVisible()` shape, it is `'
                + labelGateExpression() + '`'
        );
    }
    return !!renderer.isOrderIntentMessageVisible();
}

/**
 * Whether the inline order-intent message would currently render, derived the
 * same way — so "the label shows exactly when the message shows" can be
 * asserted as two evaluated truth values and not only as matching source text.
 */
function intentMessageVisible(renderer) {
    const expression = intentMessageGateExpression();
    if (expression !== 'isOrderIntentMessageVisible()') {
        throw new Error(
            'the order-intent message is not gated on the pinned '
                + '`isOrderIntentMessageVisible()` shape, it is `' + expression + '`'
        );
    }
    return !!renderer.isOrderIntentMessageVisible();
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
    if (!/visible:\s*!isCompanyCaptured\(\)/.test(tags[0])) {
        throw new Error(
            "the company-name field's `visible:` binding is not the pinned "
                + '`!isCompanyCaptured()` shape'
        );
    }
    return !renderer.isCompanyCaptured();
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

    test('the company label is a plain div, carries no name and no value binding to write to', () => {
        const tag = companyLabelTag();

        expect(tag).not.toMatch(/\sname\s*=/);
        expect(tag).not.toMatch(/\svalue\s*=/);
        // Not an input, not a button, not anything with default interactive
        // semantics — a plain div reads unambiguously as non-editable.
        expect(tag).toMatch(/^<div\b/);
    });

    test('the company label is one element bound to one builder', () => {
        // TWO-25326 §7 asks for ONE line, "Name (number)". The superseded
        // shape was a caption span plus a number span, i.e. two renderings of
        // one company in one tile, which is the defect the ticket names.
        const tag = companyLabelTag();

        expect(tag).toMatch(/text:\s*companyDisplayLabel\(\)/);
        // No inner elements: the whole string comes from one binding, so
        // there is no caption/value split that can fall out of step.
        expect(tag).not.toMatch(/<span\b/);
    });

    /**
     * §7 names the position, not just the existence: "between the chips and
     * the intent message (if rendered) or else the optional fields". Asserted
     * on real source offsets rather than by eye, because the label is inert
     * display text — nothing about it would break if it drifted to the bottom
     * of the tile, so nothing but this test would notice.
     */
    test('the label sits between the term chips and the intent message', () => {
        const markup = withoutComments(readTemplate());

        const lastChip = markup.lastIndexOf('two-term-chips__container');
        const label = markup.indexOf('class="two-company-label"');
        const intent = markup.indexOf('two-order-intent-message');
        const invoiceEmail = markup.indexOf('id="invoice_emails"');

        // Guard every anchor before comparing: indexOf returns -1 for a
        // missing needle, and -1 compares "less than" everything, so a
        // renamed anchor would make the ordering assertions pass on nonsense.
        expect(lastChip).toBeGreaterThan(-1);
        expect(label).toBeGreaterThan(-1);
        expect(intent).toBeGreaterThan(-1);
        expect(invoiceEmail).toBeGreaterThan(-1);

        expect(label).toBeGreaterThan(lastChip);
        expect(label).toBeLessThan(intent);
        // And ahead of the optional fields, which is where §7 puts it when no
        // intent message is rendered.
        expect(label).toBeLessThan(invoiceEmail);
    });

    test('the superseded caption and its separate number span are gone', () => {
        // Both halves, because either surviving alone is a defect: the
        // caption without its number is a label for nothing, and the number
        // rendered separately is the duplicate display §7 removes.
        const markup = withoutComments(readTemplate());

        expect(markup).not.toContain('two-company-id-label');
        expect(markup).not.toContain('two-company-id-label__caption');
        // The old caption text is not simply relocated — the new label
        // renders the bare `Name (number)` form with no caption at all.
        expect(companyLabelTag()).not.toMatch(/Company Number/);
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
        expect(labelVisible(renderer)).toBe(false);
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
    test('search mode: the tile shows one line, "Name (number)", and hides the control', () => {
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
        expect(renderedLabelText(renderer)).toBe('First Example Ltd (12345678)');
        expect(labelVisible(renderer)).toBe(true);
        expect(nameFieldVisible(renderer)).toBe(false);
    });

    test('sole-trader mode: the minted name and synthetic number show as one line', () => {
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
        expect(renderedLabelText(renderer)).toBe('Sole Trader Example (ST-SYNTH-004)');
        expect(labelVisible(renderer)).toBe(true);
        expect(nameFieldVisible(renderer)).toBe(false);
        // The field is hidden here, but its readonly binding must STILL read
        // locked. Hiding is a display decision and sole trader can be left
        // (registeredOrganisationMode()) — if the binding had been allowed to
        // relax on the assumption nobody can see the field, re-showing it
        // would hand the buyer an editable copy of a name they must not edit.
        expect(isReadOnly('company_name', renderer)).toBe(true);
    });

    test('manual-entry mode: the label disappears and the capture control comes back', () => {
        // Driven through the real manual-entry path — clearCompany() is what the
        // sentinel row's handler calls — not by poking the observable, so this
        // fails if that path stops clearing the abandoned company's number.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(labelVisible(renderer)).toBe(true);

        renderer.clearCompany();

        expect(renderer.companyId()).toBe('');
        expect(labelVisible(renderer)).toBe(false);
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
     * leaving sole-trader mode would face a sole-trader label, no search box,
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
        expect(labelVisible(renderer)).toBe(true);
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
        expect(labelVisible(renderer)).toBe(false);
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
        // ...and once the intent for it is approved, the label appears. Ordered
        // this way round on purpose: the notice's companyId subscription clears
        // it whenever the company changes, so an approval taken BEFORE the
        // init-path call would prove nothing about what survives the call.
        approveIntent(renderer);
        expect(labelVisible(renderer)).toBe(true);
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

    test('an identifier-less pick keeps the control up, with no label', () => {
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
        expect(labelVisible(renderer)).toBe(false);
        expect(nameFieldVisible(renderer)).toBe(true);
    });
});

/**
 * The 2026-08-03 ruling on TWO-25326, which supersedes §7's own gate: the label
 * is shown exactly when the inline order-intent message is shown, and hidden
 * exactly when it is hidden.
 *
 * "Exactly when" is asserted two ways, because either alone is weak:
 *
 *  - as SOURCE: the two bindings must be the identical expression. Two
 *    different expressions that agree in the states a test happens to visit is
 *    the precise defect the ruling forbids, and no set of behavioural cases can
 *    rule it out.
 *  - as BEHAVIOUR: across every state that separates the new gate from the old
 *    one — captured-but-no-intent, declined, errored, brand-suppressed notice —
 *    the two evaluate the same, and the label follows the message rather than
 *    capture.
 *
 * The behavioural cases are the mutation-sensitive half: each of them had the
 * label VISIBLE under the superseded `isCompanyCaptured()` gate, so reverting
 * the template one-liner fails them.
 */
describe('the company label is shown exactly when the intent message is', () => {
    test('both bindings are the same expression, not two that merely agree', () => {
        expect(labelGateExpression()).toBe(intentMessageGateExpression());
        // And that shared expression is the intent message's own gate, not a
        // capture check that both were rewired to.
        expect(labelGateExpression()).toBe('isOrderIntentMessageVisible()');
        // The superseded gate is gone from the label specifically. Still
        // present elsewhere in the template — it governs the capture controls —
        // so this asserts on the label's tag, not on the whole file.
        expect(companyLabelTag()).not.toMatch(/isCompanyCaptured/);
    });

    test('a captured company with no intent placed yet shows neither', () => {
        // THE case the ruling changes, and the one the old gate got wrong: the
        // company is fully captured, so `isCompanyCaptured()` is true and the
        // old label showed. No intent has been placed, so there is no message.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(intentMessageVisible(renderer)).toBe(false);
        expect(labelVisible(renderer)).toBe(false);
    });

    test('an approved intent shows both', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);

        expect(intentMessageVisible(renderer)).toBe(true);
        expect(labelVisible(renderer)).toBe(true);
        // The label still renders the company, not the notice copy — tying the
        // VISIBILITY together does not merge the two texts.
        expect(renderedLabelText(renderer)).toBe('First Example Ltd (12345678)');
    });

    test('a declined intent shows neither, though the company is still captured', () => {
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(labelVisible(renderer)).toBe(true);

        renderer.processOrderIntentSuccessResponse({ approved: false });

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(intentMessageVisible(renderer)).toBe(false);
        expect(labelVisible(renderer)).toBe(false);
    });

    test('an errored intent shows neither', () => {
        // Distinct call site from the declined branch — a separate handler —
        // and it clears the notice for its own reason (an error says nothing
        // about approval). Verified by mutation that the declined case above
        // does not cover it.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(labelVisible(renderer)).toBe(true);

        renderer.processOrderIntentErrorResponse({});

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(labelVisible(renderer)).toBe(false);
        expect(intentMessageVisible(renderer)).toBe(false);
    });

    test('editing the company after approval hides both again', () => {
        // The notice is cleared by its own companyName / companyId
        // subscriptions, because the approval it reports was for the previous
        // company. The label must follow it down even though the replacement
        // company is itself fully captured.
        const { renderer } = loadRendererOnly();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(labelVisible(renderer)).toBe(true);

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '87654321' },
            { authoritative: true }
        );

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(intentMessageVisible(renderer)).toBe(false);
        expect(labelVisible(renderer)).toBe(false);
    });

    test('a brand that suppresses the notice shows no label either', () => {
        // <intent_approved_notice_enabled>false</intent_approved_notice_enabled>
        // leaves orderIntentApprovedNoticeCopy null, so the notice text is
        // always '' and the message never renders. Under the ruling the label
        // does not render for that brand either. Flagged deliberately rather
        // than worked around: it follows from "exactly when". A label wanted
        // without the notice would be a different rule, and needs the ticket.
        const { renderer } = loadRendererOnly();

        renderer.initOrderIntentApprovedNotice({});
        expect(renderer.orderIntentApprovedNoticeCopy).toBeNull();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(intentMessageVisible(renderer)).toBe(false);
        expect(labelVisible(renderer)).toBe(false);
    });

    test('the shared gate survives a renderer that was never initialised', () => {
        // isOrderIntentMessageVisible() is read from a `visible:` AND an `if:`
        // binding, and `orderIntentApprovedNotice` is created in
        // initOrderIntentApprovedNotice() rather than in `defaults` — so on an
        // uninitialised renderer it is absent. An unguarded read would throw
        // inside a binding and take the whole payment tile down.
        const dom = makeRecordingDom();
        const bare = loadAmdModule(RENDERER, { jquery: dom.$ });

        expect(bare.orderIntentApprovedNotice).toBeUndefined();
        expect(bare.isOrderIntentMessageVisible()).toBe(false);
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
 * The brand copy ConfigProvider ships for the intent-approved notice. Real
 * shape (both variants plus the token), because resolveOrderIntentApprovedNotice()
 * substitutes into it and the label tests depend on the notice actually being
 * non-empty.
 */
const NOTICE_COPY = {
    withCompany: 'Approved for {company}.',
    withoutCompany: 'Approved.',
    companyNameToken: '{company}'
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
    // The intent-approved notice observable is created in
    // initOrderIntentApprovedNotice(), which initialize() calls — and this
    // harness deliberately does not boot the component. Called explicitly
    // rather than faked, so the real observable AND its real companyName /
    // companyId subscriptions (which clear the notice when the buyer's company
    // changes) are what the label tests run against. Without this the notice
    // is absent and the label could never show.
    renderer.initOrderIntentApprovedNotice({ orderIntentApprovedNotice: NOTICE_COPY });
    // showErrorMessage() routes through the payment block's messageContainer,
    // which the renderer-only harness does not model. Needed by the
    // intent-declined path below.
    renderer.orderIntentDeclinedMessage = 'Declined.';
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
