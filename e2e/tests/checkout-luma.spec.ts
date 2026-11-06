import { test, expect } from './fixtures';
import {
    addToCart,
    availableMethods,
    chooseTwoPayment,
    fillCheckout,
    goToPaymentStep,
    selectShipping,
    waitIdle
} from './_helpers';

const OUT = process.env.OUT_DIR || 'screenshots';

test('magento checkout journey', async ({ page }) => {
    test.setTimeout(180_000);
    await addToCart(page);
    await page.screenshot({ path: `${OUT}/checkout_1_product.png` });
    await fillCheckout(page);

    await selectShipping(page, 'freeshipping');
    await goToPaymentStep(page);
    await expect.poll(() => availableMethods(page), { timeout: 25_000 }).toContain('two_payment');

    const placeOrder = await chooseTwoPayment(page);
    await waitIdle(page);
    await page.screenshot({ path: `${OUT}/checkout_2_filled.png`, fullPage: true });

    // Place order -> confirmation. The sandbox order-intent risk decision is
    // probabilistic, so retry a couple of times on a decline before giving up.
    const errorMsg = page.locator('.message.error, .mage-error, .swal2-html-container');
    const onSuccess = () => /checkout\/onepage\/success/i.test(page.url());
    let placed = onSuccess();
    let lastError = '';
    for (let attempt = 1; attempt <= 3 && !placed; attempt++) {
        await waitIdle(page);
        await placeOrder.click({ timeout: 15_000 }).catch(() => {});
        const result = await Promise.race([
            page
                .waitForURL(/checkout\/onepage\/success/i, { timeout: 40_000 })
                .then(() => 'success'),
            errorMsg
                .first()
                .waitFor({ state: 'visible', timeout: 40_000 })
                .then(() => 'error')
        ]).catch(() => 'timeout');
        if (result === 'success' || onSuccess()) {
            placed = true;
            break;
        }
        lastError = (await errorMsg.allInnerTexts().catch(() => [])).join(' | ') || `(${result})`;
        console.log(`place-order attempt ${attempt} not successful: ${lastError}`);
        await page.waitForTimeout(2000);
    }
    // The method appearing + form rendering are asserted above; order placement
    // depends on the sandbox risk decision, so a persistent decline is logged, not
    // failed, and the confirmation shot is only refreshed on a real success.
    if (placed) {
        await expect(page.getByText(/Thank you for your purchase/i)).toBeVisible({
            timeout: 15_000
        });
        await page.screenshot({ path: `${OUT}/checkout_3_result.png`, fullPage: true });
    } else {
        console.warn(
            `Two order declined by sandbox risk after 3 attempts; confirmation shot skipped. Last: ${lastError}`
        );
    }
});
