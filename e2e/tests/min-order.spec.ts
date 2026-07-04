import { test, expect, Page } from '@playwright/test';
import {
    addToCart,
    adminLogin,
    availableMethods,
    fillCheckout,
    gotoTwoPaymentConfig,
    selectShipping
} from './_helpers';

// Regression test for the minimum-order-value gate: the Two method must appear
// and disappear LIVE as buyer-side changes (here: the shipping choice) move the
// order total across the merchant minimum, without a page reload. The minimum is
// pinned via the admin store config for the duration of the test so the run
// never depends on how the shared test merchant happens to be configured, and
// is always restored afterwards.
//
// Admin-gated like the admin-config specs: skips without ADMIN_PASS.

const MIN_FIELD = '#two_payment_payment_method_merchant_minimum_order';
const BASIS_FIELD = '#two_payment_payment_method_merchant_minimum_order_basis';

// Grand total of the current quote, in the quote currency (= store base currency
// on the staging store, so it compares 1:1 against the merchant minimum).
async function grandTotal(page: Page): Promise<number> {
    return page.evaluate(
        () =>
            new Promise<number>((resolve) => {
                (window as any).require(['Magento_Checkout/js/model/quote'], (quote: any) => {
                    resolve(Number(quote.totals().grand_total));
                });
            })
    );
}

async function readMinimumConfig(page: Page): Promise<{ amount: string; basis: string }> {
    await gotoTwoPaymentConfig(page);
    return {
        amount: await page.locator(MIN_FIELD).inputValue(),
        basis: await page.locator(BASIS_FIELD).inputValue()
    };
}

async function writeMinimumConfig(page: Page, amount: string, basis: string) {
    await gotoTwoPaymentConfig(page);
    await page.locator(MIN_FIELD).fill(amount);
    if (basis) {
        await page.locator(BASIS_FIELD).selectOption(basis);
    }
    await page.locator('#save').click();
    // The save reloads the page; a rejected value (e.g. below the platform
    // floor from the Two API) surfaces as an error banner instead of success.
    const success = page.locator('.message-success').first();
    const error = page.locator('.message-error').first();
    await expect(success.or(error)).toBeVisible({ timeout: 30_000 });
    if (await error.isVisible()) {
        throw new Error(`saving the minimum order config failed: ${await error.innerText()}`);
    }
}

test.describe('minimum order value gate', () => {
    test.skip(!process.env.ADMIN_PASS, 'ADMIN_PASS not set');

    test('method shows and hides live as shipping moves the total across the minimum', async ({
        page,
        browser
    }) => {
        test.setTimeout(240_000);

        // Build a quote and measure the total under each shipping choice — the
        // pinned minimum goes strictly between them, so toggling shipping is
        // what crosses the threshold.
        await addToCart(page);
        await fillCheckout(page);
        await selectShipping(page, 'flatrate');
        const flatTotal = await grandTotal(page);
        await selectShipping(page, 'freeshipping');
        const freeTotal = await grandTotal(page);
        expect(flatTotal, 'flat-rate shipping must cost more than free shipping').toBeGreaterThan(
            freeTotal + 0.02
        );
        const pinned = ((freeTotal + flatTotal) / 2).toFixed(2);
        console.log(`totals: free=${freeTotal} flat=${flatTotal}; pinning minimum at ${pinned}`);

        // Admin runs in its own context so the buyer page keeps its session and
        // is never reloaded — the whole point is the in-page recalc.
        const adminContext = await browser.newContext();
        const adminPage = await adminContext.newPage();
        await adminLogin(adminPage);
        const original = await readMinimumConfig(adminPage);
        try {
            // gross basis compares the grand total directly — the number the
            // buyer sees in the totals block.
            await writeMinimumConfig(adminPage, pinned, 'gross');

            await selectShipping(page, 'flatrate');
            await expect
                .poll(() => availableMethods(page), { timeout: 25_000 })
                .toContain('two_payment');
            await selectShipping(page, 'freeshipping');
            await expect
                .poll(() => availableMethods(page), { timeout: 25_000 })
                .not.toContain('two_payment');
            // …and back, so the gate re-opens as well as closes.
            await selectShipping(page, 'flatrate');
            await expect
                .poll(() => availableMethods(page), { timeout: 25_000 })
                .toContain('two_payment');
        } finally {
            await writeMinimumConfig(adminPage, original.amount, original.basis);
            await adminContext.close();
        }
    });
});
