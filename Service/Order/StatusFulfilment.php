<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Two\Gateway\Service\Order;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;
use Throwable;
use Two\Gateway\Api\BrandOverlayRegistryInterface;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Api\Log\RepositoryInterface as LogRepository;
use Two\Gateway\Api\OrderPostprocessingInterface as Postprocessing;
use Two\Gateway\Observer\InvoiceRegisteredOffline;
use Two\Gateway\Service\Api\Adapter;

/**
 * Fulfils a Two order with Two when it reaches a configured fulfil-on status
 * (fulfilment trigger "complete").
 *
 * Observer\SalesOrderSaveAfter checks the order and hands it here, and the
 * fulfilment runs after the outermost commit on the sales connection. Core's
 * refund routes save the order and its credit memo inside one transaction, so
 * by then the memo is saved too. The order is reloaded from the database and
 * fulfilled from saved state, and a failure is reported on the order rather
 * than thrown, since the merchant's save has already succeeded (TWO-26302).
 */
class StatusFulfilment
{
    /** @var ConfigRepository */
    private $configRepository;

    /** @var BrandRegistryInterface */
    private $brandRegistry;

    /** @var BrandOverlayRegistryInterface */
    private $overlayRegistry;

    /** @var Adapter */
    private $apiAdapter;

    /** @var OrderPostprocessor */
    private $orderPostprocessor;

    /** @var ComposeShipment */
    private $composeShipment;

    /** @var InvoiceService */
    private $invoiceService;

    /** @var TransactionFactory */
    private $transactionFactory;

    /** @var HistoryFactory */
    private $historyFactory;

    /** @var OrderStatusHistoryRepositoryInterface */
    private $orderStatusHistoryRepository;

    /** @var FulfilmentAttempts */
    private $attempts;

    /** @var OrderFactory */
    private $orderFactory;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var OrderResource */
    private $orderResource;

    /** @var MessageManager */
    private $messageManager;

    /** @var State */
    private $appState;

    /** @var LogRepository */
    private $logRepository;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        BrandOverlayRegistryInterface $overlayRegistry,
        Adapter $apiAdapter,
        OrderPostprocessor $orderPostprocessor,
        ComposeShipment $composeShipment,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        FulfilmentAttempts $attempts,
        OrderFactory $orderFactory,
        OrderRepositoryInterface $orderRepository,
        OrderResource $orderResource,
        MessageManager $messageManager,
        State $appState,
        LogRepository $logRepository
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->overlayRegistry = $overlayRegistry;
        $this->apiAdapter = $apiAdapter;
        $this->orderPostprocessor = $orderPostprocessor;
        $this->composeShipment = $composeShipment;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->historyFactory = $historyFactory;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->attempts = $attempts;
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->orderResource = $orderResource;
        $this->messageManager = $messageManager;
        $this->appState = $appState;
        $this->logRepository = $logRepository;
    }

    /**
     * Whether the order is a Two order in a fulfil-on status that Two has not
     * yet been told about.
     *
     * Idempotency: every successful fulfilment marks the payment
     * (this service, the shipment observer and Two::capture() alike), so later
     * saves of the same order are no-ops. An invoice alone is no evidence: the
     * merchant may have invoiced offline in Magento only, and Two must still
     * be told. Within one request an order sent once is not sent again, even
     * from a copy loaded before the marker was set.
     */
    public function isDue(Order $order): bool
    {
        $payment = $order->getPayment();
        if (!$payment
            || !$this->overlayRegistry->isTwoStackMethod((string)$payment->getMethod())
            || !$order->getTwoOrderId()
        ) {
            return false;
        }
        if ($this->configRepository->getFulfillTrigger() !== 'complete'
            || !in_array($order->getStatus(), $this->configRepository->getFulfillOrderStatusList())
        ) {
            return false;
        }

        return empty(((array)$payment->getAdditionalInformation())['marked_completed'])
            && !$this->attempts->wasAttempted((int)$order->getEntityId());
    }

    /**
     * @throws LocalizedException
     */
    public function assertWholeOrderShipped(Order $order): void
    {
        foreach ($order->getAllVisibleItems() as $orderItem) {
            // Refunded or cancelled quantity is not waiting to ship.
            $toShip = $orderItem->getQtyOrdered() - $orderItem->getQtyRefunded() - $orderItem->getQtyCanceled();
            if ($orderItem->getQtyShipped() < $toShip) {
                throw new LocalizedException(__(
                    "%1 requires whole order to be shipped before it can be fulfilled.",
                    $this->brandRegistry->getProductName()
                ));
            }
        }
    }

    /**
     * Fulfils once everything the current save is part of has committed: from
     * a commit callback, which core runs after the outermost commit on the
     * sales connection and drops on a rollback, or at once when no transaction
     * is open.
     */
    public function fulfilAfterCommit(int $orderId): void
    {
        if ($this->orderResource->getConnection()->getTransactionLevel() === 0) {
            $this->fulfilSaved($orderId);
            return;
        }
        $this->orderResource->addCommitCallback(function () use ($orderId): void {
            $this->fulfilSaved($orderId);
        });
    }

    /**
     * Reloads the order from the database, re-checks it and fulfils it. Never
     * throws: the merchant's save has already succeeded, so a failure becomes
     * an order comment, an admin message where there is an admin session, and
     * an error log line. The marker stays unset, so the next save of the order
     * in a fulfil-on status tries again.
     */
    private function fulfilSaved(int $orderId): void
    {
        $order = null;
        try {
            // A fresh instance, not the repository's: the repository hands back
            // the very instance the save was working on.
            $order = $this->orderFactory->create();
            $this->orderResource->load($order, $orderId);
            if (!$this->isDue($order)) {
                return;
            }
            $this->assertWholeOrderShipped($order);
            $response = $this->send($order);
        } catch (Throwable $e) {
            $this->report($orderId, $order, $e, 'StatusFulfilmentFailed', __(
                'Failed to fulfil order with %1. Reason: %2',
                $this->brandRegistry->getProductName(),
                $e->getMessage()
            ));
            return;
        }
        if ($response === null) {
            return;
        }

        try {
            $this->persist($order, $response);
        } catch (Throwable $e) {
            // Two has fulfilled the order: say so, rather than that it failed.
            $this->report($orderId, $order, $e, 'StatusFulfilmentNotSaved', __(
                '%1 fulfilled the order, but the order could not be saved. Reason: %2',
                $this->brandRegistry->getProductName(),
                $e->getMessage()
            ));
        }
    }

    /**
     * Sends the fulfilment to Two.
     *
     * @return array|null Two's response, or null when nothing was left to send
     * @throws LocalizedException when Two refuses
     */
    private function send(Order $order): ?array
    {
        // A merchant who invoiced in Magento can refund there before the order
        // is fulfilled with Two. Two is then told only what is left to pay
        // for, as a partial fulfilment; otherwise the whole order (TWO-26302).
        $payload = $this->hasRefunds($order)
            ? ['partial' => $this->composeShipment->executeNetOfRefunds($order)]
            : [];
        if (isset($payload['partial']) && empty($payload['partial']['line_items'])) {
            // Everything was refunded: nothing is left to fulfil.
            return null;
        }

        $this->attempts->markAttempted((int)$order->getEntityId());
        $response = $this->apiAdapter->execute(
            "/v1/order/" . $order->getTwoOrderId() . "/fulfillments",
            $this->orderPostprocessor->process(
                Postprocessing::REQUEST_CAPTURE,
                $payload,
                ['trigger' => 'status_change', 'endpoint' => '/v1/order/{id}/fulfillments', 'order' => $order]
            ),
            'POST',
            (int)$order->getStoreId()
        );

        $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
        if ($error) {
            throw new LocalizedException($error);
        }

        return $response;
    }

    /**
     * Records the accepted fulfilment: the marker on the payment, the comment,
     * the plugin's own invoice and the order with its invoiced totals, in one
     * transaction on the sales connection, so they persist or fail together.
     *
     * @throws Throwable
     */
    private function persist(Order $order, array $response): void
    {
        $connection = $this->orderResource->getConnection();
        $connection->beginTransaction();
        try {
            $comment = $this->markCompleted($order, $response);
            if ($comment !== null) {
                $this->addStatusToOrderHistory($order, $comment);
            }
            $this->saveInvoice($order, $response);
            $this->orderRepository->save($order);
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Two has invoiced the buyer; mirror with a Magento invoice. Use
     * CAPTURE_OFFLINE so we do not route back through Two::capture() and
     * re-post /fulfillments. Where the merchant already invoiced everything,
     * the invoice totals zero and none is created.
     */
    private function saveInvoice(Order $order, array $response): void
    {
        $invoice = $this->invoiceService->prepareInvoice($order);
        if ($invoice->getGrandTotal() <= 0) {
            return;
        }
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->setData(InvoiceRegisteredOffline::FULFILLED_WITH_PROVIDER, true);
        $invoice->register();
        $invoice->pay();
        $invoice->setTransactionId(
            $response['fulfilled_order']['id'] ?? $order->getPayment()->getLastTransId()
        );
        $this->transactionFactory->create()
            ->addObject($invoice)
            ->save();
    }

    /**
     * Whether anything on the order was refunded or cancelled in Magento.
     */
    private function hasRefunds(Order $order): bool
    {
        if ((float)$order->getShippingRefunded() > 0) {
            return true;
        }
        foreach ($order->getAllVisibleItems() as $orderItem) {
            if ((float)$orderItem->getQtyRefunded() > 0 || (float)$orderItem->getQtyCanceled() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sets the marker on the payment when Two returned the fulfilled order.
     *
     * @return string|null the order comment to add, if any
     */
    private function markCompleted(Order $order, array $response): ?string
    {
        if (empty($response['fulfilled_order'] ||
            empty($response['fulfilled_order']['id']))) {
            return null;
        }
        $additionalInformation = $order->getPayment()->getAdditionalInformation();
        $additionalInformation['marked_completed'] = true;
        $order->getPayment()->setAdditionalInformation($additionalInformation);

        return __(
            empty($response['remained_order'])
                ? '%1 order marked as completed.'
                : '%1 order marked as partially completed.',
            $this->brandRegistry->getProductName()
        )->render();
    }

    private function report(int $orderId, ?Order $order, Throwable $e, string $logType, Phrase $message): void
    {
        $this->logRepository->addErrorLog($logType, [
            'order_id' => $orderId,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ]);
        try {
            if ($order !== null && $order->getEntityId()) {
                $this->addStatusToOrderHistory($order, $message->render());
            }
            if ($this->isAdmin()) {
                $this->messageManager->addErrorMessage($message);
            }
        } catch (Throwable $reportError) {
            $this->logRepository->addErrorLog($logType, [
                'order_id' => $orderId,
                'report_error' => $reportError->getMessage(),
            ]);
        }
    }

    private function isAdmin(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function addStatusToOrderHistory(Order $order, string $comment): void
    {
        $history = $this->historyFactory->create();
        $history->setParentId($order->getEntityId())
            ->setComment($comment)
            ->setEntityName('order')
            ->setStatus($order->getStatus());
        $this->orderStatusHistoryRepository->save($history);
    }
}
