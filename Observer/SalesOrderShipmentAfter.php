<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Observer;

use Exception;
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
use Magento\Framework\DB\TransactionFactory;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Invoice\UploadService;
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

    /** @var BrandRegistryInterface */
    private $brandRegistry;

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
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * SalesOrderShipmentAfter constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Adapter $apiAdapter
     * @param HistoryFactory $historyFactory
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     * @param ComposeShipment $composeShipment
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     */
    /** @var \Two\Gateway\Api\BrandOverlayRegistryInterface */
    private $overlayRegistry;

    /** @var UploadService */
    private $invoiceUploadService;

    /** @var LogRepository */
    private $logRepository;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        Adapter $apiAdapter,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        ComposeShipment $composeShipment,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        \Two\Gateway\Api\BrandOverlayRegistryInterface $overlayRegistry,
        UploadService $invoiceUploadService,
        LogRepository $logRepository
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->apiAdapter = $apiAdapter;
        $this->historyFactory = $historyFactory;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->composeShipment = $composeShipment;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->overlayRegistry = $overlayRegistry;
        $this->invoiceUploadService = $invoiceUploadService;
        $this->logRepository = $logRepository;
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
        if (!$order
            || !$this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod())
            || !$order->getTwoOrderId()
        ) {
            return;
        }
        if ($this->configRepository->getFulfillTrigger() !== 'shipment') {
            // 'complete' trigger handles fulfilment in SalesOrderSaveAfter;
            // 'invoice' trigger goes through Two::capture(). Nothing to do
            // here for those.
            return;
        }

        $payload = [];
        $isWholeOrderShipped = $this->isWholeOrderShipped($order);
        $isPartialOrder = !$isWholeOrderShipped;
        while (true) {
            if ($isPartialOrder) {
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

        // Two has now invoiced the buyer. Create + pay the Magento invoice
        // to mirror that, but only on whole-order shipment. Partial Magento
        // invoicing is not in scope: we let the Two-side partial fulfilment
        // stand and create the Magento invoice on the final shipment.
        // CAPTURE_OFFLINE is critical — CAPTURE_ONLINE would route through
        // Two::capture() and post /fulfillments a second time.
        if ($isWholeOrderShipped) {
            if (!$order->hasInvoices()) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                if ($invoice->getGrandTotal() > 0) {
                    $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
                    $invoice->register();
                    $invoice->pay();
                    $invoice->setTransactionId(
                        $response['fulfilled_order']['id'] ?? $order->getPayment()->getLastTransId()
                    );
                    $this->transactionFactory->create()
                        ->addObject($invoice)
                        ->addObject($order)
                        ->save();
                }
            }

            // Self-invoice upload: gated solely on invoice_distributed_by_merchant
            // from GET /v1/merchant (TWO-25106, Option A — no admin toggle). This
            // only marks the order for upload; the actual render + 3-step upload
            // runs out-of-band via the ProcessInvoiceUploads cron so it never
            // blocks this request (see UploadService::queueForOrder). A missing
            // Magento invoice (e.g. a zero-grand-total order, skipped above) is
            // handled inside UploadService as a legitimate NOT_APPLICABLE, not
            // a failure — this call does not need to know whether one exists.
            //
            // Wrapped defensively: by this point Two has already been told the
            // order is fulfilled and the Magento invoice/shipment already
            // succeeded, so a transient failure writing the upload-queue status
            // (e.g. a DB lock-wait on this same row) must not surface as a
            // shipment-creation error (TWO-24758 review, Han).
            try {
                $twoInvoiceId = $response['fulfilled_order']['invoice_details']['id']
                    ?? $response['invoice_details']['id']
                    ?? null;
                $this->invoiceUploadService->queueForOrder(
                    $order,
                    is_string($twoInvoiceId) ? $twoInvoiceId : null
                );
            } catch (Exception $e) {
                $this->logRepository->addErrorLog(
                    'invoice-upload-queue-exception',
                    ['order_id' => $order->getEntityId(), 'error' => $e->getMessage()]
                );
            }
        }
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
                $this->brandRegistry->getProductName(),
            );
        } else {
            $comment = __(
                '%1 order marked as partially completed.',
                $this->brandRegistry->getProductName(),
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
