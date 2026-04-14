# ==============================================================================
# Development environment
# ==============================================================================

-include .env.local

CONTAINER  := magento
IMAGE      := michielgerritsen/magento-project-community-edition
TAG        := php82-fpm-magento2.4.6-sample-data
PORT       := 1234
URL        := http://localhost:$(PORT)/

TWO_API_BASE_URL     ?= https://api.staging.two.inc
TWO_CHECKOUT_BASE_URL ?= https://checkout.staging.two.inc
TWO_STORE_COUNTRY    ?= NO
TWO_BRAND            ?=
TWO_BRAND_VERSION    ?=
export PORT

.PHONY: help install configure compile run stop clean logs archive patch minor major format test test-e2e

.DEFAULT_GOAL := help

## Show this help
help:
	@awk '/^## /{desc=substr($$0,4)} /^[a-zA-Z_-]+:/{if(desc){printf "  \033[36m%-16s\033[0m %s\n",$$1,desc; desc=""}}' $(MAKEFILE_LIST)

## Create Magento container, install plugin, configure payment method
install: clean
	docker run -d \
		--name=$(CONTAINER) \
		-p $(PORT):80 \
		-e URL=$(URL) \
		-e TWO_API_BASE_URL=$(TWO_API_BASE_URL) \
		-e TWO_CHECKOUT_BASE_URL=$(TWO_CHECKOUT_BASE_URL) \
		$(if $(TWO_BRAND),-e TWO_BRAND=$(TWO_BRAND)) \
		$(if $(TWO_BRAND_VERSION),-e TWO_BRAND_VERSION=$(TWO_BRAND_VERSION)) \
		-v $(CURDIR):/data/extensions/workdir \
		$(IMAGE):$(TAG)
	@echo "Waiting for Magento to start..."
	@until docker exec $(CONTAINER) php bin/magento --version 2>/dev/null; do sleep 3; done
	docker exec $(CONTAINER) composer require two-inc/magento2:@dev --no-plugins
	docker exec $(CONTAINER) rm -rf /data/generated/code
	docker exec $(CONTAINER) php bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth
	docker exec $(CONTAINER) php bin/magento module:enable Two_Gateway
	docker exec $(CONTAINER) php bin/magento setup:upgrade
	docker exec $(CONTAINER) php bin/magento deploy:mode:set developer
	docker exec $(CONTAINER) php bin/magento setup:di:compile
	$(MAKE) configure TWO_API_KEY=dummy-dev-key
	@echo ""
	@echo "========================================="
	@echo " Magento store: $(URL)"
	@echo " Admin panel:   $(URL)admin"
	@echo " Credentials:   exampleuser / examplepassword123"
	@echo "========================================="

## Update payment config: TWO_API_KEY=xxx make configure
configure:
	docker exec \
		-e TWO_API_KEY=$(TWO_API_KEY) \
		-e TWO_STORE_COUNTRY=$(TWO_STORE_COUNTRY) \
		$(CONTAINER) php /data/extensions/workdir/dev/configure
	docker exec $(CONTAINER) php bin/magento cache:flush
	docker restart $(CONTAINER)

## Recompile DI and restart
compile:
	docker exec $(CONTAINER) php bin/magento setup:di:compile
	docker restart $(CONTAINER)

## Start the Magento container
run:
	docker start $(CONTAINER)

## Stop the Magento container
stop:
	docker stop $(CONTAINER)

## Remove the Magento container
clean:
	-docker stop $(CONTAINER) 2>/dev/null
	-docker rm $(CONTAINER) 2>/dev/null

## Tail Two plugin logs
logs:
	docker exec $(CONTAINER) tail -f var/log/two/debug.log var/log/two/error.log

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
