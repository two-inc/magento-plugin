import { Page } from '@playwright/test';
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
