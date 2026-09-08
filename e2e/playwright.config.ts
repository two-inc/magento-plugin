import { defineConfig } from '@playwright/test';
export default defineConfig({
    testDir: './tests',
    timeout: 120_000,
    workers: 1,
    reporter: [['list']],
    use: {
        // The dev store git-syncs `staging`; the staging store runs `main`, so a
        // spec written against unreleased markup can only go red there.
        baseURL: process.env.STORE_URL || 'https://magento-dev.staging.two.inc',
        actionTimeout: 8_000, // cap every action so an unactionable element can't hang the whole test
        headless: true,
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 2,
        ignoreHTTPSErrors: true,
        userAgent:
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
    },
    projects: [{ name: 'chromium' }]
});
