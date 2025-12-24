# Magento Plugin (ABN Gateway)

## Git Workflow

-   Use `SKIP=commit-msg` when committing on `abn-main` branch (no Linear ticket needed)
-   Do NOT skip commit-msg hook on feature branches
-   Never use `--no-verify` flag

## Branch Structure

-   `main` - Two.inc version (Two_Gateway namespace)
-   `abn-main` - ABN AMRO version (ABN_Gateway namespace) - **THIS BRANCH**

The `abn-main` branch should always be `main` + a single "ABN layer" commit on top.

## Rebasing abn-main onto main

When main has new changes that need to be incorporated into abn-main:

```bash
# 1. Ensure main is up to date
git checkout main
git pull origin main

# 2. Reset abn-main to main
git checkout abn-main
git fetch origin abn-main
git reset --hard origin/main

# 3. Cherry-pick the ABN layer commit (get hash from previous abn-main)
git cherry-pick <abn-layer-commit-hash> --no-commit

# 4. If there are conflicts, resolve them manually
# Key ABN-specific changes:
#   - Namespace: Two -> ABN
#   - Payment method: two_payment -> abn_payment
#   - Config paths: two_* -> abn_*
#   - Template paths: Two_Gateway:: -> ABN_Gateway::

# 5. Update any new files that need ABN namespace changes
# Check Block/, etc/adminhtml/, etc/config.xml, view/adminhtml/templates/

# 6. Remove bumpver.toml (versioning managed on main only)
rm -f bumpver.toml

# 7. Stage and commit as single ABN layer commit
git add -A
SKIP=commit-msg git commit -m "chore: ABN layer

Applies ABN-specific customizations on top of main branch:
- Namespace changes: Two -> ABN
- Payment method: two_payment -> abn_payment
- Config paths: two_* -> abn_*
- ABN branding (logo, messaging)
- Remove bumpver.toml (versioning managed on main)"

# 8. Verify no Two references remain (CRITICAL!)
rg -i "two_payment|Two_Gateway|Two\\\\Gateway" --glob '!AGENTS.md' --glob '!README.md'

# 9. Force push
git push origin abn-main --force
```

### Verifying ABN Namespace Changes

After rebasing, always check for leftover Two references that should be ABN:

```bash
# Check for any remaining Two references (should return empty except AGENTS.md/README.md)
rg -i "two_payment|Two_Gateway" --glob '!AGENTS.md' --glob '!README.md'

# Check PHP namespace references
rg "Two\\\\Gateway" --glob '*.php' --glob '*.phtml' --glob '*.xml'

# Check config paths
rg "two_payment" --glob '*.xml' --glob '*.phtml'
```

If any matches are found, they need to be renamed to the ABN equivalent:

-   `two_payment` → `abn_payment`
-   `Two_Gateway` → `ABN_Gateway`
-   `Two\Gateway` → `ABN\Gateway`
-   `two_*` config paths → `abn_*`

## Version Management

-   Version bumps are done on `main` only using `bumpver`
-   The `abn-main` branch inherits the version from main via rebase
-   `bumpver.toml` is removed from abn-main to avoid confusion

## Translations

-   ABN is Dutch-only: only `nl_NL.csv` translation file
-   No `en_US.csv` needed - Magento falls back to source strings for English
-   Norwegian (`nb_NO.csv`) and Swedish (`sv_SE.csv`) are NOT included in ABN version

## ABN-specific Differences

-   Namespace: `ABN\Gateway` instead of `Two\Gateway`
-   Payment method code: `abn_payment` instead of `two_payment`
-   Config section: `abn_*` instead of `two_*`
-   Dutch title: "Achteraf betalen - Bestel op factuur"
-   ABN logo assets in `view/*/web/images/abnLogo.svg`
-   Fixed 30-day standard payment terms (no other options)

## Admin Panel Configuration

-   Most config fields should have `canRestore="1"` to allow website/store scope inheritance
-   Sensitive fields (mode, api_key, debug) should NOT have `canRestore` - they must be explicitly set
-   Button-type fields (version, api_key_check, etc.) don't need `canRestore`

### Config Paths

All payment config is stored under `payment/abn_payment/`:

-   `payment/abn_payment/active` - Enable/disable
-   `payment/abn_payment/mode` - Environment (sandbox/staging/production)
-   `payment/abn_payment/api_key` - API key (encrypted)
-   `payment/abn_payment/debug` - Debug mode

### Setting Config via CLI

```bash
bin/magento config:set payment/abn_payment/mode sandbox
bin/magento config:set payment/abn_payment/active 1
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
rm -rf generated/code/ABN

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

### Common Issues

1. **Template not found error**: Run `setup:di:compile` and clear opcache
2. **Stale worktree paths in errors**: Delete `generated/code/ABN` and recompile DI
3. **Admin CSS/logo missing**: Redeploy admin static content
4. **Permission denied on var/cache**: Fix ownership with `chown -R www-data:www-data var/ generated/`
5. **Config changes not appearing**: Flush config cache and clear opcache
6. **Namespace/class not found errors after rebase**: Some Two references may not have been renamed. Run the verification commands in "Verifying ABN Namespace Changes" section and rename any leftover Two references.

## Publishing ABN Plugin

The ABN plugin is published to a GCS bucket for distribution:

```bash
# 1. Tag the release (creates abn-<version> tag)
make tag

# 2. Create archive and publish to GCS
make publish
```

`make publish` runs:

1. `make archive` - creates `artifacts/<version>/magento-abn-plugin.zip`
2. `gsutil cp` - uploads to `gs://achteraf-betalen/magento/`
3. `scripts/publish-to-bucket.py` - regenerates index.html with download links

The published plugin is available at: https://plugins.achterafbetalen.co/magento/index.html

## Files with ABN-specific Changes

These files contain ABN namespace/branding and need updating during rebase:

-   `registration.php` - Module name
-   `composer.json` - Package name and description
-   `etc/module.xml` - Module name
-   `etc/adminhtml/system.xml` - Section ID, frontend_model references
-   `etc/config.xml` - Config paths
-   `etc/frontend/di.xml` - Class references
-   `etc/frontend/routes.xml` - Route frontName
-   All PHP files in `Block/`, `Controller/`, `Helper/`, `Model/`, `Observer/`, `Plugin/`
-   All layout XML files in `view/adminhtml/layout/` and `view/frontend/layout/`
-   All template files in `view/adminhtml/templates/` and `view/frontend/templates/`
-   `view/frontend/web/images/` - ABN logo assets
-   `view/frontend/web/css/` - ABN styling
-   `README.md` - ABN documentation
-   `i18n/nl_NL.csv` - Dutch translations with ABN-specific strings
