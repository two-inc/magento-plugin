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
use Magento\Sales\Api\OrderRepositoryInterface;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

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

    /** @var BrandRegistryInterface */
    private $brandRegistry;

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
    /** @var \Two\Gateway\Api\BrandOverlayRegistryInterface */
    private $overlayRegistry;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        OrderRepositoryInterface $orderRepository,
        ComposeOrder $compositeOrder,
        Adapter $apiAdapter,
        \Two\Gateway\Api\BrandOverlayRegistryInterface $overlayRegistry
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->orderRepository = $orderRepository;
        $this->compositeOrder = $compositeOrder;
        $this->apiAdapter = $apiAdapter;
        $this->overlayRegistry = $overlayRegistry;
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
        if ($order
            && $this->overlayRegistry->isTwoStackMethod((string)$order->getPayment()->getMethod())
            && $order->getTwoOrderId()
        ) {
            try {
                $additionalInformation = $order->getPayment()->getAdditionalInformation();
                // Department and Project are optional at checkout, and the
                // stored payload now leaves the keys out entirely when the
                // buyer skipped them (TWO-25386) — so they must be coalesced
                // here. Re-composing with '' is correct: the composer applies
                // the same omit rule again on the way back out.
                $payload = $this->compositeOrder->execute(
                    $order,
                    $order->getTwoOrderReference(),
                    [
                        'companyName' => $additionalInformation['buyer']['company']['company_name'],
                        'telephone' => $additionalInformation['buyer']['representative']['phone_number'],
                        'companyId' => $additionalInformation['buyer']['company']['organization_number'],
                        'department' => $additionalInformation['buyer_department'] ?? '',
                        'project' => $additionalInformation['buyer_project'] ?? '',
                    ]
                );
                // TWO-25386: merchant_reference, merchant_additional_info and
                // shipping_details are deliberately never part of this
                // request.
                //
                // Nothing in this plugin ever puts them into the payment's
                // additional_information, so there is no local value to send:
                // every write to additional_information either copies the
                // create-order payload (which does not carry these keys) or
                // sets the completion marker, and the checkout data-assign
                // path is limited to its own fixed key list.
                //
                // Why sending them anyway is harmful — the edit-merge
                // semantics of this request — is recorded on TWO-25386. The
                // decision here is to leave all three out unconditionally.
                // Note that shipping_details is delivery/tracking metadata,
                // not the buyer's shipping address: shipping_address is a
                // different field and is composed as usual, above.
                $response = $this->apiAdapter->execute(
                    '/v1/order/' . $order->getTwoOrderId(),
                    $payload,
                    'PUT',
                    (int)$order->getStoreId()
                );
                $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
                if ($response && $error) {
                    $order->addStatusToHistory(
                        $order->getStatus(),
                        $error
                    );
                } else {
                    $comment = __('Order edit request was accepted by %1', $this->brandRegistry->getProductName());
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
