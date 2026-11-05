# e2e

Playwright suite that drives the Two BNPL plugin on the dev store and captures
the screenshots used in the docs (`two-inc/docs` → `static/images/magento/`).

## Specs

- `admin-config.spec.ts` — logs into Magento admin and captures the Two config
  tabs (General / Payment / Search) plus the section overview. Needs `ADMIN_PASS`;
  skips cleanly without it.
- `checkout-luma.spec.ts` — drives the full storefront checkout: product → cart →
  company search → the Two "Buy Now Pay Later on Invoice Terms" method → payment
  term → **places an order**.
- `pending-recovery.spec.ts`: places an order with the buyer's return to the
  shop blocked, then settles it with the admin's **Check status with Two**
  button and checks the late return changes nothing (TWO-26300). Needs
  `ADMIN_PASS`. Its scheduled-check test runs only with `E2E_PENDING_CRON=1`,
  set once the dev store runs the check every minute with a 2-minute threshold.
  On a pull request other than the promotion one, the button's absence is a
  skip, because the dev store serves `staging`.

Before any spec, `stock.setup.ts` makes sure the test product has stock: the
checkout specs all buy it, so the shared store's stock of it runs out. It first
cancels the e2e buyer's orders left waiting for the buyer for over an hour,
which releases the stock they reserve, then tops the quantity up through the
admin product form when fewer than 100 can still be sold. It skips without
`ADMIN_PASS`. `pending-recovery.spec.ts` also cancels any order it left
waiting for the buyer, after failures too, so none keeps stock reserved
(TWO-26300).

## Run locally

```bash
cd e2e
npm ci
npx playwright install chromium
# STORE_URL defaults to the dev store, which git-syncs `staging` and so runs the
# code the suite is checked out from; ADMIN_PASS enables the admin specs.
ADMIN_PASS="<magento admin password>" npx playwright test
```

Screenshots land in `e2e/screenshots/`.

`global-setup.ts` runs first and aborts the whole suite unless the store returns
200 and its served `Two_Gateway/css/style.css` matches the checked-out
`view/frontend/web/css/style.css` — a mismatch means the store is running a
different ref, so every assertion afterwards would be meaningless. The digest
only moves when that stylesheet does, so the check catches a store on a different
plugin release rather than every possible divergence.

The dev store rebuilds in place after every push to a branch it git-syncs, and
every page errors while it does. The rebuild starts minutes after the push, so
until then the store still answers 200 on its old deployment. In CI the workflow
therefore sets `E2E_DEPLOYED_AFTER` to the latest push to those branches, and the
gate also waits until the timestamp in the store's own `/static/version<N>/`
prefix is at least that recent, which means the rebuild has finished (TWO-27052).
The gate polls for up to fifteen minutes. Locally the variable is unset and that
check is skipped.

## Run on demand in CI

**Actions → playwright → Run workflow.** Screenshots upload as a build
artifact. The checkout journey runs without credentials; the admin-config specs
run only when `ADMIN_PASS` is provided.

## Notes

- Test buyer: a skip-verification company for the store's country (e.g.
  `RESTAURANT 53 LTD` for a GBP/GB store) so the order-intent auto-approves.
- Order placement depends on the sandbox risk decision, so a persistent decline
  is logged rather than failed; method visibility is the deterministic assertion.
