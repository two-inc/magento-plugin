<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Throwable;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Payment\PendingOrderResolver as Resolver;

/**
 * The order view's Check status button: asks Two about one order still
 * waiting for the buyer and settles it straight away, at any age, as the
 * scheduled check would (TWO-26300).
 */
class CheckStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Two_Gateway::check_pending_order';

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var Resolver */
    private $resolver;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var BrandOverlayRegistryInterface */
    private $overlayRegistry;

    /** @var LogRepository */
    private $logRepository;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        Resolver $resolver,
        BrandRegistryInterface $brandRegistry,
        BrandOverlayRegistryInterface $overlayRegistry,
        LogRepository $logRepository
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->resolver = $resolver;
        $this->brandRegistry = $brandRegistry;
        $this->overlayRegistry = $overlayRegistry;
        $this->logRepository = $logRepository;
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
        $product = $this->brandRegistry->getProductName();
        try {
            $order = $this->orderRepository->get($orderId);
            if (!$order->getTwoOrderId()
                || !$this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod())
            ) {
                $this->messageManager->addErrorMessage(__('This order was not placed with %1.', $product));
                return $redirect;
            }
            $user = $this->_auth->getUser();
            $resolution = $this->resolver->resolve(
                $order,
                Resolver::TRIGGER_ADMIN,
                $user ? (string)$user->getUserName() : ''
            );
            switch ($resolution->getOutcome()) {
                case Resolver::OUTCOME_BUSY:
                    $this->messageManager->addNoticeMessage(__(
                        'The order is being checked with %1 by another process. Try again in a minute.',
                        $product
                    ));
                    break;
                case Resolver::OUTCOME_ALREADY_PROCESSED:
                case Resolver::OUTCOME_NOT_WAITING:
                    $this->messageManager->addNoticeMessage(__(
                        'This order is no longer waiting for the buyer, so it was not checked.'
                    ));
                    break;
                case Resolver::OUTCOME_FAILED:
                    $this->messageManager->addErrorMessage($resolution->getMessage());
                    break;
                default:
                    $this->messageManager->addSuccessMessage(__(
                        '%1 order state: %2. %3',
                        $product,
                        $resolution->getTwoState() !== '' ? $resolution->getTwoState() : 'undefined',
                        $resolution->getMessage()
                    ));
            }
        } catch (Throwable $exception) {
            $this->logRepository->addErrorLog('pending-order-admin-check-failed', [
                'order_id' => $orderId,
                'message' => $exception->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(__(
                'Could not check the order with %1: %2',
                $product,
                $exception->getMessage()
            ));
        }
        return $redirect;
    }
}
