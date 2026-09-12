import { test, expect, Locator, Page } from '@playwright/test';
import { adminLogin, gotoConfigSection } from './_helpers';

// "Two" admin config (Stores -> Configuration -> Two) -> docs screenshots.
const OUT = process.env.OUT_DIR || 'screenshots';

// The open config section is the tallest .entry-edit (others are collapsed headers).
async function openSection(page: Page): Promise<Locator> {
    const all = page.locator('.entry-edit');
    const n = await all.count();
    let best = all.first();
    let bestH = -1;
    for (let i = 0; i < n; i++) {
        const b = await all.nth(i).boundingBox();
        if (b && b.height > bestH) {
            bestH = b.height;
            best = all.nth(i);
        }
    }
    return best;
}

test.describe('Two admin config', () => {
    test.skip(!process.env.ADMIN_PASS, 'ADMIN_PASS not set');

    test('config_tabs', async ({ page }) => {
        await adminLogin(page);
        await gotoConfigSection(page, 'two_general');
        // Anchor the clip on the section links, which reliably render in the nav
        // (the other config specs resolve them the same way). Top = just above the
        // General link to include the "Two" tab header; bottom = the Diagnostics
        // section, id `two_version`, which sorts last.
        const nav = page.locator('.admin__page-nav, #system_config_tabs').first();
        const general = page.locator('a[href*="/section/two_general/"]').first();
        const bottom = page.locator('a[href*="/section/two_version/"]').last();
        await expect(nav).toBeVisible({ timeout: 15_000 });
        await expect(general).toBeVisible({ timeout: 15_000 });
        await expect(bottom).toBeVisible({ timeout: 15_000 });
        const nb = await nav.boundingBox();
        const g = await general.boundingBox();
        const b = await bottom.boundingBox();
        if (!nb || !g || !b)
            throw new Error('could not resolve the Two config nav for config_tabs clip');
        const top = Math.max(0, g.y - 72); // include the "Two" tab header row above General
        await page.screenshot({
            path: `${OUT}/config_tabs.png`,
            clip: { x: nb.x, y: top, width: nb.width + 4, height: b.y + b.height - top + 20 }
        });
        console.log('config_tabs ok');
    });

    test('config_general', async ({ page }) => {
        await adminLogin(page);
        await gotoConfigSection(page, 'two_general');
        await (await openSection(page)).screenshot({ path: `${OUT}/config_general.png` });
        console.log('config_general ok');
    });

    test('config_payment split', async ({ page }) => {
        await adminLogin(page);
        await gotoConfigSection(page, 'two_payment');
        const box = await (await openSection(page)).boundingBox();
        if (!box) throw new Error('two_payment section has no bounding box');
        const half = Math.ceil(box.height / 2);
        await page.screenshot({
            path: `${OUT}/config_payment_1.png`,
            clip: { x: box.x, y: box.y, width: box.width, height: Math.min(half + 40, box.height) }
        });
        await page.evaluate((y) => window.scrollTo(0, y), box.y + half - 60);
        await page.waitForTimeout(400);
        const box2 = await (await openSection(page)).boundingBox();
        if (!box2) throw new Error('two_payment section lost its bounding box after scroll');
        await page.screenshot({
            path: `${OUT}/config_payment_2.png`,
            clip: {
                x: box2.x,
                y: Math.max(0, box2.y + half - 40),
                width: box2.width,
                height: box2.height - half + 40
            }
        });
        console.log('config_payment_1/2 ok');
    });

    test('config_search', async ({ page }) => {
        await adminLogin(page);
        // TWO-25386 folded the standalone two_search section into
        // two_checkout_fields as its own collapsible group.
        await gotoConfigSection(page, 'two_checkout_fields');
        const head = page.locator('#two_checkout_fields_search-head');
        await expect(head).toBeVisible({ timeout: 15_000 });
        const group = page.locator('#two_checkout_fields_search');
        if (!(await group.isVisible())) {
            await head.click();
        }
        await expect(group).toBeVisible({ timeout: 15_000 });
        await group.screenshot({ path: `${OUT}/config_search.png` });
        console.log('config_search ok');
    });
});
