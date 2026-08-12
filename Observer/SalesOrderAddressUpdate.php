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
                // TWO-25386: this is a PUT that merges into the order already
                // held remotely. A key left out of the payload keeps whatever
                // is stored; a key sent as an empty string is accepted and
                // overwrites the stored value with blank. So sending these
                // unconditionally does not validate-fail — it silently erases
                // data that this edit was never meant to touch.
                //
                // Same allowlist shape as the composer's optional fields, and
                // for the same reason: never a blanket empty-strip over
                // $payload, because shipping_address and the amount fields are
                // required and must survive regardless of their value.
                $optionalFields = [
                    'merchant_reference' => (string)($additionalInformation['merchant_reference'] ?? ''),
                    'merchant_additional_info' => (string)($additionalInformation['merchant_additional_info'] ?? ''),
                ];
                foreach ($optionalFields as $key => $value) {
                    // String comparison rather than empty(), so a legitimate
                    // '0' reference would still be sent.
                    if ($value !== '') {
                        $payload[$key] = $value;
                    }
                }

                // shipping_details needs a stronger rule than the scalars
                // above, because it is not merged key-by-key: sending the
                // object at all replaces the stored one wholesale, so every
                // field this payload omits — carrier tracking URL, expected
                // delivery date, the delivery-method and recipient details —
                // is cleared as a side effect. Omitting the key entirely is
                // the only way to leave the stored shipping information
                // alone. Note this is delivery/tracking metadata, not the
                // buyer's shipping address: shipping_address is a separate
                // required field and is composed as usual, above.
                $carrierName = (string)($additionalInformation['shipping_details']['carrier_name'] ?? '');
                $trackingNumber = (string)($additionalInformation['shipping_details']['tracking_number'] ?? '');
                if ($carrierName !== '' || $trackingNumber !== '') {
                    $payload['shipping_details'] = [
                        'carrier_name' => $carrierName,
                        'tracking_number' => $trackingNumber,
                    ];
                }

                $response = $this->apiAdapter->execute('/v1/order/' . $order->getTwoOrderId(), $payload, 'PUT');
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
