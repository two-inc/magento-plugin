<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

namespace ABN\Gateway\Plugin\Model\Sales;

use Magento\Sales\Model\Order;
use ABN\Gateway\Model\Two;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * AfterPlaceOrder Plugin
 * Set Two orders to status pending after place
 */
class AfterPlaceOrder
{
    private $orderRepository;

    public function __construct(
        OrderRepositoryInterface $orderRepository
    ) {
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param Order $subject
     * @param Order $order
     * @return Order
     */
    public function afterPlace(Order $subject, Order $order)
    {
        if ($order->getPayment()->getMethod() == Two::CODE) {
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->setStatus(Two::STATUS_PENDING);
            $this->orderRepository->save($order);
        }
        return $order;
    }
}
