<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Payment;

use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\PendingOrderResolver as Resolver;

/**
 * Payment confirm controller
 *
 * This controller identifies the callback solely by the
 * `_two_order_reference` param (see OrderService::getOrderByReference()),
 * which is always checked — there is no separate session/token layer on
 * top of it to skip.
 *
 * What the order's state at Two means is decided by PendingOrderResolver,
 * which the scheduled check and the admin's Check status button share
 * (TWO-26300). This controller turns its outcome into the buyer's page.
 */
class Confirm extends Action
{
    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /**
     * @var OrderService
     */
    private $orderService;

    /** @var Resolver */
    private $pendingOrderResolver;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    public function __construct(
        Context $context,
        OrderService $orderService,
        Resolver $pendingOrderResolver,
        BrandRegistryInterface $brandRegistry,
        CheckoutSession $checkoutSession
    ) {
        $this->orderService = $orderService;
        $this->pendingOrderResolver = $pendingOrderResolver;
        $this->brandRegistry = $brandRegistry;
        $this->checkoutSession = $checkoutSession;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface
     * @throws Exception
     */
    public function execute()
    {
        $order = null;
        try {
            $order = $this->orderService->getOrderByReference();
            // A repeated return (a refresh, or the redirect sent twice) finds
            // the order an earlier request confirmed: show its success page
            // again and change nothing (TWO-27057).
            if ($this->orderService->isConfirmed($order)) {
                return $this->showSuccessPage($order);
            }
            // A replayed link changes nothing on an order that has moved on (TWO-27057).
            if (!$this->orderService->isAwaitingVerification($order)) {
                return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
            }
            $resolution = $this->pendingOrderResolver->resolve($order, Resolver::TRIGGER_BUYER_RETURN);
            $order = $resolution->getOrder();
            switch ($resolution->getOutcome()) {
                case Resolver::OUTCOME_CONFIRMED:
                case Resolver::OUTCOME_PROCESSED:
                    return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/onepage/success'));
                case Resolver::OUTCOME_ALREADY_PROCESSED:
                    // The scheduled check or the admin settled it first.
                    return $this->showSuccessPage($order);
                case Resolver::OUTCOME_NOT_WAITING:
                    return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
                case Resolver::OUTCOME_BUSY:
                    return $this->whileBusy($order);
                case Resolver::OUTCOME_CANCELLED:
                case Resolver::OUTCOME_DECLINED:
                    // Two will not take this order, so the buyer gets the cart
                    // back rather than a success page (TWO-26300).
                    throw new LocalizedException(__(
                        'Your invoice purchase with %1 has been cancelled. The cart will be restored.',
                        $this->brandRegistry->getProductName()
                    ));
                default:
                    throw new LocalizedException(__(
                        'Your invoice purchase with %1 could not be processed. The cart will be restored.',
                        $this->brandRegistry->getProductName()
                    ));
            }
        } catch (Exception $exception) {
            // The order comment keeps the full account for the merchant; the
            // buyer sees only a message meant for them (TWO-26295).
            $this->messageManager->addErrorMessage($this->orderService->failBuyerReturn($order, $exception));
            return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
        }
    }

    /**
     * The scheduled check or the admin still holds the order after the wait.
     * Show the success page if it has finished; otherwise leave the order
     * alone, since failing it now could undo a confirm still in flight.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return ResponseInterface
     */
    private function whileBusy($order)
    {
        $order = $this->orderService->reloadOrder($order);
        if ($this->orderService->isConfirmed($order)) {
            return $this->showSuccessPage($order);
        }
        $this->messageManager->addNoticeMessage(__(
            'Your order is still being confirmed with %1. You will receive an email once it is.',
            $this->brandRegistry->getProductName()
        ));
        return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
    }

    /**
     * Send the buyer to the success page for this order. The page reads the
     * last order and quote from the checkout session, so they are set from the
     * order the reference found, and from nothing else.
     *
     * @param \Magento\Sales\Model\Order $order
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
}
