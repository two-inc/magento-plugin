# ==============================================================================
# Development environment
# ==============================================================================

-include .env

CONTAINER  := magento
# Images come from brtkwr/magento-helm and are published for amd64 and arm64,
# so this runs natively on Apple Silicon instead of under QEMU.
MAGENTO_TAG ?= 2.4.8
# Where the plugin working tree is mounted inside the container.
WORKDIR    := /var/www/html/app/code/Two/Gateway
PORT       := 1234
URL        := http://localhost:$(PORT)/

TWO_ENV              := $(shell gcloud config get-value account 2>/dev/null | grep -q '@two\.inc$$' && echo staging || echo sandbox)
TWO_API_BASE_URL     ?= https://api.$(TWO_ENV).two.inc
TWO_CHECKOUT_BASE_URL ?= https://checkout.$(TWO_ENV).two.inc
TWO_STORE_COUNTRY    ?= NO
export PORT

.PHONY: help install configure compile run debug stop clean flush logs proxy archive patch minor major format test-image test test-e2e

.DEFAULT_GOAL := help

## Show this help
help:
	@awk '/^## /{desc=substr($$0,4)} /^[a-zA-Z_-]+:/{if(desc){printf "  \033[36m%-16s\033[0m %s\n",$$1,desc; desc=""}}' $(MAKEFILE_LIST)

## Create Magento container, install plugin and Xdebug
install: clean
	MAGENTO_TAG=$(MAGENTO_TAG) PORT=$(PORT) \
		TWO_API_BASE_URL=$(TWO_API_BASE_URL) \
		TWO_CHECKOUT_BASE_URL=$(TWO_CHECKOUT_BASE_URL) \
		docker compose up -d
	# The image installs the shop on first boot and touches this file when it
	# is genuinely usable. `bin/magento --version` answers long before that, so
	# waiting on it races the install.
	@echo "Waiting for Magento to install (first run takes a few minutes)..."
	@until docker exec $(CONTAINER) test -f var/.dev-install-complete 2>/dev/null; do sleep 3; done
	# The plugin is bind-mounted at app/code and found via its registration.php.
	# Language packs ship preinstalled in the image. Neither needs composer, so
	# no Magento marketplace keys are required to stand a shop up locally.
	# No race guard needed here any more: the old image's entrypoint kept
	# re-bootstrapping Magento in the background and could write into
	# generated/code mid-delete. This image installs once, behind the sentinel
	# waited on above, and then leaves the shop alone.
	docker exec $(CONTAINER) rm -rf generated/code
	docker exec $(CONTAINER) php bin/magento module:disable \
		Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth \
		Magento_Analytics Magento_AdminAnalytics \
		Magento_CatalogAnalytics Magento_CustomerAnalytics \
		Magento_QuoteAnalytics Magento_ReviewAnalytics \
		Magento_SalesAnalytics Magento_WishlistAnalytics \
		Magento_GoogleAnalytics Magento_GoogleOptimizer \
		Magento_PageBuilder Magento_PageBuilderAnalytics \
		Magento_CatalogPageBuilderAnalytics Magento_CmsPageBuilderAnalytics \
		Magento_PageBuilderAdminAnalytics Magento_AwsS3PageBuilder
	# NB: Magento_NewRelicReporting NOT disabled — Magento_GraphQl declares
	# a hard dependency on it (every *GraphQl module transitively requires
	# it). Even un-licensed it should be quiet at runtime in dev.
	docker exec $(CONTAINER) php bin/magento module:enable Two_Gateway
	docker exec $(CONTAINER) php bin/magento setup:upgrade
	docker exec $(CONTAINER) php bin/magento setup:di:compile
	docker exec $(CONTAINER) php bin/magento deploy:mode:set developer
	# di:compile resets Magento to production mode as a side effect, so
	# deploy:mode:set developer must run AFTER it, or developer mode gets
	# silently clobbered back to production. See the overlay repo's 66062d8.
	# Local-dev perf: merge + minify JS/CSS so RequireJS doesn't fan out into
	# ~200 individual file fetches. Stays in developer mode (no static deploy
	# step), but the request count drops to ~20 and the storefront's KO
	# bootstrap returns in well under a second. See README "Local-dev perf".
	# Must run before `configure` — `configure` restarts the container, and
	# `config:set` requires a running Magento.
	docker exec $(CONTAINER) php bin/magento config:set dev/js/merge_files 1
	docker exec $(CONTAINER) php bin/magento config:set dev/js/minify_files 1
	docker exec $(CONTAINER) php bin/magento config:set dev/css/merge_css_files 1
	# The base image's sample-data admin account is always past-due on
	# Magento's 90-day default password lifetime the moment a fresh
	# container starts, bouncing every non-My-Account admin page.
	docker exec $(CONTAINER) php bin/magento config:set admin/security/password_lifetime 0
	docker exec $(CONTAINER) bash $(WORKDIR)/dev/create-admin-user
	# Pre-bake all theme JS/CSS so RequireJS XHRs hit plain file IO instead
	# of falling through Magento's pub/static.php router (a full bootstrap
	# per asset). Without this, RequireJS's ~hundreds of runtime-loaded
	# files each trigger a serialised Magento boot, taking the storefront
	# button-enable latency from sub-second to ~10s on the sample catalog.
	docker exec $(CONTAINER) php bin/magento setup:static-content:deploy --area frontend --theme Magento/luma --no-html-minify -f --jobs 4 en_US
	$(MAKE) configure TWO_API_KEY=$(or $(TWO_API_KEY),dummy-dev-key)
	docker exec $(CONTAINER) bash $(WORKDIR)/dev/install-xdebug
	docker exec $(CONTAINER) bash $(WORKDIR)/dev/hide-admin-loader
	@./start-proxy.sh --background || true
	@PROXY_URL=$$(./start-proxy.sh url 2>/dev/null); \
	if [ -n "$$PROXY_URL" ]; then \
		docker exec $(CONTAINER) bash $(WORKDIR)/dev/patch-proxy "$$PROXY_URL" 2>&1 | grep -v Xdebug; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " Magento store: $(URL)"; \
	echo " Admin panel:   $(URL)admin"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:   $$PROXY_URL/"; \
		echo " Proxy admin:   $$PROXY_URL/admin"; \
	fi; \
	echo " Credentials:   exampleuser@two.inc / examplepassword123"; \
	echo "                (the base image's own 'exampleuser' account still works too)"; \
	echo " Xdebug:        installed (activate with 'make debug')"; \
	bash dev/print-resolved-hosts.sh $(CONTAINER); \
	echo "========================================="

## Update payment config: make configure TWO_API_KEY=xxx
configure:
	docker exec \
		-e TWO_API_KEY=$(TWO_API_KEY) \
		-e TWO_STORE_COUNTRY=$(TWO_STORE_COUNTRY) \
		$(CONTAINER) php $(WORKDIR)/dev/configure
	docker exec $(CONTAINER) php bin/magento cache:flush
	docker restart $(CONTAINER)

## Recompile Magento DI (after adding/changing PHP classes, plugins, or preferences)
compile:
	docker exec $(CONTAINER) php bin/magento setup:di:compile
	docker restart $(CONTAINER)

## Start Magento container and FRP proxy
run:
	MAGENTO_TAG=$(MAGENTO_TAG) PORT=$(PORT) docker compose start
	@./start-proxy.sh --background || true
	@PROXY_URL=$$(./start-proxy.sh url 2>/dev/null); \
	if [ -n "$$PROXY_URL" ]; then \
		docker exec $(CONTAINER) bash $(WORKDIR)/dev/patch-proxy "$$PROXY_URL" 2>&1 | grep -v Xdebug; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " Magento store: $(URL)"; \
	echo " Admin panel:   $(URL)admin"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:   $$PROXY_URL/"; \
		echo " Proxy admin:   $$PROXY_URL/admin"; \
	fi; \
	echo " Credentials:   exampleuser@two.inc / examplepassword123"; \
	echo "                (the base image's own 'exampleuser' account still works too)"; \
	bash dev/print-resolved-hosts.sh $(CONTAINER); \
	echo "========================================="

## Start Magento with Xdebug and caches disabled for hot reload
debug:
	MAGENTO_TAG=$(MAGENTO_TAG) PORT=$(PORT) docker compose start
	@docker exec $(CONTAINER) bash -c '\
		INIS=$$(find /etc/php /usr/local/etc/php -name "*xdebug*" 2>/dev/null); \
		if [ -n "$$INIS" ]; then \
			echo "$$INIS" | xargs sed -i "s/xdebug.mode=off/xdebug.mode=debug/"; \
			echo "Xdebug activated (listening on port 9003)"; \
		else \
			echo "Xdebug not installed (run: make install)"; \
		fi'
	docker exec $(CONTAINER) php bin/magento cache:disable
	docker exec $(CONTAINER) php bin/magento cache:flush
	docker restart $(CONTAINER)
	@./start-proxy.sh --background || true
	@PROXY_URL=$$(./start-proxy.sh url 2>/dev/null); \
	if [ -n "$$PROXY_URL" ]; then \
		docker exec $(CONTAINER) bash $(WORKDIR)/dev/patch-proxy "$$PROXY_URL" 2>&1 | grep -v Xdebug; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " Magento store: $(URL)"; \
	echo " Admin panel:   $(URL)admin"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:   $$PROXY_URL/"; \
		echo " Proxy admin:   $$PROXY_URL/admin"; \
	fi; \
	echo " Credentials:   exampleuser@two.inc / examplepassword123"; \
	echo "                (the base image's own 'exampleuser' account still works too)"; \
	echo " Mode:          debug (Xdebug + caches disabled)"; \
	bash dev/print-resolved-hosts.sh $(CONTAINER); \
	echo "========================================="

## Stop Magento container and FRP proxy
stop:
	-./start-proxy.sh stop 2>/dev/null
	-docker exec $(CONTAINER) bash $(WORKDIR)/dev/patch-proxy --reset 2>/dev/null
	docker compose stop

## Clear static content and flush caches (frontend + adminhtml JS/CSS/templates)
flush:
	docker exec $(CONTAINER) bash -c \
		"rm -rf pub/static/frontend/* var/view_preprocessed/pub/static/frontend/* \
		pub/static/adminhtml/* var/view_preprocessed/pub/static/adminhtml/* \
		&& php bin/magento cache:flush"

## Remove the Magento container and stop proxy
clean:
	-./start-proxy.sh stop 2>/dev/null
	-docker compose down -v 2>/dev/null
	# A checkout predating the compose stack still has a standalone `magento`
	# container from `docker run`. `compose down` only removes what Compose
	# created, so without this the name collides on the next `compose up`.
	-docker rm -f $(CONTAINER) 2>/dev/null

## Run FRP proxy in foreground (Ctrl-C to stop)
proxy:
	./start-proxy.sh

## Tail Two plugin logs
logs:
	docker exec $(CONTAINER) bash -c 'mkdir -p var/log/two && touch var/log/two/debug.log var/log/two/error.log && chmod -R 777 var/log/two && tail -f var/log/two/debug.log var/log/two/error.log'

# ==============================================================================
# Release
# ==============================================================================

## Create a versioned zip archive
# The zip carries a `.two-deployed-commit` build stamp: a zip-dropped
# install (unpacked straight into app/code) has neither a .git gitlink nor a
# Composer registry entry, so the stamp is the only provenance signal
# Model/Provenance.php can find there. It is written into a mktemp dir OUTSIDE
# the repo and injected with `git archive --add-file`, so the working tree is
# never dirtied and the stamp can't accidentally get committed.
archive:
	eval $$(bumpver show --environ) \
	  && stampdir=$$(mktemp -d) \
	  && trap 'rm -rf "$$stampdir"' EXIT \
	  && git rev-parse --short HEAD > "$$stampdir/.two-deployed-commit" \
	  && git archive --format zip --add-file="$$stampdir/.two-deployed-commit" HEAD \
	       > magento-plugin-$${CURRENT_VERSION}.zip
bumpver-%:
	SKIP=commit-msg bumpver update --$*
## Bump patch version
patch: bumpver-patch
## Bump minor version
minor: bumpver-minor
## Bump major version
major: bumpver-major
PHPUNIT_VERSION := 10.5.64
PHPUNIT_SHA256  := a823d916151f628dd9943ccc81a98bcfbba9c5babf53f27be6c7dccc89f8ee23
TEST_IMAGE      := magento-plugin-test

# Unconditional: docker's layer cache owns the rebuild, where an image-exists guard would keep a stale image past a Dockerfile edit.
test-image:
	docker build -t $(TEST_IMAGE) - < dev/Dockerfile.test

## Run PHPUnit tests
test: test-image
	docker run --rm -v $(CURDIR):/app --tmpfs /app/.worktrees -w /app $(TEST_IMAGE) bash -c \
		"php -r \"copy('https://phar.phpunit.de/phpunit-$(PHPUNIT_VERSION).phar', '/tmp/phpunit.phar');\" \
		&& echo '$(PHPUNIT_SHA256)  /tmp/phpunit.phar' | sha256sum -c - \
		&& php /tmp/phpunit.phar"

## Run end-to-end API tests (requires TWO_API_KEY)
test-e2e: test-image
	docker run --rm -v $(CURDIR):/app --tmpfs /app/.worktrees -w /app \
		-e TWO_API_KEY=$(TWO_API_KEY) \
		-e TWO_API_BASE_URL=$(TWO_API_BASE_URL) \
		$(TEST_IMAGE) bash -c \
		"php -r \"copy('https://phar.phpunit.de/phpunit-$(PHPUNIT_VERSION).phar', '/tmp/phpunit.phar');\" \
		&& echo '$(PHPUNIT_SHA256)  /tmp/phpunit.phar' | sha256sum -c - \
		&& php /tmp/phpunit.phar --testsuite E2E"

## Format frontend assets with Prettier
format:
	prettier -w view/frontend/web/js/
	prettier -w view/frontend/web/css/
	prettier -w view/frontend/web/template/
