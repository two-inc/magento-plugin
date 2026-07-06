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
// Each config field carries a "Use Default" checkbox; while it is checked the
// field renders disabled, so fill()/selectOption() would hang waiting for an
// editable element. Manage the checkbox before touching the field.
const MIN_INHERIT = '#two_payment_payment_method_merchant_minimum_order_inherit';
const BASIS_INHERIT = '#two_payment_payment_method_merchant_minimum_order_basis_inherit';

interface MinimumConfig {
    amount: string;
    basis: string;
    // Whether each field was inheriting the default (Use Default checked) —
    // captured so teardown can restore it faithfully rather than typing an
    // empty string into a disabled field.
    amountInherited: boolean;
    basisInherited: boolean;
}

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

async function readMinimumConfig(page: Page): Promise<MinimumConfig> {
    await gotoTwoPaymentConfig(page);
    // inputValue() reads a disabled input fine; isChecked() tells us whether
    // the field was on its default so we can put it back exactly as found.
    return {
        amount: await page.locator(MIN_FIELD).inputValue(),
        basis: await page.locator(BASIS_FIELD).inputValue(),
        amountInherited: await page.locator(MIN_INHERIT).isChecked(),
        basisInherited: await page.locator(BASIS_INHERIT).isChecked()
    };
}

// Set one config field, driving its "Use Default" checkbox first. Clicking the
// checkbox is what fires Magento's handler that enables/disables the input, so
// a custom value must wait for the field to be editable before filling.
async function setConfigField(
    page: Page,
    inheritSel: string,
    fieldSel: string,
    inherited: boolean,
    apply: () => Promise<void>
) {
    if (inherited) {
        // Restore to default: checking the box disables and resets the field.
        await page.locator(inheritSel).setChecked(true);
        return;
    }
    await page.locator(inheritSel).setChecked(false);
    await expect(page.locator(fieldSel)).toBeEditable({ timeout: 10_000 });
    await apply();
}

async function writeMinimumConfig(page: Page, cfg: MinimumConfig) {
    await gotoTwoPaymentConfig(page);
    await setConfigField(page, MIN_INHERIT, MIN_FIELD, cfg.amountInherited, () =>
        page.locator(MIN_FIELD).fill(cfg.amount)
    );
    await setConfigField(page, BASIS_INHERIT, BASIS_FIELD, cfg.basisInherited, async () => {
        if (cfg.basis) {
            await page.locator(BASIS_FIELD).selectOption(cfg.basis);
        }
    });
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
            // buyer sees in the totals block. A pinned custom value, so neither
            // field inherits the default.
            await writeMinimumConfig(adminPage, {
                amount: pinned,
                basis: 'gross',
                amountInherited: false,
                basisInherited: false
            });

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
            // Restore exactly as found — including putting a field back on its
            // default (Use Default) rather than filling an empty string into a
            // now-disabled input, which is what timed the teardown out before.
            await writeMinimumConfig(adminPage, original);
            await adminContext.close();
        }
    });
});
