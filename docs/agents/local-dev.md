---
description: 'The local compose stack and the modules `make install` disables.'
---

# Local development shop

## The local shop is a compose stack, not one container

`make install` brings up Magento, MariaDB and OpenSearch via `docker-compose.yml`,
using `ghcr.io/brtkwr/magento-dev` from
[brtkwr/magento-helm](https://github.com/brtkwr/magento-helm). Consequences worth
knowing before debugging a local setup:

-   **It is multi-arch**, so it runs natively on Apple Silicon. A previous
    third-party image was amd64-only and ran under emulation.
-   **No Magento marketplace keys are needed.** The plugin is mounted at
    `app/code/Two/Gateway` and found via its `registration.php`; language packs
    are baked into the image. Nothing reaches `repo.magento.com` locally, so a
    `composer` auth failure during local setup means something has regressed to
    the old approach.
-   **The shop installs on first boot and then stays put.** `make install` waits
    for `var/.dev-install-complete` rather than `bin/magento --version`, which
    answers long before the shop works. The old image re-bootstrapped base URLs
    on every restart, which is why the proxy patching used to need a custom
    entrypoint - that is gone.
-   **`make clean` drops the database volume**, so the next `make install` is a
    genuine fresh shop.

## Local-dev modules disabled by `make install`

`make install` disables PageBuilder and the Analytics module family
in the local Docker container so that `setup:di:compile` and the
storefront's RequireJS bootstrap stay fast. See README's
"Local-dev perf" section for the full list, the rationale, and the
re-enable recipe.

**If a future enquiry surfaces along the lines of "why isn't this
PageBuilder banner / promo block / CMS slide rendering in my local
build", the answer is almost certainly that `Magento_PageBuilder` is
disabled by `make install`** — point them at the README section,
which includes the commands to re-enable PageBuilder for testing
brand content that relies on it. Same applies to anything
analytics-driven (e.g. NewRelic dashboards, GA events).
