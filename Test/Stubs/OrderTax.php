<?php
/**
 * Magento's order-tax read surface, with real method signatures so tests can
 * configure them — the catch-all autoloader's method-less interfaces cannot
 * be stubbed by PHPUnit. This is where the shipping line's declared tax rate
 * comes from (Service\Order::getTaxRateShipping).
 */
declare(strict_types=1);

namespace Magento\Tax\Api\Data {
    if (!interface_exists(OrderTaxDetailsAppliedTaxInterface::class, false)) {
        interface OrderTaxDetailsAppliedTaxInterface
        {
            public function getCode();

            public function getTitle();

            public function getPercent();

            public function getAmount();
        }
    }

    if (!interface_exists(OrderTaxDetailsItemInterface::class, false)) {
        interface OrderTaxDetailsItemInterface
        {
            public function getType();

            public function getItemId();

            public function getAssociatedItemId();

            public function getAppliedTaxes();
        }
    }

    if (!interface_exists(OrderTaxDetailsInterface::class, false)) {
        interface OrderTaxDetailsInterface
        {
            public function getAppliedTaxes();

            public function getItems();
        }
    }
}

namespace Magento\Tax\Api {
    if (!interface_exists(OrderTaxManagementInterface::class, false)) {
        interface OrderTaxManagementInterface
        {
            public function getOrderTaxDetails($orderId);
        }
    }
}

namespace Magento\Tax\Model\ResourceModel\Sales\Order\Tax {
    if (!class_exists(Collection::class, false)) {
        class Collection implements \IteratorAggregate
        {
            public function loadByOrder($order)
            {
                return $this;
            }

            public function getIterator(): \Traversable
            {
                return new \ArrayIterator([]);
            }
        }
    }

    if (!class_exists(CollectionFactory::class, false)) {
        class CollectionFactory
        {
            public function create()
            {
                return new Collection();
            }
        }
    }
}

namespace Magento\Tax\Model\Sales\Total\Quote {
    if (!class_exists(CommonTaxCollector::class, false)) {
        class CommonTaxCollector
        {
            public const ITEM_TYPE_SHIPPING = 'shipping';
            public const ITEM_TYPE_PRODUCT = 'product';
        }
    }
}
