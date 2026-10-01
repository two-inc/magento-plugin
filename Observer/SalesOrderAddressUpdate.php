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
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Two\Gateway\Model\Two;
use Two\Gateway\Service\Api\Adapter;
use Two\Gateway\Service\Order\ComposeOrder;
use Two\Gateway\Service\Order\OrderPostprocessor;
use Two\Gateway\Api\OrderPostprocessingInterface as Postprocessing;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;

/**
 * Order Address Update Observer
 * Put to api address updates for two payments
 */
class SalesOrderAddressUpdate implements ObserverInterface
{
    /** The order states and statuses in which the API accepts an edit; it refuses any other. */
    private const EDITABLE_STATES = ['UNVERIFIED', 'VERIFIED', 'CONFIRMED'];
    private const EDITABLE_STATUSES = ['APPROVED', 'REJECTED', 'DECLINED'];

    /** States and status meaning all or part of the order has been invoiced. */
    private const INVOICED_STATES = ['FULFILLING', 'FULFILLED', 'DELIVERED', 'REFUNDED'];
    private const PARTIAL_STATUS = 'PARTIAL';

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

    /** @var ManagerInterface */
    private $messageManager;

    /** @var OrderPostprocessor */
    private $orderPostprocessor;

    public function __construct(
        ConfigRepository $configRepository,
        BrandRegistryInterface $brandRegistry,
        OrderRepositoryInterface $orderRepository,
        ComposeOrder $compositeOrder,
        Adapter $apiAdapter,
        \Two\Gateway\Api\BrandOverlayRegistryInterface $overlayRegistry,
        ManagerInterface $messageManager,
        OrderPostprocessor $orderPostprocessor
    ) {
        $this->configRepository = $configRepository;
        $this->brandRegistry = $brandRegistry;
        $this->orderRepository = $orderRepository;
        $this->compositeOrder = $compositeOrder;
        $this->apiAdapter = $apiAdapter;
        $this->overlayRegistry = $overlayRegistry;
        $this->messageManager = $messageManager;
        $this->orderPostprocessor = $orderPostprocessor;
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
                $refusal = $this->editRefusal($order);
                if ($refusal !== null) {
                    // The API would refuse this edit, so say why plainly instead of sending it.
                    $order->addStatusToHistory($order->getStatus(), $refusal);
                    $this->messageManager->addNoticeMessage($refusal);
                    $order->save();
                    return $this;
                }
                $additionalInformation = $order->getPayment()->getAdditionalInformation();
                // Orders placed before terms were stored have none; the edit then omits terms so Two keeps the agreed ones.
                $placedTerms = is_array($additionalInformation['terms'] ?? null) ? $additionalInformation['terms'] : null;
                if ($placedTerms !== null && (int)($placedTerms['duration_days'] ?? 0) <= 0) {
                    throw new LocalizedException(
                        __('Order edit was not sent: the order\'s stored payment term is not usable.')
                    );
                }
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
                        'isEdit' => true,
                        'placedTerms' => $placedTerms,
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
                    $this->orderPostprocessor->process(
                        Postprocessing::REQUEST_ORDER_UPDATE,
                        $payload,
                        ['trigger' => 'admin_edit', 'endpoint' => '/v1/order/{id}', 'order' => $order]
                    ),
                    'PUT',
                    (int)$order->getStoreId()
                );
                $error = $order->getPayment()->getMethodInstance()->getErrorFromResponse($response);
                if ($response && $error) {
                    $order->addStatusToHistory(
                        $order->getStatus(),
                        $error
                    );
                    $this->messageManager->addWarningMessage((string)$error);
                } else {
                    $comment = __('Order edit request was accepted by %1', $this->brandRegistry->getProductName());
                    $order->addStatusToHistory($order->getStatus(), $comment->render());
                }
            } catch (Exception $e) {
                $order->addStatusToHistory(
                    $order->getStatus(),
                    $e->getMessage()
                );
                // The admin's address save still succeeds, so say the edit did not reach Two.
                $this->messageManager->addWarningMessage($e->getMessage());
            }

            $order->save();
        }
        return $this;
    }

    /**
     * Why the API would refuse an edit to this order, or null when it would accept one.
     *
     * Reads the order's live state and status and applies the same rule as
     * the API's edit handler: only the editable states and statuses above are
     * accepted. Local flags cannot stand in for this, because the stored
     * completion marker is set by a partial fulfilment as well as a full one.
     * After a partial fulfilment the order the admin edits has the PARTIAL
     * status, and once every part is fulfilled its state becomes FULFILLED.
     * A failed lookup returns null, so the edit is sent and any refusal is
     * reported as before.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return string|null
     */
    private function editRefusal($order): ?string
    {
        try {
            $response = $this->apiAdapter->execute(
                '/v1/order/' . $order->getTwoOrderId(),
                [],
                'GET',
                (int)$order->getStoreId()
            );
        } catch (Exception $e) {
            return null;
        }
        if ($order->getPayment()->getMethodInstance()->getErrorFromResponse($response)) {
            return null;
        }
        $state = $response['state'] ?? null;
        $status = $response['status'] ?? null;
        $refusedState = is_string($state) && !in_array($state, self::EDITABLE_STATES, true);
        $refusedStatus = is_string($status) && !in_array($status, self::EDITABLE_STATUSES, true);
        if (!$refusedState && !$refusedStatus) {
            return null;
        }
        $productName = $this->brandRegistry->getProductName();
        if (in_array($state, self::INVOICED_STATES, true) || $status === self::PARTIAL_STATUS) {
            return __(
                '%1 has already invoiced all or part of this order, so this change was not sent to %1.',
                $productName
            )->render();
        }

        return __(
            '%1 no longer accepts changes to this order (%2), so this change was not sent to %1.',
            $productName,
            $refusedState ? $state : $status
        )->render();
    }
}
