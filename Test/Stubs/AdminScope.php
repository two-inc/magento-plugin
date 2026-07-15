<?php
declare(strict_types=1);

/**
 * Minimal stubs for admin-scope resolution collaborators used by
 * config source models: the HTTP request (scope params), the store
 * manager, and the Product Tax Class option source.
 */

namespace Magento\Framework\App {
    if (!interface_exists(RequestInterface::class, false)) {
        interface RequestInterface
        {
            public function getParam($key, $defaultValue = null);
        }
    }
}

namespace Magento\Store\Api\Data {
    if (!interface_exists(StoreInterface::class, false)) {
        interface StoreInterface
        {
            public function getId();
        }
    }
    if (!interface_exists(WebsiteInterface::class, false)) {
        interface WebsiteInterface
        {
            public function getDefaultGroupId();
        }
    }
    if (!interface_exists(GroupInterface::class, false)) {
        interface GroupInterface
        {
            public function getDefaultStoreId();
        }
    }
}

namespace Magento\Store\Model {
    if (!interface_exists(StoreManagerInterface::class, false)) {
        interface StoreManagerInterface
        {
            public function getStore($storeId = null);

            public function getStores($withDefault = false, $codeKey = false);

            public function getWebsite($websiteId = null);

            public function getGroup($groupId = null);
        }
    }
}

namespace Magento\Tax\Model\TaxClass\Source {
    if (!class_exists(Product::class, false)) {
        class Product
        {
            public function getAllOptions($withEmpty = true)
            {
                return [];
            }
        }
    }
}
