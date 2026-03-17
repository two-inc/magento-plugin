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

.PHONY: help install configure run stop clean logs archive patch minor major format

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
		-v $(CURDIR):/data/extensions/workdir \
		$(IMAGE):$(TAG)
	@echo "Waiting for Magento to start..."
	@until docker exec $(CONTAINER) php bin/magento --version 2>/dev/null; do sleep 3; done
	docker exec $(CONTAINER) composer require two-inc/magento2:@dev --no-plugins
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
		$(CONTAINER) php /data/extensions/workdir/dev/configure.php
	docker exec $(CONTAINER) php bin/magento cache:flush

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
## Format frontend assets with Prettier
format:
	prettier -w view/frontend/web/js/
	prettier -w view/frontend/web/css/
	prettier -w view/frontend/web/template/
