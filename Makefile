# ==============================================================================
# Development environment
# ==============================================================================

-include .env.local

CONTAINER  := magento
IMAGE      := michielgerritsen/magento-project-community-edition
TAG        := php82-fpm-magento2.4.6-sample-data
PORT       := 1234
URL        := http://localhost:$(PORT)/

TWO_ACCOUNT_DOMAIN   := $(shell gcloud config get-value account 2>/dev/null | sed -n 's/.*@//p')
TWO_ENV              := $(if $(filter two.inc,$(TWO_ACCOUNT_DOMAIN)),staging,sandbox)
TWO_API_BASE_URL     ?= https://api.$(TWO_ENV).two.inc
TWO_CHECKOUT_BASE_URL ?= https://checkout.$(TWO_ENV).two.inc
TWO_STORE_COUNTRY    ?= NO
TWO_BRAND            ?=
TWO_BRAND_VERSION    ?=

# Brand overlay (opt-in). magento-plugin is public, so the canonical
# branding monorepo is only defaulted when the user's gcloud context
# proves they're a Two employee. External users get vanilla-only and
# must explicitly set BRAND_REPO if they have their own overlay.
BRAND_NAME    ?=
BRAND_REPO    ?= $(if $(filter two.inc,$(TWO_ACCOUNT_DOMAIN)),two-inc/magento-plugin-branding,)
BRAND_BRANCH  ?= main
BRAND_DIR     := $(CURDIR)/.brand-repo
BRAND_REGEX   := ^[a-z][a-z0-9-]*$$

export PORT

.PHONY: help install configure compile run debug stop clean flush logs proxy archive patch minor major format test test-e2e brand _install-brand

.DEFAULT_GOAL := help

## Show this help
help:
	@awk '/^## /{desc=substr($$0,4)} /^[a-zA-Z_-]+:/{if(desc){printf "  \033[36m%-16s\033[0m %s\n",$$1,desc; desc=""}}' $(MAKEFILE_LIST)

## Create Magento container, install plugin and Xdebug
install: clean
	@if [ -n "$(BRAND_NAME)" ]; then \
		[ -n "$(BRAND_REPO)" ] || { echo "ERROR: BRAND_NAME=$(BRAND_NAME) but BRAND_REPO unset (set explicitly or use a gcloud context that defaults it)."; exit 1; }; \
		echo "$(BRAND_NAME)" | grep -qE '$(BRAND_REGEX)' || { echo "ERROR: BRAND_NAME='$(BRAND_NAME)' must match regex $(BRAND_REGEX)"; exit 1; }; \
		$(MAKE) brand; \
		[ -f "$(BRAND_DIR)/brands/$(BRAND_NAME)/brand.json" ] || { echo "ERROR: brand '$(BRAND_NAME)' not in $(BRAND_REPO)@$(BRAND_BRANCH) (no brands/$(BRAND_NAME)/brand.json)"; exit 1; }; \
	fi
	docker run -d \
		--name=$(CONTAINER) \
		-p $(PORT):80 \
		--add-host=host.docker.internal:host-gateway \
		-e URL=$(URL) \
		-e TWO_API_BASE_URL=$(TWO_API_BASE_URL) \
		-e TWO_CHECKOUT_BASE_URL=$(TWO_CHECKOUT_BASE_URL) \
		$(if $(TWO_BRAND),-e TWO_BRAND=$(TWO_BRAND)) \
		$(if $(TWO_BRAND_VERSION),-e TWO_BRAND_VERSION=$(TWO_BRAND_VERSION)) \
		-v $(CURDIR):/data/extensions/workdir \
		$(if $(BRAND_NAME),-v $(BRAND_DIR):/data/extensions/branding) \
		$(IMAGE):$(TAG)
	@echo "Waiting for Magento to start..."
	@until docker exec $(CONTAINER) php bin/magento --version 2>/dev/null; do sleep 3; done
	docker exec $(CONTAINER) composer require two-inc/magento2:@dev --no-plugins
	docker exec $(CONTAINER) composer require --no-plugins \
		community-engineering/language-nl_nl \
		community-engineering/language-nb_no \
		community-engineering/language-sv_se \
		community-engineering/language-fi_fi \
		community-engineering/language-da_dk
	docker exec $(CONTAINER) rm -rf /data/generated/code
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
	@if [ -n "$(BRAND_NAME)" ]; then \
		BRAND_PKG=$$(docker exec $(CONTAINER) php -r 'echo json_decode(file_get_contents($$argv[1]),true)["composer_package"];' /data/extensions/branding/brands/$(BRAND_NAME)/brand.json); \
		BRAND_MOD=$$(docker exec $(CONTAINER) php -r 'echo json_decode(file_get_contents($$argv[1]),true)["module_name"];' /data/extensions/branding/brands/$(BRAND_NAME)/brand.json); \
		echo "Installing brand overlay: $$BRAND_PKG ($$BRAND_MOD)"; \
		docker exec $(CONTAINER) composer config repositories.branding-overlay path /data/extensions/branding/brands/$(BRAND_NAME); \
		docker exec $(CONTAINER) composer require "$$BRAND_PKG:@dev" --no-plugins; \
		docker exec $(CONTAINER) php bin/magento module:enable $$BRAND_MOD; \
	fi
	docker exec $(CONTAINER) php bin/magento setup:upgrade
	docker exec $(CONTAINER) php bin/magento deploy:mode:set developer
	docker exec $(CONTAINER) php bin/magento setup:di:compile
	# Local-dev perf: merge + minify JS/CSS so RequireJS doesn't fan out into
	# ~200 individual file fetches. Stays in developer mode (no static deploy
	# step), but the request count drops to ~20 and the storefront's KO
	# bootstrap returns in well under a second. See README "Local-dev perf".
	# Must run before `configure` — `configure` restarts the container, and
	# `config:set` requires a running Magento.
	docker exec $(CONTAINER) php bin/magento config:set dev/js/merge_files 1
	docker exec $(CONTAINER) php bin/magento config:set dev/js/minify_files 1
	docker exec $(CONTAINER) php bin/magento config:set dev/css/merge_css_files 1
	# Pre-bake all theme JS/CSS so RequireJS XHRs hit plain file IO instead
	# of falling through Magento's pub/static.php router (a full bootstrap
	# per asset). Without this, RequireJS's ~hundreds of runtime-loaded
	# files each trigger a serialised Magento boot, taking the storefront
	# button-enable latency from sub-second to ~10s on the sample catalog.
	docker exec $(CONTAINER) php bin/magento setup:static-content:deploy --area frontend --theme Magento/luma --no-html-minify -f --jobs 4 en_US
	$(MAKE) configure TWO_API_KEY=$(or $(TWO_API_KEY),dummy-dev-key)
	docker exec $(CONTAINER) bash /data/extensions/workdir/dev/install-xdebug
	docker exec $(CONTAINER) bash /data/extensions/workdir/dev/hide-admin-loader
	@./start-proxy.sh --background || true
	@PROXY_URL=$$(./start-proxy.sh url 2>/dev/null); \
	if [ -n "$$PROXY_URL" ]; then \
		docker exec $(CONTAINER) bash /data/extensions/workdir/dev/patch-proxy "$$PROXY_URL" 2>&1 | grep -v Xdebug; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " Magento store: $(URL)"; \
	echo " Admin panel:   $(URL)admin"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:   $$PROXY_URL/"; \
		echo " Proxy admin:   $$PROXY_URL/admin"; \
	fi; \
	echo " Credentials:   exampleuser / examplepassword123"; \
	echo " Xdebug:        installed (activate with 'make debug')"; \
	echo "========================================="

## Clone or refresh the brand-overlay repo at .brand-repo/ (no-op if BRAND_REPO unset)
brand:
	@if [ -z "$(BRAND_REPO)" ]; then \
		echo "BRAND_REPO unset — nothing to fetch. Set BRAND_REPO=<org/repo> or use a two.inc gcloud context."; \
		exit 0; \
	fi
	@if [ -d "$(BRAND_DIR)/.git" ]; then \
		echo "Refreshing $(BRAND_REPO) @ $(BRAND_BRANCH) in $(BRAND_DIR)..."; \
		git -C $(BRAND_DIR) fetch --depth=1 origin $(BRAND_BRANCH); \
		git -C $(BRAND_DIR) checkout -B $(BRAND_BRANCH) FETCH_HEAD; \
	else \
		echo "Cloning git@github.com:$(BRAND_REPO).git @ $(BRAND_BRANCH) into $(BRAND_DIR)..."; \
		git clone --depth=1 --branch $(BRAND_BRANCH) git@github.com:$(BRAND_REPO).git $(BRAND_DIR); \
	fi

## Update payment config: make configure TWO_API_KEY=xxx
configure:
	docker exec \
		-e TWO_API_KEY=$(TWO_API_KEY) \
		-e TWO_STORE_COUNTRY=$(TWO_STORE_COUNTRY) \
		$(CONTAINER) php /data/extensions/workdir/dev/configure
	docker exec $(CONTAINER) php bin/magento cache:flush
	docker restart $(CONTAINER)

## Recompile Magento DI (after adding/changing PHP classes, plugins, or preferences)
compile:
	docker exec $(CONTAINER) php bin/magento setup:di:compile
	docker restart $(CONTAINER)

## Start Magento container and FRP proxy
run:
	docker start $(CONTAINER)
	@./start-proxy.sh --background || true
	@PROXY_URL=$$(./start-proxy.sh url 2>/dev/null); \
	if [ -n "$$PROXY_URL" ]; then \
		docker exec $(CONTAINER) bash /data/extensions/workdir/dev/patch-proxy "$$PROXY_URL" 2>&1 | grep -v Xdebug; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " Magento store: $(URL)"; \
	echo " Admin panel:   $(URL)admin"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:   $$PROXY_URL/"; \
		echo " Proxy admin:   $$PROXY_URL/admin"; \
	fi; \
	echo " Credentials:   exampleuser / examplepassword123"; \
	echo "========================================="

## Start Magento with Xdebug and caches disabled for hot reload
debug:
	docker start $(CONTAINER)
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
		docker exec $(CONTAINER) bash /data/extensions/workdir/dev/patch-proxy "$$PROXY_URL" 2>&1 | grep -v Xdebug; \
	fi; \
	echo ""; \
	echo "========================================="; \
	echo " Magento store: $(URL)"; \
	echo " Admin panel:   $(URL)admin"; \
	if [ -n "$$PROXY_URL" ]; then \
		echo " Proxy store:   $$PROXY_URL/"; \
		echo " Proxy admin:   $$PROXY_URL/admin"; \
	fi; \
	echo " Credentials:   exampleuser / examplepassword123"; \
	echo " Mode:          debug (Xdebug + caches disabled)"; \
	echo "========================================="

## Stop Magento container and FRP proxy
stop:
	-./start-proxy.sh stop 2>/dev/null
	-docker exec $(CONTAINER) bash /data/extensions/workdir/dev/patch-proxy --reset 2>/dev/null
	docker stop $(CONTAINER)

## Clear static content and flush caches (frontend + adminhtml JS/CSS/templates)
flush:
	docker exec $(CONTAINER) bash -c \
		"rm -rf pub/static/frontend/* var/view_preprocessed/pub/static/frontend/* \
		pub/static/adminhtml/* var/view_preprocessed/pub/static/adminhtml/* \
		&& php bin/magento cache:flush"

## Remove the Magento container and stop proxy
clean:
	-./start-proxy.sh stop 2>/dev/null
	-docker stop $(CONTAINER) 2>/dev/null
	-docker rm $(CONTAINER) 2>/dev/null

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
archive:
	eval $$(bumpver show --environ) && git archive --format zip HEAD > magento-plugin-$${CURRENT_VERSION}.zip
bumpver-%:
	SKIP=commit-msg bumpver update --$*
## Bump patch version
patch: bumpver-patch
## Bump minor version
minor: bumpver-minor
## Bump major version
major: bumpver-major
PHPUNIT_VERSION := 9.6.34
PHPUNIT_SHA256  := e7264ae61fe58a487c2bd741905b85940d8fbc2b32cf4a279949b6d9a172a06a

## Run PHPUnit tests
test:
	docker run --rm -v $(CURDIR):/app -w /app php:8.1-cli bash -c \
		"php -r \"copy('https://phar.phpunit.de/phpunit-$(PHPUNIT_VERSION).phar', '/tmp/phpunit.phar');\" \
		&& echo '$(PHPUNIT_SHA256)  /tmp/phpunit.phar' | sha256sum -c - \
		&& php /tmp/phpunit.phar"

## Run end-to-end API tests (requires TWO_API_KEY)
test-e2e:
	docker run --rm -v $(CURDIR):/app -w /app \
		-e TWO_API_KEY=$(TWO_API_KEY) \
		-e TWO_API_BASE_URL=$(TWO_API_BASE_URL) \
		php:8.1-cli bash -c \
		"php -r \"copy('https://phar.phpunit.de/phpunit-$(PHPUNIT_VERSION).phar', '/tmp/phpunit.phar');\" \
		&& echo '$(PHPUNIT_SHA256)  /tmp/phpunit.phar' | sha256sum -c - \
		&& php /tmp/phpunit.phar --testsuite E2E"

## Format frontend assets with Prettier
format:
	prettier -w view/frontend/web/js/
	prettier -w view/frontend/web/css/
	prettier -w view/frontend/web/template/
