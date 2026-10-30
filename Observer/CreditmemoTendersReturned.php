<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Throwable;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Service\Order\ComposeRefund;

/**
 * Tells the merchant when a credit memo returned tenders that a fulfilled
 * part had already declared to Two as prepaid (TWO-26309). A memo refunded
 * offline never reaches Two, so Two still holds that part as paid and cannot
 * raise what the buyer owes on it: the amount is not collected through Two.
 * Logged as an error and noted on the order, never refused: the memo is the
 * merchant's to make.
 */
class CreditmemoTendersReturned implements ObserverInterface
{
    /**
     * @var ComposeRefund
     */
    private $orderService;

    /**
     * @var BrandOverlayRegistryInterface
     */
    private $overlayRegistry;

    /**
     * @var BrandRegistryInterface
     */
    private $brandRegistry;

    /**
     * @var LogRepository
     */
    private $logRepository;

    /**
     * @var HistoryFactory
     */
    private $historyFactory;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private $historyRepository;

    public function __construct(
        ComposeRefund $orderService,
        BrandOverlayRegistryInterface $overlayRegistry,
        BrandRegistryInterface $brandRegistry,
        LogRepository $logRepository,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $historyRepository
    ) {
        $this->orderService = $orderService;
        $this->overlayRegistry = $overlayRegistry;
        $this->brandRegistry = $brandRegistry;
        $this->logRepository = $logRepository;
        $this->historyFactory = $historyFactory;
        $this->historyRepository = $historyRepository;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getCreditmemo();
        $order = $creditmemo instanceof Creditmemo ? $creditmemo->getOrder() : null;
        if (!$order || !$order->getTwoOrderId() || $this->orderService->getTenderTotal($creditmemo) <= 0
            || !$this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod())
        ) {
            return;
        }
        try {
            $uncollected = $this->orderService->uncollectedPrepaid($order);
        } catch (Throwable $e) {
            // The memo stands either way; only the account of it is missing.
            $this->logRepository->addErrorLog('TendersReturnedUnchecked', [
                'order' => $order->getIncrementId(),
                'exception' => get_class($e),
            ]);
            return;
        }
        if ($uncollected <= 0) {
            return;
        }
        $amount = number_format($uncollected / 100, 2, '.', '');
        $currency = (string)$order->getOrderCurrencyCode();
        $this->logRepository->addErrorLog('TendersReturnedAfterDeclared', [
            'order' => $order->getIncrementId(),
            'uncollected' => $amount,
            'currency' => $currency,
        ]);
        $comment = __(
            'This credit memo returned to a gift card or store credit %1 %2 that %3 already holds as paid on a fulfilled part of this order. %3 cannot raise what the buyer owes on that part, so %1 %2 will not be collected through %3.',
            $currency,
            $amount,
            $this->brandRegistry->getProductName()
        )->render();
        try {
            $history = $this->historyFactory->create();
            $history->setParentId($order->getEntityId())
                ->setComment($comment)
                ->setEntityName('order')
                ->setIsCustomerNotified(false)
                ->setStatus($order->getStatus());
            $this->historyRepository->save($history);
        } catch (Throwable $e) {
            $this->logRepository->addErrorLog('TendersReturnedCommentFailed', get_class($e));
        }
    }
}
