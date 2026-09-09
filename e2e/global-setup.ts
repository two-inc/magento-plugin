import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { FullConfig } from '@playwright/test';

const READY_TIMEOUT_MS = 5 * 60_000; // > the ~3min in-place static redeploy a plugin merge triggers
const POLL_INTERVAL_MS = 10_000;

// The one plugin asset that is both deployed as static content and versioned in
// the repo, so its digest identifies which ref the store is serving. It only
// moves when the stylesheet does, so a branch whose only changes are PHP, JS or
// templates matches a store on a different ref — the guard catches a store on a
// different plugin release, not every possible divergence.
const ASSET = 'Two_Gateway/css/style.css';
const REPO_ASSET = '../view/frontend/web/css/style.css';

type Probe =
    | { ready: true }
    | { ready: false; kind: 'unreachable' | 'no-storefront' | 'wrong-ref'; detail: string };

function sha256(body: Buffer | string): string {
    return createHash('sha256').update(body).digest('hex');
}

// Derive the static prefix from a stylesheet the store itself emits, so the
// theme, locale and version segments come from the live deployment rather than
// being guessed.
function staticPrefix(html: string): string | null {
    const m = html.match(/\/static\/version\d+\/frontend\/[^/"']+\/[^/"']+\/[^/"']+\//);
    return m ? m[0] : null;
}

async function probe(baseURL: string, local: string): Promise<Probe> {
    let res: Response;
    try {
        // `page.goto` resolves on a 500, so without this a mid-redeploy run
        // surfaces as an assertion failure and reads as a plugin defect.
        res = await fetch(baseURL, { redirect: 'follow' });
    } catch (err) {
        return {
            ready: false,
            kind: 'unreachable',
            detail: err instanceof Error ? err.message : String(err)
        };
    }
    if (!res.ok) {
        return { ready: false, kind: 'unreachable', detail: `HTTP ${res.status}` };
    }

    const prefix = staticPrefix(await res.text());
    if (!prefix) {
        return {
            ready: false,
            kind: 'no-storefront',
            detail: 'no /static/version.../frontend/ path in the page'
        };
    }

    const url = new URL(prefix + ASSET, baseURL).toString();
    const asset = await fetch(url);
    if (!asset.ok) {
        return { ready: false, kind: 'no-storefront', detail: `HTTP ${asset.status} from ${url}` };
    }

    const served = sha256(Buffer.from(await asset.arrayBuffer()));
    if (served !== local) {
        return {
            ready: false,
            kind: 'wrong-ref',
            detail: `served ${ASSET}: ${served}\n  local  ${REPO_ASSET}: ${local}\n  url: ${url}`
        };
    }
    return { ready: true };
}

function readinessError(baseURL: string, last: Probe & { ready: false }): Error {
    const minutes = READY_TIMEOUT_MS / 60_000;
    if (last.kind === 'wrong-ref') {
        return new Error(
            `e2e readiness: ${baseURL} is not serving the checked-out branch.\n  ${last.detail}\n` +
                `Specs would be asserting this branch's expectations against someone else's deployed code. ` +
                `Point STORE_URL at the store that git-syncs this branch, or wait for its deployment to catch up.`
        );
    }
    if (last.kind === 'no-storefront') {
        return new Error(
            `e2e readiness: ${baseURL} did not serve a usable storefront within ${minutes} minutes ` +
                `(last: ${last.detail}). Static content is not deployed at the path the store advertises.`
        );
    }
    return new Error(
        `e2e readiness: ${baseURL} never returned 200 within ${minutes} minutes (last: ${last.detail}). ` +
            `The store is redeploying or unreachable — this is not a plugin defect. Re-run once it settles.`
    );
}

export default async function globalSetup(config: FullConfig): Promise<void> {
    const baseURL = config.projects[0]?.use?.baseURL;
    if (!baseURL) {
        throw new Error('e2e readiness: no baseURL configured');
    }

    // One deadline for both legs: a redeploy hands out a transient 200 on the
    // old static version, so a digest mismatch straight after a merge is a
    // not-settled-yet signal, not a wrong-store verdict.
    const local = sha256(readFileSync(join(__dirname, REPO_ASSET)));
    const deadline = Date.now() + READY_TIMEOUT_MS;
    let last: Probe & { ready: false } = {
        ready: false,
        kind: 'unreachable',
        detail: 'no response'
    };
    for (;;) {
        const result = await probe(baseURL, local);
        if (result.ready) {
            console.log(`e2e readiness: ${baseURL} is up and serving the checked-out branch`);
            return;
        }
        last = result;
        if (Date.now() >= deadline) {
            throw readinessError(baseURL, last);
        }
        await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    }
}
