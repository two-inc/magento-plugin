<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Two\Gateway\Service\Order\StatusFulfilment;

/**
 * After Order Save Observer
 *
 * Fulfils a Two order with Two when it reaches a configured fulfil-on status.
 * The whole-order check refuses the save here, as before; the fulfilment itself
 * runs after the outermost commit on the sales connection, so it sees every
 * row saved in the same transaction, such as a credit memo (TWO-26302).
 */
class SalesOrderSaveAfter implements ObserverInterface
{
    /** @var StatusFulfilment */
    private $statusFulfilment;

    public function __construct(StatusFulfilment $statusFulfilment)
    {
        $this->statusFulfilment = $statusFulfilment;
    }

    /**
     * @param Observer $observer
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !$this->statusFulfilment->isDue($order)) {
            return;
        }

        $this->statusFulfilment->assertWholeOrderShipped($order);
        $this->statusFulfilment->fulfilAfterCommit((int)$order->getEntityId());
    }
}
