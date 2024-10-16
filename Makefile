archive:
	eval $$(bumpver show --environ) && git archive --format zip HEAD > magento-plugin-$${CURRENT_VERSION}.zip
format:
	prettier -w view/frontend/web/js/
	prettier -w view/frontend/web/css/
	prettier -w view/frontend/web/template/
