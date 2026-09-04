import { expect, Page } from '@playwright/test';

// GB skip-verification test buyer (auto-approved, no SCA) — the staging store is
// GBP, so a GB buyer keeps the order coherent and passes the order-intent.
// Value mirrors the shared GB skip-verification buyer used by the internal e2e
// suite; override with COMPANY_QUERY if that fixture changes.
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

// Whether the checkout has reached the payment step, per Magento's own
// step-navigator (the authority the checkout renders from).
async function onPaymentStep(page: Page): Promise<boolean> {
    return page.evaluate(
        () =>
            new Promise<boolean>((resolve) => {
                (window as any).require(
                    ['Magento_Checkout/js/model/step-navigator'],
                    (nav: any) => {
                        resolve(
                            (nav.steps() || []).some(
                                (s: any) => s.code === 'payment' && s.isVisible()
                            )
                        );
                    }
                );
            })
    );
}

// Advance the Luma checkout from the shipping step to the payment step.
//
// This is a required step of the journey, not a convenience: on a non-virtual
// quote Magento leaves `checkoutConfig.paymentMethods` empty on page load and
// only populates payment-service from the shipping-information POST that this
// button triggers. Reading availableMethods() while still on the shipping step
// therefore returns [] no matter what the store offers.
export async function goToPaymentStep(page: Page) {
    if (await onPaymentStep(page)) {
        return;
    }
    await waitIdle(page);
    const next = page.locator(
        '#shipping-method-buttons-container button[data-role="opc-continue"]'
    );
    await expect(next).toBeEnabled({ timeout: 20_000 });
    await next.click();
    // The step flips on the shipping-information response, a network round trip
    // after the click, so poll the navigator rather than reading it once.
    await expect.poll(() => onPaymentStep(page), { timeout: 30_000 }).toBe(true);
    await waitIdle(page);
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

// The company control the address step renders. Scoped to the wrapper the
// popover is built inside, because the payment tile hosts a second one.
export const COMPANY_FIELD = '#shipping-new-address-form input[name="company"]';

function companyWrap(page: Page, fieldSelector = COMPANY_FIELD) {
    return page.locator(fieldSelector).locator('xpath=ancestor::*[contains(@class,"two-company-field-wrap")][1]');
}

// Open the popover, search, and take the first hit.
export async function selectCompany(page: Page, query = COMPANY_QUERY, fieldSelector = COMPANY_FIELD) {
    const wrap = companyWrap(page, fieldSelector);
    const panel = wrap.locator('.two-company-dropdown');

    await page.locator(fieldSelector).first().click();
    // The panel carries `hidden` while closed, so visibility is the open state.
    await expect(panel).toBeVisible({ timeout: 15_000 });

    await panel.locator('input.two-company-dropdown__query').fill(query);

    const firstRow = panel.locator('.two-company-dropdown__row').first();
    await expect(firstRow).toBeVisible({ timeout: 15_000 });
    const rowText = ((await firstRow.textContent()) || '').trim();
    await firstRow.click();

    await expect(panel).toBeHidden({ timeout: 10_000 });
    const captured = await page.locator(fieldSelector).first().inputValue();
    expect(captured.length).toBeGreaterThan(0);
    // The row renders the name plus the registry identifier, the field only the
    // name — so containment, not equality.
    expect(rowText).toContain(captured);
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
    await selectCompany(page);
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
