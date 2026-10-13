import { test as base, type BrowserContext } from '@playwright/test';

export * from '@playwright/test';

export const STORE_URL = process.env.STORE_URL || 'https://magento-dev.staging.two.inc';

// The staging stores sit behind oauth2-proxy (PLAT-2550). CI passes a Google ID
// token; send it to the store host only, never to api.staging or third parties.
// STORE_URL is caller-controlled on workflow_dispatch, so only our staging hosts get the token.
const TOKEN_HOST = /\.staging\.two\.inc$/;

export function storeAuthHeaders(url: string): Record<string, string> {
    const token = process.env.STORE_ID_TOKEN;
    const host = new URL(url).host;
    return token && host === new URL(STORE_URL).host && TOKEN_HOST.test(host)
        ? { authorization: `Bearer ${token}` }
        : {};
}

function isGatedStoreUrl(url: string): boolean {
    const { host, pathname } = new URL(url);
    // The proxy leaves GET /static/ and /media/ open, so those need no token.
    return (
        host === new URL(STORE_URL).host &&
        TOKEN_HOST.test(host) &&
        !/^\/(static|media)\//.test(pathname)
    );
}

export async function authoriseStore(context: BrowserContext): Promise<void> {
    const token = process.env.STORE_ID_TOKEN;
    if (!token) return;
    await context.route(
        (url) => isGatedStoreUrl(url.toString()),
        async (route) => {
            try {
                // Redirects are handled here, never by Playwright: continue({ headers }) carries the
                // header onto every hop (including other origins), and a fulfilled 3xx's next hop is
                // not routed again (playwright#34994), so it would lose the token on the store.
                const request = route.request();
                const headers = { ...request.headers(), authorization: `Bearer ${token}` };
                let url = request.url();
                // Not the 8s actionTimeout: order-intent calls the Two API from the server.
                let response = await route.fetch({
                    url,
                    headers,
                    maxRedirects: 0,
                    timeout: 60_000
                });
                for (let hop = 0; hop < 10; hop++) {
                    const location = response.headers()['location'];
                    if (response.status() < 300 || response.status() >= 400 || !location) break;
                    const next = new URL(location, url).toString();
                    if (!isGatedStoreUrl(next)) break; // the browser follows it without the token
                    if (request.isNavigationRequest()) {
                        // Navigate from a script so the next hop is routed (and gets the token).
                        await route.fulfill({
                            contentType: 'text/html',
                            body: `<script>location.replace(${JSON.stringify(next)})</script>`
                        });
                        return;
                    }
                    url = next;
                    response = await route.fetch({
                        url,
                        headers,
                        maxRedirects: 0,
                        timeout: 60_000
                    });
                }
                await route.fulfill({ response });
            } catch {
                // A failed fetch's error carries the request headers (token, session
                // cookies) into the report, and this repo's CI logs are public.
                await route.abort().catch(() => {});
            }
        }
    );
}

export const test = base.extend({
    context: async ({ context }, use) => {
        await authoriseStore(context);
        await use(context);
    }
});
