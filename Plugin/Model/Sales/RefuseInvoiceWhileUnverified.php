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
 * No offline Magento invoice for a Two order the buyer has not verified
 * (TWO-26294).
 *
 * A Two order waits in pending_payment until the buyer completes Two's
 * hosted verification and returns to the shop. Core lets that state be
 * invoiced, and when the method cannot capture (any fulfilment trigger but
 * "invoice") the admin form submits an offline capture, so core recorded the
 * invoice as Paid with no call to Two.
 *
 * Refused only in that case. Where the method can capture, the invoice
 * captures online through Two, which refuses an unverified order itself and
 * accepts a verified one still waiting here because the buyer never came
 * back, so core and Two decide. Refusing at canInvoice() removes the Invoice
 * button, and core's invoice controllers refuse a direct request with their
 * own translated "does not allow an invoice" message.
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
        if (!$payment || !$this->overlayRegistry->isTwoStackMethod((string)$payment->getMethod())) {
            return $result;
        }

        // The same question the admin invoice form asks before it falls back
        // to an offline capture.
        return (bool)$payment->canCapture();
    }
}
