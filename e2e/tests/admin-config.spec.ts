import { test, Locator, Page } from '@playwright/test';
import { adminLogin } from './_helpers';

// "Two" admin config (Stores -> Configuration -> Two) -> docs screenshots.
const OUT = process.env.OUT_DIR || 'screenshots';

async function hideSystemMessages(page: Page) {
    await page
        .addStyleTag({
            content: '.message-system, .message-system-collapsible { display: none !important; }'
        })
        .catch(() => {});
}

async function gotoSection(page: Page, section: string) {
    const cfg = await page.locator('a[href*="admin/system_config/"]').first().getAttribute('href');
    if (!cfg) throw new Error('could not find a system_config link (admin login likely failed)');
    // Loading a Two section expands the Two tab in the nav with valid secret keys.
    await page.goto(cfg.replace(/\/?$/, '') + '/section/two_payment/', {
        waitUntil: 'domcontentloaded'
    });
    await page.waitForSelector('.entry-edit', { timeout: 30_000 }).catch(() => {});
    const href = await page.locator(`a[href*="/section/${section}/"]`).first().getAttribute('href');
    if (!href) throw new Error(`could not find the nav link for section ${section}`);
    await page.goto(href, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.entry-edit', { timeout: 30_000 }).catch(() => {});
    await hideSystemMessages(page);
    await page.waitForTimeout(600);
}

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
        await gotoSection(page, 'two_general');
        const title = page
            .locator('.admin__page-nav-title')
            .filter({ hasText: /^\s*Two\s*$/ })
            .first();
        const version = page.locator('a[href*="/section/two_version/"]').first();
        const nav = page.locator('.admin__page-nav, #system_config_tabs').first();
        const t = await title.boundingBox();
        const v = await version.boundingBox();
        const nb = await nav.boundingBox();
        if (t && v) {
            const x = nb ? nb.x : Math.max(0, t.x - 40);
            const width = nb ? nb.width + 4 : 440;
            await page.screenshot({
                path: `${OUT}/config_tabs.png`,
                clip: { x, y: t.y - 16, width, height: v.y + v.height - t.y + 28 }
            });
            console.log('config_tabs ok, nav?', !!nb);
        } else {
            throw new Error('could not resolve Two tab title / version link for config_tabs clip');
        }
    });

    test('config_general', async ({ page }) => {
        await adminLogin(page);
        await gotoSection(page, 'two_general');
        await (await openSection(page)).screenshot({ path: `${OUT}/config_general.png` });
        console.log('config_general ok');
    });

    test('config_payment split', async ({ page }) => {
        await adminLogin(page);
        await gotoSection(page, 'two_payment');
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
        await gotoSection(page, 'two_search');
        await (await openSection(page)).screenshot({ path: `${OUT}/config_search.png` });
        console.log('config_search ok');
    });
});
