<?php
/**
 * Admin system-message surface. The catch-all autoloader in
 * Test/bootstrap.php produces a constant-less interface and a method-less
 * URL builder, neither of which the message class can be exercised against.
 */
declare(strict_types=1);

namespace Magento\Framework\Notification {
    if (!interface_exists(MessageInterface::class, false)) {
        interface MessageInterface
        {
            public const SEVERITY_CRITICAL = 1;
            public const SEVERITY_MAJOR = 2;
            public const SEVERITY_MINOR = 3;
            public const SEVERITY_NOTICE = 4;

            public function getIdentity();

            public function isDisplayed();

            public function getText();

            public function getSeverity();
        }
    }
}

namespace Magento\Backend\Model {
    if (!interface_exists(UrlInterface::class, false)) {
        interface UrlInterface
        {
            /**
             * @param string|null $routePath
             * @param array|null $routeParams
             * @return string
             */
            public function getUrl($routePath = null, $routeParams = null);
        }
    }
}
