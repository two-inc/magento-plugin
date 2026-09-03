/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * The payment tile's company display.
 *
 * Two independent rules define it:
 *
 *  - the capture CONTROL (the field and whatever is mounted in it) is visible
 *    exactly when the tile is where the page-level capture component has
 *    chosen to mount — `isTileCompanyFieldVisible()` and nothing else. Capture
 *    state and order-intent outcome do not affect it: gating it on capture made
 *    the control disappear on the common approve path with nothing to bring it
 *    back;
 *  - a `.two-company-id-text` org-number LABEL renders under the control once a
 *    company is captured, painted by the capture panel mounted there
 *    (renderCompanyNumber() in company-capture-component.js). It is a separate
 *    surface from the order-intent notice, which carries its own embedded
 *    "Name (number)" sentence.
 *
 * Four groups, and they are NOT the same kind of test. Be honest about which is
 * which before trusting a green run:
 *
 *  1. NO-INPUT PINS — 'the payment tile shows the number as an uneditable
 *     label, not a field'. These fail against the old `<input readonly>` shape
 *     and against any future reinstatement of an editable NUMBER field, because
 *     there is no `<input id="company_id">` left to satisfy either.
 *
 *  2. GATE PINS — the control's `visible:` binding and the org-number label's
 *     are each pinned to their exact shape, so a drift to some other predicate
 *     — or to an always-true stub — fails the string match rather than passing
 *     on a coincidence.
 *
 *  3. WHAT-THE-BUYER-SEES PINS — 'what each capture mode puts in front of the
 *     buyer'. These do not restate the markup: they read the binding target out
 *     of the template and evaluate it against a renderer driven through the
 *     real capture flow, so a label bound to the wrong method fails here even
 *     though the markup is present and correct.
 *
 *  4. ACCEPTED-SOURCE REGRESSION PINS — most of 'an accepted organisation
 *     number still reaches the order'. They exist because the observable is the
 *     ONLY carrier — the label has no `name` and submits nothing — so a change
 *     that breaks a writer path loses the number outright rather than falling
 *     back to a field, and the sole-trader route is the one most likely to
 *     break silently, its value being minted rather than picked.
 *
 * Group 4 is deliberately asserted through `getData()` rather than the DOM.
 * That is the actual submit path (`Observer/DataAssignObserver.php` reads
 * `additional_data`), and it reads the observable.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const {
    loadAmdModule,
    defaultMocks,
    loadCompanyCapture,
    brandConfigMock,
    quoteAddress
} = require('./amd-harness');

const RENDERER = 'view/frontend/web/js/view/payment/method-renderer/gateway_method.js';
const IDENTITY = 'view/frontend/web/js/model/company-identity.js';
const TEMPLATE = 'view/frontend/web/template/payment/gateway_method.html';

/** The two hosts the capture component chooses between. */
const TILE_FIELD_SELECTOR = '#two_gateway_form input#company_name';
const ADDRESS_FIELD_SELECTOR = '#shipping-new-address-form input[name="company"]';

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
 * which are real template syntax rather than developer prose. The name field
 * and the number label both carry long explanatory comments that name
 * `company_id`, `readonly` and `input` on purpose, and a raw substring search
 * would match those and report a passing pin for prose; stripping ko's own
 * comments too would instead make every i18n-wrapped string invisible.
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
 * Evaluate a `data-bind` sub-expression the way ko would: with the view model
 * as implicit scope, so `isCompanyCaptured()` in the markup means
 * `renderer.isCompanyCaptured()` rather than a free identifier.
 */
function evaluateBinding(expression, renderer) {
    // eslint-disable-next-line no-new-func
    return !!new Function(
        'renderer',
        'with (renderer) { return (' + expression + '); }'
    ).call(null, renderer);
}

/**
 * Whether the approved / declined order-intent notice would currently render,
 * and what it says — both read from the live observable rather than restated,
 * so a rewired binding fails here rather than passing on an assumed string.
 * These observables are the ONLY surface the captured company NAME appears on
 * in the tile; the NUMBER renders separately and notice-independently in the
 * capture panel's own `.two-company-id-text` label.
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
 * Confirms the template gates each notice `<div>` on its OWN observable via a
 * `ko if`, rather than on some shared predicate that could drift from what the
 * renderer actually resolved. Read from source rather than assumed.
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
 * render.
 *
 * Pinned to `isTileCompanyFieldVisible()` ALONE — the control's visibility
 * follows where the page-level component has mounted and nothing else, so a
 * drift that conjoins capture state or an order-intent outcome fails the exact
 * shape check as well as the behavioural cases below.
 */
function nameFieldVisible(renderer) {
    const markup = withoutComments(readTemplate());
    const tags = markup.match(/<div\b[^>]*class="field field-text required"[^>]*>/g) || [];
    if (tags.length !== 1) {
        throw new Error('expected exactly one company-name field wrapper, found ' + tags.length);
    }
    const bind = tags[0].match(/data-bind="([^"]*)"/);
    if (!bind) {
        throw new Error("the company-name field's wrapper has no data-bind attribute");
    }
    const visibleMatch = bind[1].match(/visible:\s*([^,]+?)\s*(?:,|$)/);
    if (!visibleMatch) {
        throw new Error("the company-name field's `visible:` binding is missing");
    }
    if (visibleMatch[1].trim() !== 'isTileCompanyFieldVisible()') {
        throw new Error(
            "the company-name field's `visible:` binding is not the pinned "
                + '`isTileCompanyFieldVisible()` shape — got `'
                + visibleMatch[1].trim()
                + '`'
        );
    }
    return evaluateBinding(visibleMatch[1], renderer);
}

/**
 * The org-number label the capture panel mounted at the tile would paint under
 * its own field: whether it shows anything, and what. Read through the panel's
 * own decision rather than restated, so a drift in either the mode gate or the
 * display filter fails here.
 */
function companyIdLabel(component) {
    const text = component.shipping.displayCompanyNumber();
    return { visible: text !== '', text: text };
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
 * the ko `attr` binding's target is evaluated the same way renderedValue() does
 * it, so a `readonly` bound to an observable is answered for THIS state rather
 * than assumed.
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

/**
 * jQuery double that records writes per selector, so a claim that the renderer
 * "no longer writes the number field" is checkable rather than assumed. The
 * default harness jQuery reports `length: 0` and returns the same inert object
 * from every setter, which cannot distinguish a write from no write.
 *
 * `presence` decides which selectors report a node. Only the TILE host does, so
 * the capture component resolves its mount there — which is the state the whole
 * file is about.
 */
function makeRecordingDom() {
    const writes = [];
    const nodes = {};
    const presence = {};
    presence[ADDRESS_FIELD_SELECTOR] = 0;
    presence[TILE_FIELD_SELECTOR] = 1;

    function node(selector) {
        if (nodes[selector]) return nodes[selector];
        const n = {
            selector: selector,
            length: selector in presence ? presence[selector] : 1,
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
            text: () => n,
            on: function (event, fn) {
                n.handlers[String(event).split('.')[0]] = fn;
                return n;
            },
            off: () => n,
            closest: (sel) => node(selector + ' >closest> ' + sel),
            find: (sel) => node(selector + ' >find> ' + sel),
            append: () => n,
            appendTo: () => n,
            insertAfter: () => n,
            prev: () => n,
            attr: () => n,
            addClass: () => n,
            removeClass: () => n,
            toggleClass: () => n,
            remove: () => n,
            data: () => null,
            eq: () => n,
            first: () => n,
            is: () => false,
            each: () => n,
            get: () => ({ style: {} }),
            hide: () => n,
            show: () => n,
            trigger: () => n,
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
        (xs || []).forEach(function (x, i) { fn(i, x); });
    };
    $.ajax = function () {
        const r = { done: () => r, fail: () => r, always: () => r };
        return r;
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

    return { $: $, node: node, writes: writes, presence: presence };
}

/**
 * The brand copy ConfigProvider ships for the intent-approved / -declined
 * notices. Real shape (both variants plus both tokens), because
 * resolveCompanyNotice() substitutes into it and the tests below depend on each
 * notice actually being non-empty when it should be.
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

/**
 * The tile: the renderer, the capture component that owns its mount, and the
 * SHIPPING panel's own identity — the one mounted at the tile, and the one
 * whose mode and adoption the tile's bindings read (TWO-25554).
 *
 * The REAL component, not a stub — every mode transition below is production's
 * own, so a mode that stopped clearing what it clears fails here.
 */
function loadTile() {
    const dom = makeRecordingDom();
    const soleTrader = { launches: [] };

    function SoleTraderStub() {
        this.listenForSignupResult = function () {};
        this.ensureTokens = function () { return Promise.resolve(true); };
        this.focusSignupPopup = function () { return false; };
        this.launchSignup = function (options) {
            soleTrader.launches.push(options || null);
            return null;
        };
        this.forgetAdoptions = function () {};
        this.selectDifferentSoleTrader = function () { return 'relaunched'; };
    }

    const shared = {
        jquery: dom.$
    };
    const component = loadCompanyCapture(
        Object.assign({}, shared, {
            'Two_Gateway/js/model/sole-trader': SoleTraderStub,
            'Two_Gateway/js/model/brand-config': brandConfigMock({
                isCompanySearchEnabled: true,
                checkoutApiUrl: 'https://api.example.test',
                checkoutPageUrl: 'https://checkout.example.test',
                supportedCompanyTypes: { gb: ['SOLE_TRADER'] }
            }),
            'Magento_Checkout/js/model/quote': Object.assign(
                {},
                defaultMocks()['Magento_Checkout/js/model/quote'],
                { billingAddress: quoteAddress({ countryId: 'GB' }) }
            )
        })
    );
    component.start();

    const renderer = loadAmdModule(
        RENDERER,
        Object.assign({}, shared, {
            'Two_Gateway/js/model/company-capture': component
        })
    );
    // `getCode()` comes from the Magento Component base class, which the
    // harness's Component double does not provide.
    renderer.getCode = function () {
        return 'two_payment';
    };
    // The notice observables are created in initOrderIntentApprovedNotice(),
    // which initialize() calls — and this harness deliberately does not boot the
    // component. Called explicitly rather than faked, so the real observables
    // AND their real companyName/companyId subscriptions are what the tests run
    // against.
    renderer.initOrderIntentApprovedNotice({
        orderIntentApprovedNotice: NOTICE_COPY,
        orderIntentDeclinedNotice: DECLINED_NOTICE_COPY
    });
    // showErrorMessage() routes through the payment block's messageContainer,
    // which this harness does not model.
    renderer.messageContainer = {
        addErrorMessage: function () {},
        errorMessages: { remove: function () {} }
    };

    return { renderer, component, identity: component.shipping.identity(), dom, soleTrader };
}

/**
 * Adopt a sole trader the way the hosted signup does: enter the mode, then hand
 * back an authenticated buyer record.
 */
function adoptSoleTrader(component, organizationNumber, companyName) {
    component.shipping.soleTraderMode();
    component.shipping.adoptSoleTrader({
        organization_number: organizationNumber,
        company_name: companyName
    });
}

/**
 * Drive the renderer to the state where the inline intent-approved notice is on
 * screen, through the REAL path — the ajax success handler — rather than by
 * poking the observable. A test that set the observable by hand would still
 * pass if processOrderIntentSuccessResponse() stopped setting it.
 */
function approveIntent(renderer) {
    renderer.processOrderIntentSuccessResponse({ approved: true });
}

/** Same, for the "not approved" business outcome — a clean `approved: false`. */
function declineIntent(renderer) {
    renderer.processOrderIntentSuccessResponse({ approved: false });
}

describe('the payment tile shows the number as an uneditable label, not a field', () => {
    test('there is no input#company_id left in the template', () => {
        const markup = withoutComments(readTemplate());
        expect(markup).not.toMatch(/<input\b[^>]*\bid\s*=\s*["']company_id["']/);
    });

    test.each([
        ['two-company-label', 'the standalone name+number line'],
        ['two-company-id-label', 'the superseded caption wrapper'],
        ['two-company-id-label__caption', 'its caption'],
        ['companyDisplayLabel', 'the builder method behind them']
    ])('%s is gone (%s)', (token) => {
        // Removed, not relocated: the company NAME now lives only inside the
        // notice text, and the NUMBER only in `.two-company-id-text`. Either
        // half of the old caption surviving alone is a defect — the caption
        // without its number labels nothing, and the number rendered separately
        // is the duplicate display that was removed.
        expect(withoutComments(readTemplate())).not.toContain(token);
    });

    test('the capture control is retained and stays visible on capture', () => {
        const { renderer } = loadTile();

        // Still present in the template — not deleted.
        expect(withoutComments(readTemplate())).toMatch(
            /<input\b[^>]*\bid\s*=\s*["']company_name["']/
        );

        // Nothing captured: the control is available.
        expect(nameFieldVisible(renderer)).toBe(true);

        // Captured: it stays up, unconditionally — no order-intent outcome and
        // no capture state changes it.
        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        expect(nameFieldVisible(renderer)).toBe(true);
    });

    test('the control is hidden when the component mounted at the address step instead', () => {
        // The one thing that DOES hide it. `visible:` rather than `if:`, because
        // the component resolves its mount by this node's presence in the DOM
        // and `if:` would destroy the node underneath a live select2 binding.
        const { renderer, component, dom } = loadTile();
        dom.presence[ADDRESS_FIELD_SELECTOR] = 1;
        dom.node(ADDRESS_FIELD_SELECTOR).length = 1;
        component.shipping.refreshMount();

        expect(nameFieldVisible(renderer)).toBe(false);
    });

    test('a name with no number leaves the capture control up — name-only is not captured', () => {
        // Manual entry (name, no number) must not make the payment method
        // usable, so capture is NOT complete. The control staying up is the same
        // rule as the captured case above, but this case is separate because
        // isCompanyCaptured() still gates the org-number label, and a name-only
        // capture must show neither it nor a notice.
        const { renderer, component } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'Typed By Hand Ltd', companyId: '' },
            { authoritative: true }
        );

        expect(renderer.isCompanyCaptured()).toBe(false);
        expect(nameFieldVisible(renderer)).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(companyIdLabel(component).visible).toBe(false);
    });

    test('the name field is read-only only once a sole trader has actually been captured', () => {
        // Not a static attribute, and the asymmetry is the point. Sole trader is
        // the one mode where this node is a plain text box holding a captured
        // name. In search mode select2 replaces it; in manual-entry mode the
        // buyer MUST be able to type. A static `readonly` bricks manual entry.
        const { renderer, component } = loadTile();

        adoptSoleTrader(component, 'ST-SYNTH-005', 'Sole Trader Example');
        expect(isReadOnly('company_name', renderer)).toBe(true);

        component.shipping.registeredMode();
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('the name field is never both empty and locked', () => {
        // THE REGRESSION THIS GUARDS, and it is not hypothetical. The input is
        // `required`, and jQuery Validation enforces `required` on a
        // `[readonly]` field (its `elements()` skips `:disabled` only). So a
        // state that is both empty and locked is a validation error with no
        // buyer action that clears it.
        //
        // Reached by entering sole-trader mode and abandoning the signup: the
        // mode clears the number, and nothing refills it.
        const { renderer, component, identity } = loadTile();

        component.shipping.soleTraderMode();

        expect(identity.captureMode()).toBe('soletrader');
        expect(renderer.companyId()).toBe('');
        // The name the buyer must now supply — so the field cannot be locked,
        // whatever the mode says.
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('a prior search does not leave the name locked after switching to sole trader', () => {
        // Same trap by a different route: a captured company, THEN the mode
        // switch. Sole-trader mode abandons the registry number and, on the
        // unmatched branch, nothing refills it.
        const { renderer, component } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        component.shipping.soleTraderMode();

        expect(renderer.companyId()).toBe('');
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('the name field binds required to isTileCompanyFieldVisible(), not a static flag', () => {
        // A static `required` stayed on this input even once the wrapper's
        // `visible:` binding hid it — `visible` only sets `display:none`, it
        // does not touch `required`, and a hidden required field still blocks
        // native HTML5 form submission silently (no bubble, since it cannot be
        // pointed at). Bound `required` to the same predicate as `visible`.
        const tag = inputTag('company_name');

        expect(hasAttribute(tag, 'required')).toBe(false);
        expect(tag).toMatch(/required:\s*isTileCompanyFieldVisible\(\)/);
        expect(tag).toMatch(/name="payment\[company_name\]"/);
        expect(tag).toMatch(/value:\s*tileCompanyName\b/);
    });

    test('company_name is still the only input whose required state jQuery Validation would enforce', () => {
        // Pins what `$(formSelector).valid()` enforces. The number is
        // deliberately not a validatable input at all any more.
        const markup = withoutComments(readTemplate());

        // Every spelling jQuery Validation would honour: a bare `required`,
        // `required="required"`, a bound `required:` in `attr`, and the
        // `data-validate` form all count.
        const requiredInputs = (markup.match(/<input\b[^>]*>/g) || []).filter(function (tag) {
            return (
                hasAttribute(tag, 'required') ||
                /\brequired:\s*[A-Za-z_$]/.test(tag) ||
                /data-validate\s*=\s*["'][^"']*required/.test(tag)
            );
        });

        expect(requiredInputs).toHaveLength(1);
        expect(requiredInputs[0]).toMatch(/\bid\s*=\s*["']company_name["']/);
    });

    test.each([
        ['companyIdSelector', 'a selector for a number field'],
        ['needsManualCompanyId', 'a derivation of whether one may be typed'],
        ['syncCompanyIdEditable', 'a writer of that derivation'],
        ['companyNameSelector', 'a mount selector of its own — the component owns the mount'],
        ['clearCompany', 'a capture-mode transition of its own']
    ])('the renderer has no %s (%s)', (member) => {
        const { renderer } = loadTile();

        expect(renderer[member]).toBeUndefined();
    });
});

describe('what each capture mode puts in front of the buyer', () => {
    test('search mode: an approved intent embeds "Name (number)" in the notice sentence, and the control stays up', () => {
        const { renderer, component } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);

        expect(renderedValue('company_name', renderer)).toBe('First Example Ltd');
        expect(approvedNoticeText(renderer)).toBe('Approved for First Example Ltd (12345678).');
        expect(approvedNoticeVisible(renderer)).toBe(true);
        expect(nameFieldVisible(renderer)).toBe(true);
        // The org-number label shows alongside both the control and the notice —
        // a separate display route from the notice sentence.
        expect(companyIdLabel(component)).toEqual({ visible: true, text: '12345678' });
    });

    test('sole-trader mode: the minted name and synthetic number are embedded the same way', () => {
        const { renderer, component } = loadTile();

        adoptSoleTrader(component, 'ST-SYNTH-004', 'Sole Trader Example');
        approveIntent(renderer);

        expect(renderedValue('company_name', renderer)).toBe('Sole Trader Example');
        expect(approvedNoticeText(renderer)).toBe(
            'Approved for Sole Trader Example (ST-SYNTH-004).'
        );
        expect(approvedNoticeVisible(renderer)).toBe(true);
        // Visible throughout — but LOCKED: a visible, editable copy of a
        // sole-trader-minted name is what isCompanyNameReadOnly() prevents.
        expect(nameFieldVisible(renderer)).toBe(true);
        expect(isReadOnly('company_name', renderer)).toBe(true);
    });

    test('manual-entry mode: the notice and the org-number label go, and the control is typeable again', () => {
        // Driven through the real transition, not by poking the observable, so
        // this fails if manual entry stops abandoning the registry number.
        const { renderer, component } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);
        expect(companyIdLabel(component).visible).toBe(true);

        component.shipping.manualEntryMode();

        expect(renderer.companyId()).toBe('');
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(companyIdLabel(component).visible).toBe(false);
        // The control was never hidden, and is now typeable again — which is the
        // whole point of the mode.
        expect(nameFieldVisible(renderer)).toBe(true);
        expect(isReadOnly('company_name', renderer)).toBe(false);
    });

    test('leaving sole-trader mode discards the sole-trader identity', () => {
        // A sole trader's minted name and synthetic number are not a registered
        // organisation, so carrying them across would submit one identity under
        // the other's mode.
        const { renderer, component, identity } = loadTile();

        adoptSoleTrader(component, 'ST-SYNTH-004', 'Sole Trader Example');
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);

        component.shipping.registeredMode();

        expect(identity.captureMode()).toBe('registered');
        expect(identity.soleTraderAdopted()).toBe(false);
        expect(renderer.companyId()).toBe('');
        expect(renderer.isCompanyCaptured()).toBe(false);
        expect(nameFieldVisible(renderer)).toBe(true);
        expect(isReadOnly('company_name', renderer)).toBe(false);
        expect(approvedNoticeVisible(renderer)).toBe(false);
    });

    test('returning to registered mode from search mode keeps a company captured upstream', () => {
        // registeredMode() is reachable with no sole trader ever adopted — a
        // chip click, or the picker's own "Search for company" link. Clearing
        // unconditionally there would wipe a company captured on the address
        // step, silently turning a completed capture into an unpayable order.
        const { renderer, component } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'Captured Upstream Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        component.shipping.registeredMode();

        expect(renderer.companyId()).toBe('12345678');
        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(companyIdLabel(component)).toEqual({ visible: true, text: '12345678' });
        // ...and once the intent for it is approved, the notice appears. Ordered
        // this way round on purpose: the notice's companyId subscription clears
        // it whenever the company changes, so an approval taken BEFORE the mode
        // call would prove nothing about what survives it.
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);
    });

    test('an internal TWO: identifier is never shown, but is still what the order carries', () => {
        // A sole trader's number is minted, not issued by a registry: it means
        // nothing to the buyer, so the label goes entirely rather than showing a
        // string they cannot act on. `companyId()` is untouched — it is the
        // single carrier, and getData() still reads it raw.
        const { renderer, component } = loadTile();

        adoptSoleTrader(component, 'TWO:ST:0199', 'Sole Trader Example');

        expect(companyIdLabel(component).visible).toBe(false);
        expect(renderer.getData().additional_data.companyId).toBe('TWO:ST:0199');
    });

    test('an identifier-less pick keeps the control up, with no notice', () => {
        // The other route to a blank number: the registry holds no identifier
        // for the picked company. Distinct from manual entry — a company IS
        // selected here.
        const { renderer } = loadTile();

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
 * Each notice is gated on its OWN observable via a `ko if`, and each observable
 * is set exactly once the corresponding order-intent outcome is known. There is
 * no shared "the label follows the message" predicate to pin, because there is
 * no label — company display and intent outcome are the SAME element.
 *
 * The behavioural cases are the mutation-sensitive part: each exercises a state
 * a naive "captured ⇒ show something" gate would get wrong.
 */
describe('the notices are gated on their own observables, not on capture', () => {
    test('each notice template block guards on its own observable expression', () => {
        expect(noticeGateExpression('approved')).toBe('isOrderIntentApprovedNoticeVisible()');
        expect(noticeGateExpression('declined')).toBe('isOrderIntentDeclinedNoticeVisible()');
    });

    test('a captured company with no intent placed yet shows neither notice', () => {
        // THE case a capture-only gate would get wrong: the company is fully
        // captured, so `isCompanyCaptured()` is true. No intent has been placed,
        // so there is no message.
        const { renderer } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(false);
    });

    test('an approved intent shows the approved notice only, with the company embedded', () => {
        const { renderer } = loadTile();

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
        const { renderer } = loadTile();
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

        declineIntent(renderer);

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(approvedNoticeVisible(renderer)).toBe(false);
        expect(declinedNoticeVisible(renderer)).toBe(true);
        expect(declinedNoticeText(renderer)).toBe('Declined for First Example Ltd (12345678).');
        // A clean decline is a persistent tile notice, not a toast that a later
        // checkout update wipes.
        expect(errors).toEqual([]);
    });

    test('the capture control stays up through approve, decline, and a re-pick', () => {
        const { renderer, component } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(nameFieldVisible(renderer)).toBe(true);

        declineIntent(renderer);

        expect(renderer.isCompanyCaptured()).toBe(true);
        expect(declinedNoticeVisible(renderer)).toBe(true);
        expect(nameFieldVisible(renderer)).toBe(true);

        renderer.applyCompanyData(
            { companyName: 'Second Example Ltd', companyId: '87654321' },
            { authoritative: true }
        );

        expect(declinedNoticeVisible(renderer)).toBe(false);
        expect(nameFieldVisible(renderer)).toBe(true);
        expect(companyIdLabel(component)).toEqual({ visible: true, text: '87654321' });
    });

    test('an errored intent shows neither notice', () => {
        // A distinct call site from the declined branch — a separate handler —
        // clearing both notices for its own reason: an error says nothing about
        // approval OR decline. Verified by mutation that the declined case above
        // does not cover it.
        const { renderer } = loadTile();

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
        // Cleared by the notice's own companyName / companyId subscriptions,
        // because the approval it reports was for the previous company.
        const { renderer } = loadTile();

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
        // deleting the companyName clear left the whole suite green.
        const { renderer } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );
        approveIntent(renderer);
        expect(approvedNoticeVisible(renderer)).toBe(true);

        // Only the name, as a buyer typing into the tile's input would.
        renderer.tileCompanyName('First Example Limited');

        expect(renderer.companyId()).toBe('12345678');
        expect(approvedNoticeVisible(renderer)).toBe(false);
    });

    test('order intent turned off means neither notice, ever', () => {
        // `enable_order_intent` off: fillCompanyData() never calls
        // placeOrderIntent(), so nothing sets either notice. Pinned so it reads
        // as a decision rather than an oversight, and so widening either
        // predicate cannot happen silently.
        const { renderer } = loadTile();
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
        // here touches billingAddress or BVCompanyRegex.
        const { renderer } = loadTile();

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
        // <intent_approved_notice_enabled>false</…> leaves both copy objects
        // null, so neither notice text is ever non-empty — one switch suppresses
        // both. The control's visibility does not read either observable, so a
        // brand with the notice UI off can never produce a hidden-with-no-notice
        // dead end.
        const { renderer } = loadTile();

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

        expect(nameFieldVisible(renderer)).toBe(true);
    });

    test('an approved-notice override does not leak into the declined notice, or vice versa', () => {
        // The two overrides are independent knobs.
        const { renderer } = loadTile();
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

    test('both notice observables are absent on a renderer that was never initialised', () => {
        // They are created in initOrderIntentApprovedNotice() rather than in
        // `defaults` — entries there are copied onto each instance BY REFERENCE,
        // so an observable declared there would be shared across every renderer
        // and carry one quote's notice into the next. The template's `ko if`
        // guards read them through the `is…Visible()` helpers for exactly this
        // window.
        const dom = makeRecordingDom();
        const bare = loadAmdModule(RENDERER, { jquery: dom.$ });

        expect(bare.orderIntentApprovedNotice).toBeUndefined();
        expect(bare.orderIntentDeclinedNotice).toBeUndefined();
    });
});

describe('an accepted organisation number still reaches the order', () => {
    test('a company-search pick reaches getData()', () => {
        const { renderer } = loadTile();

        renderer.applyCompanyData(
            { companyName: 'First Example Ltd', companyId: '12345678' },
            { authoritative: true }
        );

        expect(renderer.getData().additional_data.companyId).toBe('12345678');
        expect(renderer.getData().additional_data.companyName).toBe('First Example Ltd');
    });

    test('the sole-trader synthetic number reaches getData() via the adoption', () => {
        // The autofill endpoint MINTS this number; it is never picked from a
        // registry and never typed, so the adoption is the only path that lands
        // it and the only one that can lose it silently.
        const { renderer, component } = loadTile();

        adoptSoleTrader(component, 'ST-SYNTH-003', 'Chip Click Example');

        expect(renderer.companyId()).toBe('ST-SYNTH-003');
        expect(renderer.getData().additional_data.companyId).toBe('ST-SYNTH-003');
        expect(renderer.getData().additional_data.companyName).toBe('Chip Click Example');
    });

    test('showing the number does not change what the form submits', () => {
        // The regression this guards: reinstating a carrier for the number
        // beside the observable — a `name`, or a DOM read in getData() — so the
        // payload could come from the DOM instead. Asserted against the WHOLE
        // additional_data payload rather than the two keys, because a new
        // company key smuggled in beside them is the same defect.
        const { renderer, dom } = loadTile();

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
        // renderer never wrote any company_id node either — ko's `text:` binding
        // is the label's only painter, and a second one could disagree with the
        // observable, which is what actually ships.
        expect(
            dom.writes.filter(function (w) {
                return /company_id/.test(w[0]);
            })
        ).toEqual([]);
    });

    test('an identifier-less selection leaves the number empty for the server to refuse', () => {
        const { renderer } = loadTile();

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
