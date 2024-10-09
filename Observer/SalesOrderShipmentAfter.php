<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace ABN\Gateway\Observer;

use Exception;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use ABN\Gateway\Model\Two;
use ABN\Gateway\Service\Api\Adapter;
use ABN\Gateway\Service\Order\ComposeShipment;

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
     * SalesOrderShipmentAfter constructor.
     *
     * @param ConfigRepository $configRepository
     * @param Adapter $apiAdapter
     * @param HistoryFactory $historyFactory
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     * @param ComposeShipment $composeShipment
     */
    public function __construct(
        ConfigRepository $configRepository,
        Adapter $apiAdapter,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        ComposeShipment $composeShipment
    ) {
        $this->configRepository = $configRepository;
        $this->apiAdapter = $apiAdapter;
        $this->historyFactory = $historyFactory;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->composeShipment = $composeShipment;
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
                if ($isWholeOrderShipped) {
                    foreach ($order->getInvoiceCollection() as $invoice) {
                        $invoice->pay();
                        $invoice->setTransactionId($order->getPayment()->getLastTransId());
                        $invoice->save();
                    }
                }
            } elseif ($this->configRepository->getFulfillTrigger() == 'complete') {
                foreach ($order->getInvoiceCollection() as $invoice) {
                    $invoice->pay();
                    $invoice->setTransactionId($order->getPayment()->getLastTransId());
                    $invoice->save();
                }

                $additionalInformation = $order->getPayment()->getAdditionalInformation();
                $additionalInformation['marked_completed'] = true;

                $order->getPayment()->setAdditionalInformation($additionalInformation);

                $comment = __('%1 order invoice has not been issued yet.', $this->configRepository::PRODUCT_NAME);
                $this->addStatusToOrderHistory($order, $comment->render());
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
                $this->configRepository::PRODUCT_NAME,
            );
        } else {
            $comment = __(
                '%1 order marked as partially completed.',
                $this->configRepository::PRODUCT_NAME,
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
