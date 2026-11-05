import { test, expect, Page, storeAuthHeaders } from './fixtures';
import {
    addToCart,
    adminLogin,
    adminLogout,
    availableMethods,
    chooseTwoPayment,
    fillCheckout,
    goToPaymentStep,
    selectShipping,
    waitIdle
} from './_helpers';

// Recovery of an order whose buyer verified with Two but never came back to the
// shop (TWO-26300). The buyer's return to the shop is blocked, so Two holds the
// order as verified while the shop still waits. The order is then settled by
// the admin's "Check status with Two" button, or by the scheduled check.
//
// Admin-gated like the other admin specs: skips without ADMIN_PASS.
//
// The scheduled-check test needs dev store settings the suite cannot make (the
// first check after 2 minutes rather than 30, the job every minute, and cron
// running), so it runs only when E2E_PENDING_CRON=1 says the store has them.

const RETURN_URL = /\/two\/payment\/confirm/;
const PLACE_ORDER_URL = /\/payment-information(\?|$)/;
const RECOVERED = /did not return to the shop/;

// The dev store serves `staging`, so on a pull request that adds the button it
// is not there yet. Only then is its absence a skip rather than a failure; the
// promotion pull request (head `staging`) and a manual run still fail.
function featureMayBeUndeployed(): boolean {
    return (
        process.env.GITHUB_EVENT_NAME === 'pull_request' &&
        process.env.GITHUB_HEAD_REF !== 'staging'
    );
}

interface Placed {
    // Magento's entity id for the order, from the place-order response.
    orderId: string;
    // The return to the shop the buyer never made.
    returnUrl: string;
}

// Place a Two order and stop the buyer before they return to the shop: the
// return request is aborted and its URL kept, and the order's entity id is
// read from the place-order response. Returns null if the sandbox declined
// every attempt (logged, as the checkout spec does).
async function placeOrderWithoutReturning(page: Page, email: string): Promise<Placed | null> {
    let returnUrl = '';
    let orderId = '';
    await page.route(RETURN_URL, async (route) => {
        returnUrl = route.request().url();
        await route.abort();
    });
    // Read the order id from the place-order response here, before the page
    // moves on to Two: a body read after that navigation can come back empty.
    // A page route takes precedence over the context's, so it sends the store
    // token itself.
    await page.route(PLACE_ORDER_URL, async (route) => {
        const request = route.request();
        if (request.method() !== 'POST') return route.fallback();
        try {
            const response = await route.fetch({
                headers: { ...request.headers(), ...storeAuthHeaders(request.url()) },
                timeout: 60_000
            });
            if (response.ok()) orderId = String(await response.json().catch(() => ''));
            await route.fulfill({ response });
        } catch {
            // The error would carry the request headers into the public log.
            await route.abort().catch(() => {});
        }
    });

    await addToCart(page);
    await fillCheckout(page, email);
    await selectShipping(page, 'freeshipping');
    await goToPaymentStep(page);
    await expect.poll(() => availableMethods(page), { timeout: 25_000 }).toContain('two_payment');
    const placeOrder = await chooseTwoPayment(page);

    const errorMsg = page.locator('.message.error, .mage-error, .swal2-html-container');
    for (let attempt = 1; attempt <= 3 && !returnUrl; attempt++) {
        await waitIdle(page);
        await placeOrder.click({ timeout: 15_000 }).catch(() => {});
        await Promise.race([
            expect.poll(() => returnUrl, { timeout: 60_000 }).not.toBe(''),
            errorMsg.first().waitFor({ state: 'visible', timeout: 60_000 })
        ]).catch(() => {});
        if (!returnUrl) {
            const shown = (await errorMsg.allInnerTexts().catch(() => [])).join(' | ');
            console.log(`place-order attempt ${attempt} did not reach the return: ${shown}`);
            await page.waitForTimeout(2000);
        }
    }
    await page.unroute(RETURN_URL);
    await page.unroute(PLACE_ORDER_URL);
    if (!returnUrl) return null;
    expect(orderId, 'the place-order response named the order').toMatch(/^\d+$/);
    return { orderId, returnUrl };
}

// Open the admin view of exactly this order. Admin URLs carry a secret key
// per route, not per order, so any order's View link from the grid serves
// once its order id is swapped. Starts from the admin, so it can be called
// from a storefront page.
async function openOrder(page: Page, orderId: string, email: string) {
    await page.goto('/admin', { waitUntil: 'domcontentloaded' });
    const grid = await page.locator('a[href*="/sales/order/"]').first().getAttribute('href');
    if (!grid)
        throw new Error('could not find the sales order grid link (admin login likely failed)');
    await page.goto(grid, { waitUntil: 'domcontentloaded' });
    const view = page.locator('tr.data-row a[href*="/sales/order/view/"]').first();
    await expect(view).toBeAttached({ timeout: 60_000 });
    const href = await view.getAttribute('href');
    if (!href || !/\/order_id\/\d+\//.test(href)) {
        throw new Error(`unexpected order view link: ${href}`);
    }
    await page.goto(href.replace(/\/order_id\/\d+\//, `/order_id/${orderId}/`), {
        waitUntil: 'domcontentloaded'
    });
    await expect(page.locator('#order_status')).toBeVisible({ timeout: 60_000 });
    await expect(page.locator('.order-account-information')).toContainText(email);
}

async function comments(page: Page, pattern: RegExp): Promise<number> {
    const texts = await page.locator('#order_history_block .note-list-comment').allInnerTexts();
    return texts.filter((t) => pattern.test(t)).length;
}

function uniqueEmail(path: string): string {
    return `pending-recovery-${path}-${Date.now()}@example.com`;
}

test.describe('pending order recovery', () => {
    test.skip(!process.env.ADMIN_PASS, 'ADMIN_PASS not set; admin-gated spec skipped');

    test.afterEach(async ({ page }) => {
        await adminLogout(page);
    });

    test('the admin button settles an order the buyer never returned to', async ({ page }) => {
        test.setTimeout(300_000);
        const email = uniqueEmail('button');
        const placed = await placeOrderWithoutReturning(page, email);
        test.skip(!placed, 'Two order declined by sandbox risk after 3 attempts');
        const { orderId, returnUrl } = placed as Placed;

        await adminLogin(page);
        await openOrder(page, orderId, email);
        await expect(page.locator('#order_status')).toHaveText(/Pending/);

        const button = page.locator('button.two-check-status');
        if ((await button.count()) === 0 && featureMayBeUndeployed()) {
            test.skip(
                true,
                'Check status button not on the dev store yet: it serves staging, which this pull request changes'
            );
        }
        await expect(button).toBeVisible();
        await Promise.all([
            page.waitForURL(/sales\/order\/view/, { timeout: 60_000 }),
            button.click()
        ]);

        await expect(
            page.locator('.message-success', { hasText: /order state: (VERIFIED|CONFIRMED)/ })
        ).toBeVisible();
        await expect(page.locator('#order_status')).toHaveText(/Processing/);
        expect(await comments(page, RECOVERED)).toBe(1);
        expect(await comments(page, /verified by the customer/)).toBe(1);
        await expect(page.locator('button.two-check-status')).toHaveCount(0);

        // The buyer's late return now finds the order settled: success page,
        // and nothing done a second time.
        await page.goto(returnUrl, { waitUntil: 'domcontentloaded' });
        await expect(page).toHaveURL(/checkout\/onepage\/success/, { timeout: 60_000 });
        await openOrder(page, orderId, email);
        expect(await comments(page, RECOVERED)).toBe(1);
        expect(await comments(page, /verified by the customer/)).toBe(1);
    });

    test('the scheduled check settles an order the buyer never returned to', async ({ page }) => {
        test.skip(
            process.env.E2E_PENDING_CRON !== '1',
            'dev store not set up for a short scheduled check (E2E_PENDING_CRON)'
        );
        test.setTimeout(600_000);
        const email = uniqueEmail('cron');
        const placed = await placeOrderWithoutReturning(page, email);
        test.skip(!placed, 'Two order declined by sandbox risk after 3 attempts');

        await adminLogin(page);
        await openOrder(page, (placed as Placed).orderId, email);
        await expect
            .poll(
                async () => {
                    await page.reload({ waitUntil: 'domcontentloaded' });
                    return (await page.locator('#order_status').innerText()).trim();
                },
                { timeout: 420_000, intervals: [30_000] }
            )
            .toMatch(/Processing/);
        expect(
            await comments(
                page,
                /Confirmed with .+ by the scheduled check|Processed by the scheduled check/
            )
        ).toBe(1);
        expect(await comments(page, /verified by the customer/)).toBe(1);
    });
});
