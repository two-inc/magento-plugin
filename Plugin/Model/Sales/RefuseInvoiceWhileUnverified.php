<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Plugin\Model\Sales;

use Magento\Sales\Model\Order;
use Two\Gateway\Api\BrandOverlayRegistryInterface;

/**
 * No Magento invoice for a Two order the buyer has not verified (TWO-26294).
 *
 * A Two order waits in pending_payment until the buyer completes Two's
 * hosted verification. Core lets that state be invoiced, and unless the
 * fulfilment trigger is "invoice" this method cannot capture, so the admin
 * form submits an offline capture and core records the invoice as Paid with
 * no call to Two. The order would then read as paid while Two has neither
 * verified nor invoiced it.
 *
 * Refused at canInvoice() so the Invoice button goes, alongside the Ship and
 * Credit Memo buttons the order toolbar plugin already removes in this state,
 * and core's own invoice controllers refuse a direct request with their
 * translated "does not allow an invoice" message. Once the buyer verifies,
 * the order moves to processing and invoicing follows the fulfilment trigger
 * as before.
 */
class RefuseInvoiceWhileUnverified
{
    /** @var BrandOverlayRegistryInterface */
    private $overlayRegistry;

    public function __construct(BrandOverlayRegistryInterface $overlayRegistry)
    {
        $this->overlayRegistry = $overlayRegistry;
    }

    /**
     * @param Order $subject
     * @param bool $result
     * @return bool
     */
    public function afterCanInvoice(Order $subject, $result)
    {
        if (!$result || $subject->getState() !== Order::STATE_PENDING_PAYMENT) {
            return $result;
        }
        $payment = $subject->getPayment();
        if ($payment && $this->overlayRegistry->isTwoStackMethod((string)$payment->getMethod())) {
            return false;
        }

        return $result;
    }
}
