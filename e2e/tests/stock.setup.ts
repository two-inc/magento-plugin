import { test as setup, expect } from './fixtures';
import { adminLogin, adminLogout } from './_helpers';

// Runs before every spec (the chromium project depends on it): the specs that
// check out all buy the same product, and every order they leave pending holds
// a reservation, so the dev store's stock of it runs out and every checkout
// spec fails with "Not enough items for sale" (TWO-26300). Tops the quantity
// up through the admin product form, and saves only when it is low, so a
// healthy store is left untouched.
//
// Admin UI rather than the REST API: the store's proxy takes the
// Authorization header for its own token, so an admin API token cannot be
// sent alongside it. Skips without ADMIN_PASS, like the other admin specs.

const PRODUCT_NAME = process.env.PRODUCT_NAME || 'Push It Messenger Bag';
// Topped up when fewer than this many can still be sold.
const LOW = 100;
const TOP_UP = 1000;

const QTY = 'input[name="product[quantity_and_stock_status][qty]"]';
const IN_STOCK = 'select[name="product[quantity_and_stock_status][is_in_stock]"]';

setup('the test product has stock', async ({ page }) => {
    setup.skip(!process.env.ADMIN_PASS, 'ADMIN_PASS not set; stock not checked');
    setup.setTimeout(180_000);
    try {
        await adminLogin(page);
        const catalog = await page
            .locator('a[href*="/catalog/product/"]')
            .first()
            .getAttribute('href');
        if (!catalog)
            throw new Error('could not find the catalog link (admin login likely failed)');
        await page.goto(catalog, { waitUntil: 'domcontentloaded' });

        // The grid renders a second, sticky copy of its header: use the visible one.
        const search = page
            .locator('.admin__data-grid-header input#fulltext')
            .filter({ visible: true })
            .first();
        await expect(search).toBeVisible({ timeout: 60_000 });
        await search.fill(PRODUCT_NAME);
        await search.press('Enter');
        const row = page.locator('tr.data-row', { hasText: PRODUCT_NAME }).first();
        await expect(row).toBeVisible({ timeout: 60_000 });
        const edit = await row
            .locator('a[href*="/catalog/product/edit/"]')
            .first()
            .getAttribute('href');
        if (!edit) throw new Error(`no edit link for ${PRODUCT_NAME}`);
        // What can still be sold is the quantity less the reservations of
        // orders not yet shipped, which the grid shows per stock. Without that
        // column (no multi-source inventory) the quantity is all there is.
        const salableText = (await row.innerText()).match(/Default Stock:\s*(-?[\d.]+)/);
        const salable = salableText ? Number(salableText[1]) : null;
        await page.goto(edit, { waitUntil: 'domcontentloaded' });

        const qty = page.locator(QTY);
        // Only a single-source store has this field; with several sources the
        // quantity lives per source and this step cannot set it.
        await expect(qty, 'the product form has a single quantity field').toBeVisible({
            timeout: 60_000
        });
        const current = Number(await qty.inputValue());
        const inStock = (await page.locator(IN_STOCK).inputValue()) === '1';
        console.log(
            `${PRODUCT_NAME}: quantity ${current}, salable ${salable ?? 'not shown'}, ` +
                `${inStock ? 'in' : 'out of'} stock`
        );
        if ((salable ?? current) >= LOW && inStock) return;

        const target = String(current + TOP_UP);
        await qty.fill(target);
        await page.locator(IN_STOCK).selectOption('1');
        await page.locator('#save-button').click();
        await expect(page.locator('.message-success')).toContainText(/saved the product/i, {
            timeout: 60_000
        });
        console.log(`${PRODUCT_NAME}: quantity set to ${target}, in stock`);
    } catch (error) {
        // Evidence from the page as it failed, before the logout replaces it.
        await page
            .screenshot({ path: setup.info().outputPath('before-logout.png'), fullPage: true })
            .catch(() => {});
        const shown = await page
            .locator('.message-error, .admin__field-error, label.mage-error, .message-warning')
            .filter({ visible: true })
            .allInnerTexts()
            .catch(() => []);
        console.log(
            `stock step failed on ${page.url().replace(/\/key\/[^/]+/, '/key/…')}: ${shown.join(' | ') || '(no message shown)'}`
        );
        throw error;
    } finally {
        await adminLogout(page);
    }
});
