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
