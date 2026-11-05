<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Hands the payment the invoice it is about to capture (TWO-26278).
 *
 * Core captures online from inside Invoice::register(), before the invoice is
 * saved, and calls the method's capture() with the payment and an amount only.
 * The order's invoice collection is read from the database, so it holds only
 * the invoices saved before this one. Core names the invoice on
 * sales_order_payment_capture, dispatched just before capture(); this keeps it
 * on the payment for Two::capture() to compose from.
 */
class PaymentCaptureInvoice implements ObserverInterface
{
    /** Payment data key holding the invoice being captured. Not persisted. */
    public const INVOICE = 'two_capture_invoice';

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $event = $observer->getEvent();
        $event->getPayment()->setData(self::INVOICE, $event->getInvoice());
    }
}
