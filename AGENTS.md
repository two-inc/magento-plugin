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

-   All config fields should have `canRestore="1"` to allow website/store scope inheritance
-   Button-type fields (version, api_key_check, etc.) don't need `canRestore`
-   Use `translate="label comment"` when field has both label and comment to translate
