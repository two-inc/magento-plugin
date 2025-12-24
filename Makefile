tag:
	eval $$(bumpver show --environ) && git tag abn-$${CURRENT_VERSION} -f && git push abn abn-$${CURRENT_VERSION} -f && git push -f
archive:
	eval $$(bumpver show --environ) && mkdir -p artifacts/$${CURRENT_VERSION} && git archive --format zip HEAD > artifacts/$${CURRENT_VERSION}/magento-abn-plugin.zip
publish: archive
	gsutil cp -r artifacts/* gs://achteraf-betalen/magento/ && ./scripts/publish-to-bucket.py
format:
	prettier -w view/frontend/web/js/
	prettier -w view/frontend/web/css/
	prettier -w view/frontend/web/template/
