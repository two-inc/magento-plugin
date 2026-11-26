<?php
/**
 * Stub for Magento's order cancel service, which OrderService closes an order
 * through (TWO-27389): the catch-all autoloader only produces method-less
 * interfaces, which PHPUnit cannot configure, so this declares the real
 * method. The cart repository is deliberately left method-less: tests
 * implement it with anonymous classes.
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
