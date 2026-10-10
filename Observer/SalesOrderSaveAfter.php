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
use Two\Gateway\Service\Order\FulfilmentDeferral;
use Two\Gateway\Service\Order\StatusFulfilment;

/**
 * After Order Save Observer
 *
 * Fulfils a Two order with Two when it reaches a configured fulfil-on status.
 * The whole-order check refuses the save here, as before; the fulfilment itself
 * waits until the save is complete (TWO-26302):
 * - inside a refund call, the order is queued and the refund plugins fulfil
 *   it once the call returns, when the order and its credit memo are saved;
 * - otherwise it runs after the outermost commit on the sales connection, or
 *   at once when no transaction is open.
 */
class SalesOrderSaveAfter implements ObserverInterface
{
    /** @var StatusFulfilment */
    private $statusFulfilment;

    /** @var FulfilmentDeferral */
    private $deferral;

    public function __construct(StatusFulfilment $statusFulfilment, FulfilmentDeferral $deferral)
    {
        $this->statusFulfilment = $statusFulfilment;
        $this->deferral = $deferral;
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

        if ($this->deferral->isInsideRefund()) {
            $this->deferral->queue((int)$order->getEntityId());
            return;
        }

        $this->statusFulfilment->fulfilAfterCommit($order);
    }
}
