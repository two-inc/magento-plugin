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
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeShipment;

/**
 * After Order Shipment Save Observer
 * Fulfill Two paymemt after order shipped
 */
class SalesOrderShipmentAfter implements ObserverInterface
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
     * @var ComposeShipment
     */
    private $composeShipment;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var Transaction
     */
    private $transaction;

    /**
     * SalesOrderShipmentAfter constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Adapter $apiAdapter
     * @param HistoryFactory $historyFactory
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     * @param ComposeShipment $composeShipment
     * @param InvoiceService $invoiceService
     * @param Transaction $transaction
     */
    public function __construct(
        ConfigRepository $configRepository,
        Adapter $apiAdapter,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        ComposeShipment $composeShipment,
        InvoiceService $invoiceService,
        Transaction $transaction
    ) {
        $this->configRepository = $configRepository;
        $this->apiAdapter = $apiAdapter;
        $this->historyFactory = $historyFactory;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->composeShipment = $composeShipment;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
    }

    /**
     * @param Observer $observer
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        /** @var Order\Shipment $shipment */
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        if ($order
            && $order->getPayment()->getMethod() === Two::CODE
            && $order->getTwoOrderId()
        ) {
            if ($this->configRepository->getFulfillTrigger() == 'shipment') {
                $payload = [];
                $isWholeOrderShipped = $this->isWholeOrderShipped($order);
                $isPartialOrder = !$isWholeOrderShipped;
                while (true) {
                    if ($isPartialOrder) {
                        // partial fulfilment
                        $payload = [
                            'partial' => $this->composeShipment->execute($shipment, $order),
                        ];
                    }
                    $response = $this->apiAdapter->execute(
                        "/v1/order/" . $order->getTwoOrderId() . "/fulfillments",
                        $payload
                    );

                    $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);

                    if ($error) {
                        if ($response['error_code'] == 'PARTIAL_ORDER_MISSING_DATA') {
                            $isPartialOrder = true;
                            continue;
                        }
                        throw new LocalizedException($error);
                    }
                    break;
                }

                $this->parseFulfillResponse($response, $order);
                if ($isWholeOrderShipped && !$order->hasInvoices()) {
                    $this->createOfflinePaidInvoice($order);
                }
            }
        }
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
