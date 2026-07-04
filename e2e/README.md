# e2e

Playwright suite that drives the Two BNPL plugin on a staging store and captures
the screenshots used in the docs (`two-inc/docs` → `static/images/magento/`).

## Specs

- `admin-config.spec.ts` — logs into Magento admin and captures the Two config
  tabs (General / Payment / Search) plus the section overview. Needs `ADMIN_PASS`;
  skips cleanly without it.
- `checkout-luma.spec.ts` — drives the full storefront checkout: product → cart →
  company search → the Two "Buy Now Pay Later on Invoice Terms" method → payment
  term → **places an order**.

## Run locally

```bash
cd e2e
npm ci
npx playwright install chromium
# STORE_URL defaults to the staging store; ADMIN_PASS enables the admin specs.
ADMIN_PASS="<magento admin password>" npx playwright test
```

Screenshots land in `e2e/screenshots/`.

## Run on demand in CI

**Actions → playwright → Run workflow.** Screenshots upload as a build
artifact. The checkout journey runs without credentials; the admin-config specs
run only when `ADMIN_PASS` is provided.

## Notes

- Test buyer: a skip-verification company for the store's country (e.g.
  `RESTAURANT 53 LTD` for a GBP/GB store) so the order-intent auto-approves.
- Order placement depends on the sandbox risk decision, so a persistent decline
  is logged rather than failed; method visibility is the deterministic assertion.
