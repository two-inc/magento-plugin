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

## Staging Environment

| URL                             | Namespace   |
| ------------------------------- | ----------- |
| https://magento.staging.two.inc | Two_Gateway |

### Running Commands on Staging

**Important**: Run Magento CLI commands as `www-data` user to avoid permission issues:

```bash
# Correct - run as www-data
kubectl exec -n staging <pod-name> -c magento -- su www-data -s /bin/bash -c 'bin/magento cache:flush'

# Also correct - the container often defaults to www-data
kubectl exec -n staging <pod-name> -c magento -- bin/magento cache:flush

# Find pod names
kubectl get pods -n staging | grep magento
```

### Clearing Caches on Staging

After pushing changes, git-sync will pick them up within ~30 seconds. Clear caches to see changes:

```bash
# Clear opcache (required for PHP changes due to opcache.validate_timestamps=0)
curl -s https://magento.staging.two.inc/opcache-clear.php

# Flush Magento cache via kubectl
kubectl exec -n staging <pod-name> -c magento -- bin/magento cache:flush
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

-   Translation files: `nb_NO.csv`, `nl_NL.csv`, `sv_SE.csv`
-   No `en_US.csv` needed - Magento falls back to source strings for English

## Admin Panel Configuration

-   All config fields should have `canRestore="1"` to allow website/store scope inheritance
-   Button-type fields (version, api_key_check, etc.) don't need `canRestore`
-   Use `translate="label comment"` when field has both label and comment to translate
