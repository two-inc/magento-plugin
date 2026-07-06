import { expect, Page } from '@playwright/test';

// GB skip-verification test buyer (auto-approved, no SCA) — the staging store is
// GBP, so a GB buyer keeps the order coherent and passes the order-intent.
// Source: two-inc/e2e-tests e2e_tests/config.py GBSettings.TEST_BUYER_COMPANY_SKIP_VERIFICATION.
export const COMPANY_QUERY = process.env.COMPANY_QUERY || 'RESTAURANT 53 LTD';
export const COUNTRY = process.env.COUNTRY || 'GB';
export const PRODUCT = process.env.PRODUCT || '/default/push-it-messenger-bag.html';

export async function fill(page: Page, sel: string, val: string) {
    await page.locator(sel).first().fill(val);
}

// Wait for Magento's async loading masks to clear so a screenshot isn't captured
// mid-update and clicks don't land on a busy overlay.
export async function waitIdle(page: Page) {
    await expect(page.locator('.loading-mask:visible')).toHaveCount(0, { timeout: 20_000 });
}

// The rendered payment list the checkout offers for the current quote.
export async function availableMethods(page: Page): Promise<string[]> {
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

// The quote's current shipping charge, for confirming a rate change landed.
async function shippingAmount(page: Page): Promise<number> {
    return page.evaluate(
        () =>
            new Promise<number>((resolve) => {
                (window as any).require(['Magento_Checkout/js/model/quote'], (q: any) => {
                    // totals() can be momentarily null mid-recalc — exactly the
                    // window we poll in; NaN keeps the caller polling.
                    const t = q.totals();
                    resolve(t ? Number(t.shipping_amount) : NaN);
                });
            })
    );
}

// Native click on the shipping radio — Playwright's .check()/.click() on the
// styled input doesn't fire Magento's shipping-change handler that recalculates
// totals, so wait for the radio to load, then drive it in-page like a real click.
export async function selectShipping(page: Page, kind: 'freeshipping' | 'flatrate') {
    await waitIdle(page);
    const radio = page
        .locator(`input[type="radio"][id*="${kind}"], input[type="radio"][value*="${kind}"]`)
        .first();
    await expect(radio).toBeVisible({ timeout: 20_000 });
    await radio.evaluate((el) => (el as HTMLInputElement).click());
    await waitIdle(page);
    // waitIdle only clears the loading-mask; the totals recalc lands a beat later
    // via a knockout observable, so a grand_total read here can catch the stale
    // pre-recalc value (flaky on slow CI runners). Poll until shipping_amount
    // reflects the chosen rate — non-zero for flat, zero for free — before
    // returning, so any following total read is settled.
    if (kind === 'flatrate') {
        await expect.poll(() => shippingAmount(page), { timeout: 20_000 }).toBeGreaterThan(0);
    } else {
        await expect.poll(() => shippingAmount(page), { timeout: 20_000 }).toBe(0);
    }
}

export async function addToCart(page: Page) {
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

export async function fillCheckout(page: Page) {
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

// Admin login. Password comes from env ADMIN_PASS (never committed / printed).
export async function adminLogin(page: Page, user = process.env.ADMIN_USER || 'brtkwr') {
    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    await page.fill('#username', user);
    await page.fill('#login', process.env.ADMIN_PASS || '');
    await page.locator('.action-login, button.action-primary').first().click();
    await page.waitForURL(/dashboard/, { timeout: 30_000 }).catch(() => {});
}
// The admin secret-key changes per session; grab it from any config link.
export async function configKey(page: Page): Promise<string> {
    const href = await page.locator('a[href*="admin/system_config/"]').first().getAttribute('href');
    const m = href?.match(/\/key\/([a-f0-9]+)/);
    if (!m) throw new Error('could not resolve admin config key');
    return m[1];
}

// Open the Two payment section of the admin store config (default scope).
export async function gotoTwoPaymentConfig(page: Page) {
    const cfg = await page.locator('a[href*="admin/system_config/"]').first().getAttribute('href');
    if (!cfg) throw new Error('could not find a system_config link (admin login likely failed)');
    await page.goto(cfg.replace(/\/?$/, '') + '/section/two_payment/', {
        waitUntil: 'domcontentloaded'
    });
    await page.waitForSelector('.entry-edit', { timeout: 30_000 });
}
