/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25658: what focus landing on a control does to an open sole-trader signup popup, and to
 * the capture popover it was launched from. The popup's own controls are in another document.
 */

'use strict';

const { loadAmdModule, tagged } = require('./amd-harness');

const SOLE_TRADER = 'view/frontend/web/js/model/sole-trader.js';

/** The popover open behind the signup; the chip labels are deliberately not English. */
function renderCheckout() {
    document.body.innerHTML =
        '<input id="other-field">'
        // The wrap holds the field and the popover as siblings, which is the shape
        // the panel builds and the shape a morph re-render leaves the field in.
        + '<span class="two-company-field-wrap" id="wrap">'
        // The company field is the popover's own trigger and sits outside it.
        + '<input id="company">'
        + '<div class="two-company-dropdown" id="popover">'
        + '<input id="query">'
        + '<button data-two-chip="registered" id="registered">Ingeschreven bedrijf</button>'
        + '<button data-two-chip="soletrader" id="soletrader">Eenmanszaak</button>'
        + '</div>'
        + '</span>';
}

/**
 * Re-render a capture the way a host that morphs its server markup over the live
 * DOM does: the popover goes, the field node stays.
 *
 * @param {Element} wrap the capture's own field wrap
 * @param {boolean} keepWrap whether the morph stopped short of the wrap or took it too
 * @returns {Element} the newly rendered Sole trader chip
 */
function remorph(wrap, keepWrap) {
    const field = wrap.querySelector('#company');
    const host = keepWrap ? wrap : wrap.parentElement;
    if (!keepWrap) {
        wrap.parentElement.insertBefore(field, wrap);
        wrap.remove();
    } else {
        wrap.querySelector('.two-company-dropdown').remove();
    }
    const popover = document.createElement('div');
    popover.className = 'two-company-dropdown';
    popover.innerHTML = '<button data-two-chip="soletrader">Eenmanszaak</button>';
    host.insertBefore(popover, field.nextSibling);
    return popover.querySelector('[data-two-chip="soletrader"]');
}

/** Every flow load() armed, so afterEach can release its watcher. */
const loadedFlows = [];

/**
 * The flow, with a signup popup already up and the watcher armed.
 *
 * @returns {object} `{ flow, windowHandlers, popupRaised, focusins, popoverClosed,
 *          returnToCheckout }`
 */
function load() {
    const handlers = {};
    const fakeWindow = {
        addEventListener: function (type, handler) { handlers[type] = handler; },
        removeEventListener: function () {},
        open: function () { return null; }
    };
    const SoleTraderCtor = loadAmdModule(SOLE_TRADER, {}, {
        document: document,
        window: fakeWindow,
        setTimeout: setTimeout,
        clearTimeout: clearTimeout
    });

    let popoverClosed = 0;
    const storedPopover = document.getElementById('popover');
    const flow = new SoleTraderCtor({
        host: function () { return {}; },
        identity: function () { return {}; },
        config: function () { return {}; },
        panel: function () {
            return {
                // Stored, not re-queried: `getPanelElement()` hands back the node the
                // panel built, which a re-render detaches until the panel rebuilds.
                getPanelElement: function () { return storedPopover; },
                getField: function () { return [document.getElementById('company')]; },
                close: function () { popoverClosed += 1; }
            };
        }
    });
    let raised = 0;
    flow._popupWindow = {
        closed: false,
        close: function () { this.closed = true; },
        focus: function () { raised += 1; }
    };
    flow.watchForReturnToCheckout();
    loadedFlows.push(flow);
    // As company-search-panel.js binds every chip, and as soleTraderMode()
    // opens: the cancelled mousedown is why a mouse click never focuses it.
    const chip = document.getElementById('soletrader');
    chip.addEventListener('mousedown', (event) => { event.preventDefault(); });
    chip.addEventListener('click', () => { flow.focusSignupPopup(); });

    let focusins = 0;
    document.addEventListener('focusin', () => { focusins += 1; }, true);

    return {
        flow: flow,
        windowHandlers: handlers,
        popupRaised: function () { return raised; },
        focusins: function () { return focusins; },
        popoverClosed: function () { return popoverClosed; },
        /** @param {string} kind one of the gestures the table names */
        returnToCheckout: function (kind) {
            if (kind === 'a real mouse click on the Sole trader chip') {
                const mousedown = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
                chip.dispatchEvent(mousedown);
                expect(mousedown.defaultPrevented).toBe(true);
                chip.click();
            }
            if (kind === 'unrelated control') document.getElementById('other-field').focus();
            if (kind === 'the company name field') document.getElementById('company').focus();
            if (kind === 'the company query field') document.getElementById('query').focus();
            if (kind === 'a sibling chip') document.getElementById('registered').focus();
            if (kind === 'the Sole trader chip') document.getElementById('soletrader').focus();
            // A tab or app switch returns focus to the page, not to any control.
            if (kind === 'window focus') {
                if (handlers.focus) handlers.focus();
            }
        }
    };
}

beforeEach(renderCheckout);

// A `document` listener outlives `document.body.innerHTML = ...`: left armed, an earlier test's flow judges this test's focus against its own still-open popup.
afterEach(() => {
    loadedFlows.splice(0).forEach((flow) => flow.stopReturnToCheckoutWatcher());
});

describe('what a return to checkout does to an open signup popup', () => {
    test.each([
        ['the company query field', false, 0, 0, 1,
            'inside the popover: the signup goes, the capture the buyer is still in stays'],
        ['a sibling chip', false, 0, 0, 1,
            'inside the popover: switching capture mode ends the signup, not the capture'],
        ['unrelated control', false, 1, 0, 1,
            'outside the popover: the buyer has left capture, so both go'],
        ['the company name field', false, 0, 0, 1,
            'the popover\'s own trigger: the signup goes, the results being typed against stay'],
        ['the Sole trader chip', true, 0, 0, 1,
            'arriving on the chip moves the popup neither way'],
        ['a real mouse click on the Sole trader chip', true, 0, 1, 0,
            'the cancelled mousedown moves no focus, so the click alone raises it'],
        ['window focus', true, 0, 0, 0, 'a tab or app switch lands on no control at all']
    ])('focus landing on %s: popup open=%s, popover closed %d time(s), raised %d time(s)',
        (kind, open, popoverClosed, raised, focusins, why) => {
            const ctx = load();

            ctx.returnToCheckout(kind);

            expect(tagged(why, [
                ctx.flow.isPopupOpen(), ctx.popoverClosed(), ctx.popupRaised(), ctx.focusins()
            ])).toEqual(tagged(why, [open, popoverClosed, raised, focusins]));
        });
});

test('the keyboard route raises the popup it kept, rather than reopening one', () => {
    const ctx = load();
    const held = ctx.flow._popupWindow;

    // Tab onto the chip, then Enter — which the browser delivers as a click.
    ctx.returnToCheckout('the Sole trader chip');
    document.getElementById('soletrader').click();

    // The Enter alone: the arrival before it raised nothing.
    expect(ctx.popupRaised()).toBe(1);
    expect(ctx.flow._popupWindow).toBe(held);
    expect(ctx.flow.isPopupOpen()).toBe(true);
});

describe('a second capture on the same page (TWO-25658)', () => {
    /**
     * A second capture's own popover and chip — this checkout mounts two, each
     * with its own panel, chips and sole-trader flow.
     *
     * @param {boolean} [first] mount it ahead of the launching capture in the document
     * @returns {object} `{ chip, launches }`, `launches` counting activations
     */
    function renderSibling(first) {
        const sibling = document.createElement('div');
        sibling.className = 'two-company-field-wrap';
        sibling.id = 'wrap-b';
        sibling.innerHTML = '<input id="company-b">'
            + '<div class="two-company-dropdown">'
            + '<button data-two-chip="soletrader" id="soletrader-b">Eenmanszaak</button>'
            + '</div>';
        // First in tree order is the order a descendant search under a shared container
        // resolves the WRONG capture's popover in.
        if (first) document.body.insertBefore(sibling, document.body.firstChild);
        else document.body.appendChild(sibling);
        // Queried in the sibling's own subtree: jsdom's `getElementById` answers with
        // the first node REGISTERED under an id, not the first in the tree.
        const chip = sibling.querySelector('[data-two-chip="soletrader"]');
        const launches = { count: 0 };
        chip.addEventListener('click', function () { launches.count += 1; });
        return { chip: chip, launches: launches };
    }

    test.each([
        ['soletrader-b', false, 1, 1,
            'another control, and outside this popover: popup and capture both go, and that chip gets a popup of its own'],
        ['soletrader', true, 0, 0,
            'the launching chip stays exempt with a sibling on the page']
    ])('focus landing on #%s: popup open=%s, popover closed %d time(s), sibling launched %d time(s)',
        (chipId, open, popoverClosed, launches, why) => {
            const ctx = load();
            const sibling = renderSibling();

            document.getElementById(chipId).focus();

            expect(tagged(why, [
                ctx.flow.isPopupOpen(), ctx.popoverClosed(), sibling.launches.count, ctx.popupRaised()
            ])).toEqual(tagged(why, [open, popoverClosed, launches, 0]));
        });

    // The launching capture is the one that re-rendered, so its own chip is a node the
    // panel's stored popover never contained.
    test.each([
        ['own', true, false, true, 0, 0,
            'the launching capture\'s own re-rendered chip is still its own: the popup it launched stays'],
        ['own', false, false, true, 0, 0,
            'and still its own when the re-render took the wrap too, leaving the field where it is'],
        ['own', false, true, true, 0, 0,
            'and still its own with the other capture ahead of it in the document'],
        ['sibling', true, false, false, 1, 1,
            'the sibling capture\'s chip is still another control: this popup and its popover go, and that chip gets one']
    ])('after a re-render, focus landing on the %s chip (wrap kept=%s, sibling first=%s): popup open=%s, popover closed %d time(s), sibling launched %d time(s)',
        (which, keepWrap, siblingFirst, open, popoverClosed, launches, why) => {
            const ctx = load();
            const sibling = renderSibling(siblingFirst);
            const ownChip = remorph(document.getElementById('wrap'), keepWrap);

            (which === 'own' ? ownChip : sibling.chip).focus();

            expect(tagged(why, [
                ctx.flow.isPopupOpen(), ctx.popoverClosed(), sibling.launches.count, ctx.popupRaised()
            ])).toEqual(tagged(why, [open, popoverClosed, launches, 0]));
        });
});

test('no window-level focus listener is armed at all', () => {
    const ctx = load();

    expect(Object.keys(ctx.windowHandlers)).not.toContain('focus');
});

test('closing the popup releases the watcher, so a later focus closes nothing', () => {
    const ctx = load();

    ctx.flow.closeSignupPopup();
    document.getElementById('other-field').focus();

    expect(ctx.flow._returnHandler).toBe(null);
});
