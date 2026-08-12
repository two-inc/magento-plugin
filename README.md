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

### Post-install steps

Run these immediately after `setup:upgrade` to refresh the DI graph
and clear any stale cache types:

```bash
php bin/magento setup:di:compile
php bin/magento cache:flush
```

If admin Configuration is missing expected Two/brand sections, the
cause is almost certainly one of:

- A plugin registered only under `etc/adminhtml/di.xml` or
  `etc/crontab/di.xml` instead of `etc/di.xml`. See AGENTS.md
  for the DI-scope rule.
- An FPM worker holding stale opcache. Restart PHP-FPM
  (`systemctl reload php-fpm` or `kill -USR2 <fpm-master-pid>`).
- A cache type (config / layout / full_page) in stale state.
  `bin/magento cache:flush` is the canonical fix.

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

Brand-specific overlay packages live in their own Composer packages and are
installed separately from this plugin, not through `make install`. See
`docs/brand-overlay-guide.md` for how overlay modules are structured.

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

The version is computed on the pull request that lands on `staging`, from that PR's own commits. `main` computes nothing — it tags the version already in the tree and cuts the GitHub Release.

### The version-bump convention

| Change | What happens |
|---|---|
| PR into `staging` | the version is computed from that PR's own commits and committed onto the PR's branch (`.github/workflows/version-bump.yml`) |
| merge into `staging` | nothing — the merge brings in the version its PR computed |
| `staging` into `main` | nothing is computed; `main` tags the version already in the tree and cuts the GitHub Release |

With `M` the version on `origin/main` and `C` the version on the PR head, the PR's own commits (`origin/staging..HEAD`, `--no-merges`) decide the candidate: a `!` type or a `BREAKING CHANGE:` footer gives `(M.major + 1).0.0`, a `feat:` gives `M.major.(M.minor + 1).0`, and anything else — `fix` and `chore`/`docs`/`ci`/`test`/`refactor` alike — gives `M.major.M.minor.(M.patch + 1)`. The result is clamped with `max(C, candidate)`, which makes it idempotent (a re-run, the `synchronize` the bump commit itself fires, or a second fix commit on the same PR all write nothing) and means the version can never regress while `main` is behind `staging`.

A **major** is an explicit escape hatch, and overrides the rule above. Two independent signals, the higher wins:

- **Declared** — a root `.next-major` file whose first whitespace-delimited token is the target major, with a short human reason on the same line:

  ```
  3  # overlay migration, 3.0.0 release
  ```

  Reviewable in the PR that decides it, so a *planned* major with no single breaking commit still lands as a major. The file is never cleared by CI: it disarms itself once the major it names has shipped. A declaration that has fallen *below the major on `main`* is a hard CI failure — delete or raise it.

- **Discovered** — a `!` on a conventional-commit type (`feat!:`, `TWO-1/fix(scope)!:`) or a `BREAKING CHANGE:` footer in **this PR's own commits** only. Deliberately not the cumulative `main..staging` range: a break that already landed on `staging` must not be re-discovered by every later PR.

The new version for a major is exactly `<target>.0.0`, so a declaration may skip more than one major.

`.github/scripts/decide-bump-level.sh` owns this decision, is unit-tested by `.github/scripts/test-decide-bump-level.sh`, and is shared byte-identically across the plugin repos. It logs the full decision — inputs included — to the workflow log on every run.

### Bumping (on the PR) and tagging (on `main`)

`.github/workflows/version-bump.yml` runs on every `pull_request` into `staging`. It:

1. Runs the unit tests for the computation, then computes an absolute version with `.github/scripts/decide-bump-level.sh origin/staging HEAD`.
2. If that version differs from the one on the PR head, runs `bumpver update --set-version <X.Y.Z> --no-tag-commit --no-push` to rewrite `composer.json`, `etc/config.xml` and `bumpver.toml`, and pushes the commit onto the PR's own branch under the org GitHub App identity. Otherwise it writes nothing.

The push goes out under the App token rather than `GITHUB_TOKEN` for two reasons: `GITHUB_TOKEN` pushes do not trigger workflows, so CI would never re-run on the bump SHA; and the App holds the ruleset bypass. Because the commit lands on a feature branch, it is outside `terraform-managed-branch-protection` (which targets only `refs/heads/{main,release,staging}`) entirely.

`.github/workflows/release.yml` is triggered by the `CI` workflow completing on `main`. When CI's conclusion is `success`, it:

1. Skips itself if the branch tip drifted from the SHA CI signed off on, or if the SHA already carries a numeric tag. (That last check is what makes the merge-back a no-op: after a `main` release fast-forwards into `staging`, staging's tip already carries the tag.)
2. Reads the version out of `bumpver.toml` — it does not compute or bump one.
3. Tags `X.Y.Z` (bare numeric, matching the established tag convention), pushes the tag, and creates a GitHub Release with a bucketed changelog (Breaking / Features / Fixes / Internals / Other).

`.github/workflows/merge-back.yml` keeps `staging` fast-forwarded to match `main` after each release (falling back to a sync PR if the two have diverged).

`.github/workflows/auto-pr.yml` runs on every push to `staging` (a merge is a push) and keeps a single rolling `staging → main` promotion PR open, no-opping when one already exists or when `staging` is not ahead of `main`.

To trigger a release, merge that `staging → main` PR. CI runs on the merged commit; once green, `release.yml` fires.

## Links

- [Two developer documentation](https://docs.two.inc/)
- [Magento plugin setup guide](https://docs.two.inc/developer-portal/plugins/magento)

## License

OSL-3.0 / AFL-3.0. See [composer.json](composer.json) for details.
