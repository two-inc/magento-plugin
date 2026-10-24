# Magento Plugin (Two_Gateway)

Two's Magento 2 BNPL payment plugin. Brand-aware single-module
extension; brand-specific identity values resolve through
`Two\Gateway\Api\BrandRegistryInterface`. The default DI
binding in `etc/di.xml` resolves to
`Two\Gateway\Brand\DescriptorBackedBrandRegistry`.
Building a partner overlay or adding a brand-driven field:
see docs/brand-overlay-guide.md.

Standard Magento dev workflow: composer install, bin/magento
setup:di:compile, setup:upgrade, cache:flush. PHPUnit under Test/.

This is a **public repository**. Do not commit session-specific
content such as plans, transcripts, or implementation notes. In code
comments, commit messages and PR bodies alike, cite a Linear ticket id
and nothing else: a section, question or ruling number belonging to an
internal review document means nothing to a reader outside the company,
and neither does a person named as the authority for a rule.

## Branching & releases

-   **Day-to-day PRs target `staging`** (the GitHub default); branch off
    `origin/staging` — `version-bump.yml` decides the release version on PRs
    landing there.
    `auto-pr.yml` opens the staging → main promotion PR on every push to
    `staging`; `main` is prod. `merge-back.yml` syncs `main → staging`
    after merges (ff-only, else a sync PR). There is no `develop` branch.
-   **Releases are automated** — `release.yml` runs on CI success on
    `main` and does not compute a version: it reads the version committed
    by `version-bump.yml` on the PR that landed on `staging` (bump level
    from that PR's own conventional-commit types: `feat!:` → major,
    `feat:` → minor, else patch), tags it, and creates the GitHub Release.
    Don't hand-run bumpver.
-   `bumpver.toml` `current_version` MUST equal the version strings in the
    files it patches (`composer.json` `"version"`, `etc/config.xml`
    `<version>`) or every release dies at the bump step with "No match for
    pattern". Both fields are functional (rendered by the adminhtml Version
    field) — keep them.
-   **Re-cutting an exact version after deleting its tag:** deleting the
    tag + Release is not enough — reset `current_version` to the highest
    surviving semver tag first, or the next release overshoots (prev-tag +
    accumulated commits can bump past the intended version). Deleting +
    recreating a tag that has a Release demotes it to a draft — repair with
    `gh release delete` + `gh release create --verify-tag`.
-   Packagist syncs off this repo's webhook. If a tag doesn't appear on
    Packagist, check `gh api repos/two-inc/magento-plugin/hooks`
    `last_response.code` — 403 means stale Packagist-side authorization for
    the package (fix on Packagist, not GitHub); redeliver the hook to
    confirm.

## Which shop tracks `staging`

**`magento-dev.staging.two.inc` is the shared shop that serves this branch**, its
deployment carrying a `git-sync-gateway` container; each brand's own dev shop
git-syncs this repo's `staging` too, alongside that brand's overlay.
`magento.staging.two.inc` has no git-sync container at all and serves the deployed
image's code, which tracks `main`.

**Anything that verifies `staging` code targets the dev shop** — e2e, a manual
click-through, a screenshot. Point it at the other shop and it silently reports
on `main`: the run stays green for as long as the two branches happen to agree
and turns red, at the first specification that moved, against a storefront still
serving the widget the branch deleted. Confirm which code a shop has
from the git-sync container's checked-out HEAD; `pub/static/deployed_version.txt`
answers with an HTML 404 page on these shops and settles nothing.

A merge to `staging` triggers an in-place static redeploy on the dev shop and
the storefront 500s for roughly three minutes, so a suite that starts mid-sync
fails for environmental reasons. Warn testers before merging.

## Task docs

Detail needed only for one kind of task lives under `docs/agents/`. Read the matching doc before starting that task:

Every "read before" line below applies equally when reviewing a PR that touches that area.

-   [docs/agents/local-dev.md](docs/agents/local-dev.md): read before setting up or debugging the local `make install` shop, changing `docker-compose.yml` or the Makefile's install and clean targets, or answering why PageBuilder, CMS or analytics content does not render locally.
-   [docs/agents/surcharge.md](docs/agents/surcharge.md): read before changing surcharge pricing or caps (`SurchargeCalculator`, `SurchargeCapProvider`, `isSurchargeResolvable()`), the surcharge grid field, its JS or backend model (`SurchargeGrid`), the per-term surcharge config paths or their read path (`Repository::getSurchargeConfig()`), the merchant record's surcharge limit fields, or any monetary amount in the pricing request.
-   [docs/agents/admin-settings.md](docs/agents/admin-settings.md): read before adding or changing any admin setting (a `system.xml` or `brand_form_template.xml` field, its source, backend or frontend model, brand form synthesis, or the config repository read path), how availability gates or totals collectors handle a bad setting, payment-term options or the default term (`getDefaultPaymentTerm()`, `Two_Gateway/js/default-term`), the custom-header table or the headers the API adapter sends (`Service\Api\Adapter`), the API key field or its verification (`ApiKeyStatus`), the merchant record, its cache, cron refresh or admin scope (`Model/Config/AdminScope`), the admin health checklist, the module's cache types, or the admin fee column.
-   [docs/agents/payment-availability.md](docs/agents/payment-availability.md): read before changing `isAvailable()` or any check that can withhold the payment method (api-key verdict, surcharge FX and resolvability, an unreadable setting or tax-code map, the fee quote, the FX rate table and its refresh, minimum order), the charged-term resolver or the surcharge quote timeout, the admin fee preview (`FeeRatesProvider`), the buyer-facing surfaces that ask the same question (`ConfigProvider`, `OrderIntent::place()`, `CompanyLookup`) or the merchant identity they publish (`SettingsProvider`), or debugging a missing payment method.
-   [docs/agents/company-capture.md](docs/agents/company-capture.md): read before editing `company-search-panel.js`, its vendored-copy test or anything that hosts it (the company field, its chips, the checkout config that mounts it, any checkout variant including Hyvä), the sole-trader signup popup or focus handling around it, the company lookup relay and its region resolution (`CompanyLookup`, `IsoRegionResolver`, `RegionDirectory`), or how either checkout fills the address region and city from a lookup.
-   [docs/agents/checkout-payment-tile.md](docs/agents/checkout-payment-tile.md): read before changing the checkout payment renderer, its template, CSS or translations (Place Order gating, the decline notice and its brand switch `intent_declined_notice_enabled`, live regions, ARIA labelling, the method radio or consent checkbox), the term chips and their text (`isEndOfMonthTerms` from `ConfigProvider`), or term selection and its totals refresh (`/select-term`, `TermSelection.php`, the surcharge model, the surcharge collector's session term).
-   [docs/agents/order-intent-refusals.md](docs/agents/order-intent-refusals.md): read before changing order intent or which company an order is sent with (`applyCompanyData()`, the billing/shipping company source resolver), any buyer-facing message built from a Two API refusal (`getFieldErrorLabels()`, `TwoRefusalException`, order create's refusal handling), or the confirm, cancel and failed-verification return controllers (`OrderService::failBuyerReturn()`).
-   [docs/agents/frontend-testing.md](docs/agents/frontend-testing.md): read before writing or reviewing JS (jsdom) tests or their fixtures, using a mutation to prove a test's coverage, or checking popup or keyboard behaviour in a browser.
-   [docs/agents/order-composition-and-tax.md](docs/agents/order-composition-and-tax.md): read before changing how order, edit, capture, fulfilment or refund payloads are composed (`Service\Order`, `Compose*`), line tax rates or the shipping tax fallback, the placement records on `sales_order` (`two_shipping_tax_rate*`, `two_tax_codes`), the postprocessing hook, its subscribers or shop-match checks, tenders and prepaid amounts (`OrderTenders`, `PrepaidLedger`), the other-charges residual, the buyer VAT number, or tax codes, their admin section, JS, backend model, data patch or API client (`Service\Api\TaxCodes`).
-   [docs/agents/fulfilment-and-refunds.md](docs/agents/fulfilment-and-refunds.md): read before changing invoicing (online or offline, `canCapture()`, the invoice observers and plugins), fulfilment on shipment or status change (`SalesOrderSaveAfter`, `StatusFulfilment`), the shipment and refund payload builders (`ComposeShipment`, `ComposeRefund`), credit memos and their totals collectors (including the surcharge one), the credit-memo fee override field, fee line providers, or the other-charges residual (`OtherChargesResolver`).

## The Environment setting matches the other plugins

`dev/environment-parity.php` (TWO-26613), run by the "Environment parity"
workflow on every PR, every push to `staging` and `main`, and nightly, checks
that this plugin, two-inc/prestashop-plugin and two-inc/woocommerce-plugin offer
the same Environment setting: the stored values and their order, the
fresh-install default and the install step that writes it, the dropdown with and
without `TWO_INTERNAL_ENVIRONMENTS=1`, how each test input reads, and the field
label, description and option labels. Each plugin's legacy-value migration must
land on a stored value and be idempotent, and may rewrite a canonical value only
where its `dev/environment-contract.php` lists that value under the variant's
`moves` (a registered move); what each one maps is printed, not compared,
because the platforms stored different values historically.

The script extracts this plugin's contract from its own code, as
`dev/environment-contract.php` describes, into the committed
`dev/environment-contract.json`, and fails if the committed copy is stale: after
changing the setting, its declarations or that file, run
`php dev/environment-parity.php --write` and commit the JSON. The siblings' JSON
is fetched with `gh api` and compared as data; no sibling code runs.
`environment-cases.json` and the script itself must be byte-identical in all
three, so change them in all three, in step.

A PR reads each sibling at the PR's branch name when the sibling has that
branch, and then a missing snapshot fails: push a paired change under the same
branch name in all three repos. Otherwise it reads the sibling's `staging`, as
does a push to `staging` and the nightly run, while a push to `main` reads the
siblings' `main`; there a sibling with no snapshot yet is a warning, and one
that differs fails. Locally, run `php dev/environment-parity.php` with
`ENVIRONMENT_PARITY_SIBLINGS_DIR=<dir>` to compare with checkouts named after
their repos in `<dir>`.

## A NON-EXECUTABLE guard is invoked through `bash`

A script whose mode is `100644` and which is run as `./script.sh` exits 126. On a
CI dashboard that is indistinguishable from a check that ran and failed, so the
guard's own absence reads as its verdict. A guard committed executable runs
directly; anything else is invoked `bash script.sh`, and every guard prints what
it checked.

## An optional constructor argument is NOT autowired

A constructor parameter with a default of `null` is left at its default by
the object manager — it is never resolved from its type hint. Adding a
dependency that way and relying on DI to fill it in gets you a silent
`null` at runtime while every unit test (constructor skipped) still passes.
`bin/magento dev:di:info <class>` reports it as `"_vn_": "string 1"`
(value null) instead of `"_i_"` (instance); that is the check.

`Service\Order::$orderTaxManagement`, `Service\Order::$feeLineProviderPool`,
`Service\Order::$taxCodeResolver`, `Service\Order::$creditmemoCollectionFactory`,
`Service\Order::$tenderReader` and `Service\Order::$prepaidLedger` are all declared optional for constructor BC and all named explicitly in
`etc/di.xml` on the abstract parent, which all four `Compose*` subclasses
inherit.

## DI registration scope for Structure / Config Reader plugins

**Plugins that target `Magento\Config\Model\Config\Structure\Reader`
(or any class whose output gets cached under an area-specific cache
key like `adminhtml::backend_system_configuration_structure`) MUST
be registered in `etc/di.xml` (global), NOT `etc/adminhtml/di.xml`.**

Reason: CLI invocations of `bin/magento` (`config:set`,
`app:config:import`, `deploy:mode:set`, `admin:user:create`, etc.)
populate the adminhtml-scoped Structure cache but bootstrap with
the CLI process's DI graph — which loads `etc/di.xml` +
`etc/crontab/di.xml` and does **NOT** load `etc/adminhtml/di.xml`.
Plugins registered only under adminhtml therefore never fire for
CLI-driven cache writes; the cache lands incomplete, and subsequent
admin web requests read the broken cached Structure from
`Scoped::_loadScopedData`.

This is exactly how the admin-tab-vanishes-after-pod-restart bug
happened — `SynthesiseBrandAdminForm` was originally
registered under adminhtml; every CLI command in the init/setup
hooks repopulated the cache without invoking synthesis.

If your plugin's `afterRead` body only mutates the adminhtml shape,
firing in other areas is harmless (wasted parse on a payload no
consumer reads). The cost of registering globally is essentially
zero; the cost of getting this wrong is a recurring restart-time
production bug that masks itself behind cache-flush workarounds.

The inverse trap applies to `etc/crontab/di.xml`: a plugin registered
ONLY there fires in CLI processes (cron, indexer) but NOT in HTTP
requests. If you find yourself reaching for crontab-scope DI, ask
whether the symmetric case (HTTP request misses the plugin) would
break correctness — almost always yes; register globally instead.

## A table prefix is not supported, and only one route applies it

**The module is not verified against an installation configured with a
database table prefix, and its direct `core_config_data` queries do not
survive one**. The gap is tolerated because no merchant has reported
it; closing it is an audit of every raw query plus a prefixed-install test run,
a project of its own. Nothing guards it, so a new query inherits the gap
silently.

Two methods share the name `getTableName()`. The injected `ResourceConnection`'s
own method prepends the configured prefix, and is the one to call. The database
adapter's method, reached through `->getConnection()`, only shortens an
over-long identifier and prepends nothing — which is the form in use, and is
why a prefixed install addresses a table that does not exist while every
unprefixed one behaves correctly.
