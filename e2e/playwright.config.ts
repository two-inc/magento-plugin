import { defineConfig } from '@playwright/test';
import { STORE_URL } from './tests/fixtures';
export default defineConfig({
    testDir: './tests',
    // Refuses to run the suite against a store that is mid-redeploy or serving a
    // different ref — both produce failures that read as plugin defects.
    globalSetup: './global-setup.ts',
    timeout: 120_000,
    workers: 1,
    reporter: [['list']],
    use: {
        // The dev store git-syncs `staging`; the staging store runs `main`, so a
        // spec written against unreleased markup can only go red there.
        baseURL: STORE_URL,
        actionTimeout: 8_000, // cap every action so an unactionable element can't hang the whole test
        headless: true,
        // No trace: this repo is public and a trace would hold the token and admin password.
        screenshot: 'only-on-failure',
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 2,
        ignoreHTTPSErrors: true,
        userAgent:
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
    },
    projects: [{ name: 'chromium' }]
});
