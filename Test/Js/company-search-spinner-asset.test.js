/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * TWO-25288. Pins the spinner's figure to a real file on disk.
 *
 * The spinner draws nothing of its own: the whole indicator is one background
 * image referenced from the stylesheet by a relative URL. Two independent
 * things can therefore break it while every markup-level test stays green —
 * the declaration can be dropped from the rule, or the URL can point at a
 * path that no longer holds an asset. Neither is visible from the DOM, so
 * both are asserted here directly: the computed style must still reference
 * the asset, and the asset must still exist where the URL says it does.
 *
 * The stylesheet is parsed by jsdom rather than string-matched, so a rule that
 * is present but syntactically dead (or overridden later in the file) fails.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '../..');
const STYLESHEET = path.join(REPO_ROOT, 'view/frontend/web/css/style.css');
const SPINNER_CLASS = 'two-company-dropdown__spinner';
const SPINNER_ACTIVE_CLASS = SPINNER_CLASS + '--active';

/**
 * Resolve whatever URL the stylesheet actually declares, relative to the
 * stylesheet — deliberately NOT a hardcoded asset path, so that repointing the
 * `url()` at a file that does not exist fails here instead of passing because
 * the old file is still lying around.
 *
 * @param {string} backgroundImage computed `background-image` value
 * @returns {string} absolute path, or '' when no URL was declared
 */
function resolveDeclaredAsset(backgroundImage) {
    const match = /url\((?:"|')?([^"')]+)(?:"|')?\)/.exec(backgroundImage || '');
    if (!match) return '';
    return path.resolve(path.dirname(STYLESHEET), match[1]);
}

/**
 * Inject the real stylesheet and return the computed style of a node carrying
 * the spinner class, exactly as the browser would resolve it.
 *
 * @param {string} [extraClass] modifier to carry alongside the base class
 * @returns {CSSStyleDeclaration}
 */
function computedSpinnerStyle(extraClass) {
    const style = document.createElement('style');
    style.textContent = fs.readFileSync(STYLESHEET, 'utf8');
    document.head.appendChild(style);

    const el = document.createElement('span');
    el.className = extraClass ? `${SPINNER_CLASS} ${extraClass}` : SPINNER_CLASS;
    document.body.appendChild(el);

    return window.getComputedStyle(el);
}

afterEach(() => {
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

test('the stylesheet paints the spinner from the loader asset', () => {
    const computed = computedSpinnerStyle();

    // Guards the `background-image` declaration itself. Note that jsdom does
    // NOT resolve the multi-value `background-position` shorthand form, so
    // that property is deliberately not asserted on — it reads as empty even
    // when the rule is correct.
    expect(computed.backgroundImage).toContain('loader.gif');
    expect(computed.backgroundRepeat).toBe('no-repeat');
    expect(computed.backgroundSize).toBe('16px 16px');
});

test('the loader asset the stylesheet points at exists on disk', () => {
    // A URL aimed at a missing file computes exactly the same as a good one,
    // so the computed-style assertion above cannot catch a deleted or unstaged
    // asset. Checked separately, against the path the declared URL resolves to.
    const asset = resolveDeclaredAsset(computedSpinnerStyle().backgroundImage);

    expect(asset).not.toBe('');
    expect(fs.existsSync(asset)).toBe(true);
});

// The DOM-level suites can only assert the modifier class toggles; whether that
// class actually paints or hides the figure lives here.
test.each([
    [undefined, 'none', 'idle'],
    [SPINNER_ACTIVE_CLASS, 'inline-block', 'searching']
])('the spinner is %s -> display %s (%s)', (modifier, expected) => {
    expect(computedSpinnerStyle(modifier).display).toBe(expected);
});

test('the spinner is sized to the asset rather than to inherited text', () => {
    const computed = computedSpinnerStyle();

    // The asset is natively 16x16; sizing the box in `em` would scale it with
    // the surrounding font and blur it on any surface whose text is larger.
    expect(computed.width).toBe('16px');
    expect(computed.height).toBe('16px');
});
