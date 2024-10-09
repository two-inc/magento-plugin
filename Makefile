archive:
	eval $$(bumpver show --environ) && git archive --format zip HEAD > magento-abn-plugin-$${CURRENT_VERSION}.zip
format:
	prettier -w view/frontend/web/js/
	prettier -w view/frontend/web/css/
	prettier -w view/frontend/web/template/
