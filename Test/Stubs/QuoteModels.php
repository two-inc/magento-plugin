<?php
/**
 * Quote model stubs with the CartInterface relationship intact —
 * required before the catch-all autoloader so type hints against
 * CartInterface accept Quote mocks (the catch-all would otherwise
 * stub Quote as an empty class implementing nothing).
 *
 * Must be required BEFORE the catch-all autoloader in bootstrap.php;
 * if another stub for these classes appears later, the class_exists
 * guards silently resolve the collision by require order.
 */
declare(strict_types=1);

namespace Magento\Quote\Api\Data {
    if (!interface_exists(CartInterface::class, false)) {
        interface CartInterface
        {
        }
    }
}

namespace Magento\Store\Model {
    if (!class_exists(Store::class, false)) {
        class Store
        {
            public function getBaseCurrencyCode()
            {
                return null;
            }
        }
    }
}

namespace Magento\Quote\Model {
    if (!class_exists(Quote::class, false)) {
        class Quote implements \Magento\Quote\Api\Data\CartInterface
        {
            public function getGrandTotal()
            {
                return null;
            }

            public function getQuoteCurrencyCode()
            {
                return null;
            }

            public function getStoreId()
            {
                return null;
            }

            public function getStore()
            {
                return null;
            }
        }
    }
}
