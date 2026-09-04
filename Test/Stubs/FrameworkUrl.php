<?php
/**
 * URL builder stub with a real getUrl() signature, so ComposeOrder's
 * merchant_urls construction can be exercised. The catch-all autoloader in
 * Test/bootstrap.php produces a method-less class, which PHPUnit cannot
 * configure via ->method(); this gives it something to override.
 */
declare(strict_types=1);

namespace Magento\Framework {
    if (!class_exists(Url::class, false)) {
        class Url
        {
            /**
             * @param string $routePath
             * @param array $routeParams
             * @return string
             */
            public function getUrl($routePath = null, $routeParams = null)
            {
                return '';
            }
        }
    }
}
