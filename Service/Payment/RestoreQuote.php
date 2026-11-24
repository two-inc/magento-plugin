<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Payment;

use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Gives a buyer back the cart of an order that did not go through, so they
 * can pay for the same basket another way (TWO-27389).
 *
 * Only the browser that placed the order gets it back: the checkout session's
 * last order must be this order, and the order's own quote must still be
 * reserved for it (a quote already restored, or reused for another order, is
 * left alone). Another browser or device gets nothing, so a buyer's cart is
 * never attached to someone else's session.
 *
 * Stock is not touched here: closing the order through Magento's cancel
 * returns it, once.
 */
class RestoreQuote
{
    /**
     * @var Session
     */
    private $session;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @param Session $session
     * @param CartRepositoryInterface $cartRepository
     */
    public function __construct(
        Session $session,
        CartRepositoryInterface $cartRepository
    ) {
        $this->session = $session;
        $this->cartRepository = $cartRepository;
    }

    /**
     * Restore the order's cart into this browser's session.
     *
     * @param Order $order
     * @return bool whether the cart came back
     */
    public function execute(Order $order): bool
    {
        $incrementId = (string)$order->getIncrementId();
        if ($incrementId === '' || (string)$this->session->getLastRealOrderId() !== $incrementId) {
            return false;
        }
        try {
            $quote = $this->cartRepository->get((int)$order->getQuoteId());
        } catch (NoSuchEntityException $exception) {
            return false;
        }
        if ((string)$quote->getReservedOrderId() !== $incrementId) {
            return false;
        }
        // Magento's own restore: the last real order's quote, which the checks
        // above have shown is this order's, made active and current again.
        return (bool)$this->session->restoreQuote();
    }
}
