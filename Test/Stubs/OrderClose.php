<?php
/**
 * Stub for Magento's order cancel service, which OrderService closes an order
 * through (TWO-27389): the catch-all autoloader only produces method-less
 * interfaces, which PHPUnit cannot configure, so this declares the real
 * method, and the cookie manager the cart restore uses (TWO-27389). The cart
 * repository is deliberately left method-less: tests implement it with
 * anonymous classes.
 */
declare(strict_types=1);

namespace Magento\Sales\Api;

if (!interface_exists(OrderManagementInterface::class, false)) {
    interface OrderManagementInterface
    {
        /**
         * @param int $id
         * @return bool
         */
        public function cancel($id);
    }
}

namespace Magento\Framework\Stdlib;

if (!interface_exists(CookieManagerInterface::class, false)) {
    interface CookieManagerInterface
    {
        public function getCookie($name, $default = null);
        public function deleteCookie($name, $metadata = null);
    }
}

namespace Magento\Framework\Stdlib\Cookie;

if (!class_exists(CookieMetadataFactory::class, false)) {
    class CookieMetadataFactory
    {
        public function createCookieMetadata($metadata = [])
        {
            return new \Magento\Framework\DataObject($metadata);
        }

        public function createPublicCookieMetadata($metadata = [])
        {
            return new \Magento\Framework\DataObject($metadata);
        }
    }
}
