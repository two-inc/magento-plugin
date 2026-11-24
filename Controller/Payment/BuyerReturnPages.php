<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Payment;

use Magento\Framework\App\ResponseInterface;
use Magento\Sales\Model\Order;

/**
 * The pages a buyer's return can end on whatever link brought them back: the
 * success page of an order Two holds, and a notice while its outcome is not
 * known yet (TWO-26300, TWO-27389).
 *
 * The using controller holds `$checkoutSession` and `$brandRegistry`.
 */
trait BuyerReturnPages
{
    /**
     * Send the buyer to the success page for this order. The page reads the
     * last order and quote from the checkout session, so they are set from the
     * order the reference found, and from nothing else.
     *
     * @param Order $order
     * @return ResponseInterface
     */
    private function showSuccessPage($order)
    {
        $this->checkoutSession->setLastQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastOrderId($order->getId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        $this->checkoutSession->setLastOrderStatus($order->getStatus());
        return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/onepage/success'));
    }

    /**
     * Neither a success page nor a restored cart: the order still stands,
     * and how it ends is not known yet.
     *
     * @return ResponseInterface
     */
    private function stillChecking()
    {
        $this->messageManager->addNoticeMessage(__(
            'Your order is still being checked with %1. The shop will confirm your order by email if it goes through.',
            $this->brandRegistry->getProductName()
        ));
        return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
    }
}
