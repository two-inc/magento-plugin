# Magento Plugin (Two Gateway)

## Git Workflow

-   Use `SKIP=commit-msg` when committing on `main` or `abn-main` branches (no Linear ticket needed)
-   Do NOT skip commit-msg hook on feature branches
-   Never use `--no-verify` flag

## Branch Structure

-   `main` - Two.inc version (Two_Gateway namespace)
-   `abn-main` - ABN AMRO version (ABN_Gateway namespace)

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
SKIP=commit-msg,php-lint git commit -m "chore: ABN layer

Applies ABN-specific customizations on top of main branch:
- Namespace changes: Two -> ABN
- Payment method: two_payment -> abn_payment
- Config paths: two_* -> abn_*
- ABN branding (logo, messaging)
- Remove bumpver.toml (versioning managed on main)"

# 8. Force push
git push origin abn-main --force
```

## Version Management

-   Version bumps are done on `main` only using `bumpver`
-   The `abn-main` branch inherits the version from main via rebase
-   `bumpver.toml` is removed from abn-main to avoid confusion

```bash
# On main branch
SKIP=commit-msg bumpver update --patch  # or --minor, --major
git push origin main --tags
```

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
-   `i18n/*.csv` - Translations with ABN-specific strings

## Staging Environments

| Branch     | URL                                        | Namespace   |
| ---------- | ------------------------------------------ | ----------- |
| `main`     | https://magento.staging.two.inc            | Two_Gateway |
| `abn-main` | https://magento.staging.achterafbetalen.co | ABN_Gateway |

### Clearing Caches on Staging

After pushing changes, git-sync will pick them up within ~30 seconds. Clear caches to see changes:

```bash
# Clear opcache (required for PHP changes due to opcache.validate_timestamps=0)
curl -s https://magento.staging.two.inc/opcache-clear.php
curl -s https://magento.staging.achterafbetalen.co/opcache-clear.php

# Flush Magento cache via kubectl
kubectl exec -n staging <pod-name> -c magento -- bin/magento cache:flush

# Find pod names
kubectl get pods -n staging | grep magento
```

### Deploying Admin Static Content

If admin CSS/JS changes aren't showing:

```bash
kubectl exec -n staging <pod-name> -c magento -- bash -c '
  rm -rf pub/static/adminhtml/* var/view_preprocessed/pub/static/adminhtml/*
  bin/magento setup:static-content:deploy -f --area=adminhtml
  bin/magento cache:flush
'
```

Then clear opcache via the HTTP endpoint.

### Regenerating DI (Interceptors)

If you get class/interceptor errors:

```bash
kubectl exec -n staging <pod-name> -c magento -- bin/magento setup:di:compile
```

## Translations

-   `main` branch has: `nb_NO.csv`, `nl_NL.csv`, `sv_SE.csv`
-   `abn-main` branch has: `nl_NL.csv` only (ABN is NL-focused)
-   No `en_US.csv` needed - Magento falls back to source strings for English

## Admin Panel Configuration

-   All config fields should have `canRestore="1"` to allow website/store scope inheritance
-   Button-type fields (version, api_key_check, etc.) don't need `canRestore`
-   Use `translate="label comment"` when field has both label and comment to translate
