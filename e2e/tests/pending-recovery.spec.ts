import { test, expect, Page } from './fixtures';
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

// Place a Two order and stop the buyer before they return to the shop: the
// return request is aborted and its URL kept. Returns null if the sandbox
// declined every attempt (logged, as the checkout spec does).
async function placeOrderWithoutReturning(page: Page, email: string): Promise<string | null> {
    let returnUrl = '';
    await page.route(RETURN_URL, async (route) => {
        returnUrl = route.request().url();
        await route.abort();
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
    return returnUrl || null;
}

// Open the admin order view of the order placed with this email.
async function openOrder(page: Page, email: string) {
    const grid = await page.locator('a[href*="/sales/order/"]').first().getAttribute('href');
    if (!grid)
        throw new Error('could not find the sales order grid link (admin login likely failed)');
    await page.goto(grid, { waitUntil: 'domcontentloaded' });
    // The grid renders a second, sticky copy of its header: use the visible one.
    const search = page
        .locator('.admin__data-grid-header input#fulltext')
        .filter({ visible: true })
        .first();
    await expect(search).toBeVisible({ timeout: 60_000 });
    await search.fill(email);
    await search.press('Enter');
    await expect(
        page.locator('.admin__current-filters-list').filter({ visible: true }).first()
    ).toContainText(email, {
        timeout: 60_000
    });
    await expect(page.locator('.admin__data-grid-loading-mask:visible')).toHaveCount(0, {
        timeout: 60_000
    });
    // The keyword search matches on words, so earlier runs' orders can match
    // too: this run's order is the newest, and the view must show its email.
    const rows = page.locator('tr.data-row');
    await expect(rows.first()).toBeVisible({ timeout: 60_000 });
    const ids = (await rows.locator('td:nth-child(2)').allInnerTexts()).map((t) => t.trim());
    const newest = ids.reduce((a, b) => (Number(b) > Number(a) ? b : a));
    await rows
        .filter({ has: page.locator('td:nth-child(2)', { hasText: newest }) })
        .getByRole('link', { name: 'View' })
        .click();
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
        const returnUrl = await placeOrderWithoutReturning(page, email);
        test.skip(!returnUrl, 'Two order declined by sandbox risk after 3 attempts');

        await adminLogin(page);
        await openOrder(page, email);
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
        await page.goto(returnUrl as string, { waitUntil: 'domcontentloaded' });
        await expect(page).toHaveURL(/checkout\/onepage\/success/, { timeout: 60_000 });
        await openOrder(page, email);
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
        const returnUrl = await placeOrderWithoutReturning(page, email);
        test.skip(!returnUrl, 'Two order declined by sandbox risk after 3 attempts');

        await adminLogin(page);
        await openOrder(page, email);
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
