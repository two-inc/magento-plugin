<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Two\Gateway\Api\BrandOverlayRegistryInterface;

/**
 * Observer to disable order confirmation email
 */
class SalesOrderPlaceAfter implements ObserverInterface
{
    private $overlayRegistry;

    public function __construct(BrandOverlayRegistryInterface $overlayRegistry)
    {
        $this->overlayRegistry = $overlayRegistry;
    }

    /**
     * @param Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        if ($this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod())) {
            $order->setCanSendNewEmailFlag(false);
        }

        return $this;
    }
}
