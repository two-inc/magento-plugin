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

            /**
             * Declared so tests can configure the display-currency lookup
             * Model\Ui\ConfigProvider::getCurrencySymbol() makes.
             *
             * @return \Magento\Directory\Model\Currency|null
             */
            public function getCurrentCurrency()
            {
                return null;
            }

            public function getCurrentCurrencyCode()
            {
                return null;
            }
        }
    }
    // StoreManagerInterface itself is stubbed in AdminScope.php, which
    // already declares getStore(); do not redeclare it here — this file
    // loads first, so a partial copy would win and strip the rest.
}

namespace Magento\Directory\Model {
    if (!class_exists(Currency::class, false)) {
        class Currency
        {
            public function getCurrencySymbol()
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

            public function getAllAddresses()
            {
                return [];
            }

            /**
             * Declared (rather than left to the catch-all) so tests can
             * configure it: Model\Ui\ConfigProvider resolves the quote's
             * billing country through it.
             *
             * @return \Magento\Quote\Model\Quote\Address|null
             */
            public function getBillingAddress()
            {
                return null;
            }
        }
    }
}

namespace Magento\Quote\Model\Quote {
    if (!class_exists(Address::class, false)) {
        class Address
        {
            public function getCountryId()
            {
                return null;
            }
        }
    }
}
