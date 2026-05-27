<p align="center">
  <img src="view/frontend/web/images/logo.svg" width="128" height="128"/>
</p>
<h1 align="center">Two — Magento 2 Payment Plugin</h1>

B2B Buy Now, Pay Later for Magento 2.3.3+. This plugin integrates [Two](https://www.two.inc/) as a payment method, letting merchants offer invoice-based checkout with flexible net terms.

## What it does

**For merchants:**

- Instant credit checks on business customers
- B2B guest checkout (up to 36% conversion uplift)
- Flexible invoice terms from 14 to 90 days
- Automatic invoicing via the [PEPPOL](https://peppol.eu/) e-invoicing network
- Partial capture and refunds
- Instant payment on fulfilment — Two assumes the credit risk

**For buyers:**

- Frictionless checkout with no onboarding
- Flexible repayment terms
- PDF and electronic invoicing straight to their ERP

## Installation

Install via Composer:

```bash
composer require two-inc/magento2
php bin/magento module:enable Two_Gateway
php bin/magento setup:upgrade
php bin/magento cache:flush
```

In production mode, also deploy static content:

```bash
php bin/magento setup:static-content:deploy
```

Then configure the plugin under **Stores > Configuration > Sales > Payment Methods > Two**.

### Post-install smoke test

Run this immediately after `setup:upgrade` to catch the
ABN-415-class regression surface — DI scope misregistration,
Structure cache poisoning, or a stale opcache holding the old
interceptor list — in one round-trip:

```bash
php bin/magento setup:di:compile
php bin/magento cache:flush
php bin/magento config:set payment/two_payment/title 'smoke test'
php bin/magento config:show payment/two_payment/title
```

The final command must print `smoke test` (and no error trace).
If it errors, doesn't round-trip the value, or admin Configuration
shows fewer Two/brand sections than expected, the cause is almost
certainly one of:

- A plugin you've added is registered only under `etc/adminhtml/di.xml`
  or `etc/crontab/di.xml` instead of `etc/di.xml`. See AGENTS.md
  for the DI-scope rule.
- An FPM worker holding stale opcache. Restart PHP-FPM
  (`systemctl reload php-fpm` or `kill -USR2 <fpm-master-pid>`).
- A cache type (config / layout / full_page) is in stale state.
  `bin/magento cache:flush` is the canonical fix.

The same sequence runs in CI on every PR (see `.github/workflows/ci.yml`'s
`di-compile` job), so regressions are caught upstream of merchant
installs.

## Development

The development environment runs Magento in Docker with the plugin bind-mounted, so file changes are reflected immediately.

### Prerequisites

- Docker
- Make
- A Two API key ([request sandbox access](https://www.two.inc/))

### Quick start

```bash
# Create the Magento container and install the plugin
make install

# Configure with your API key
make configure TWO_API_KEY=<your-key>

# Start / stop
make run
make stop
```

After install, Magento is available at http://localhost:1234/ (admin: http://localhost:1234/admin, credentials: `exampleuser` / `examplepassword123`).

To use a different port: `make install PORT=5678`.

By default, the plugin points at Two's staging environment for `@two.inc` gcloud accounts, or sandbox for everyone else. You can override the API and checkout URLs explicitly:

```bash
make install TWO_API_BASE_URL=http://localhost:8000 TWO_CHECKOUT_BASE_URL=http://localhost:3000
```

In production mode these are ignored — the URLs are derived from the `mode` setting in the admin panel (sandbox/staging/production).

Run `make help` to see all available targets.

### Local-dev perf — disabled modules and what breaks

`make install` runs `module:disable` on a fixed list of modules that aren't needed for plugin development but add significant load to `setup:di:compile` (every module's DI is re-generated) and to the storefront's RequireJS dependency graph (every enabled module's JS gets pulled into the boot, even on pages that don't use it). Disabling them cuts `setup:di:compile` time and drops storefront button-enable latency from ~10s to under a second on the sample-data catalog.

| Module(s) | Why it's disabled in dev |
|---|---|
| `Magento_AdminAdobeImsTwoFactorAuth`, `Magento_TwoFactorAuth` | TOTP setup required on every admin login — friction for local dev |
| `Magento_Analytics`, `Magento_AdminAnalytics`, `Magento_CatalogAnalytics`, `Magento_CustomerAnalytics`, `Magento_QuoteAnalytics`, `Magento_ReviewAnalytics`, `Magento_SalesAnalytics`, `Magento_WishlistAnalytics`, `Magento_GoogleAnalytics`, `Magento_GoogleOptimizer` | JS/tracking hooks that fire on every storefront load |
| `Magento_PageBuilder`, `Magento_PageBuilderAnalytics`, `Magento_CatalogPageBuilderAnalytics`, `Magento_CmsPageBuilderAnalytics`, `Magento_PageBuilderAdminAnalytics`, `Magento_AwsS3PageBuilder` | Loads the full PageBuilder ContentTypes JS tree on **every** storefront page — biggest single contributor to client-side boot time |

`Magento_NewRelicReporting` is **not** disabled — `Magento_GraphQl` declares a hard dependency on it, and disabling it cascades through every GraphQL module. It stays quiet at runtime when un-licensed.

**Consequence:** PageBuilder-driven CMS content (banners, slides, promo blocks edited via the visual editor) **will not render** in a `make install` environment. If you're testing brand content that relies on PageBuilder blocks, re-enable them manually inside the container:

```bash
docker exec magento php bin/magento module:enable Magento_PageBuilder Magento_PageBuilderAnalytics Magento_CatalogPageBuilderAnalytics Magento_CmsPageBuilderAnalytics Magento_PageBuilderAdminAnalytics Magento_AwsS3PageBuilder
docker exec magento php bin/magento setup:upgrade
docker exec magento php bin/magento setup:di:compile
docker exec magento php bin/magento cache:flush
```

Install also runs:

- `config:set dev/js/merge_files=1`, `dev/js/minify_files=1`, `dev/css/merge_css_files=1` — flatten the inline RequireJS bootstrap into a single merged bundle in the HTML.
- `setup:static-content:deploy --area frontend --theme Magento/luma --no-html-minify -f --jobs 4 en_US` — pre-bake the Luma theme so RequireJS's ~hundreds of runtime XHRs hit plain file IO instead of falling through Magento's `pub/static.php` router (a full Magento bootstrap per asset). Without this, on the sample catalog the storefront's "Add to Cart" button-enable latency is ~10s; with it, ~1s warm.

### Brand overlays

Brand-specific overlay packages (e.g. ABN AMRO's Zakelijk op Rekening) live in
their own Composer packages and are installed separately from this plugin, not
through `make install`. See `two-inc/magento-abn-plugin` for the ABN overlay.

### Debugging

Xdebug is installed automatically by `make install` but is disabled by default. To start in debug mode:

```bash
make debug
```

This activates Xdebug (port 9003) and disables all Magento caches for hot reload — PHP changes, templates, layout XML, and config changes are picked up on the next request without manual cache flushing. The only exception is DI wiring changes (new classes, plugins, or preferences in `di.xml`), which still require `make compile`.

**Setting breakpoints in VSCode:**

1. Install the [PHP Debug](https://marketplace.visualstudio.com/items?itemName=xdebug.php-debug) extension
2. Press **F5** to start listening (uses the included `.vscode/launch.json`)
3. Click the gutter next to any line in the plugin code to set a breakpoint
4. Browse to the Magento store — every request will trigger the debugger automatically

The debugger will pause at your breakpoint with full access to variables, call stack, and step-through execution.

### HTTPS proxy

For testing integrations that require HTTPS callbacks (e.g. the Two checkout flow), you can expose your local instance via an [FRP](https://github.com/fatedier/frp) reverse proxy.

**Setup (one-time):** install the FRP client (`frpc`):

- macOS: `brew install frpc`
- Linux: download from [GitHub releases](https://github.com/fatedier/frp/releases) and place `frpc` on your PATH

**Authentication:**

The proxy needs an `FRP_AUTH_TOKEN` to connect to the FRP server. The `start-proxy.sh` script resolves the token in this order:

1. **Command-line argument:** `./start-proxy.sh <token>`
2. **Environment variable:** `export FRP_AUTH_TOKEN=<token>` (or set it in `.env.local`)
3. **GCP Secret Manager:** falls back to `gcloud secrets versions access latest --secret=FRP_AUTH_TOKEN --project=two-beta`

Edit `frpc.toml` to point at your FRP server, then provide the token via any of the methods above.

**Usage:**

```bash
# Proxy starts automatically with make run / make debug.
# To run the proxy standalone in the foreground:
make proxy
```

### Tests

```bash
# Unit tests
make test

# End-to-end API tests (requires a valid API key)
make test-e2e TWO_API_KEY=<your-key>
```

### Other useful targets

| Target | Description |
|--------|-------------|
| `make compile` | Recompile Magento DI (after adding/changing PHP classes, plugins, or preferences) |
| `make logs` | Tail the Two plugin debug and error logs |
| `make format` | Run Prettier on frontend JS/CSS/templates |
| `make clean` | Stop and remove the Magento container |

## Releases

Releases are cut automatically once CI passes on `main`.

### Tagging (automatic, gated on CI)

`.github/workflows/release.yml` is triggered by the `CI` workflow completing on `main`. When CI's conclusion is `success`, it:

1. Skips itself if the head commit is already a `chore: Bump version` commit, or if the SHA already carries a numeric tag.
2. Reads conventional-commit types in `<previous-tag>..HEAD` to pick the bump level:
   - `BREAKING CHANGE:` / `<type>!:` → **major**
   - `feat:` → **minor**
   - everything else → **patch**

   Linear ticket prefixes are supported (e.g. `INF-123/feat:`).
3. Runs `bumpver update --<level> --no-tag-commit --no-push` to rewrite `composer.json`, `etc/config.xml`, and `bumpver.toml`.
4. Tags `X.Y.Z` (bare numeric, matching the established tag convention), pushes the bump commit and tag under the org GitHub App identity, and creates a GitHub Release with a bucketed changelog (Breaking / Features / Fixes / Internals / Other) — so reading the Release page reveals at a glance why the bump was a major / minor / patch.

`.github/workflows/merge-back.yml` keeps `develop` fast-forwarded to match `main` after each release. `.github/workflows/auto-pr.yml` keeps a rolling sync PR open from `develop` to `main` with a preview of the next release notes — the same bucketing the actual Release page uses.

To trigger a release, merge the rolling sync PR into `main`. CI runs on the merged commit; once green, `release.yml` fires.

## Links

- [Two developer documentation](https://docs.two.inc/)
- [Magento plugin setup guide](https://docs.two.inc/developer-portal/plugins/magento)

## License

OSL-3.0 / AFL-3.0. See [composer.json](composer.json) for details.
