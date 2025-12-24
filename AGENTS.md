# Magento Plugin (Two Gateway)

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

### Common Issues

1. **Template not found error**: Run `setup:di:compile` and clear opcache
2. **Stale worktree paths in errors**: Delete `generated/code/Two` and recompile DI
3. **Admin CSS/logo missing**: Redeploy admin static content
4. **Permission denied on var/cache**: Fix ownership with `chown -R www-data:www-data var/ generated/`
5. **Config changes not appearing**: Flush config cache and clear opcache
