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
    /** Order states in which the provider has issued the invoice, or is issuing it, and refuses edits. */
    private const INVOICED_STATES = ['FULFILLING', 'FULFILLED', 'DELIVERED', 'REFUNDED'];

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
                if ($this->isInvoicedByProvider($order)) {
                    // The API refuses edits once the order is invoiced, so say so plainly instead of sending one.
                    $notice = __(
                        '%1 has already invoiced this order, so this change was not sent to %1.',
                        $this->brandRegistry->getProductName()
                    );
                    $order->addStatusToHistory($order->getStatus(), $notice->render());
                    $this->messageManager->addNoticeMessage($notice->render());
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
     * Whether the provider has fully fulfilled, and so invoiced, the order.
     *
     * Asks the API for the order's live state rather than trusting local
     * flags: the stored completion marker is also set by a partial
     * fulfilment, and the order an admin edits is the root order, whose state
     * becomes FULFILLED only once every part of it has been fulfilled. A
     * failed lookup returns false, so the edit is sent and any refusal is
     * reported as before.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return bool
     */
    private function isInvoicedByProvider($order): bool
    {
        try {
            $response = $this->apiAdapter->execute(
                '/v1/order/' . $order->getTwoOrderId(),
                [],
                'GET',
                (int)$order->getStoreId()
            );
        } catch (Exception $e) {
            return false;
        }
        if ($order->getPayment()->getMethodInstance()->getErrorFromResponse($response)) {
            return false;
        }

        return in_array($response['state'] ?? null, self::INVOICED_STATES, true);
    }
}
