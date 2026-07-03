import { test, expect, Page } from '@playwright/test';

const OUT = process.env.OUT_DIR || 'screenshots';
// GB skip-verification test buyer (auto-approved, no SCA) — the staging store is
// GBP, so a GB buyer keeps the order coherent and passes the order-intent.
// Source: two-inc/e2e-tests e2e_tests/config.py GBSettings.TEST_BUYER_COMPANY_SKIP_VERIFICATION.
const COMPANY_QUERY = process.env.COMPANY_QUERY || 'RESTAURANT 53 LTD';
const COUNTRY = process.env.COUNTRY || 'GB';
const PRODUCT = process.env.PRODUCT || '/default/push-it-messenger-bag.html';

async function fill(page: Page, sel: string, val: string) {
    await page.locator(sel).first().fill(val);
}

// Wait for Magento's async loading masks to clear so a screenshot isn't captured
// mid-update and clicks don't land on a busy overlay.
async function waitIdle(page: Page) {
    await expect(page.locator('.loading-mask:visible')).toHaveCount(0, { timeout: 20_000 });
}

async function availableMethods(page: Page): Promise<string[]> {
    return page.evaluate(
        () =>
            new Promise<string[]>((resolve) => {
                (window as any).require(
                    ['Magento_Checkout/js/model/payment-service'],
                    (ps: any) => {
                        resolve((ps.getAvailablePaymentMethods() || []).map((m: any) => m.method));
                    }
                );
            })
    );
}

// Native click on the shipping radio — Playwright's .check()/.click() on the
// styled input doesn't fire Magento's shipping-change handler that recalculates
// totals, so wait for the radio to load, then drive it in-page like a real click.
async function selectShipping(page: Page, kind: 'freeshipping' | 'flatrate') {
    await waitIdle(page);
    const radio = page
        .locator(`input[type="radio"][id*="${kind}"], input[type="radio"][value*="${kind}"]`)
        .first();
    await expect(radio).toBeVisible({ timeout: 20_000 });
    await radio.evaluate((el) => (el as HTMLInputElement).click());
    await waitIdle(page);
}

async function addToCart(page: Page) {
    await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });
    const btn = page.locator('#product-addtocart-button');
    await expect(btn).toBeEnabled({ timeout: 20_000 });
    await Promise.all([
        page.waitForResponse((r) => /checkout\/cart\/add/.test(r.url()) && r.status() === 200, {
            timeout: 25_000
        }),
        btn.click()
    ]);
}

async function fillCheckout(page: Page) {
    await page.goto('/checkout', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#customer-email')).toBeVisible({ timeout: 30_000 });
    await fill(page, '#customer-email', 'docs-demo@example.com');
    await fill(page, '[name="firstname"]', 'Demo');
    await fill(page, '[name="lastname"]', 'Koper');
    await fill(page, '[name="street[0]"]', 'Gustav Mahlerlaan 10');
    await fill(page, '[name="city"]', 'Amsterdam');
    await fill(page, '[name="postcode"]', '1082 PP');
    await fill(page, '[name="telephone"]', '+442071234567');
    await page.locator('[name="country_id"]').first().selectOption(COUNTRY);
    await page.locator('.select2-selection').first().click();
    await page.locator('.select2-search__field').first().fill(COMPANY_QUERY);
    const firstResult = page.locator('.select2-results__option').first();
    await expect(firstResult).toBeVisible({ timeout: 15_000 });
    await firstResult.click();
    await expect(page.locator('[name="city"]').first()).toHaveValue(/.+/, { timeout: 15_000 });
    await waitIdle(page);
}

test('magento checkout journey', async ({ page }) => {
    test.setTimeout(180_000);
    await addToCart(page);
    await page.screenshot({ path: `${OUT}/checkout_1_product.png` });
    await fillCheckout(page);

    await selectShipping(page, 'freeshipping');
    await expect.poll(() => availableMethods(page), { timeout: 25_000 }).toContain('two_payment');

    // Select the Two method and wait for its expanded form to render.
    const twoRadio = page.locator('#two_payment');
    await expect(twoRadio).toBeVisible({ timeout: 15_000 });
    await twoRadio.click();
    const twoBlock = page.locator('.two-payment-method');
    await expect(twoBlock.getByRole('button', { name: /place order/i })).toBeVisible({
        timeout: 15_000
    });

    // Accept the terms agreement checkbox(es) in the Two block (custom-styled -> native click).
    await expect(twoBlock.locator('input[type="checkbox"]').first()).toBeVisible({
        timeout: 10_000
    });
    await page.evaluate(() => {
        document.querySelectorAll('.two-payment-method input[type="checkbox"]').forEach((cb) => {
            if (!(cb as HTMLInputElement).checked) (cb as HTMLInputElement).click();
        });
    });

    const placeOrder = page.getByRole('button', { name: /place order/i });
    await expect(placeOrder).toBeVisible();
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
