import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { FullConfig } from '@playwright/test';

const REACHABLE_TIMEOUT_MS = 5 * 60_000; // > the ~3min in-place static redeploy a plugin merge triggers
const POLL_INTERVAL_MS = 10_000;

// The one plugin asset that is both deployed as static content and versioned in
// the repo, so its digest identifies which ref the store is serving.
const ASSET = 'Two_Gateway/css/style.css';
const REPO_ASSET = '../view/frontend/web/css/style.css';

function sha256(body: Buffer | string): string {
    return createHash('sha256').update(body).digest('hex');
}

// `page.goto` resolves on a 500, so a mid-redeploy run would otherwise surface as
// an assertion failure against missing markup and read as a plugin defect.
async function waitReachable(baseURL: string): Promise<void> {
    const deadline = Date.now() + REACHABLE_TIMEOUT_MS;
    let last = 'no response';
    while (Date.now() < deadline) {
        try {
            const res = await fetch(baseURL, { redirect: 'follow' });
            if (res.ok) {
                return;
            }
            last = `HTTP ${res.status}`;
        } catch (err) {
            last = err instanceof Error ? err.message : String(err);
        }
        await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    }
    throw new Error(
        `e2e readiness: ${baseURL} never returned 200 within ${REACHABLE_TIMEOUT_MS / 60_000} minutes (last: ${last}). ` +
            `The store is redeploying or unreachable — this is not a plugin defect. Re-run once it settles.`
    );
}

// Derive the static prefix from a stylesheet the store itself emits, so the
// theme, locale and version segments come from the live deployment rather than
// being guessed.
function staticPrefix(html: string, baseURL: string): string {
    const m = html.match(/\/static\/version\d+\/frontend\/[^/"']+\/[^/"']+\/[^/"']+\//);
    if (!m) {
        throw new Error(
            `e2e readiness: could not find a /static/version.../frontend/<vendor>/<theme>/<locale>/ path in ${baseURL}. ` +
                `The page did not render its stylesheets — the store is not serving a usable storefront.`
        );
    }
    return m[0];
}

async function assertServingCheckout(baseURL: string): Promise<void> {
    const html = await (await fetch(baseURL, { redirect: 'follow' })).text();
    const url = new URL(staticPrefix(html, baseURL) + ASSET, baseURL).toString();

    const res = await fetch(url);
    if (!res.ok) {
        throw new Error(
            `e2e readiness: ${url} returned HTTP ${res.status}. The plugin's static content is not deployed at the ` +
                `path the store advertises, so the suite cannot confirm which ref is live.`
        );
    }

    const served = sha256(Buffer.from(await res.arrayBuffer()));
    const local = sha256(readFileSync(join(__dirname, REPO_ASSET)));
    if (served !== local) {
        throw new Error(
            `e2e readiness: ${baseURL} is not serving the checked-out branch.\n` +
                `  served ${ASSET}: ${served}\n  local  ${REPO_ASSET}: ${local}\n  url: ${url}\n` +
                `Specs would be asserting this branch's expectations against someone else's deployed code. ` +
                `Point STORE_URL at the store that git-syncs this branch, or wait for its deployment to catch up.`
        );
    }
}

export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0]?.use?.baseURL;
    if (!baseURL) {
        throw new Error('e2e readiness: no baseURL configured');
    }
    await waitReachable(baseURL);
    await assertServingCheckout(baseURL);
    console.log(`e2e readiness: ${baseURL} is up and serving the checked-out branch`);
}
