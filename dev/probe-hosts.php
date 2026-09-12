#!/usr/bin/env php
<?php
/**
 * Print the API / checkout-page hosts the running Magento instance is
 * actually configured to hit, by resolving Model\Config\Repository's own
 * getters. Piped through dev/print-resolved-hosts.sh for formatting.
 */

require 'app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();

$repository = $obj->get(\Two\Gateway\Api\Config\RepositoryInterface::class);

echo "getCheckoutApiUrl(): " . $repository->getCheckoutApiUrl() . "\n";
echo "getCheckoutPageUrl(): " . $repository->getCheckoutPageUrl() . "\n";
