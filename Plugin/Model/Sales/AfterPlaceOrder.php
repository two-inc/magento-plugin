<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Two\Gateway\Plugin\Model\Sales;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Model\Two;

/**
 * AfterPlaceOrder Plugin
 * Set Two-stack orders (parent-brand `two_payment` + any registered
 * brand overlays such as `acme_payment`) to status pending after place.
 */
class AfterPlaceOrder
{
    private $orderRepository;
    private $overlayRegistry;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        BrandOverlayRegistryInterface $overlayRegistry
    ) {
        $this->orderRepository = $orderRepository;
        $this->overlayRegistry = $overlayRegistry;
    }

    /**
     * @param Order $subject
     * @param Order $order
     * @return Order
     */
    public function afterPlace(Order $subject, Order $order)
    {
        if ($this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod())) {
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->setStatus(Two::STATUS_PENDING);
            $this->orderRepository->save($order);
        }
        return $order;
    }
}
