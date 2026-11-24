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
use Magento\Sales\Model\Order;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Service\Payment\OrderService;
use Two\Gateway\Service\Payment\PendingOrderResolver as Resolver;

/**
 * Cancel return controller: where Two's checkout sends a buyer it did not
 * approve, or who left verification (TWO-27389).
 *
 * That buyer wants to pay for the same basket another way, so the click is
 * not taken as a cancel. What Two reports for the order decides, through the
 * resolver the confirm return and the scheduled check share: an order Two
 * holds is processed as a confirm return is, and any other is closed. The
 * basket comes back on the checkout's payment step in the browser that placed
 * the order; any other browser gets the message and nothing else.
 */
class Cancel extends Action
{
    use BuyerReturnPages;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var OrderService */
    private $orderService;

    /** @var Resolver */
    private $pendingOrderResolver;

    /** @var CheckoutSession */
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
     */
    public function execute()
    {
        $order = null;
        try {
            $order = $this->orderService->getOrderByReference();
        } catch (Exception $exception) {
            // The buyer sees only a message meant for them (TWO-26295).
            $this->messageManager->addErrorMessage($this->orderService->failBuyerReturn($order, $exception));
            return $this->toCart();
        }
        if (!$this->orderService->isAwaitingVerification($order)) {
            // A repeated or late click changes nothing (TWO-27057).
            return $this->settled($order);
        }
        $resolution = $this->pendingOrderResolver->resolve($order, Resolver::TRIGGER_BUYER_DECLINE);
        $order = $resolution->getOrder();
        switch ($resolution->getOutcome()) {
            case Resolver::OUTCOME_CONFIRMED:
            case Resolver::OUTCOME_PROCESSED:
            case Resolver::OUTCOME_ALREADY_PROCESSED:
                return $this->showSuccessPage($order);
            case Resolver::OUTCOME_CANCELLED:
            case Resolver::OUTCOME_DECLINED:
            case Resolver::OUTCOME_UNVERIFIED:
                return $this->toPaymentStep($order);
            case Resolver::OUTCOME_NOT_WAITING:
                return $this->settled($order);
            case Resolver::OUTCOME_BUSY:
                return $this->settled($this->orderService->reloadOrder($order));
            case Resolver::OUTCOME_FAILED:
                if ($resolution->getBuyerMessage() !== '') {
                    // Two refused the cancel: the order and the cart stay as
                    // they are (TWO-27057).
                    $this->messageManager->addErrorMessage($resolution->getBuyerMessage());
                    return $this->toCart();
                }
                return $this->stillChecking();
            default:
                return $this->stillChecking();
        }
    }

    /**
     * An order no longer waiting for the buyer: its success page once Two
     * holds it, the payment step once it is closed (for example by the
     * scheduled check, or by an earlier click), and otherwise nothing.
     *
     * @param Order $order
     * @return ResponseInterface
     */
    private function settled(Order $order)
    {
        if ($this->orderService->isConfirmed($order)) {
            return $this->showSuccessPage($order);
        }
        if ($order->getState() === Order::STATE_CANCELED) {
            return $this->toPaymentStep($order);
        }
        if ($this->orderService->isAwaitingVerification($order)) {
            return $this->stillChecking();
        }
        return $this->toCart();
    }

    /**
     * The order is closed: tell the buyer, and give the basket back on the
     * payment step when this browser placed the order. The cart comes back at
     * most once; a later click finds it already in this browser's session.
     *
     * @param Order $order
     * @return ResponseInterface
     */
    private function toPaymentStep(Order $order)
    {
        $this->messageManager->addErrorMessage(__(
            '%1 could not approve this purchase. Please choose another payment method.',
            $this->brandRegistry->getProductName()
        ));
        $restored = $this->orderService->restoreQuote($order);
        if ($restored || (int)$this->checkoutSession->getQuoteId() === (int)$order->getQuoteId()) {
            return $this->getResponse()->setRedirect(
                $this->_url->getUrl('checkout', ['_fragment' => 'payment'])
            );
        }
        return $this->toCart();
    }

    /**
     * @return ResponseInterface
     */
    private function toCart()
    {
        return $this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
    }
}
