# Magento Plugin (Two Gateway)

## Project Overview

Two's Magento 2 payment plugin, providing BNPL (Buy Now Pay Later) checkout integration for Magento stores.

-   **Language**: PHP 7.4+
-   **Framework**: Magento 2 module
-   **Purpose**: Payment gateway integration for Two BNPL service

## Directory Structure

```
etc/                  # Module configuration (module.xml, di.xml, system.xml)
Model/                # Business logic and data models
Controller/           # Controllers for routes
Block/                # View layer blocks
view/                 # Frontend/adminhtml templates and layouts
Observer/             # Event observers
Plugin/               # Plugins (interceptors)
Setup/                # Installation/upgrade scripts
i18n/                 # Translations
```

## Git Workflow

-   Use `SKIP=commit-msg` when committing on `main` branch (no Linear ticket needed)
-   Do NOT skip commit-msg hook on feature branches
-   Never use `--no-verify` flag

## Version Management

Version bumps are done using `bumpver`:

```bash
SKIP=commit-msg bumpver update --patch  # or --minor, --major
git push origin main --tags
```

## Translations

-   Translation files: `nb_NO.csv`, `nl_NL.csv`, `sv_SE.csv`
-   No `en_US.csv` needed - Magento falls back to source strings for English

## Admin Panel Configuration

-   Most config fields should have `canRestore="1"` to allow website/store scope inheritance
-   Sensitive fields (mode, api_key, debug) should NOT have `canRestore` - they must be explicitly set
-   Button-type fields (version, api_key_check, etc.) don't need `canRestore`
-   Use `translate="label comment"` when field has both label and comment to translate

### Config Paths

All payment config is stored under `payment/two_payment/`:

-   `payment/two_payment/active` - Enable/disable
-   `payment/two_payment/mode` - Environment (sandbox/staging/production)
-   `payment/two_payment/api_key` - API key (encrypted)
-   `payment/two_payment/debug` - Debug mode

### Setting Config via CLI

```bash
bin/magento config:set payment/two_payment/mode sandbox
bin/magento config:set payment/two_payment/active 1
bin/magento cache:flush config
```

## Development Tips

### Running Commands

Most Magento CLI commands should be run as the web server user to avoid permission issues:

```bash
su www-data -s /bin/bash -c "bin/magento <command>"
```

### Cache Clearing

After making changes, clear caches in this order:

```bash
# 1. Clear generated code (if PHP classes changed)
rm -rf generated/code/Two

# 2. Recompile DI (if new classes/interceptors)
bin/magento setup:di:compile

# 3. Deploy admin static content (if admin templates/CSS changed)
rm -rf pub/static/adminhtml/* var/view_preprocessed/pub/static/adminhtml/*
bin/magento setup:static-content:deploy -f --area=adminhtml

# 4. Flush all caches
bin/magento cache:flush

# 5. Clear PHP opcache (if opcache.validate_timestamps=0)
# Create pub/opcache-clear.php or restart PHP-FPM
```

## Session Artifacts

This is a **public repository**. Do not commit session-specific content such as:
-   Session summaries or transcripts
-   Implementation plans or review notes
-   Any file under `docs/` that contains conversation context

Use agent memory (e.g. `~/.claude/projects/` or equivalent) for session persistence instead. Plans can be saved locally and stashed but must not be committed.

## Per-package release process (ABN-392)

This source tree produces **two distributable Composer packages** from
a single codebase:

| Package | Magento module(s) | Brand binding |
|---|---|---|
| `two-inc/magento2` | `Two_Gateway` only | `Two\Gateway\Brand\TwoBrand` (default in `etc/di.xml`) |
| `two-inc/magento-abn-plugin` | `Two_Gateway` + `ABN_Gateway` (the second is a skinny override module) | `Two\Gateway\Brand\AbnBrand` (`ABN_Gateway/etc/di.xml` overrides the preference) |

The brand seam is `Two\Gateway\Api\BrandRegistryInterface`.

### Source-tree layout

- Top-level (`Api/`, `Block/`, `Controller/`, `Model/`, … `composer.json`,
  `etc/di.xml`, etc.) is the **`Two_Gateway`** module: all runtime PHP,
  default DI binding (`BrandRegistryInterface → TwoBrand`), Two-flavoured
  i18n, Two CSP allowlist, neutral CSS that does not reference brand
  shields.
- `ABN_Gateway/` is a **skinny override module** containing no PHP code:
  - `etc/di.xml` — re-binds `BrandRegistryInterface → AbnBrand`
  - `etc/csp_whitelist.xml` — adds `*.achterafbetalen.abnamro.nl` hosts
    (additive — Magento merges all modules' CSP files)
  - `etc/module.xml` — declares dependency on `Two_Gateway` so it loads
    after, ensuring its DI preference and view-layer overrides win
  - `i18n/{nb_NO,nl_NL,sv_SE}.csv` — ABN-flavoured translation overrides
    (e.g. "ABN AMRO Zakelijk op Rekening")
  - `view/{frontend,adminhtml}/web/images/abn-shield.svg` — ABN
    brand asset (referenced by ABN_Gateway's CSS overrides)
  - `view/{frontend,adminhtml}/web/css/...` — adds the abn-shield image
    to the `.two-payment-shield` and `.two-extension .title:before`
    selectors that `Two_Gateway` ships intentionally bare

### Building each package

The release pipeline produces each package by **directory inclusion**
rather than file substitution:

- **`two-inc/magento2`** zip: includes the top-level tree, **excludes**
  `ABN_Gateway/`. Top-level `composer.json` declares
  `name: two-inc/magento2`.
- **`two-inc/magento-abn-plugin`** zip: includes only `ABN_Gateway/`
  contents at the package root. `ABN_Gateway/composer.json` declares
  `name: two-inc/magento-abn-plugin` and `require: two-inc/magento2`.

ABN merchants install both packages via Composer; the dependency
graph ensures Two_Gateway is present, and ABN_Gateway's overrides
take effect via Magento's module load order.

### Migrations on upgrade

Existing ABN merchants upgrading from the legacy abn_* namespace pick
up three Setup/Patch/Data classes on the next `bin/magento setup:upgrade`:

- `MigrateAbnConfigPaths` — rewrites `payment/abn_payment/*` paths in
  `core_config_data` to `payment/two_payment/*`.
- `MigrateAbnOrderStatuses` — renames status codes `abn_new` →
  `two_new`, `abn_failed` → `two_failed`, `pending_abn_payment` →
  `pending_two_payment` across `sales_order_status`,
  `sales_order_status_state`, `sales_order`, `sales_order_grid`, and
  `sales_order_status_history`.
- `MigrateAbnPaymentMethod` — renames the payment method code
  `abn_payment` → `two_payment` in `sales_order_payment` and
  `quote_payment`.

All three are idempotent (Magento tracks applied patches by class
name) and Two-install safe (no-op on installs that were never on
the abn_* namespace).

### Open follow-ups

- **CI release pipeline**: `.github/workflows/release.yml` still
  references the legacy abn-* tag-prefix logic. Needs reworking to
  produce both packages from the same source tree (likely two parallel
  jobs: one zips Two_Gateway only, the other zips ABN_Gateway only).
- **PHPUnit suite**: existing tests under `Test/` predate ABN-392 and
  were authored against upstream's SurchargeCalculator + Repository
  shapes. Multiple breaks:
    1. `Test/Unit/Service/Order/SurchargeCalculatorTest.php` constructs
       `SurchargeCalculator` with `CurrencyFactory`; the imported ABN
       implementation takes `CurrencyRatesProviderInterface` instead.
       Wholesale rewrite needed.
    2. `Test/Unit/Model/Config/RepositoryPaymentTermsTest.php` and
       `Test/Unit/Model/Config/RepositoryUrlTest.php` construct
       `Repository` without the new `BrandRegistryInterface` arg.
    3. `Test/Unit/Model/TwoErrorHandlingTest.php` and others may
       reference `::PRODUCT_NAME` etc. on the old static const path.
  Suite needs review against the ABN-imported runtime before CI can
  go green. Cannot be done blind — needs PHP local + a sandboxed
  iteration cycle. Logged as a follow-up under ABN-392 (or a
  dedicated subtask).
- **GDPR consent paragraph**: lives in `ABN_Gateway/i18n/nl_NL.csv`
  as part of the wholesale ABN translation override. Already brand-
  conditional via the dual-module structure — Two_Gateway ships the
  generic copy, ABN_Gateway overrides it.

### Common Issues

1. **Template not found error**: Run `setup:di:compile` and clear opcache
2. **Stale worktree paths in errors**: Delete `generated/code/Two` and recompile DI
3. **Admin CSS/logo missing**: Redeploy admin static content
4. **Permission denied on var/cache**: Fix ownership with `chown -R www-data:www-data var/ generated/`
5. **Config changes not appearing**: Flush config cache and clear opcache
