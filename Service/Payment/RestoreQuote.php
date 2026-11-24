<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Payment;

use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
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
 * The restored cart has no payment method, so neither checkout preselects
 * the one that was not approved; it stays offered. Luma also remembers the
 * buyer's last choice in the browser's checkout storage, which Magento clears
 * when its `mage-cache-sessid` cookie is gone, as it does at login.
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
     * @var CookieManagerInterface
     */
    private $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @param Session $session
     * @param CartRepositoryInterface $cartRepository
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     */
    public function __construct(
        Session $session,
        CartRepositoryInterface $cartRepository,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory
    ) {
        $this->session = $session;
        $this->cartRepository = $cartRepository;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
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
        if (!$this->session->restoreQuote()) {
            return false;
        }
        $restored = $this->session->getQuote();
        $restored->removePayment();
        $this->cartRepository->save($restored);
        $this->forgetCheckoutChoices();
        return true;
    }

    /**
     * Have Luma drop the payment method it remembers in the browser.
     */
    private function forgetCheckoutChoices(): void
    {
        if ($this->cookieManager->getCookie('mage-cache-sessid')) {
            $metadata = $this->cookieMetadataFactory->createCookieMetadata();
            $metadata->setPath('/');
            $this->cookieManager->deleteCookie('mage-cache-sessid', $metadata);
        }
    }
}
