<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Exception;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;

/**
 * After Order Save Observer
 * Fulfill Two paymemt after order saved
 */
class SalesOrderSaveAfter implements ObserverInterface
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * @var HistoryFactory
     */
    private $historyFactory;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private $orderStatusHistoryRepository;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var Transaction
     */
    private $transaction;

    /**
     * SalesOrderSaveAfter constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Adapter $apiAdapter
     * @param HistoryFactory $historyFactory
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     * @param InvoiceService $invoiceService
     * @param Transaction $transaction
     */
    public function __construct(
        ConfigRepository $configRepository,
        Adapter $apiAdapter,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        InvoiceService $invoiceService,
        Transaction $transaction
    ) {
        $this->configRepository = $configRepository;
        $this->apiAdapter = $apiAdapter;
        $this->historyFactory = $historyFactory;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
    }

    /**
     * @param Observer $observer
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order
            || $order->getPayment()->getMethod() !== Two::CODE
            || !$order->getTwoOrderId()
        ) {
            return;
        }

        if ($this->configRepository->getFulfillTrigger() !== 'complete'
            || !in_array($order->getStatus(), $this->configRepository->getFulfillOrderStatusList())
        ) {
            return;
        }

        // Idempotency gate: once we've created a Magento invoice, the order is
        // already fulfilled on Two — do not re-post /fulfillments on subsequent
        // saves of the same order.
        if ($order->hasInvoices()) {
            return;
        }

        if (!$this->isWholeOrderShipped($order)) {
            $error = __(
                "%1 requires whole order to be shipped before it can be fulfilled.",
                $this->configRepository::PRODUCT_NAME
            );
            throw new LocalizedException($error);
        }

        $response = $this->apiAdapter->execute(
            "/v1/order/" . $order->getTwoOrderId() . "/fulfillments"
        );

        $this->parseFulfillResponse($response, $order);

        $this->createOfflinePaidInvoice($order);
    }

    /**
     * Create a Magento invoice for the whole order and mark it paid.
     *
     * Two has already been told to fulfil the order via /fulfillments above,
     * so this invoice records that fact in Magento — CAPTURE_OFFLINE prevents
     * Two::capture() from re-posting /fulfillments.
     *
     * @param Order $order
     * @throws \Exception
     */
    private function createOfflinePaidInvoice(Order $order): void
    {
        $invoice = $this->invoiceService->prepareInvoice($order);
        if ($invoice->getGrandTotal() <= 0) {
            return;
        }
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->pay();
        $invoice->setTransactionId($order->getPayment()->getLastTransId());
        $this->transaction
            ->addObject($invoice)
            ->addObject($invoice->getOrder())
            ->save();
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    private function isWholeOrderShipped(OrderInterface $order): bool
    {
        foreach ($order->getAllVisibleItems() as $orderItem) {
            /** @var Order\Item $orderItem */
            if ($orderItem->getQtyShipped() < $orderItem->getQtyOrdered()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $response
     * @param Order $order
     * @return void
     * @throws Exception
     */
    private function parseFulfillResponse(array $response, Order $order): void
    {
        $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);

        if ($error) {
            throw new LocalizedException($error);
        }

        if (empty($response['fulfilled_order'] ||
            empty($response['fulfilled_order']['id']))) {
            return;
        }
        $additionalInformation = $order->getPayment()->getAdditionalInformation();
        $additionalInformation['marked_completed'] = true;

        $order->getPayment()->setAdditionalInformation($additionalInformation);

        if (empty($response['remained_order'])) {
            $comment = __(
                '%1 order marked as completed.',
                $this->configRepository::PROVIDER,
            );
        } else {
            $comment = __(
                '%1 order marked as partially completed.',
                $this->configRepository::PROVIDER,
            );
        }

        $this->addStatusToOrderHistory($order, $comment->render());
    }

    /**
     * @param Order $order
     * @param string $comment
     * @throws Exception
     */
    private function addStatusToOrderHistory(Order $order, string $comment)
    {
        $history = $this->historyFactory->create();
        $history->setParentId($order->getEntityId())
            ->setComment($comment)
            ->setEntityName('order')
            ->setStatus($order->getStatus());
        $this->orderStatusHistoryRepository->save($history);
    }
}
