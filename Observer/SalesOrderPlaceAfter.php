<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use ABN\Gateway\Model\Two;

/**
 * Observer to disable order confirmation email
 */
class SalesOrderPlaceAfter implements ObserverInterface
{

    /**
     * @param Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer)
    {
        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        if ($order->getPayment()->getMethod() == Two::CODE) {
            $order->setCanSendNewEmailFlag(false);
        }

        return $this;
    }
}
