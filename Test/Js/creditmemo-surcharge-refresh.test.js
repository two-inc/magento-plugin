/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

'use strict';

const { loadAmdModule } = require('./amd-harness');

describe('creditmemo-surcharge-refresh', () => {
    let init;
    let input;
    let button;
    let clickSpy;

    beforeEach(() => {
        jest.useFakeTimers();
        document.body.innerHTML =
            '<input id="two_surcharge_refund" value="5.00" />' +
            '<button data-ui-id="order-items-update-button" class="action-default disabled" disabled>Update Qty\'s</button>';
        input = document.getElementById('two_surcharge_refund');
        button = document.querySelector('[data-ui-id="order-items-update-button"]');
        clickSpy = jest.fn();
        button.addEventListener('click', clickSpy);

        init = loadAmdModule('view/adminhtml/web/js/creditmemo-surcharge-refresh.js');
        init(document, window);
    });

    afterEach(() => {
        jest.useRealTimers();
        document.body.innerHTML = '';
    });

    test('reloads totals on blur after the surcharge is edited', () => {
        input.value = '2.50';
        input.dispatchEvent(new window.Event('blur'));

        expect(clickSpy).toHaveBeenCalledTimes(1);
        // The disabled "Update Qty's" button must be re-enabled so the click lands.
        expect(button.disabled).toBe(false);
        expect(button.classList.contains('disabled')).toBe(false);
    });

    test('does NOT reload on blur when the value is unchanged', () => {
        input.dispatchEvent(new window.Event('blur'));
        expect(clickSpy).not.toHaveBeenCalled();
    });

    test('reloads after the debounce window on change', () => {
        input.value = '3.00';
        input.dispatchEvent(new window.Event('input'));

        expect(clickSpy).not.toHaveBeenCalled(); // still within debounce
        jest.advanceTimersByTime(700);
        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    test('debounce coalesces rapid edits into a single reload', () => {
        input.value = '3.00';
        input.dispatchEvent(new window.Event('input'));
        jest.advanceTimersByTime(300);
        input.value = '4.00';
        input.dispatchEvent(new window.Event('input'));
        jest.advanceTimersByTime(700);

        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    test('a second unchanged blur does not re-fire the reload', () => {
        input.value = '2.50';
        input.dispatchEvent(new window.Event('blur'));
        input.dispatchEvent(new window.Event('blur'));
        expect(clickSpy).toHaveBeenCalledTimes(1);
    });
});
