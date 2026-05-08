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
use Magento\Sales\Api\OrderRepositoryInterface;
use ABN\Gateway\Model\Two;
use ABN\Gateway\Service\Api\Adapter;
use ABN\Gateway\Service\Order\ComposeOrder;
use ABN\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Order Address Update Observer
 * Put to api address updates for two payments
 */
class SalesOrderAddressUpdate implements ObserverInterface
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var ComposeOrder
     */
    private $compositeOrder;

    /**
     * @var Adapter
     */
    private $apiAdapter;

    /**
     * SalesOrderAddressUpdate constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param ComposeOrder $compositeOrder
     * @param Adapter $apiAdapter
     */
    public function __construct(
        ConfigRepository $configRepository,
        OrderRepositoryInterface $orderRepository,
        ComposeOrder $compositeOrder,
        Adapter $apiAdapter
    ) {
        $this->configRepository = $configRepository;
        $this->orderRepository = $orderRepository;
        $this->compositeOrder = $compositeOrder;
        $this->apiAdapter = $apiAdapter;
    }

    /**
     * @param Observer $observer
     * @return $this
     * @throws Exception
     */
    public function execute(Observer $observer): self
    {
        $orderId = $observer->getEvent()->getOrderId();
        $order = $this->orderRepository->get($orderId);
        if ($order && $order->getPayment()->getMethod() === Two::CODE && $order->getTwoOrderId()) {
            try {
                $additionalInformation = $order->getPayment()->getAdditionalInformation();
                $payload = $this->compositeOrder->execute(
                    $order,
                    $order->getTwoOrderReference(),
                    [
                        'companyName' => $additionalInformation['buyer']['company']['company_name'],
                        'telephone' => $additionalInformation['buyer']['representative']['phone_number'],
                        'companyId' => $additionalInformation['buyer']['company']['organization_number'],
                        'department' => $additionalInformation['buyer_department'],
                        'project' => $additionalInformation['buyer_project'],
                    ]
                );
                $payload['merchant_reference'] = '';
                $payload['merchant_additional_info'] = '';
                $payload['shipping_details'] = [
                    'carrier_name' => '',
                    'tracking_number' => '',
                ];

                $response = $this->apiAdapter->execute('/v1/order/' . $order->getTwoOrderId(), $payload, 'PUT');
                $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
                if ($response && $error) {
                    $order->addStatusToHistory(
                        $order->getStatus(),
                        $error
                    );
                } else {
                    $comment = __('Order edit request was accepted by %1', $this->configRepository::PRODUCT_NAME);
                    $order->addStatusToHistory($order->getStatus(), $comment->render());
                }
            } catch (Exception $e) {
                $order->addStatusToHistory(
                    $order->getStatus(),
                    $e->getMessage()
                );
            }

            $order->save();
        }
        return $this;
    }
}
